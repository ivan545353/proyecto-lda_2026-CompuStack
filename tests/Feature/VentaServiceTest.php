<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Cliente;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\PagoService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Exceptions\TransicionInvalidaException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(VentaService::class);
});

/*
|--------------------------------------------------------------------------
| El alta del presupuesto
|--------------------------------------------------------------------------
|
| Se prueba el servicio directo, sin pasar por HTTP: es lo que M-28 hizo posible
| —en el original cada método hacía `new SaleDao(Connection::get())` adentro y no
| había forma de probar una regla de negocio sin levantar la aplicación entera—.
|
*/

test('el alta crea un presupuesto con sus lineas y los totales calculados', function () {
    $uno  = productoConPrecios(contado: 1000);
    $otro = productoConPrecios(contado: 2500);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($uno, 3),
        lineaDeVenta($otro, 2),
    ]), admin());

    // 3 × 1.000 + 2 × 2.500
    expect($venta->estado)->toBe('presupuesto')
        ->and($venta->lineas)->toHaveCount(2)
        ->and($venta->subtotal)->toBe('8000.00')
        ->and($venta->descuento)->toBe('0.00')
        ->and($venta->total)->toBe('8000.00')
        ->and($venta->numeroFormateado())->toBe('V-'.str_pad((string) $venta->id, 5, '0', STR_PAD_LEFT));
});

test('el presupuesto nace de mostrador, con retiro, y registra al vendedor', function () {
    $vendedor = usuarioCon('venta.crear');

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios())]), $vendedor);

    // Canal y modo de entrega no llegan del formulario: la tienda y la tabla de
    // envíos son de la Etapa 2, y una venta con envío no tendría dónde guardar la
    // dirección. Las columnas están declaradas; el campo aparece con su módulo.
    expect($venta->canal)->toBe('mostrador')
        ->and($venta->modo_entrega)->toBe('retiro')
        ->and($venta->costo_envio)->toBe('0.00')
        ->and($venta->usuario_id)->toBe($vendedor->id);
});

test('la linea congela el precio, la alicuota, el costo y la descripcion', function () {
    $producto = productoConPrecios(contado: 1000, costo: 600, alicuota: 21);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());
    $linea = $venta->lineas->first();

    expect($linea->precio_unitario)->toBe('1000.00')
        ->and($linea->alicuota_iva)->toBe('21.00')
        // Sin el costo congelado, el margen del panel se calcularía contra el costo
        // de hoy y daría números falsos cada vez que llega mercadería más cara.
        ->and($linea->costo_unitario)->toBe('600.00')
        // La descripción se copia: si el producto se renombra, el documento tiene
        // que seguir diciendo qué se vendió.
        ->and($linea->descripcion)->toBe($producto->nombre)
        ->and($linea->cantidad_devuelta)->toBe(0);
});

test('un cambio de precio posterior no toca la venta ya emitida', function () {
    $producto = productoConPrecios(contado: 1000);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1800, 'precio_lista' => 2000]);

    // Es la mitad de M-14 que el original sí tenía resuelta en la línea y rompía en
    // el update: `detalle_ventas.precio_unit` guardaba el precio congelado, pero
    // `SaleDao::update()` borraba las líneas y las reinsertaba con el precio nuevo.
    // Acá nadie relee nada sin que se lo pidan.
    expect($venta->fresh('lineas')->lineas->first()->precio_unitario)->toBe('1000.00')
        ->and($venta->fresh()->total)->toBe('2000.00');
});

test('el neto y el iva de la linea suman exactamente el total', function (float|string $alicuota, string $neto, string $iva) {
    $producto = productoConPrecios(contado: 1000, alicuota: $alicuota);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());
    $linea = $venta->lineas->first();

    // Los dos precios del producto son finales: el IVA está adentro. Así que el neto
    // se obtiene dividiendo y el IVA es la diferencia. Calcular el IVA sobre el neto
    // redondeado haría que neto + iva no diera el total por un centavo, y el total
    // es lo que el cliente paga y lo que se declara.
    expect($linea->total)->toBe('3000.00')
        ->and($linea->neto)->toBe($neto)
        ->and($linea->iva)->toBe($iva)
        ->and(round((float) $linea->neto + (float) $linea->iva, 2))->toBe(3000.00);
})->with([
    [21, '2479.34', '520.66'],
    [10.5, '2714.93', '285.07'],
    // Exento: el neto es el total y el IVA es cero. La división por 1 no es un caso
    // especial en el código, y este caso verifica que no haga falta que lo sea.
    [0, '3000.00', '0.00'],
]);



test('el precio que manda el cliente se ignora', function () {
    $producto = productoConPrecios(contado: 1000);

    $venta = $this->service->crear(datosDeVenta([[
        'producto_id'     => $producto->id,
        'cantidad'        => 1,
        // La manipulación de precios, que es contra lo que el original se defendía
        // bien y hay que conservar. Dos barreras: el servicio no mira estas claves,
        // y el modelo no las podría escribir aunque las mirara.
        'precio_unitario' => 1,
        'total'           => 1,
        'neto'            => 1,
    ]]), admin());

    expect($venta->lineas->first()->precio_unitario)->toBe('1000.00')
        ->and($venta->total)->toBe('1000.00');
});


/*
|--------------------------------------------------------------------------
| Lo que el alta rechaza
|--------------------------------------------------------------------------
*/

