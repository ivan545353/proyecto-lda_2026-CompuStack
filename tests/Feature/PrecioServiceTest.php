<?php

use App\Models\Categoria;
use App\Models\HistorialPrecio;
use App\Models\Impuesto;
use App\Models\Producto;
use App\Models\User;
use App\Services\PrecioService;
use App\Models\LoteAjuste;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);



// --- La cascada -----------------------------------------------------------

test('la cascada toma la ganancia del producto cuando la define', function () {
    $categoria = Categoria::factory()->create(['ganancia' => '3.000']);
    $producto  = productoConCosto(1000, ['categoria_id' => $categoria->id, 'ganancia' => '4.000']);

    expect(servicioConPolitica()->gananciaDe($producto))->toBe('4.000');
});

test('sin ganancia propia toma la de su categoria', function () {
    $categoria = Categoria::factory()->create(['ganancia' => '3.000']);
    $producto  = productoConCosto(1000, ['categoria_id' => $categoria->id]);

    expect(servicioConPolitica()->gananciaDe($producto))->toBe('3.000');
});

test('si la categoria no la define, sube por el arbol', function () {
    // Poner la ganancia en «Componentes» tiene que alcanzar a los SSD: si no,
    // habría que cargarla hoja por hoja, que es el trabajo que la fase saca.
    $raiz  = Categoria::factory()->create(['ganancia' => '2.000']);
    $media = Categoria::factory()->hijaDe($raiz)->create(['ganancia' => null]);
    $hoja  = Categoria::factory()->hijaDe($media)->create(['ganancia' => null]);

    $producto = productoConCosto(1000, ['categoria_id' => $hoja->id]);

    expect(servicioConPolitica()->gananciaDe($producto))->toBe('2.000');
});

test('si nadie la define, usa la ganancia global', function () {
    $producto = productoConCosto(1000);

    expect(servicioConPolitica(['ganancia_global' => '1.800'])->gananciaDe($producto))->toBe('1.800');
});

// --- El recálculo ---------------------------------------------------------

test('el criterio del tramo, de punta a punta', function () {
    $producto = productoConCosto(20000);

    $resultado = servicioConPolitica()->recalcular($producto);

    expect($resultado)->toBe(PrecioService::APLICADO)
        ->and($producto->fresh()->precio_contado)->toBe('60500.00');
});

test('un producto con precio manual no cambia al recalcular', function () {
    // El tercer criterio de terminado del tramo.
    $producto = productoConCosto(20000, ['precio_manual' => true, 'precio_contado' => '999.00']);

    expect(servicioConPolitica()->recalcular($producto))->toBe(PrecioService::MANUAL)
        ->and($producto->fresh()->precio_contado)->toBe('999.00')
        ->and(HistorialPrecio::count())->toBe(0);
});

test('un producto sin costo no se recalcula', function () {
    // Calcularlo daría cero, y un precio cero es regalar el producto.
    $producto = productoConCosto(0, ['precio_contado' => '5000.00']);

    expect(servicioConPolitica()->recalcular($producto))->toBe(PrecioService::SIN_COSTO)
        ->and($producto->fresh()->precio_contado)->toBe('5000.00')
        ->and(HistorialPrecio::count())->toBe(0);
});

test('recalcular dos veces seguidas asienta una sola', function () {
    // No se escribe un hecho que no pasó: el historial tiene que poder leerse
    // como la lista de los cambios reales.
    $producto = productoConCosto(20000);
    $servicio = servicioConPolitica();

    $servicio->recalcular($producto);
    $segundo = $servicio->recalcular($producto->fresh());

    expect($segundo)->toBe(PrecioService::SIN_CAMBIO)
        ->and(HistorialPrecio::count())->toBe(1);
});

test('el recalculo deja su asiento con el precio anterior y el usuario', function () {
    $usuario  = User::factory()->create();
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    servicioConPolitica()->recalcular($producto, $usuario);

    $asiento = HistorialPrecio::sole();

    expect($asiento->precio_contado_anterior)->toBe('1000.00')
        ->and($asiento->precio_contado_nuevo)->toBe('60500.00')
        ->and($asiento->motivo)->toBe('recalculo')
        ->and($asiento->usuario_id)->toBe($usuario->id);
});

test('el precio de lista sale del recargo de la politica', function () {
    // 50.000 de neto, +15 % de costo financiero = 57.500, y el IVA se paga
    // sobre el recargo también: 57.500 × 1,21.
    $producto = productoConCosto(20000);

    servicioConPolitica(['recargo_lista' => '15.000'])->recalcular($producto);

    expect($producto->fresh()->precio_contado)->toBe('60500.00')
        ->and($producto->fresh()->precio_lista)->toBe('69575.00');
});

// --- La parametrización ---------------------------------------------------

