<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\PagoService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Los dos: el cobro atraviesa los dos servicios, y las ventas se arman llamando
    // al servicio y no a la factory. `VentaFactory::pagada()` escribe el estado y
    // nada más —no descuenta stock, no deja kardex y no crea pagos—, así que sirve
    // para listados, filtros y transiciones, y no sirve para probar nada sobre plata.
    $this->pagos  = app(PagoService::class);
    $this->ventas = app(VentaService::class);
});

/*
|--------------------------------------------------------------------------
| El pago: invariantes del modelo
|--------------------------------------------------------------------------
|
| En este paso todavía no hay servicio del cobro, así que lo que se prueba acá son
| afirmaciones sobre el modelo que no tienen pantalla desde donde probarlas: que un
| pago no se borra, que las columnas del servidor no entran por asignación masiva, y
| que el saldo se calcula bien. Es el mismo criterio con el que VentaFiltrosTest
| termina probando la guarda de borrado de la venta y OrdenCompraFiltrosTest el
| UNIQUE de sus líneas.
|
| Las reglas del cobro —el saldo dentro del lock, la idempotencia, el descuento de
| stock exactamente una vez— se agregan a este mismo archivo cuando exista
| PagoService.
|
*/

/**
 * Un pago escrito a mano, sin pasar por el servicio.
 *
 * Es deliberadamente lo que nadie debería hacer en el código de producción: no
 * descuenta stock y no cambia el estado de la venta, así que produce una fila que el
 * sistema nunca produciría. Existe sólo para poder probar qué pasa cuando alguien la
 * escribe, y por eso vive acá y no en `tests/Pest.php`: no es un ayudante
 * compartido, es el sujeto de estos tests.
 *
 * Asigna `fecha` por propiedad porque la columna es NOT NULL, no tiene DEFAULT y
 * está fuera de `$fillable`. Todo camino que escriba un pago tiene que hacer lo
 * mismo, incluido el servicio.
 */
function pagoCrudoDeVenta(Venta $venta, float|string $monto, string $metodo = 'efectivo', array $extra = []): Pago
{
    $pago = $venta->pagos()->make(array_merge([
        'metodo' => $metodo,
        'monto'  => $monto,
    ], $extra));

    $pago->fecha = now();
    $pago->save();

    return $pago;
}

test('un pago no se puede borrar', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();
    $pago  = pagoCrudoDeVenta($venta, 1000);

    // M-16 sobre un documento financiero: un pago registra que entró plata, y si se
    // devolvió eso son dos hechos. Borrar el primero afirmaría que nunca pasó.
    expect(fn () => $pago->delete())->toThrow(LogicException::class);

    expect(Pago::count())->toBe(1);
});

test('las columnas que escribe el servidor no entran por asignacion masiva', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();
    $otro  = User::factory()->create();

    $pago = pagoCrudoDeVenta($venta, 1000, 'efectivo', [
        'usuario_id'      => $otro->id,
        'mp_payment_id'   => 'mp-inventado',
        'mp_status'       => 'approved',
        'comision'        => 50,
        'neto_acreditado' => 950,
        'cuotas'          => 6,
    ]);

    // Se descartan en silencio, que es lo que queremos de esta capa: el cajero sale
    // de la sesión y las columnas de Mercado Pago las escribe el webhook de la
    // Etapa 2. Acreditar un neto que nadie acreditó rompería la métrica del panel.
    // El `prohibited` que además lo informa va en el Form Request del paso 2: el
    // modelo impide, el Request explica.
    expect($pago->fresh())
        ->usuario_id->toBeNull()
        ->mp_payment_id->toBeNull()
        ->mp_status->toBeNull()
        ->comision->toBeNull()
        ->neto_acreditado->toBeNull()
        ->cuotas->toBeNull();
});

test('el saldo de una venta sin pagos es su total', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(1000), 3)->create();

    expect($venta->pagado())->toBe(0.0)
        ->and($venta->saldo())->toBe(3000.0);
});

test('lo pagado lo suma la base y el saldo baja con cada pago', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(1000), 3)->create();

    pagoCrudoDeVenta($venta, 1200, 'efectivo');
    pagoCrudoDeVenta($venta, 800, 'transferencia');

    // Dos filas para una venta, que es por qué `pagos` es una tabla y no dos
    // columnas: un cobro se reparte entre métodos. Y la suma la hace la base
    // (A-26), no una colección recorrida en PHP.
    expect($venta->pagado())->toBe(2000.0)
        ->and($venta->saldo())->toBe(1000.0);
});