test('el alta sin lineas se rechaza', function () {
    expect(fn () => $this->service->crear(datosDeVenta([]), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('el alta con el mismo producto dos veces se rechaza en vez de fusionarse', function () {
    $producto = productoConPrecios();

    // `venta_lineas` no tiene UNIQUE (venta_id, producto_id), a diferencia de la
    // línea de compra: acá la regla la pone el servicio. Fusionar en silencio sería
    // decidir por el usuario qué quiso decir.
    expect(fn () => $this->service->crear(datosDeVenta([
        lineaDeVenta($producto, 2),
        lineaDeVenta($producto, 3),
    ]), admin()))->toThrow(ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('un producto dado de baja no se puede vender', function () {
    $baja = Producto::factory()->inactivo()->create();

    // StockService dejó esta validación afuera del depósito a propósito: un producto
    // discontinuado sigue estando en el estante y el inventario tiene que poder
    // corregirlo. Que no se pueda vender es una regla de la línea de venta, y este
    // es el lugar donde vive.
    expect(fn () => $this->service->crear(datosDeVenta([lineaDeVenta($baja, 1)]), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('una cantidad menor a uno se rechaza', function () {
    $producto = productoConPrecios();

    expect(fn () => $this->service->crear(datosDeVenta([lineaDeVenta($producto, 0)]), admin()))
        ->toThrow(ReglaDeNegocioException::class)
        ->and(fn () => $this->service->crear(datosDeVenta([lineaDeVenta($producto, -2)]), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('el rechazo de una linea no deja la venta a medias', function () {
    $bueno = productoConPrecios();
    $baja  = Producto::factory()->inactivo()->create();

    expect(fn () => $this->service->crear(datosDeVenta([
        lineaDeVenta($bueno, 2),
        lineaDeVenta($baja, 1),
    ]), admin()))->toThrow(ReglaDeNegocioException::class);

    // La cabecera y la primera línea se escribieron antes de que la segunda fallara.
    // Si la transacción no envolviera todo, quedaría una venta con la mitad del
    // documento y un total que no corresponde a nada.
    expect(Venta::count())->toBe(0)
        ->and(App\Models\VentaLinea::count())->toBe(0);
});

test('cotiza con el precio de contado y nunca con el de lista', function () {
    $producto = productoConPrecios(contado: 1000, lista: 1200);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // En el mostrador el precio es uno. `precio_lista` tiene el recargo de tarjeta
    // adentro y es el precio del canal online, porque Checkout Pro recibe el monto
    // antes de saber con qué se paga; acá el recargo lo hace el posnet al cobrar y
    // el sistema no lo calcula. Si esta afirmación se cae, alguien cambió de precio
    // al mostrador sin decirlo.
    expect($venta->lineas->first()->precio_unitario)->toBe('1000.00')
        ->and($venta->total)->toBe('2000.00');
});

/*
|--------------------------------------------------------------------------
| El descuento y su tope — hallazgo A-12
|--------------------------------------------------------------------------
|
| En el original `descuentoPorcentaje` lo fijaba el cliente y se validaba sólo que
| estuviera entre 0 y 100: cualquier vendedor podía cargar el 100 %, y en el dump
| hay una venta al 50 %.
|
*/

test('el descuento se calcula sobre el subtotal y se resta del total', function () {
    $producto = productoConPrecios(contado: 1000);

    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 3)], ['descuento_porcentaje' => 10]),
        admin(),
    );

    // El porcentaje lo escribe el vendedor; lo que se guarda es el monto, que es lo
    // que la columna `ventas.descuento` es.
    expect($venta->subtotal)->toBe('3000.00')
        ->and($venta->descuento)->toBe('300.00')
        ->and($venta->total)->toBe('2700.00');
});

test('el descuento por encima del tope de quien lo carga se rechaza', function () {
    $producto = productoConPrecios();
    $vendedor = usuarioCon('venta.crear');

    expect($this->service->topeDeDescuento($vendedor))->toBe(10.0);

    expect(fn () => $this->service->crear(
        datosDeVenta([lineaDeVenta($producto)], ['descuento_porcentaje' => 15]),
        $vendedor,
    ))->toThrow(ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('con el permiso de autorizar descuento el tope es el otro', function () {
    $producto   = productoConPrecios(contado: 1000);
    $encargado  = usuarioCon('venta.crear', 'venta.autorizar_descuento');

    expect($this->service->topeDeDescuento($encargado))->toBe(30.0);

    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 1)], ['descuento_porcentaje' => 25]),
        $encargado,
    );

    expect($venta->descuento)->toBe('250.00')
        ->and($venta->total)->toBe('750.00');

    // Y el tope autorizado sigue siendo un techo: el 100 % no se alcanza por ningún
    // camino, que es la diferencia con el original.
    expect(fn () => $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 1)], ['descuento_porcentaje' => 100]),
        $encargado,
    ))->toThrow(ReglaDeNegocioException::class);
});

test('un descuento negativo se rechaza', function () {
    $producto = productoConPrecios();

    // Un porcentaje negativo sería un recargo, y no existe en este sistema: el
    // precio de tarjeta es `precio_lista`, no un recargo sobre el de contado.
    expect(fn () => $this->service->crear(
        datosDeVenta([lineaDeVenta($producto)], ['descuento_porcentaje' => -5]),
        admin(),
    ))->toThrow(ReglaDeNegocioException::class);
});

test('el tope sale de la configuracion y no del codigo', function () {
    config(['venta.descuento.tope_general' => 25]);

    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('venta.crear');

    expect($this->service->topeDeDescuento($vendedor))->toBe(25.0);

    // Es un parámetro del negocio: el dueño puede querer moverlo sin que nadie
    // recompile nada, igual que los permisos están en la base.
    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 1)], ['descuento_porcentaje' => 20]),
        $vendedor,
    );

    expect($venta->descuento)->toBe('200.00');
});

/*
|--------------------------------------------------------------------------
| Lo que cotizar NO hace
|--------------------------------------------------------------------------
*/

test('cotizar no mira el stock ni deja movimiento en el kardex', function () {
    $producto = Producto::factory()->sinStock()->create(['precio_contado' => 1000]);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 99)]), admin());

    // Un presupuesto no compromete nada: no mueve stock, no genera comprobante y
    // puede no convertirse nunca en venta. El stock se valida al cobrar, cuando
    // StockService::descontar() compara contra el disponible — y eso es la segunda
    // mitad de la fase. La pantalla va a avisar que no hay stock, porque avisar no
    // es impedir.
    expect($venta->total)->toBe('99000.00')
        ->and($producto->fresh()->stock)->toBe(0)
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and(MovimientoStock::count())->toBe(0);
});

test('la venta puede no identificar a nadie, y si identifica lo guarda', function () {
    $producto = productoConPrecios();
    $cliente  = Cliente::factory()->create();

    $anonima = $this->service->crear(datosDeVenta([lineaDeVenta($producto)]), admin());

    $identificada = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto)], ['cliente_id' => $cliente->id]),
        admin(),
    );

    // `cliente_id` nullable es la venta rápida de mostrador a consumidor final: AFIP
    // la admite por debajo de cierto monto, y el umbral y la obligación de
    // identificar son de la facturación, en la Etapa 2.
    expect($anonima->cliente_id)->toBeNull()
        ->and($identificada->cliente_id)->toBe($cliente->id);
});

/*
|--------------------------------------------------------------------------
| La edición del presupuesto — hallazgo M-14
|--------------------------------------------------------------------------
|
| `resolverPreciosYTotales()` releía el precio en cada `save` y en cada `update`, y
| `SaleDao::update()` borraba las líneas y las reinsertaba con el precio nuevo: un
| presupuesto emitido cambiaba de total si el producto subía antes de confirmarlo.
|
| El primer test de este bloque es el hallazgo. Si se pone en rojo, volvió.
|
*/

test('editar conserva el precio congelado de las lineas que ya estaban', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1800]);

    $venta = $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    expect($venta->lineas->first()->precio_unitario)->toBe('1000.00')
        ->and($venta->total)->toBe('2000.00');
});

test('cambiar la cantidad recalcula los importes con el precio viejo', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500, alicuota: 21);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1800]);

    $venta = $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 5)]), admin());
    $linea = $venta->lineas->first();

    // 5 × 1.000, con el precio de cuando se cotizó. Que el precio se conserve no
    // alcanza si los importes se recalculan contra otro número.
    expect($linea->cantidad)->toBe(5)
        ->and($linea->precio_unitario)->toBe('1000.00')
        ->and($linea->total)->toBe('5000.00')
        ->and($linea->neto)->toBe('4132.23')
        ->and($linea->iva)->toBe('867.77')
        ->and($venta->total)->toBe('5000.00');
});

test('agregar un producto lo cotiza al precio de hoy y no toca a los demas', function () {
    $viejo = productoConPrecios(contado: 1000, lista: 2500);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($viejo, 2)]), admin());

    $viejo->update(['precio_contado' => 1800]);
    $nuevo = productoConPrecios(contado: 500);

    $venta = $this->service->actualizar($venta, datosDeVenta([
        lineaDeVenta($viejo, 2),
        lineaDeVenta($nuevo, 3),
    ]), admin());

    $precios = $venta->lineas->pluck('precio_unitario', 'producto_id');

    // El que ya estaba conserva el precio de cuando se cotizó; el que entra se
    // cotiza hoy. Una línea nueva en un presupuesto viejo no tiene precio viejo que
    // conservar, y cotizarla con un precio inventado sería peor.
    expect($precios[$viejo->id])->toBe('1000.00')
        ->and($precios[$nuevo->id])->toBe('500.00')
        ->and($venta->total)->toBe('3500.00');
});

test('quitar un producto borra su linea y recalcula el total', function () {
    $uno  = productoConPrecios(contado: 1000);
    $otro = productoConPrecios(contado: 500);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($uno, 2),
        lineaDeVenta($otro, 2),
    ]), admin());

    expect($venta->total)->toBe('3000.00');

    $venta = $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($otro, 2)]), admin());

    expect($venta->lineas)->toHaveCount(1)
        ->and($venta->lineas->first()->producto_id)->toBe($otro->id)
        ->and($venta->total)->toBe('1000.00')
        ->and(App\Models\VentaLinea::count())->toBe(1);
});

