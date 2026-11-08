<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Marca;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(StockService::class);
});

/*
|--------------------------------------------------------------------------
| El kardex: A-13 y M-15
|--------------------------------------------------------------------------
|
| El `origen` de un movimiento es polimórfico: en la Fase 6 va a ser la venta y
| en la recepción la orden de compra, pero el servicio no sabe ni le importa de
| qué modelo se trata. Los tests usan una marca como documento justamente para
| demostrar eso: lo que se verifica es que el par origen_type / origen_id quede
| escrito, cualquiera sea el modelo.
|
*/

function documentoDeOrigen(): Marca
{
    return Marca::factory()->create();
}

// ---------- Descontar ----------

test('descontar baja el stock y deja un movimiento negativo', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $movimiento = $this->service->descontar($producto, 3, documentoDeOrigen(), admin());

    expect($producto->fresh()->stock)->toBe(7)
        ->and($movimiento->tipo)->toBe('venta')
        ->and($movimiento->cantidad)->toBe(-3)
        ->and($movimiento->stock_resultante)->toBe(7);
});

test('descontar valida contra el disponible y no contra el stock', function () {
    // 5 en depósito, 5 comprometidas por checkouts en curso: disponible 0. Si
    // mirara sólo `stock`, esta venta dejaría a un cliente que ya pagó sin su
    // unidad y el disponible quedaría en -1.
    $producto = Producto::factory()->conStock(5, 5)->create();

    expect(fn () => $this->service->descontar($producto, 1, documentoDeOrigen(), admin()))
        ->toThrow(StockInsuficienteException::class);

    // Y no deja rastro: la transacción revirtió.
    expect($producto->fresh()->stock)->toBe(5)
        ->and(MovimientoStock::count())->toBe(0);
});