test('un pago negativo revierte lo pagado y devuelve el saldo entero', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(1000), 2)->create();

    pagoCrudoDeVenta($venta, 2000, 'efectivo');
    expect($venta->saldo())->toBe(0.0);

    // El contra-asiento de la devolución (A-11), probado como aritmética antes de que
    // exista quien lo escriba. No se edita ni se borra el pago original: se le pone
    // uno en contra, igual que el kardex guarda la cantidad con signo. `SUM(monto)`
    // sigue respondiendo cuánta plata quedó, sin filtrar nada y sin una columna de
    // reversión que habría que mirar en cada consulta.
    pagoCrudoDeVenta($venta, -2000, 'efectivo');

    expect($venta->pagado())->toBe(0.0)
        ->and($venta->saldo())->toBe(2000.0)
        ->and($venta->pagos()->count())->toBe(2);   // los dos hechos, a la vista
});

test('el pago nombra su metodo en palabras', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 1)->create();
    $pago  = pagoCrudoDeVenta($venta, 1000, 'qr');

    // Igual que Venta::estadoTexto(): el modelo sabe nombrar su propio ENUM, así que
    // la pantalla no muestra el valor crudo de la columna ni repite el diccionario.
    expect($pago->metodoTexto())->toBe('QR');
});

test('mercadopago esta declarado en la base y no se ofrece en la etapa 1', function () {
    // La base declara los cuatro para no reescribir la tabla cuando llegue la
    // integración; la pantalla ofrece los tres que el sistema puede honrar. Un cobro
    // registrado como Mercado Pago tendría comision y neto_acreditado en null, y el
    // sistema estaría afirmando una acreditación que no conoce. Mismo criterio que
    // `canal_pedido` y el correo al proveedor en la Fase 5.
    expect(Pago::METODOS_EN_USO)->toBe(['efectivo', 'transferencia', 'qr'])
        ->and(array_keys(Pago::METODOS))->toContain('mercadopago')
        ->and(Pago::METODOS_EN_USO)->not->toContain('mercadopago');

    // Y ninguno de los que se ofrecen puede faltar en el diccionario de textos: si
    // faltara, la pantalla mostraría el valor de la columna sin que nada falle.
    foreach (Pago::METODOS_EN_USO as $metodo) {
        expect(Pago::METODOS)->toHaveKey($metodo);
    }
});

test('el pago se relaciona con su venta y con el cajero que cobro', function () {
    $cajero = User::factory()->create();
    $venta  = Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $pago = pagoCrudoDeVenta($venta, 1000);
    $pago->usuario_id = $cajero->id;    // por propiedad: no es asignable en masa
    $pago->save();

    expect($pago->fresh()->venta->id)->toBe($venta->id)
        ->and($pago->fresh()->usuario->id)->toBe($cajero->id);
});

test('los cobros de una persona se llegan desde su usuario', function () {
    $cajero   = User::factory()->create();
    $vendedor = User::factory()->create();

    $venta = Venta::factory()->conLinea(productoConPrecios(), 1)
        ->create(['usuario_id' => $vendedor->id]);

    $pago = pagoCrudoDeVenta($venta, 1000);
    $pago->usuario_id = $cajero->id;
    $pago->save();

    // Quién vendió y quién cobró son dos preguntas distintas, y por eso son dos
    // relaciones. Es lo que le faltaba al withCount del listado de personal
    // (pendiente #2) y lo que el panel de la Fase 7 necesita para no confundir los
    // dos rankings.
    expect($cajero->pagos()->count())->toBe(1)
        ->and($vendedor->pagos()->count())->toBe(0)
        ->and($vendedor->ventas()->count())->toBe(1)
        ->and($cajero->ventas()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El cobro
|--------------------------------------------------------------------------
|
| El cobro es por el total exacto: los pagos parciales están fuera del alcance.
| Lo que sí existe es el cobro repartido entre medios de pago, que es lo que la
| auditoría pedía conservar —la venta 25 del dump original tenía transferencia más
| Mercado Pago— y que no es lo mismo: tres filas que suman el total son un pago con
| tres medios, no tres pagos parciales.
|
*/

test('el cobro por el total exacto pasa la venta a pagada y descuenta el stock', function () {
    // Un precio que no da un total redondo, a propósito: la comparación del monto
    // contra el saldo se hace en centavos enteros, y preguntar una igualdad de
    // dinero con `===` sobre flotantes es cómo se llega a una tolerancia de 0,001
    // como la que tenía el plan original.
    $producto = productoConPrecios(contado: 333.33);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    expect($venta->total)->toBe('999.99');

    // El monto llega con punto y no con coma: la traducción del formato argentino
    // —"999,99" → "999.99"— la hace `PagoRequest::prepareForValidation()`, que es la
    // capa que habla con el formulario. El servicio recibe números, no texto de
    // pantalla.
    $cobrada = $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => '999.99'],
    ]], admin());

    expect($cobrada->estado)->toBe('pagada')
        ->and($cobrada->pagos()->count())->toBe(1)
        ->and($cobrada->pagos()->first()->monto)->toBe('999.99')
        ->and($cobrada->saldo())->toBe(0.0)
        // El cobro es lo que descuenta el stock, y es lo único que lo descuenta por
        // una venta.
        ->and($producto->fresh()->stock)->toBe(47)
        ->and(MovimientoStock::count())->toBe(1);
});