test('editar el cliente, las observaciones y el descuento no toca las lineas', function () {
    $producto = productoConPrecios(contado: 1000);
    $cliente  = Cliente::factory()->create();

    $venta         = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());
    $lineaOriginal = $venta->lineas->first()->id;

    $venta = $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 3)], [
        'cliente_id'           => $cliente->id,
        'observaciones'        => 'Pasa a buscarlo el jueves.',
        'descuento_porcentaje' => 10,
    ]), admin());

    expect($venta->cliente_id)->toBe($cliente->id)
        ->and($venta->observaciones)->toBe('Pasa a buscarlo el jueves.')
        ->and($venta->descuento)->toBe('300.00')
        ->and($venta->total)->toBe('2700.00')
        // Es la MISMA fila: mismo id. Es la afirmación más directa de que no se
        // borró y se reinsertó, que es lo que hacía `SaleDao::update()`.
        ->and($venta->lineas->first()->id)->toBe($lineaOriginal)
        ->and($venta->lineas->first()->precio_unitario)->toBe('1000.00');
});

test('editar sin lineas se rechaza y no cambia nada', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    expect(fn () => $this->service->actualizar($venta, datosDeVenta([]), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh('lineas')->lineas)->toHaveCount(1)
        ->and($venta->fresh()->total)->toBe('2000.00');
});

test('editar con el mismo producto dos veces se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    expect(fn () => $this->service->actualizar($venta, datosDeVenta([
        lineaDeVenta($producto, 2),
        lineaDeVenta($producto, 3),
    ]), admin()))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh('lineas')->lineas)->toHaveCount(1);
});

test('editar con un descuento por encima del tope se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());
    $vendedor = usuarioCon('venta.editar');

    // El tope se verifica en la edición igual que en el alta: si sólo se validara al
    // crear, se cargaría la venta sin descuento y se le pondría el 20 % editándola.
    expect(fn () => $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 2)], [
        'descuento_porcentaje' => 20,
    ]), $vendedor))->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->descuento)->toBe('0.00');
});

test('un producto dado de baja no impide editar el resto del presupuesto', function () {
    $baja  = productoConPrecios(contado: 1000);
    $sigue = productoConPrecios(contado: 500);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($baja, 1),
        lineaDeVenta($sigue, 1),
    ]), admin());

    $baja->update(['activo' => false]);

    // Editar no cotiza lo que ya estaba, así que no hay nada que validar sobre un
    // producto que no se vuelve a mirar. Negar la edición entera por un renglón que
    // el usuario no tocó lo dejaría sin forma de arreglarlo. Que no se pueda cobrar
    // una venta con un producto de baja es una validación del pase a `pagada`, en la
    // segunda mitad de la fase.
    $venta = $this->service->actualizar($venta, datosDeVenta([
        lineaDeVenta($baja, 1),
        lineaDeVenta($sigue, 4),
    ]), admin());

    expect($venta->total)->toBe('3000.00');

    // Y quitarlo funciona, que es la salida que el usuario tiene.
    $venta = $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($sigue, 4)]), admin());

    expect($venta->lineas)->toHaveCount(1)
        ->and($venta->total)->toBe('2000.00');
});

test('si una linea nueva falla, la edicion entera se revierte', function () {
    $producto = productoConPrecios(contado: 1000);
    $baja     = Producto::factory()->inactivo()->create();

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    expect(fn () => $this->service->actualizar($venta, datosDeVenta([
        lineaDeVenta($producto, 7),
        lineaDeVenta($baja, 1),
    ], ['observaciones' => 'esto no tiene que quedar']), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    // La cabecera se escribió antes de que la línea nueva fallara. Sin la
    // transacción quedaría un presupuesto con una observación que nadie confirmó.
    $venta = $venta->fresh('lineas');

    expect($venta->observaciones)->toBeNull()
        ->and($venta->lineas)->toHaveCount(1)
        ->and($venta->lineas->first()->cantidad)->toBe(2)
        ->and($venta->total)->toBe('2000.00');
});

test('no se puede editar una venta que no es un presupuesto', function (string $estado) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->{$estado}()->conLinea($producto, 2)->create();

    // Lo que se cobró no se corrige editando: se devuelve, y la devolución deja su
    // rastro. Es la misma idea que «sólo un borrador se edita» en compras, pero acá
    // lo que hay del otro lado no es un monto comprometido sino plata cobrada.
    expect(fn () => $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 9)]), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh('lineas')->lineas->first()->cantidad)->toBe(2);
})->with(['pagada', 'entregada', 'cancelada']);

/*
|--------------------------------------------------------------------------
| La recotización — la otra mitad de M-14
|--------------------------------------------------------------------------
|
| Recotizar no está mal. Lo que estaba mal era recotizar sin que nadie lo pidiera ni
| se enterara. Por eso estos tests no verifican sólo que los precios se actualicen:
| verifican que el método diga qué cambió.
|
*/

test('recotizar relee los precios de todas las lineas e informa que cambio', function () {
    $sube  = productoConPrecios(contado: 1000, lista: 2500);
    $queda = productoConPrecios(contado: 500);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($sube, 2),
        lineaDeVenta($queda, 2),
    ]), admin());

    $sube->update(['precio_contado' => 1500]);

    $resultado = $this->service->recotizar($venta);

    expect($resultado['total_anterior'])->toBe('3000.00')
        ->and($resultado['venta']->total)->toBe('4000.00')
        // Informa sólo el que cambió: listar los diez renglones de una venta donde
        // cambió uno esconde el dato que importa.
        ->and($resultado['cambios'])->toHaveCount(1)
        ->and($resultado['cambios'][0]['descripcion'])->toBe($sube->nombre)
        ->and($resultado['cambios'][0]['precio_anterior'])->toBe('1000.00')
        ->and($resultado['cambios'][0]['precio_nuevo'])->toBe('1500.00');
});

test('recotizar conserva el porcentaje de descuento y no el monto', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500);

    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 2)], ['descuento_porcentaje' => 10]),
        admin(),
    );

    expect($venta->descuento)->toBe('200.00')
        ->and($venta->total)->toBe('1800.00');

    $producto->update(['precio_contado' => 2000]);

    $resultado = $this->service->recotizar($venta);

    // Un 10 % sigue siendo un 10 % sobre el subtotal nuevo. Conservar el monto
    // dejaría un descuento del 5 % que nadie decidió, y encima más chico que el
    // acordado con el cliente.
    expect($resultado['venta']->subtotal)->toBe('4000.00')
        ->and($resultado['venta']->descuento)->toBe('400.00')
        ->and($resultado['venta']->total)->toBe('3600.00')
        ->and($resultado['venta']->descuentoPorcentaje())->toBe(10.0);
});

test('recotizar sin cambios de precio no informa ninguno', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $resultado = $this->service->recotizar($venta);

    // La pantalla necesita poder decir «los precios no cambiaron» en lugar de fingir
    // que hizo algo. Un mensaje de éxito que no describe ningún efecto es ruido.
    expect($resultado['cambios'])->toBe([])
        ->and($resultado['total_anterior'])->toBe('2000.00')
        ->and($resultado['venta']->total)->toBe('2000.00');
});