test('un impuesto general nuevo aparece en el precio sin tocar codigo', function () {
    // El segundo criterio de terminado del tramo.
    Impuesto::create([
        'nombre'      => 'Impuestos internos',
        'alicuota'    => '10.00',
        'alcance'     => 'general',
        'comprobante' => 'otros_nacionales',
        'orden'       => 50,
    ]);

    $producto = productoConCosto(1000);
    $contado  = servicioConPolitica(['ganancia_global' => '1.000'])->calcular($producto)['contado'];

    expect($contado['final'])->toBe(1331.0)
        ->and($contado['impuestos'])->toHaveCount(2)
        ->and($contado['impuestos'][1]['nombre'])->toBe('Impuestos internos')
        ->and($contado['neto'] + $contado['impuestos'][0]['importe'] + $contado['impuestos'][1]['importe'])
        ->toBe($contado['final']);
});

test('el desglose nombra el IVA con el nombre del catalogo', function () {
    $producto = productoConCosto(1000, ['alicuota_iva' => '10.50']);

    $impuestos = servicioConPolitica()->calcular($producto)['contado']['impuestos'];

    expect($impuestos[0]['nombre'])->toBe('IVA 10,5 %');
});

test('la parametrizacion se lee una sola vez aunque se recalculen muchos productos', function () {
    // Sin memorizar, el ajuste masivo del tramo D leería estas tablas una vez
    // por producto. Es el mismo defecto que el tramo A encontró en los @can.
    $servicio  = servicioConPolitica();
    $productos = Producto::factory()->count(5)->conStockYCosto(10, 1000)->create();

    $consultas = consultasSobre('politica_precios', function () use ($servicio, $productos) {
        foreach ($productos as $producto) {
            $servicio->recalcular($producto);
        }
    });

    expect($consultas)->toBe(1);
});

test('el simulador no guarda nada', function () {
    $calculo = servicioConPolitica()->simular('20000.00', '2.500', '21.00');

    expect($calculo['contado']['final'])->toBe(60500.0)
        ->and($calculo['margen'])->toBe(60.0)
        ->and(HistorialPrecio::count())->toBe(0)
        ->and(Producto::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El asiento de un ajuste masivo
|--------------------------------------------------------------------------
|
| `fijarPrecios()` es el único escritor de `historial_precios`, y el tramo D le
| agrega dos cosas opcionales: el lote al que pertenece el cambio y el valor
| anterior del costo y de la ganancia, que son las dos columnas que un ajuste puede
| mover y un cambio de precio no.
|
*/


test('el asiento guarda el lote del que vino el cambio', function () {
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);
    $lote     = loteDePrueba();

    servicioConPolitica()->fijarPrecios($producto, 1150, 1150, null, 'ajuste_masivo', $lote);

    expect(HistorialPrecio::sole()->lote_id)->toBe($lote->id);
});

test('un cambio que no vino de un ajuste queda sin lote', function () {
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    servicioConPolitica()->recalcular($producto);

    expect(HistorialPrecio::sole()->lote_id)->toBeNull();
});

test('el asiento guarda el costo y la ganancia anteriores cuando el ajuste los movio', function () {
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);
    $lote     = loteDePrueba(['operacion' => 'costo']);

    servicioConPolitica()->fijarPrecios($producto, 1150, 1150, null, 'ajuste_masivo', $lote, [
        'costo_anterior' => '20000.00',
        'costo_nuevo'    => '23000.00',
    ]);

    $asiento = HistorialPrecio::sole();

    expect($asiento->costo_anterior)->toBe('20000.00')
        ->and($asiento->costo_nuevo)->toBe('23000.00')
        ->and($asiento->ganancia_anterior)->toBeNull();
});

test('un aumento de costo que deja el precio igual se asienta igual', function () {
    // Es el caso que vuelve necesaria la condición: con redondeo a múltiplo de 1.000
    // el precio puede no moverse, y sin asiento ese cambio de costo no se podría
    // deshacer. El lote diría que alcanzó a un producto del que no quedó rastro.
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);
    $lote     = loteDePrueba(['operacion' => 'costo']);

    $resultado = servicioConPolitica()->fijarPrecios(
        $producto, '1000.00', '1000.00', null, 'ajuste_masivo', $lote,
        ['costo_anterior' => '20000.00', 'costo_nuevo' => '23000.00'],
    );

    expect($resultado)->toBe(PrecioService::APLICADO)
        ->and(HistorialPrecio::sole()->costo_nuevo)->toBe('23000.00');
});

test('el mismo precio y sin economia sigue sin asentar nada', function () {
    // La otra mitad: la condición nueva no puede volverse una excusa para asentar
    // cambios que no pasaron.
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    $resultado = servicioConPolitica()->fijarPrecios(
        $producto, '1000.00', '1000.00', null, 'ajuste_masivo', loteDePrueba(),
    );

    expect($resultado)->toBe(PrecioService::SIN_CAMBIO)
        ->and(HistorialPrecio::count())->toBe(0);
});

test('el asiento del ajuste guarda tambien al usuario que lo corrio', function () {
    $usuario  = User::factory()->create();
    $producto = productoConCosto(20000, ['precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    servicioConPolitica()->fijarPrecios($producto, 1150, 1150, $usuario, 'ajuste_masivo', loteDePrueba());

    expect(HistorialPrecio::sole()->usuario_id)->toBe($usuario->id);
});