test('varios medios en un solo cobro suman el total', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    $cobrada = $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo',      'monto' => 1200],
        ['metodo' => 'transferencia', 'monto' => 800],
        ['metodo' => 'qr',            'monto' => 1000],
    ]], admin());

    expect($cobrada->estado)->toBe('pagada')
        ->and($cobrada->pagos()->count())->toBe(3)
        ->and($cobrada->pagado())->toBe(3000.0)
        ->and($cobrada->saldo())->toBe(0.0)
        ->and($cobrada->pagos()->pluck('metodo')->sort()->values()->all())
            ->toBe(['efectivo', 'qr', 'transferencia'])
        // Tres filas de pago, un solo descuento de stock: el stock se descuenta por
        // el pase a `pagada`, que ocurre una vez, no por cada fila.
        ->and($producto->fresh()->stock)->toBe(47)
        ->and(MovimientoStock::count())->toBe(1);
});

test('un cobro de menos se rechaza y dice cuanto falta', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    try {
        $this->pagos->cobrar($venta, ['pagos' => [
            ['metodo' => 'efectivo', 'monto' => 1500],
        ]], admin());

        $this->fail('El cobro parcial se aceptó, y los pagos parciales están fuera del alcance.');
    } catch (ReglaDeNegocioException $e) {
        // Los dos lados del desajuste tienen mensajes distintos porque son dos
        // problemas con dos salidas distintas, y el usuario tiene que saber cuál
        // tiene.
        expect($e->getMessage())->toContain('Faltan');
    }

    expect($venta->fresh()->pagos()->count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto')
        ->and($producto->fresh()->stock)->toBe(50);
});

test('un cobro de mas se rechaza y dice que no hay vuelto', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    try {
        $this->pagos->cobrar($venta, ['pagos' => [
            ['metodo' => 'efectivo', 'monto' => 5000],
        ]], admin());

        $this->fail('El cobro con exceso se aceptó: el sistema registraría plata que no corresponde a la venta.');
    } catch (ReglaDeNegocioException $e) {
        // Se carga lo que vale la venta, no lo que el cliente puso sobre el
        // mostrador. El vuelto no es un dato del documento.
        expect($e->getMessage())->toContain('vuelto');
    }

    expect($venta->fresh()->pagos()->count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('cobrar dos veces se rechaza y no descuenta stock dos veces', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    expect($producto->fresh()->stock)->toBe(47);

    // Doble clic, dos pestañas, o el botón «atrás» y volver a enviar. Lo ataja el
    // estado: `pagada → pagada` no está declarado, y el servicio le pregunta a la
    // tabla de transiciones antes de leer el saldo. Es el cuarto agujero de C-9
    // haciendo el trabajo de C-10.
    expect(fn () => $this->pagos->cobrar($venta->fresh(), ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(47)
        ->and($venta->fresh()->pagos()->count())->toBe(1)
        ->and(MovimientoStock::count())->toBe(1);
});

test('cobrar una venta que no admite el pase se rechaza', function (string $estado) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->{$estado}()->conLinea($producto, 2)->create();

    // La pregunta no se compara contra `'presupuesto'` escrito a mano: se le hace a
    // `MaquinaEstadosVenta`. Así la respuesta sale de la única fuente que la tiene, y
    // cuando la Etapa 2 opere `pendiente_pago` el servicio no se toca.
    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->estado)->toBe($estado)
        ->and(Pago::count())->toBe(0)
        ->and($producto->fresh()->stock)->toBe(50);
})->with(['cancelada', 'entregada']);

test('el mismo medio de pago dos veces se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Suma el total exacto, así que lo único que lo rechaza es el medio repetido.
    // Mismo criterio que el mismo producto dos veces en una venta: no es un agujero
    // de plata, es un documento con el mismo renglón repetido, y el día que haya que
    // revertirlo habría dos filas candidatas para la misma cosa.
    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => 1000],
        ['metodo' => 'efectivo', 'monto' => 1000],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->pagos()->count())->toBe(0);
});