test('recotizar actualiza tambien la alicuota, el costo y la descripcion', function () {
    $producto = productoConPrecios(contado: 1000, costo: 600, alicuota: 21);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    $producto->update(['nombre' => 'Teclado mecánico retroiluminado', 'alicuota_iva' => 10.5]);

    // costo_promedio está fuera de $fillable: sólo lo mueve StockService. Acá se
    // simula una recepción de mercadería más cara sin pasar por el kardex.
    $producto->costo_promedio = 900;
    $producto->save();

    $linea = $this->service->recotizar($venta)['venta']->lineas->first();

    // Recotizar es cotizar otra vez: toma la foto completa. Media foto dejaría el
    // precio de hoy con el costo del mes pasado, y el margen del panel saldría mal.
    expect($linea->descripcion)->toBe('Teclado mecánico retroiluminado')
        ->and($linea->alicuota_iva)->toBe('10.50')
        ->and($linea->costo_unitario)->toBe('900.00')
        ->and($linea->neto)->toBe('904.98')
        ->and($linea->iva)->toBe('95.02');
});

test('recotizar rechaza un producto dado de baja', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['activo' => false]);

    // Acá sí se exige que esté activo, al contrario que en la edición: recotizar es
    // cotizar, y no se cotiza lo que no se puede vender. La salida es quitarlo.
    expect(fn () => $this->service->recotizar($venta))
        ->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->total)->toBe('2000.00');
});

test('no se puede recotizar una venta que no es un presupuesto', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->pagada()->conLinea($producto, 2)->create();

    expect(fn () => $this->service->recotizar($venta))
        ->toThrow(ReglaDeNegocioException::class);
});

/*
|--------------------------------------------------------------------------
| El pase a `pagada` — el llamador que C-9 dejó anunciado
|--------------------------------------------------------------------------
|
| El cierre de C-9 decía que la segunda mitad de la fase «no agrega reglas acá:
| agrega el llamador de dos transiciones que ya están declaradas». Éste es el
| primero, y es además el primer y único código del sistema que llama a
| `StockService::descontar()`, que existía sin usar desde la Fase 5.
|
| Los tres primeros tests son el criterio de terminado de la fase, textual del
| plan de migración: que `presupuesto → pagada` descuente stock, que hacerlo dos
| veces no lo descuente dos veces, y que una transición no declarada se rechace.
|
| Se prueba el servicio directo y no a través de `cobrar()`: son dos reglas
| distintas y mezclarlas haría que un test del stock falle por un problema del
| saldo. El cruce de los dos —que un rechazo del stock revierta también el pago—
| se prueba en PagoServiceTest, que es donde el pago existe.
|
*/

test('el pase a pagada descuenta el stock y deja un movimiento por linea', function () {
    $uno  = productoConPrecios(contado: 1000);
    $otro = productoConPrecios(contado: 2500);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($uno, 3),
        lineaDeVenta($otro, 2),
    ]), admin());

    // `productoConPrecios()` deja 50 unidades, que es más que suficiente: en este
    // test el stock no es lo que se prueba, es la premisa.
    expect($venta->estado)->toBe('presupuesto')
        ->and(MovimientoStock::count())->toBe(0);

    $venta = $this->service->marcarPagada($venta, admin());

    expect($venta->estado)->toBe('pagada')
        ->and($uno->fresh()->stock)->toBe(47)
        ->and($otro->fresh()->stock)->toBe(48)
        // Un asiento por línea, no uno por venta: el kardex es por producto, y
        // «¿por qué este producto tiene 47?» es la pregunta que A-13 vino a poder
        // responder.
        ->and(MovimientoStock::count())->toBe(2);

    $movimientos = MovimientoStock::orderBy('producto_id')->get();

    expect($movimientos->pluck('tipo')->unique()->all())->toBe(['venta'])
        // La cantidad va con signo, y una salida es negativa. Es lo que permite
        // reconstruir el stock a una fecha sumando la columna.
        ->and($movimientos->pluck('cantidad')->sort()->values()->all())->toBe([-3, -2])
        // El saldo que quedó, escrito en el asiento: sin esto, reconstruir el stock
        // exige sumar todo el historial en lugar de leer la última fila.
        ->and($movimientos->firstWhere('producto_id', $uno->id)->stock_resultante)->toBe(47);
});

test('el pase descuenta exactamente una vez: el segundo intento se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    $this->service->marcarPagada($venta, admin());

    expect($producto->fresh()->stock)->toBe(47);

    // La idempotencia no es una bandera ni un chequeo extra: es que `pagada → pagada`
    // no esté declarado en la tabla de transiciones. En el original
    // `confirmada → confirmada` volvía a descontar porque la cadena de `if` no
    // comparaba el origen, y ése es el cuarto agujero de C-9.
    expect(fn () => $this->service->marcarPagada($venta->fresh(), admin()))
        ->toThrow(TransicionInvalidaException::class);

    expect($producto->fresh()->stock)->toBe(47)
        ->and(MovimientoStock::count())->toBe(1);
});

test('el pase pasa por la maquina de estados', function (string $estado) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->{$estado}()->conLinea($producto, 2)->create();

    $stockPrevio = $producto->fresh()->stock;

    expect(fn () => $this->service->marcarPagada($venta, admin()))
        ->toThrow(TransicionInvalidaException::class);

    // Y la transición se valida ANTES de tomar un solo lock de producto, así que no
    // queda ni un movimiento escrito y revertido.
    expect($venta->fresh()->estado)->toBe($estado)
        ->and($producto->fresh()->stock)->toBe($stockPrevio)
        ->and(MovimientoStock::count())->toBe(0);
})->with([
    // Los tres agujeros del original que apuntan a esta transición:
    // `cobrada → confirmada` revivía la venta, `anulada → confirmada` también, y
    // `confirmada → confirmada` descontaba de nuevo.
    'pagada',
    'entregada',
    'cancelada',
]);