test('descontar rechaza una cantidad que no es positiva', function () {
    // Sin esta guarda, descontar(-5) sumaría stock en silencio.
    $producto = Producto::factory()->conStock(10)->create();

    expect(fn () => $this->service->descontar($producto, -5, documentoDeOrigen(), admin()))
        ->toThrow(ReglaDeNegocioException::class)
        ->and(fn () => $this->service->descontar($producto, 0, documentoDeOrigen(), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(10);
});

// ---------- Reponer ----------

test('reponer suma el stock y deja un movimiento de devolucion', function () {
    $producto = Producto::factory()->conStock(4)->create();

    $movimiento = $this->service->reponer($producto, 2, documentoDeOrigen(), admin());

    expect($producto->fresh()->stock)->toBe(6)
        ->and($movimiento->tipo)->toBe('devolucion')
        ->and($movimiento->cantidad)->toBe(2)
        ->and($movimiento->stock_resultante)->toBe(6);
});

test('reponer guarda el motivo si lo recibe, y lo deja nulo si no', function () {
    $producto = Producto::factory()->conStock(4)->create();

    $conMotivo = $this->service->reponer($producto, 1, documentoDeOrigen(), admin(), 'Vino fallado');
    $sinMotivo = $this->service->reponer($producto, 1, documentoDeOrigen(), admin());

    // El motivo es opcional porque la devolución tiene documento de origen: el
    // «por qué cambió el stock» lo responde el `origen`, y el motivo agrega lo
    // único que no se puede reconstruir después. En el ajuste es obligatorio
    // justamente porque ahí no hay documento.
    expect($conMotivo->motivo)->toBe('Vino fallado')
        ->and($sinMotivo->motivo)->toBeNull()
        // El tipo lo sigue poniendo el método y no el llamador.
        ->and($conMotivo->tipo)->toBe('devolucion')
        ->and($producto->fresh()->stock)->toBe(6);
});
// ---------- Recepción de compra ----------

test('recibir una compra ingresa stock y recalcula el costo promedio ponderado', function () {
    // 10 unidades a $100 más 10 a $200 = 20 unidades a $150.
    $producto = Producto::factory()->conStockYCosto(10, 100)->create();

    $movimiento = $this->service->recibirCompra($producto, 10, 200, documentoDeOrigen(), admin());

    expect($producto->fresh()->stock)->toBe(20)
        // El cast decimal:2 devuelve string, que es lo correcto para un importe.
        ->and($producto->fresh()->costo_promedio)->toBe('150.00')
        ->and($movimiento->tipo)->toBe('compra');
});

test('la primera compra de un producto sin stock fija el costo', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();

    $this->service->recibirCompra($producto, 5, 80, documentoDeOrigen(), admin());

    // Sin el caso especial, la división sería por cero o el promedio arrancaría
    // arrastrando un costo de 0 que nunca se pagó.
    expect($producto->fresh()->costo_promedio)->toBe('80.00');
});

test('el costo promedio se redondea a dos decimales antes de guardarse', function () {
    // (3 × 100 + 4 × 110) / 7 = 105.714285…
    $producto = Producto::factory()->conStockYCosto(3, 100)->create();

    $this->service->recibirCompra($producto, 4, 110, documentoDeOrigen(), admin());

    expect($producto->fresh()->costo_promedio)->toBe('105.71');
});

// ---------- Ajuste de inventario ----------

test('el ajuste recibe el conteo fisico y guarda la diferencia con signo', function () {
    $producto = Producto::factory()->conStock(5)->create();

    $faltante = $this->service->ajustar($producto, 3, 'Faltante detectado en inventario', admin());

    expect($producto->fresh()->stock)->toBe(3)
        ->and($faltante->cantidad)->toBe(-2)
        ->and($faltante->stock_resultante)->toBe(3);

    $sobrante = $this->service->ajustar($producto->fresh(), 8, 'Aparecieron cinco unidades', admin());

    expect($producto->fresh()->stock)->toBe(8)
        ->and($sobrante->cantidad)->toBe(5);
});

test('un ajuste no tiene documento de origen y el motivo ocupa su lugar', function () {
    $producto = Producto::factory()->conStock(5)->create();

    $movimiento = $this->service->ajustar($producto, 7, 'Dos unidades recibidas sin remito', admin());

    // Es la respuesta al «por qué» de A-13 cuando no hay documento que lo explique.
    expect($movimiento->origen_type)->toBeNull()
        ->and($movimiento->origen_id)->toBeNull()
        ->and($movimiento->motivo)->toBe('Dos unidades recibidas sin remito');
});

test('el ajuste exige un motivo', function () {
    $producto = Producto::factory()->conStock(5)->create();

    expect(fn () => $this->service->ajustar($producto, 7, '', admin()))
        ->toThrow(ReglaDeNegocioException::class)
        // Ni un motivo de sólo espacios.
        ->and(fn () => $this->service->ajustar($producto, 7, '   ', admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(5)
        ->and(MovimientoStock::count())->toBe(0);
});

test('el ajuste rechaza un conteo negativo', function () {
    $producto = Producto::factory()->conStock(5)->create();

    expect(fn () => $this->service->ajustar($producto, -1, 'Error de tipeo', admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(5);
});

test('el ajuste rechaza un conteo que no cambia nada', function () {
    $producto = Producto::factory()->conStock(5)->create();

    expect(fn () => $this->service->ajustar($producto, 5, 'Conteo de rutina', admin()))
        ->toThrow(ReglaDeNegocioException::class);

    // El kardex es un libro de movimientos: una fila que no movió nada no es uno.
    expect(MovimientoStock::count())->toBe(0);
});

test('el ajuste se rechaza si el stock cambio desde que se abrio el formulario', function () {
    $producto = Producto::factory()->conStock(10)->create();

    // Entre que el operario abrió la pantalla y guardó, entró una devolución.
    $this->service->reponer($producto, 2, documentoDeOrigen(), admin());

    // La pantalla mostraba 10; el ajuste pisaría la devolución en silencio.
    expect(fn () => $this->service->ajustar($producto, 9, 'Inventario', admin(), stockEsperado: 10))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(12);
});

test('un ajuste puede dejar el stock por debajo de lo reservado', function () {
    $producto = Producto::factory()->conStock(5, 5)->create();

    // El inventario encontró 3 y hay 5 reservadas: la verdad son 3, y lo que
    // está mal son las reservas. Rechazar el ajuste sería negarle al sistema la
    // capacidad de registrar la realidad.
    $this->service->ajustar($producto, 3, 'Faltante detectado en inventario', admin());

    expect($producto->fresh()->stock)->toBe(3)
        ->and($producto->fresh()->stock_reservado)->toBe(5);
});

// ---------- Reglas transversales ----------

test('el servicio mueve el stock de un producto inactivo', function () {
    // El kardex registra movimientos físicos; `activo` es un atributo comercial.
    // Un producto discontinuado sigue estando en el depósito, y el inventario
    // tiene que poder corregirlo: es justo cuando aparece.
    $producto = Producto::factory()->inactivo()->conStock(2)->create();

    $this->service->ajustar($producto, 4, 'Aparecieron dos cajas en el deposito', admin());

    expect($producto->fresh()->stock)->toBe(4);
});

test('el movimiento registra quien, cuando y el documento de origen', function () {
    $producto  = Producto::factory()->conStock(10)->create();
    $documento = documentoDeOrigen();
    $usuario   = admin();

    $movimiento = $this->service->descontar($producto, 2, $documento, $usuario);

    // Las cuatro preguntas que el UPDATE del sistema original no podía responder.
    expect($movimiento->usuario_id)->toBe($usuario->id)
        ->and($movimiento->created_at)->not->toBeNull()
        ->and($movimiento->origen_type)->toBe($documento->getMorphClass())
        ->and($movimiento->origen_id)->toBe($documento->id);
});

test('el stock resultante de cada movimiento reconstruye el saldo', function () {
    $producto  = Producto::factory()->conStock(10)->create();
    $documento = documentoDeOrigen();
    $usuario   = admin();

    $this->service->descontar($producto, 3, $documento, $usuario);
    $this->service->reponer($producto, 1, $documento, $usuario);
    $this->service->ajustar($producto->fresh(), 5, 'Inventario de cierre', $usuario);

    // Poder contestar "¿por qué este producto tiene 5 unidades?" es el motivo
    // por el que existe la tabla.
    expect($producto->movimientos()->orderBy('id')->pluck('stock_resultante')->all())
        ->toBe([7, 8, 5])
        ->and($producto->fresh()->stock)->toBe(5);
});

test('un movimiento del kardex no se puede modificar ni borrar', function () {
    $producto   = Producto::factory()->conStock(5)->create();
    $movimiento = $this->service->ajustar($producto, 7, 'Conteo inicial', admin());

    expect(fn () => $movimiento->update(['cantidad' => 99]))->toThrow(LogicException::class)
        ->and(fn () => $movimiento->delete())->toThrow(LogicException::class);

    expect($movimiento->fresh()->cantidad)->toBe(2);
});

test('los cuatro metodos bloquean la fila del producto', function () {
    // M-15 en su forma exacta: el moverStock original hacía SELECT ... FOR UPDATE
    // para descontar e iba directo al UPDATE para reponer.
    //
    // Un test de una sola conexión no reproduce una carrera —para eso harían
    // falta dos conexiones compitiendo—, pero sí puede afirmar que la consulta
    // PIDE el bloqueo, que es la mitad verificable del hallazgo.
    $producto  = Producto::factory()->conStockYCosto(10, 100)->create();
    $documento = documentoDeOrigen();
    $usuario   = admin();

    $operaciones = [
        'descontar'     => fn () => $this->service->descontar($producto->fresh(), 1, $documento, $usuario),
        'reponer'       => fn () => $this->service->reponer($producto->fresh(), 1, $documento, $usuario),
        'recibirCompra' => fn () => $this->service->recibirCompra($producto->fresh(), 1, 100, $documento, $usuario),
        'ajustar'       => fn () => $this->service->ajustar($producto->fresh(), 50, 'Inventario', $usuario),
    ];

    foreach ($operaciones as $nombre => $operacion) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $operacion();

        $consultas = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        DB::disableQueryLog();

        expect(strtolower($consultas))->toContain('for update');
    }
});

/*
|--------------------------------------------------------------------------
| El stock anterior, derivado del asiento
|--------------------------------------------------------------------------
|
| Punto 8 del revisor: el kardex mostraba el saldo que quedó y no el que había.
| Se resolvió con un accesor y sin migración, y lo que lo hace posible es que el
| servicio escriba `cantidad` con signo junto con `stock_resultante`. El test afirma
| la identidad sobre asientos creados por el servicio, que es el único que las
| escribe: con filas puestas a mano probaría la resta y no el sistema.
|
*/

test('el stock anterior de cada asiento es el resultante del asiento previo', function () {
    $producto = Producto::factory()->create();
    $usuario  = admin();

    // Tres ajustes: una entrada, otra entrada y una salida. El tercero es el que
    // importa: con `cantidad` negativa, la resta tiene que seguir dando el saldo
    // anterior y no la suma.
    $this->service->ajustar($producto, 12, 'Conteo inicial de inventario.', $usuario);
    $this->service->ajustar($producto->fresh(), 15, 'Llegó mercadería sin orden.', $usuario);
    $this->service->ajustar($producto->fresh(), 9, 'Faltante detectado en góndola.', $usuario);

    $asientos = $producto->movimientos()->orderBy('id')->get();

    // El primero arranca en cero: antes de ese asiento el producto no tenía nada.
    expect($asientos->pluck('stock_anterior')->all())->toBe([0, 12, 15]);
    expect($asientos->pluck('stock_resultante')->all())->toBe([12, 15, 9]);
    expect($asientos->pluck('cantidad')->all())->toBe([12, 3, -6]);
});

/*
|--------------------------------------------------------------------------
| La puerta al costo, sin movimiento de stock
|--------------------------------------------------------------------------
|
| El ajuste masivo del tramo D sube el costo de un conjunto de productos para que
| el motor recalcule el precio solo. `costo_promedio` está fuera de `$fillable` y
| esta clase es la única que lo escribe, así que la operación entra acá como un
| método más y no como una excepción al invariante.
|
| Lo que estos tests fijan es justamente lo que lo distingue de los otros cuatro:
| cambia el costo, no toca el stock y NO escribe en el kardex. Un asiento con
| cantidad cero en un libro de movimientos físicos sería una fila que no registra
| ningún movimiento.
|
*/

test('fijar el costo cambia el costo y no deja asiento en el kardex', function () {
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    $this->service->fijarCosto($producto, 1150);

    expect($producto->fresh()->costo_promedio)->toBe('1150.00')
        ->and($producto->fresh()->stock)->toBe(10)
        ->and(MovimientoStock::where('producto_id', $producto->id)->count())->toBe(0);
});

test('el costo se redondea a dos decimales antes de guardarse', function () {
    // La columna es decimal(12,2) y el ajuste multiplica por un porcentaje: el
    // resultado se redondea en la frontera que se persiste, como ya hacía el
    // promedio ponderado.
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    expect($this->service->fijarCosto($producto, 1234.567))->toBe('1234.57');
});

test('fijar el costo rechaza un costo negativo', function () {
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    expect(fn () => $this->service->fijarCosto($producto, -1))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->costo_promedio)->toBe('1000.00');
});

test('fijar el costo en cero se permite, porque es un costo que el sistema ya conoce', function () {
    // Es el valor con el que nace un producto, y el motor ya sabe qué hacer con él:
    // `PrecioService::recalcular()` devuelve SIN_COSTO en lugar de calcular cero.
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    expect($this->service->fijarCosto($producto, 0))->toBe('0.00');
});

test('fijar el costo bloquea la fila del producto', function () {
    // La quinta hoja de la puerta pide el bloqueo como las otras cuatro (M-15): una
    // recepción simultánea calcula el promedio ponderado sobre este mismo número.
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->service->fijarCosto($producto, 1200);

    $consultas = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();

    expect(strtolower($consultas))->toContain('for update');
});

test('fijar el costo no deja el producto en memoria con la columna sucia', function () {
    // Quien llama recalcula el precio con el costo que el método devuelve. Si el
    // método tocara el objeto, el `save()` del precio arrastraría una columna que
    // está fuera de `$fillable` a propósito.
    $producto = Producto::factory()->conStockYCosto(10, 1000)->create();

    $this->service->fijarCosto($producto, 1150);

    expect($producto->isDirty())->toBeFalse()
        ->and($producto->costo_promedio)->toBe('1000.00');
});