test('un monto que no es positivo se rechaza', function (int|float $monto) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Un cobro suma plata. Un monto negativo es la reversión de un pago, y eso lo
    // escribe la devolución: si el cobro lo aceptara, se podría bajar lo cobrado de
    // una venta por la pantalla del mostrador, sin dejar el rastro de una devolución.
    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $monto],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->pagos()->count())->toBe(0);
})->with([0, -1000]);

test('un cobro sin medios de pago se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => []], admin()))
        ->toThrow(ReglaDeNegocioException::class)
        // Y sin la clave tampoco: el servicio no puede depender de que el Form
        // Request la haya puesto, porque la API de la Etapa 3 lo va a llamar directo.
        ->and(fn () => $this->pagos->cobrar($venta, [], admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->estado)->toBe('presupuesto');
});

test('los medios sin monto se descartan y no son un error', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Esto es exactamente lo que manda la pantalla: una fila por medio ofrecido, con
    // monto sólo en el que se usó. El bug que este test fija vivía en el Form
    // Request, que descartaba «filas vacías» y nunca encontraba ninguna porque
    // `metodo` es un hidden con valor fijo.
    //
    // Se prueban las dos formas del vacío —null y cadena vacía— para no depender de
    // que el middleware global convierta una en la otra.
    $cobrada = $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo',      'monto' => $venta->total],
        ['metodo' => 'transferencia', 'monto' => null],
        ['metodo' => 'qr',            'monto' => ''],
    ]], admin());

    expect($cobrada->estado)->toBe('pagada')
        ->and($cobrada->pagos()->count())->toBe(1)
        ->and($cobrada->pagos()->first()->metodo)->toBe('efectivo')
        ->and($cobrada->pagado())->toBe(2000.0);
});

test('un cobro donde ningun medio trae monto se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Que venga al menos un monto es una regla sobre el conjunto y no sobre un campo,
    // así que la decide el servicio y se traduce a un aviso general. Mismo criterio
    // que la recepción de mercadería con todas las cantidades en cero.
    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo',      'monto' => null],
        ['metodo' => 'transferencia', 'monto' => ''],
        ['metodo' => 'qr',            'monto' => null],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->pagos()->count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('el pago guarda al cajero y la fecha, y nada que venga de afuera', function () {
    $vendedor = admin();
    $cajero   = usuarioCon('venta.cobrar');
    $producto = productoConPrecios(contado: 1000);

    $venta = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), $vendedor);
    $total = $venta->total;

    $this->travelTo('2026-03-15 10:30:00');

    $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $total],
    ]], $cajero);

    $this->travelBack();

    $pago = $venta->fresh()->pagos()->firstOrFail();

    expect($pago->usuario_id)->toBe($cajero->id)
        // Quién cobró y quién vendió, en dos columnas de dos tablas (M-19). No hay
        // un `created_by` genérico: la autoría se guarda donde la pregunta aparece.
        ->and($venta->fresh()->usuario_id)->toBe($vendedor->id)
        ->and($cajero->id)->not->toBe($vendedor->id)
        // `fecha` es la del hecho. Que exista al lado de `created_at` no es
        // redundancia: en el mostrador coinciden, y un webhook de Mercado Pago que
        // llega con diez minutos de atraso no.
        ->and($pago->fecha->format('Y-m-d H:i'))->toBe('2026-03-15 10:30')
        // El cobro de mostrador no acredita nada de Mercado Pago, y el servicio no
        // inventa valores para columnas que no conoce.
        ->and($pago->mp_payment_id)->toBeNull()
        ->and($pago->comision)->toBeNull()
        ->and($pago->neto_acreditado)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| El saldo se lee después del lock — el núcleo de C-10