test('un producto dado de baja no se puede cobrar, y no se mueve ninguna linea', function () {
    $vendible = productoConPrecios(contado: 1000);
    $deBaja   = productoConPrecios(contado: 2000);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($vendible, 2),
        lineaDeVenta($deBaja, 1),
    ]), admin());

    // Se desactiva DESPUÉS de emitido el presupuesto, que es el caso real: la
    // edición lo tolera a propósito —una referencia que cambió después no puede
    // volver inválido un registro que ya existe— y el pase a `pagada` es el lugar
    // correcto para rechazarlo, porque es el momento en que la venta se vuelve real.
    $deBaja->update(['activo' => false]);

    expect(fn () => $this->service->marcarPagada($venta, admin()))
        ->toThrow(ReglaDeNegocioException::class);

    // Lo importante es el primer producto: los productos de baja se revisan TODOS
    // antes de mover ninguno, así que el que sí era vendible tampoco se descontó.
    // Con una sola recorrida, qué error recibe el usuario dependería del orden de
    // las líneas.
    expect($vendible->fresh()->stock)->toBe(50)
        ->and($deBaja->fresh()->stock)->toBe(50)
        ->and(MovimientoStock::count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('el pase valida contra el disponible y no contra el stock', function () {
    // Cinco en depósito y cinco comprometidas por checkouts en curso: el disponible
    // es cero. Una venta de mostrador que mirara sólo `stock` le sacaría la unidad a
    // alguien que ya pagó online. Es además la misma definición que usan
    // `Producto::scopeConStock()` y la reposición automática: si acá fuera otra, el
    // sistema diría dos cosas distintas del mismo producto.
    $producto = Producto::factory()->conStock(5, 5)->create();
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    expect(fn () => $this->service->marcarPagada($venta, admin()))
        ->toThrow(StockInsuficienteException::class);

    expect($producto->fresh()->stock)->toBe(5)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('si una linea no tiene disponible, la anterior tampoco queda descontada', function () {
    $alcanza   = Producto::factory()->conStock(10)->create();
    $noAlcanza = Producto::factory()->conStock(1)->create();

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($alcanza, 2),
        lineaDeVenta($noAlcanza, 5),
    ]), admin());

    expect(fn () => $this->service->marcarPagada($venta, admin()))
        ->toThrow(StockInsuficienteException::class);

    // Esto es lo que prueba que el savepoint funciona. `StockService` abre su propia
    // transacción por cada llamada, y en Laravel una transacción anidada es un
    // savepoint: sin eso, el primer producto quedaría descontado y el kardex tendría
    // el asiento de una venta que nunca se cobró.
    expect($alcanza->fresh()->stock)->toBe(10)
        ->and($noAlcanza->fresh()->stock)->toBe(1)
        ->and(MovimientoStock::count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('una venta sin lineas no puede pasar a pagada', function () {
    // No se alcanza por el camino normal: `crear()` rechaza una venta sin líneas. Se
    // prueba igual porque el método no puede depender de eso — si descontara cero
    // productos y escribiera el estado, la venta quedaría «pagada» sin que nada
    // hubiera salido del depósito, que es el peor estado posible de todos.
    $venta = Venta::factory()->create();

    expect(fn () => $this->service->marcarPagada($venta, admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($venta->fresh()->estado)->toBe('presupuesto');
});

test('el movimiento nombra la venta y registra al cajero, no al vendedor', function () {
    $vendedor = admin();
    $cajero   = usuarioCon('venta.cobrar');
    $producto = productoConPrecios(contado: 1000);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), $vendedor);
    $this->service->marcarPagada($venta, $cajero);

    $movimiento = MovimientoStock::where('origen_id', $venta->id)->firstOrFail();

    expect($movimiento->origen_type)->toBe(Venta::class)
        // El kardex nombra el documento sin cargarlo: `Venta::numeroDe()` es estático
        // justamente para esto, y por eso la ficha y el kardex no se pueden
        // desincronizar (A-18).
        ->and($movimiento->origenTexto())->toBe('Venta '.$venta->numeroFormateado())
        // Quién cobró y quién vendió son dos preguntas distintas en dos columnas de
        // dos tablas. La mercadería salió por el cobro, así que el kardex lleva al
        // cajero; el vendedor sigue intacto en la venta. Es lo que le permite al
        // panel de la Fase 7 rankear las dos cosas sin confundirlas (M-19).
        ->and($movimiento->usuario_id)->toBe($cajero->id)
        ->and($venta->fresh()->usuario_id)->toBe($vendedor->id)
        ->and($cajero->id)->not->toBe($vendedor->id);
});

test('el pase bloquea la venta antes de tocar un solo producto', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->service->marcarPagada($venta, admin());

    $consultas = collect(DB::getQueryLog())->pluck('query')->map('strtolower');

    $lockDeLaVenta    = $consultas->search(fn (string $q) => str_contains($q, 'from `ventas`') && str_contains($q, 'for update'));
    $primerLockDeProd = $consultas->search(fn (string $q) => str_contains($q, 'from `productos`') && str_contains($q, 'for update'));

    // Las dos cosas en un solo test porque son la misma afirmación: la venta se
    // bloquea, y se bloquea PRIMERO. Si la transición no se validara antes de
    // empezar a descontar, una venta en un estado equivocado haría tomar N locks de
    // productos y escribir N asientos de kardex para que la transacción los
    // revierta al final.
    expect($lockDeLaVenta)->not->toBeFalse()
        ->and($primerLockDeProd)->not->toBeFalse()
        ->and($lockDeLaVenta)->toBeLessThan($primerLockDeProd);

    DB::disableQueryLog();
});

test('los productos se bloquean en orden de id, para que dos cobros no se cruce', function () {
    $menor = productoConPrecios(contado: 1000);
    $mayor = productoConPrecios(contado: 2000);

    // La venta los carga en el orden INVERSO al de sus ids, que es lo que hace que
    // el test diga algo: si el servicio recorriera las líneas en el orden en que
    // están cargadas, los locks saldrían al revés.
    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($mayor, 1),
        lineaDeVenta($menor, 1),
    ]), admin());

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->service->marcarPagada($venta, admin());

    $ordenDeLosLocks = collect(DB::getQueryLog())
        ->filter(fn (array $c) => str_contains(strtolower($c['query']), 'from `productos`')
            && str_contains(strtolower($c['query']), 'for update'))
        ->map(fn (array $c) => (int) $c['bindings'][0])
        ->values()
        ->all();

    // El orden no es cosmético: dos cobros simultáneos de dos ventas que comparten
    // dos productos, recorridos en órdenes distintos, se bloquean en cruz y MariaDB
    // mata una de las dos transacciones con un error de interbloqueo — que no es una
    // regla de negocio y le llegaría al usuario como un error del servidor. Tomando
    // los locks siempre en el mismo orden, el cruce no puede formarse.
    //
    // Este test está para que nadie saque el `orderBy('producto_id')` creyendo que
    // ordenar las líneas es una preferencia de presentación.
    expect($menor->id)->toBeLessThan($mayor->id)   // la premisa
        ->and($ordenDeLosLocks)->toBe([$menor->id, $mayor->id]);

    DB::disableQueryLog();
});


/*
|--------------------------------------------------------------------------
| Concurrencia y derivados
|--------------------------------------------------------------------------
*/

test('editar y recotizar bloquean la fila de la venta', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Sin el lock, dos pestañas sobre el mismo presupuesto pasan las dos la
    // comprobación de «es un presupuesto» y escriben las dos. No es el escenario de
    // C-10 —acá no hay saldo que exceder— pero es el mismo mecanismo, y leer el
    // estado de una fila para decidir si se la modifica exige bloquearla.
    DB::enableQueryLog();
    $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    expect(strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | ')))
        ->toContain('for update');

    DB::flushQueryLog();
    $this->service->recotizar($venta);

    expect(strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | ')))
        ->toContain('for update');

    DB::disableQueryLog();
});

test('descuentoPorcentaje recupera el porcentaje que se cargo', function () {
    $producto = productoConPrecios(contado: 1000);

    $diez  = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)], ['descuento_porcentaje' => 10]), admin());
    $siete = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)], ['descuento_porcentaje' => 7.5]), admin());
    $cero  = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    // Se guarda el monto, que es lo que el documento declara, y el porcentaje se
    // deriva. El formulario de edición necesita el porcentaje, y `recotizar()`
    // también. La recuperación es exacta a dos decimales.
    expect($diez->descuentoPorcentaje())->toBe(10.0)
        ->and($siete->descuentoPorcentaje())->toBe(7.5)
        ->and($cero->descuentoPorcentaje())->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| La cancelación
|--------------------------------------------------------------------------
|
| Lo que estos tests afirman tanto como lo que hace es lo que NO hace: no mueve
| stock y no borra nada, porque `cancelada` sólo se alcanza antes de que exista
| comprobante y antes de que haya entrado un peso. Una venta cobrada no se
| cancela: se devuelve, y ése es el camino que revierte los pagos (A-11).
|
| Es la razón por la que cancelar exige `venta.editar` y no `venta.anular`: no
| mueve stock ni plata, así que es parte de operar un presupuesto y el vendedor
| tiene que poder dar de baja lo que él mismo cargó. `venta.anular` queda para la
| devolución.
|
*/

test('cancelar un presupuesto lo deja cancelado y no toca nada mas', function () {
    $producto = productoConPrecios(contado: 1000);
    $producto->stock = 10;
    $producto->save();

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    $venta = $this->service->cancelar($venta);

    expect($venta->estado)->toBe('cancelada')
        // La venta no se borra: sigue teniendo su número, su total y sus líneas.
        // Es M-16 desde el otro lado — `SaleDao::delete()` era un DELETE plano
        // sobre una tabla con ON DELETE CASCADE.
        ->and(Venta::count())->toBe(1)
        ->and($venta->total)->toBe('3000.00')
        ->and($venta->fresh('lineas')->lineas)->toHaveCount(1)
        // Un presupuesto nunca descontó stock, así que cancelarlo no tiene nada que
        // reponer. Si acá apareciera un movimiento, algo está reponiendo lo que
        // nunca salió.
        ->and($producto->fresh()->stock)->toBe(10)
        ->and(MovimientoStock::count())->toBe(0);
});

test('cancelar pasa por la maquina de estados', function (string $estado) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->{$estado}()->conLinea($producto, 2)->create();

    // `cancelada` sólo se alcanza desde `presupuesto` y `pendiente_pago`: antes de
    // que exista comprobante y antes de que haya entrado un peso. El original
    // permitía `cobrada → anulada` y dejaba los pagos intactos (A-11).
    expect(fn () => $this->service->cancelar($venta))
        ->toThrow(TransicionInvalidaException::class);

    expect($venta->fresh()->estado)->toBe($estado);
})->with([
    'pagada',
    'entregada',
    // Cancelar dos veces tampoco: `cancelada` no tiene salida.
    'cancelada',
]);

test('cancelar bloquea la fila de la venta', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    DB::enableQueryLog();
    $this->service->cancelar($venta);

    // Leer el estado de una fila para decidir si se la modifica exige bloquearla:
    // sin el lock, dos pestañas leen «presupuesto» las dos y escriben las dos.
    expect(strtolower(collect(DB::getQueryLog())->pluck('query')->implode(' | ')))
        ->toContain('for update');

    DB::disableQueryLog();
});

test('el estado destino no es una puerta publica del servicio', function () {
    // La otra mitad de C-9. El original tenía `updateEstado($id, $nuevoEstado)` con
    // el destino viniendo del body: con una tabla de transiciones delante seguiría
    // siendo el controlador el que elige a dónde va la venta. Acá el destino lo
    // escribe el método que lo decide, y `cambiarEstado()` es privado.
    $metodo = new ReflectionMethod(App\Services\VentaService::class, 'cambiarEstado');

    expect($metodo->isPrivate())->toBeTrue();
});

test('el estado no se puede mover con un update masivo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    // Tercera barrera, además de la tabla de transiciones y del método privado:
    // `estado` está fuera de $fillable. Las tres apuntan al mismo hallazgo desde
    // lugares distintos, y ninguna sobra: la primera dice qué transición vale, la
    // segunda quién la pide, y esta que no se puede saltear a las dos.
    $venta->update(['estado' => 'pagada']);

    expect($venta->fresh()->estado)->toBe('presupuesto');
});

/*
|--------------------------------------------------------------------------
| La entrega
|--------------------------------------------------------------------------
|
| La transición más chica del sistema: sin efectos. Existe porque sin ella
| `entregada` sería un estado declarado en el ENUM, ofrecido como filtro en el
| listado y consultado por el panel de la Fase 7, que nada podría alcanzar.
|
*/

test('entregar deja la venta entregada y no mueve stock ni plata', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);

    $stockPrevio   = $producto->fresh()->stock;
    $pagadoPrevio  = $venta->pagado();
    $asientosPrevios = MovimientoStock::count();

    $venta = $this->service->entregar($venta);

    // Lo que este test afirma tanto como el estado es lo que NO pasó: el stock ya
    // salió del depósito al cobrar, así que entregar no lo vuelve a tocar.
    expect($venta->estado)->toBe('entregada')
        ->and($producto->fresh()->stock)->toBe($stockPrevio)
        ->and($venta->pagado())->toBe($pagadoPrevio)
        ->and(MovimientoStock::count())->toBe($asientosPrevios)
        ->and(Pago::count())->toBe(1);
});

test('entregar pasa por la maquina de estados', function () {
    $producto = productoConPrecios(contado: 1000);

    $presupuesto = Venta::factory()->conLinea($producto, 1)->create();
    $cancelada   = Venta::factory()->cancelada()->conLinea($producto, 1)->create();
    $entregada   = Venta::factory()->entregada()->conLinea($producto, 1)->create();

    // Para entregar algo hay que haberlo cobrado, y cobrar es lo que descuenta el
    // stock: `presupuesto → entregada` es el agujero más grave de C-9 visto desde
    // este lado. Y entregar dos veces tampoco, porque `entregada → entregada` no
    // está declarado.
    foreach ([$presupuesto, $cancelada, $entregada] as $venta) {
        expect(fn () => $this->service->entregar($venta))
            ->toThrow(TransicionInvalidaException::class);
    }

    expect($presupuesto->fresh()->estado)->toBe('presupuesto')
        ->and($cancelada->fresh()->estado)->toBe('cancelada')
        ->and($entregada->fresh()->estado)->toBe('entregada');
});

/*
|--------------------------------------------------------------------------
| La devolución — hallazgo A-11
|--------------------------------------------------------------------------
|
| El original permitía `confirmada/cobrada → anulada`: reponía el stock y dejaba
| las filas de `pagos` intactas, así que quedaba plata cobrada sobre una venta
| inexistente. Acá esa transición no existe, y el único camino que deshace una
| venta cobrada pasa por donde se devuelve el dinero.
|
| La reversión es un contra-asiento —una fila de pago con monto negativo— y no una
| edición ni un borrado de la fila original. `SUM(monto)` sigue respondiendo cuánta
| plata quedó de la venta, sin filtrar nada.
|
*/

test('la devolucion total repone el stock, revierte los pagos y cierra en cero', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 4);
    $linea    = $venta->lineas()->firstOrFail();

    // Quien devuelve no es quien cobró: el contra-asiento y el asiento de kardex
    // tienen que registrar a quien hizo la devolución.
    $quienDevuelve = usuarioCon('venta.anular');

    expect($producto->fresh()->stock)->toBe(46)
        ->and($venta->pagado())->toBe(4000.0);

    $venta = $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 4],
        'metodo'     => 'efectivo',
    ], $quienDevuelve);

    expect($venta->estado)->toBe('devuelta')
        ->and($producto->fresh()->stock)->toBe(50)
        // El libro cierra en cero: es la condición de que A-11 esté cerrado.
        ->and($venta->pagado())->toBe(0.0)
        ->and($venta->saldo())->toBe(4000.0)
        // Dos filas, no una editada: el cobro y su contra-asiento, los dos hechos a
        // la vista.
        ->and($venta->pagos()->count())->toBe(2)
        ->and($venta->pagos()->orderBy('id')->pluck('monto')->all())->toBe(['4000.00', '-4000.00'])
        ->and($linea->fresh()->cantidad_devuelta)->toBe(4)
        ->and($linea->fresh()->estaDevueltaPorCompleto())->toBeTrue();

    $reversion = $venta->pagos()->orderByDesc('id')->firstOrFail();
    $reingreso = $venta->movimientos()->where('tipo', 'devolucion')->firstOrFail();

    expect($reversion->usuario_id)->toBe($quienDevuelve->id)
        ->and($reingreso->usuario_id)->toBe($quienDevuelve->id)
        ->and($quienDevuelve->id)->not->toBe($venta->usuario_id);

    // Y una venta ya devuelta por completo no tiene nada más que volver: `devuelta`
    // es una lista vacía en la tabla de transiciones.
    expect(fn () => $this->service->devolver($venta->fresh(), [
        'cantidades' => [$linea->id => 1],
        'metodo'     => 'efectivo',
    ], $quienDevuelve))->toThrow(ReglaDeNegocioException::class);
});

test('la devolucion no toca los importes de la venta', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5);
    $linea    = $venta->lineas()->firstOrFail();

    $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 2],
        'metodo'     => 'efectivo',
    ], admin());

    // El documento sigue diciendo lo que se vendió. Lo devuelto se lee en
    // `cantidad_devuelta` y en los contra-asientos, y es lo que hace que el margen
    // del panel se calcule con `cantidad - cantidad_devuelta` sin consultar nada más.
    expect($venta->fresh()->subtotal)->toBe('5000.00')
        ->and($venta->fresh()->descuento)->toBe('0.00')
        ->and($venta->fresh()->total)->toBe('5000.00')
        ->and($venta->fresh()->lineas()->first()->total)->toBe('5000.00');
});