|--------------------------------------------------------------------------
|
| El original hacía esto:
|
|     if ($monto > (float) $venta["saldo"] + 0.001) throw ...
|     $dao->registrarPago(...);   // recién acá abre transacción y hace FOR UPDATE
|
| Los tres tests de esta sección atacan ese defecto desde tres lados, y cada uno
| se pone en rojo con un cambio distinto: saquemos el lock y falla el primero,
| comparemos contra `total` en lugar de contra el saldo y falla el segundo, leamos
| el saldo antes del lock y fallan el primero y el tercero.
|
*/

test('la venta se bloquea antes de leer el saldo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $consultas = collect(DB::getQueryLog())->pluck('query')->map('strtolower');

    $lock  = $consultas->search(fn (string $q) => str_contains($q, 'from `ventas`') && str_contains($q, 'for update'));
    $saldo = $consultas->search(fn (string $q) => str_contains($q, 'from `pagos`') && str_contains($q, 'sum('));

    expect($lock)->not->toBeFalse()
        ->and($saldo)->not->toBeFalse()
        // El orden es el hallazgo. La fila de `ventas` es el candado de todo lo que
        // es plata de esa venta: mientras una petición lo tiene, ninguna otra puede
        // leer el saldo ni escribir un pago, porque las dos empiezan por el mismo
        // lock. Que el candado sea `ventas` y no `pagos` es a propósito: un pago que
        // todavía no existe no se puede bloquear.
        ->and($lock)->toBeLessThan($saldo);

    DB::disableQueryLog();
});

test('el saldo sale de los pagos ya registrados y no del total', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Un presupuesto con plata adentro es una fila que el sistema no produce —los
    // pagos parciales están fuera del alcance— y por eso se escribe a mano. Es
    // exactamente para lo que existe `pagoCrudoDeVenta()`.
    pagoCrudoDeVenta($venta, 1500);

    // Por el total completo ahora sobra, porque el saldo son 500.
    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => 2000],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    // Y por el saldo real, pasa. Si el servicio comparara el monto contra `total` en
    // lugar de contra el saldo, las dos mitades de este test fallarían al revés.
    $cobrada = $this->pagos->cobrar($venta->fresh(), ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => 500],
    ]], admin());

    expect($cobrada->estado)->toBe('pagada')
        ->and($cobrada->pagado())->toBe(2000.0)
        ->and($cobrada->saldo())->toBe(0.0)
        ->and($cobrada->pagos()->count())->toBe(2);
});

test('dos cobros concurrentes no exceden el saldo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Es el criterio de terminado de la fase, con lo que un test de una sola
    // conexión puede afirmar. `RefreshDatabase` envuelve cada test en su propia
    // transacción, así que una segunda conexión no vería los datos de este test y
    // dos conexiones reales no se pueden montar acá.
    //
    // Lo que sí se puede es montar el entrelazado exacto donde el lock importa: se
    // escucha el log de consultas y, en el instante en que el cobro termina de tomar
    // el lock de la venta, se inserta «el pago de la otra petición». Si el saldo se
    // leyera antes del lock, ese pago no entraría en la cuenta y este cobro por el
    // total se aceptaría, dejando 4000 cobrados sobre una venta de 2000 — que es,
    // línea por línea, el hallazgo C-10.
    //
    // Que lance es la afirmación del test. El pago inyectado vive en la misma
    // transacción que el cobro, así que el rollback se lo lleva también y después no
    // queda ninguna fila: eso es un artefacto de la simulación, no del sistema.
    $inyectado = false;

    DB::listen(function ($consulta) use ($venta, &$inyectado) {
        if ($inyectado) {
            return;
        }

        $sql = strtolower($consulta->sql);

        if (str_contains($sql, 'from `ventas`') && str_contains($sql, 'for update')) {
            $inyectado = true;
            pagoCrudoDeVenta($venta, 2000);
        }
    });

    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => 2000],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($inyectado)->toBeTrue()     // el escenario se montó de verdad
        ->and($venta->fresh()->estado)->toBe('presupuesto')
        ->and($producto->fresh()->stock)->toBe(50)
        ->and(MovimientoStock::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La idempotencia por clave externa — la otra mitad de C-10
|--------------------------------------------------------------------------
|
| «Falta además clave de idempotencia en `pagos`: no hay forma de distinguir un
| reintento de un pago nuevo», dice el hallazgo. El índice único de
| `mp_payment_id` está en el esquema desde la Fase 1 y hasta ahora no había
| ningún código que lo leyera, así que el mecanismo existía y no intervenía.
|
| En la Etapa 1 el único que manda la clave es este test, y es la razón por la que
| el parámetro existe: cambia el comportamiento del método y cierra una mitad de un
| hallazgo nombrado. `PagoRequest` la declara `prohibited`, porque la pantalla del
| mostrador no es el webhook.
|
*/

test('el mismo pago externo dos veces no acredita dos veces ni descuenta dos veces', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $mensaje = ['pagos' => [
        ['metodo' => 'mercadopago', 'monto' => $venta->total, 'mp_payment_id' => 'mp-12345'],
    ]];

    $this->pagos->cobrar($venta, $mensaje, admin());

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($producto->fresh()->stock)->toBe(48)
        ->and($venta->fresh()->pagos()->first()->mp_payment_id)->toBe('mp-12345');

    // El reintento. No tira y no escribe: devuelve la venta como está, que es la
    // definición de idempotente —el mismo mensaje dos veces produce el mismo
    // estado—. Si tirara, Mercado Pago no recibiría el 200 y seguiría reintentando;
    // y sin este camino, el índice único convertiría el reintento en un error del
    // servidor en lugar de en un no-op.
    $devuelta = $this->pagos->cobrar($venta->fresh(), $mensaje, admin());

    expect($devuelta->estado)->toBe('pagada')
        ->and($venta->fresh()->pagos()->count())->toBe(1)
        ->and($producto->fresh()->stock)->toBe(48)
        ->and(MovimientoStock::count())->toBe(1);
});