test('la devolucion parcial deriva el estado y devuelve la parte proporcional', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5);
    $linea    = $venta->lineas()->firstOrFail();

    $venta = $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 2],
        // El medio lo elige quien devuelve y no se copia del cobro: la venta se
        // cobró en efectivo y la plata vuelve por QR. Escribir un negativo en
        // efectivo afirmaría que salió de la caja, y no salió.
        'metodo'     => 'qr',
    ], admin());

    expect($venta->estado)->toBe('devuelta_parcial')
        ->and($linea->fresh()->cantidad_devuelta)->toBe(2)
        ->and($linea->fresh()->cantidadPendienteDeDevolver())->toBe(3)
        ->and($venta->pagado())->toBe(3000.0)
        ->and($producto->fresh()->stock)->toBe(47)
        ->and($venta->pagos()->orderByDesc('id')->first()->metodo)->toBe('qr')
        ->and($venta->pagos()->orderByDesc('id')->first()->monto)->toBe('-2000.00');
});

test('una segunda devolucion parcial completa la venta y cierra en cero', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5);
    $linea    = $venta->lineas()->firstOrFail();

    $this->service->devolver($venta, ['cantidades' => [$linea->id => 2], 'metodo' => 'efectivo'], admin());

    expect($venta->fresh()->estado)->toBe('devuelta_parcial');

    // Es el caso de quien devuelve el mouse en enero y la fuente en marzo:
    // `devuelta_parcial → devuelta_parcial` está declarado a propósito.
    $venta = $this->service->devolver($venta->fresh(), [
        'cantidades' => [$linea->id => 3],
        'metodo'     => 'efectivo',
    ], admin());

    // La última devolución devuelve lo que quedó cobrado y no la cuenta
    // proporcional, para que el libro cierre en cero exacto y no en un centavo por
    // el redondeo de cada parte.
    expect($venta->estado)->toBe('devuelta')
        ->and($venta->pagado())->toBe(0.0)
        ->and($venta->pagos()->count())->toBe(3)
        ->and($producto->fresh()->stock)->toBe(50)
        ->and($linea->fresh()->cantidad_devuelta)->toBe(5);
});

test('el descuento vuelve en su parte proporcional', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5, 10);
    $linea    = $venta->lineas()->firstOrFail();

    expect($venta->subtotal)->toBe('5000.00')
        ->and($venta->descuento)->toBe('500.00')
        ->and($venta->total)->toBe('4500.00')
        ->and($venta->pagado())->toBe(4500.0);

    $venta = $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 2],
        'metodo'     => 'efectivo',
    ], admin());

    // Dos unidades son 2000 de subtotal, menos su 10 % = 1800. Si volvieran 2000,
    // devolver de a poco saldría más caro que devolver todo junto: se reintegraría
    // el precio sin descuento de cada unidad sobre una venta cobrada con descuento.
    expect($venta->pagos()->orderByDesc('id')->first()->monto)->toBe('-1800.00')
        ->and($venta->pagado())->toBe(2700.0);

    // Y el resto cierra en cero, que es la comprobación de que las dos reglas
    // —proporcional y última— encajan.
    $venta = $this->service->devolver($venta->fresh(), [
        'cantidades' => [$linea->id => 3],
        'metodo'     => 'efectivo',
    ], admin());

    expect($venta->estado)->toBe('devuelta')
        ->and($venta->pagado())->toBe(0.0);
});

test('no se puede devolver mas de lo vendido, ni de una vez ni en dos tandas', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 3);
    $linea    = $venta->lineas()->firstOrFail();

    // De una vez.
    expect(fn () => $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 4],
        'metodo'     => 'efectivo',
    ], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($linea->fresh()->cantidad_devuelta)->toBe(0)
        ->and($producto->fresh()->stock)->toBe(47);

    // Y en dos tandas: es para esto que `cantidad_devuelta` es un contador y no una
    // bandera. Sin él, alguien devuelve dos unidades tres veces y se lleva seis.
    $this->service->devolver($venta->fresh(), ['cantidades' => [$linea->id => 2], 'metodo' => 'efectivo'], admin());

    expect(fn () => $this->service->devolver($venta->fresh(), [
        'cantidades' => [$linea->id => 2],
        'metodo'     => 'efectivo',
    ], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($linea->fresh()->cantidad_devuelta)->toBe(2)
        ->and($producto->fresh()->stock)->toBe(49);
});

test('solo se devuelve lo que se cobro', function () {
    $producto = productoConPrecios(contado: 1000);

    $presupuesto = Venta::factory()->conLinea($producto, 2)->create();
    $cancelada   = Venta::factory()->cancelada()->conLinea($producto, 2)->create();

    // Un presupuesto se cancela, no se devuelve: no movió stock ni plata, así que no
    // hay nada que revertir. `puedeDevolver()` le pregunta a la tabla de
    // transiciones en lugar de comparar contra estados escritos a mano.
    foreach ([$presupuesto, $cancelada] as $venta) {
        expect(fn () => $this->service->devolver($venta, [
            'cantidades' => [$venta->lineas()->first()->id => 1],
            'metodo'     => 'efectivo',
        ], admin()))->toThrow(ReglaDeNegocioException::class);
    }

    expect(MovimientoStock::count())->toBe(0)
        ->and(Pago::count())->toBe(0)
        ->and($producto->fresh()->stock)->toBe(50);
});

test('el medio por el que vuelve la plata tiene que estar operativo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);
    $linea    = $venta->lineas()->firstOrFail();

    // `mercadopago` está declarado en el ENUM y no se ofrece: sin integración, una
    // devolución registrada por ese medio afirmaría un reembolso que el sistema no
    // disparó. Mismo criterio que en el cobro.
    foreach (['mercadopago', 'inventado', ''] as $metodo) {
        expect(fn () => $this->service->devolver($venta, [
            'cantidades' => [$linea->id => 1],
            'metodo'     => $metodo,
        ], admin()))->toThrow(ReglaDeNegocioException::class);
    }

    expect($linea->fresh()->cantidad_devuelta)->toBe(0)
        ->and($venta->fresh()->pagos()->count())->toBe(1);
});

test('una linea de otra venta no se puede devolver', function () {
    $producto = productoConPrecios(contado: 1000);

    $propia = ventaCobrada($producto, 2);
    $ajena  = ventaCobrada($producto, 3);

    // Laravel no verifica que dos parámetros de la petición estén relacionados: con
    // un id de línea suelto se podría devolver contra la venta de otro cliente. Las
    // líneas se resuelven a través de la relación del padre, igual que en la
    // recepción de mercadería.
    expect(fn () => $this->service->devolver($propia, [
        'cantidades' => [$ajena->lineas()->first()->id => 1],
        'metodo'     => 'efectivo',
    ], admin()))->toThrow(ReglaDeNegocioException::class);

    expect($ajena->fresh()->lineas()->first()->cantidad_devuelta)->toBe(0)
        ->and($ajena->fresh()->estado)->toBe('pagada')
        ->and($propia->fresh()->estado)->toBe('pagada');
});

test('una devolucion sin unidades se rechaza', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);
    $linea    = $venta->lineas()->firstOrFail();

    // El formulario muestra todas las líneas, así que las que no vuelven viajan en
    // cero: eso no es un error y el servicio las descarta. Lo que no puede pasar es
    // que no vuelva nada en ninguna.
    foreach ([[], [$linea->id => 0]] as $cantidades) {
        expect(fn () => $this->service->devolver($venta, [
            'cantidades' => $cantidades,
            'metodo'     => 'efectivo',
        ], admin()))->toThrow(ReglaDeNegocioException::class);
    }

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->pagos()->count())->toBe(1);
});

test('el motivo llega a cada movimiento del kardex y es opcional', function () {
    $uno  = productoConPrecios(contado: 1000);
    $otro = productoConPrecios(contado: 2000);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($uno, 2),
        lineaDeVenta($otro, 1),
    ]), admin());

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $lineaUno  = $venta->lineas()->where('producto_id', $uno->id)->firstOrFail();
    $lineaOtro = $venta->lineas()->where('producto_id', $otro->id)->firstOrFail();

    $this->service->devolver($venta->fresh(), [
        'cantidades' => [$lineaUno->id => 1, $lineaOtro->id => 1],
        'metodo'     => 'efectivo',
        'motivo'     => 'Vino fallado',
    ], admin());

    // El motivo es lo único de la devolución que el sistema no puede reconstruir
    // después: el «por qué cambió el stock» ya lo responde el `origen`.
    expect(MovimientoStock::where('tipo', 'devolucion')->pluck('motivo')->unique()->all())
        ->toBe(['Vino fallado']);

    // Y es opcional: sin motivo se guarda null y no cadena vacía, para que «sin
    // motivo» tenga una sola representación en la base.
    $this->service->devolver($venta->fresh(), [
        'cantidades' => [$lineaUno->id => 1],
        'metodo'     => 'efectivo',
        'motivo'     => '   ',
    ], admin());

    expect(MovimientoStock::where('tipo', 'devolucion')->orderByDesc('id')->first()->motivo)
        ->toBeNull();
});

test('el kardex queda con dos asientos del mismo origen, uno por sentido', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 3);
    $linea    = $venta->lineas()->firstOrFail();

    $this->service->devolver($venta, ['cantidades' => [$linea->id => 3], 'metodo' => 'efectivo'], admin());

    $asientos = $venta->fresh()->movimientos()->orderBy('id')->get();

    // Dos filas con el mismo `origen_type`/`origen_id`, distinguidas por el tipo y
    // por el signo. Las dos nombran el mismo documento, y eso es correcto: la
    // historia completa de esa venta en el kardex son los dos asientos juntos.
    expect($asientos)->toHaveCount(2)
        ->and($asientos->pluck('tipo')->all())->toBe(['venta', 'devolucion'])
        ->and($asientos->pluck('cantidad')->all())->toBe([-3, 3])
        ->and($asientos->pluck('stock_resultante')->all())->toBe([47, 50])
        ->and($asientos->pluck('origen_id')->unique()->all())->toBe([$venta->id])
        ->and($asientos->map(fn ($a) => $a->origenTexto())->unique()->all())
            ->toBe(['Venta '.$venta->numeroFormateado()]);
});

test('la devolucion repone un producto dado de baja', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);
    $linea    = $venta->lineas()->firstOrFail();

    $producto->update(['activo' => false]);

    // `StockService` no mira `activo` a propósito, y acá es donde esa decisión paga:
    // una unidad que el cliente trae de vuelta entró físicamente al depósito, esté
    // el producto discontinuado o no. Rechazarla sería negarle al kardex la
    // capacidad de registrar lo que pasó. Es el contrapunto del pase a `pagada`, que
    // sí lo rechaza: ahí la mercadería sale, acá entra.
    $venta = $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 2],
        'metodo'     => 'efectivo',
    ], admin());

    expect($venta->estado)->toBe('devuelta')
        ->and($producto->fresh()->stock)->toBe(50)
        ->and($producto->fresh()->activo)->toBeFalse();
});

test('si una linea falla, la devolucion entera se revierte', function () {
    $uno  = productoConPrecios(contado: 1000);
    $otro = productoConPrecios(contado: 2000);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($uno, 2),
        lineaDeVenta($otro, 1),
    ]), admin());

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $lineaUno  = $venta->lineas()->where('producto_id', $uno->id)->firstOrFail();
    $lineaOtro = $venta->lineas()->where('producto_id', $otro->id)->firstOrFail();

    // Del segundo producto se vendió 1 y se piden 5. Las líneas se recorren en orden
    // de producto, así que el primero ya se repuso cuando salta el error.
    expect(fn () => $this->service->devolver($venta->fresh(), [
        'cantidades' => [$lineaUno->id => 2, $lineaOtro->id => 5],
        'metodo'     => 'efectivo',
    ], admin()))->toThrow(ReglaDeNegocioException::class);

    // Esto es lo que prueba que el savepoint envuelve las tres cosas: el stock del
    // primero no quedó repuesto, el contador no se movió, no hay contra-asiento y la
    // venta sigue pagada.
    expect($uno->fresh()->stock)->toBe(48)
        ->and($otro->fresh()->stock)->toBe(49)
        ->and($lineaUno->fresh()->cantidad_devuelta)->toBe(0)
        ->and($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->pagos()->count())->toBe(1)
        ->and(MovimientoStock::where('tipo', 'devolucion')->count())->toBe(0);
});

test('los productos se reponen en orden de id, igual que al descontar', function () {
    $menor = productoConPrecios(contado: 1000);
    $mayor = productoConPrecios(contado: 2000);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($mayor, 1),
        lineaDeVenta($menor, 1),
    ]), admin());

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $lineaMenor = $venta->lineas()->where('producto_id', $menor->id)->firstOrFail();
    $lineaMayor = $venta->lineas()->where('producto_id', $mayor->id)->firstOrFail();

    DB::enableQueryLog();
    DB::flushQueryLog();

    // Las cantidades llegan con el de id mayor primero, que es el orden del
    // formulario: si el servicio recorriera el arreglo tal como viene, los locks
    // saldrían al revés y dos devoluciones que comparten productos se podrían
    // bloquear en cruz.
    $this->service->devolver($venta->fresh(), [
        'cantidades' => [$lineaMayor->id => 1, $lineaMenor->id => 1],
        'metodo'     => 'efectivo',
    ], admin());

    $orden = collect(DB::getQueryLog())
        ->filter(fn (array $c) => str_contains(strtolower($c['query']), 'from `productos`')
            && str_contains(strtolower($c['query']), 'for update'))
        ->map(fn (array $c) => (int) $c['bindings'][0])
        ->values()
        ->all();

    expect($menor->id)->toBeLessThan($mayor->id)
        ->and($orden)->toBe([$menor->id, $mayor->id]);

    DB::disableQueryLog();
});

test('una venta sin pagos registrados se devuelve sin escribir contra-asiento', function () {
    $producto = productoConPrecios(contado: 1000);

    // Una venta `pagada` sin pagos la produce sólo una factory, y está escrito en su
    // docblock. Se prueba igual porque el servicio no puede depender de que no
    // ocurra: un contra-asiento de monto cero sería un asiento que no afirma nada, y
    // devolver un monto negativo sobre una venta que nunca se cobró dejaría lo
    // pagado en negativo.
    $venta = Venta::factory()->pagada()->conLinea($producto, 2)->create();
    $linea = $venta->lineas()->firstOrFail();

    expect($venta->pagado())->toBe(0.0);

    $venta = $this->service->devolver($venta, [
        'cantidades' => [$linea->id => 2],
        'metodo'     => 'efectivo',
    ], admin());

    expect($venta->estado)->toBe('devuelta')
        ->and(Pago::count())->toBe(0)
        ->and($venta->pagado())->toBe(0.0)
        // El stock sube por encima de 50 porque la factory nunca descontó: el kardex
        // registra lo que pasó, y lo que pasó es que entraron dos unidades.
        ->and($producto->fresh()->stock)->toBe(52)
        ->and($venta->movimientos()->count())->toBe(1);
});