test('un reintento que mezcla pagos acreditados y nuevos se rechaza sin escribir nada', function () {
    $producto = productoConPrecios(contado: 1000);

    $primera = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());
    $this->pagos->cobrar($primera, ['pagos' => [
        ['metodo' => 'mercadopago', 'monto' => $primera->total, 'mp_payment_id' => 'mp-999'],
    ]], admin());

    $segunda = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Los montos suman el total exacto de la segunda venta, así que lo único que
    // rechaza el cobro es la mezcla. Si se dejara pasar, el UNIQUE de
    // `mp_payment_id` abortaría el insert de la ya acreditada y el reintento se
    // convertiría en un error del servidor; y si se filtraran las ya acreditadas
    // para escribir sólo las nuevas, el total no coincidiría con el saldo y el
    // rechazo sería por el motivo equivocado.
    expect(fn () => $this->pagos->cobrar($segunda, ['pagos' => [
        ['metodo' => 'mercadopago',   'monto' => 1000, 'mp_payment_id' => 'mp-999'],
        ['metodo' => 'transferencia', 'monto' => 1000],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($segunda->fresh()->pagos()->count())->toBe(0)
        ->and($segunda->fresh()->estado)->toBe('presupuesto')
        // La búsqueda de la clave es global y no por venta: un `mp_payment_id`
        // identifica un pago en el mundo, no un pago de esta venta.
        ->and(Pago::where('mp_payment_id', 'mp-999')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| El cruce con el stock: una sola transacción para las dos mitades
|--------------------------------------------------------------------------
|
| Las filas de pago se escriben ANTES del pase a `pagada`, que es el que descuenta
| stock. Así que cuando el pase falla, el pago ya está escrito y la transacción
| tiene que llevárselo. Si no, quedaría plata registrada sobre una venta que nunca
| se cobró — que es A-11 por la puerta de atrás.
|
*/

test('un producto dado de baja revierte tambien el pago', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Se desactiva después de emitido el presupuesto, que es el caso real.
    $producto->update(['activo' => false]);

    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin()))->toThrow(ReglaDeNegocioException::class);

    expect(Pago::count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto')
        ->and($producto->fresh()->stock)->toBe(50)
        ->and(MovimientoStock::count())->toBe(0);
});

test('sin disponible suficiente no queda ningun pago registrado', function () {
    $producto = Producto::factory()->conStock(1)->create();
    $venta    = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 5)]), admin());

    expect(fn () => $this->pagos->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin()))->toThrow(StockInsuficienteException::class);

    expect(Pago::count())->toBe(0)
        ->and($producto->fresh()->stock)->toBe(1)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});