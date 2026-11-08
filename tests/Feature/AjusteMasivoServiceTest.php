<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Categoria;
use App\Models\HistorialPrecio;
use App\Models\LoteAjuste;
use App\Services\AjusteMasivoService;
use App\Services\PrecioService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| El ajuste masivo
|--------------------------------------------------------------------------
|
| Las tres salvaguardas del tramo D, cada una con sus tests:
|   1. Previsualización obligatoria: los conteos salen del conjunto completo y no
|      escriben nada.
|   2. Lote reversible: aplicar deja un lote, deshacer devuelve los precios
|      escribiendo otro, y ninguno de los dos pisa un hecho.
|   3. Respeta el precio manual: afuera por omisión, adentro con la casilla.
|
| `servicioConPolitica()` y `productoConCosto()` viven en PrecioServiceTest: Pest
| carga todos los archivos en el mismo proceso, así que están disponibles acá y
| declararlas de nuevo sería un error fatal.
|
*/
/** Fija los números del comercio y devuelve el servicio del ajuste, recién creado. */
function ajusteConPolitica(array $valores = []): AjusteMasivoService
{
    servicioConPolitica($valores);

    return app(AjusteMasivoService::class);
}

/** Los datos de un ajuste, ya validados como los deja el Form Request. */
function datosDeAjusteMasivo(array $cambios = []): array
{
    return array_merge([
        'operacion'  => 'precio',
        'porcentaje' => '15.000',
        'alcance'    => 'todos',
    ], $cambios);
}




// --- El criterio de terminado --------------------------------------------

test('el criterio de terminado: un aumento en una categoria se previsualiza, se aplica y se deshace', function () {
    $categoria = Categoria::factory()->create();
    $servicio  = ajusteConPolitica();

    $productos = collect(range(1, 3))->map(
        fn () => productoConPrecioCalculado(20000, ['categoria_id' => $categoria->id]),
    );

    $datos = datosDeAjusteMasivo([
        'alcance'      => 'recorte',
        'categoria_id' => $categoria->id,
    ]);

    // 1. Previsualizar: los tres entran y a los tres les cambia el precio.
    $conteos = $servicio->previsualizar($datos);

    expect($conteos['total'])->toBe(3)
        ->and($conteos['alcanzados'])->toBe(3)
        ->and($conteos['bajo_costo'])->toBe(0)
        ->and(LoteAjuste::count())->toBe(0)
        ->and(HistorialPrecio::where('motivo', 'ajuste_masivo')->count())->toBe(0);

    // 2. Aplicar: 60.500 × 1,15 = 69.575.
    $lote = $servicio->aplicar($datos, null);

    expect($lote->alcanzados)->toBe(3)
        ->and($lote->omitidos)->toBe(0)
        ->and($productos->first()->fresh()->precio_contado)->toBe('69575.00')
        ->and($lote->cambios()->count())->toBe(3);

    // 3. Deshacer: vuelven a 60.500 y queda escrito como un hecho nuevo.
    $reversion = $servicio->deshacer($lote, null);

    expect($reversion->operacion)->toBe('deshacer')
        ->and($reversion->revierte_a)->toBe($lote->id)
        ->and($reversion->alcanzados)->toBe(3)
        ->and($productos->first()->fresh()->precio_contado)->toBe('60500.00')
        ->and($lote->fresh()->reversion)->not->toBeNull()
        ->and(HistorialPrecio::where('motivo', 'deshacer')->count())->toBe(3);
});

test('un presupuesto emitido antes del ajuste sigue diciendo lo que decia', function () {
    // La otra mitad del criterio de terminado. El renglón congela su precio desde la
    // Fase 6, así que lo que este test fija es que el ajuste no lo toque.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $venta = app(VentaService::class)->crear(
        datosDeVenta([lineaDeVenta($producto, 2)]),
        admin(),
    );

    $servicio->aplicar(datosDeAjusteMasivo(), null);

    expect($venta->lineas()->sole()->precio_unitario)->toBe('60500.00')
        ->and($producto->fresh()->precio_contado)->toBe('69575.00');
});

// --- Las cinco operaciones -----------------------------------------------

test('variar el costo sube el costo y el motor recalcula el precio solo', function () {
    // Es la operación correcta cuando el mayorista aumentó: se carga el aumento del
    // costo y el precio sale del motor, no de una regla de tres sobre el precio viejo.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'costo', 'porcentaje' => '10.000']), null);

    // 22.000 × 2,5 × 1,21 = 66.550
    expect($producto->fresh()->costo_promedio)->toBe('22000.00')
        ->and($producto->fresh()->precio_contado)->toBe('66550.00');
});

test('el asiento de un ajuste de costo guarda el costo anterior', function () {
    // Es lo que hace que deshacer sea exacto y no aproximado.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'costo', 'porcentaje' => '10.000']), null);

    $asiento = HistorialPrecio::where('motivo', 'ajuste_masivo')->sole();

    expect($asiento->costo_anterior)->toBe('20000.00')
        ->and($asiento->costo_nuevo)->toBe('22000.00');
});

test('deshacer un ajuste de costo devuelve el costo y el precio', function () {
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'costo', 'porcentaje' => '10.000']), null);
    $servicio->deshacer($lote, null);

    expect($producto->fresh()->costo_promedio)->toBe('20000.00')
        ->and($producto->fresh()->precio_contado)->toBe('60500.00');
});

test('fijar la ganancia del conjunto escribe la del producto y recalcula', function () {
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'ganancia', 'ganancia' => '3.000']), null);

    // 20.000 × 3 × 1,21 = 72.600
    expect($producto->fresh()->ganancia)->toBe('3.000')
        ->and($producto->fresh()->precio_contado)->toBe('72600.00');
});

test('deshacer un ajuste de ganancia devuelve el null que significa heredar', function () {
    // El caso que obliga a mirar la operación del lote y no la columna: null en
    // `ganancia_anterior` es un valor legítimo —«heredá de la categoría»— y no «el
    // lote no tocó la ganancia».
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    expect($producto->ganancia)->toBeNull();

    $lote = $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'ganancia', 'ganancia' => '3.000']), null);
    $servicio->deshacer($lote, null);

    expect($producto->fresh()->ganancia)->toBeNull()
        ->and($producto->fresh()->precio_contado)->toBe('60500.00');
});

test('recalcular desde los factores alcanza a un producto que nunca se calculo', function () {
    $servicio = ajusteConPolitica();
    $producto = productoConCosto(20000);   // precio_contado en 1,00

    $servicio->aplicar(datosDeAjusteMasivo(['operacion' => 'recalculo', 'porcentaje' => null]), null);

    expect($producto->fresh()->precio_contado)->toBe('60500.00');
});

test('redondear a un multiplo usa el multiplo de la operacion y el modo de la politica', function () {
    // 60.500 al múltiplo de 1.000 más cercano son 61.000. El múltiplo lo elige la
    // operación; hacia dónde se redondea es del comercio.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $servicio->aplicar(datosDeAjusteMasivo([
        'operacion'         => 'redondeo',
        'porcentaje'        => null,
        'redondeo_multiplo' => '1000.00',
    ]), null);

    expect($producto->fresh()->precio_contado)->toBe('61000.00');
});

test('un porcentaje negativo es una baja de precios', function () {
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $servicio->aplicar(datosDeAjusteMasivo(['porcentaje' => '-10.000']), null);

    expect($producto->fresh()->precio_contado)->toBe('54450.00');
});

// --- La primera salvaguarda: la previsualización -------------------------

test('la previsualizacion avisa cuantos productos quedarian por debajo del costo', function () {
    // Con ganancia 1 el precio es el costo más IVA, así que el neto es exactamente el
    // costo: 50.000. Una baja del 10 % deja el neto en 45.000 y ese producto se
    // vendería perdiendo plata. Con la ganancia de 2,5 del resto de los tests el caso
    // no existe, porque el precio calculado es siempre dos veces y media el costo.
    $servicio = ajusteConPolitica(['ganancia_global' => '1.000']);
    productoConPrecioCalculado(50000);

    $conteos = $servicio->previsualizar(datosDeAjusteMasivo(['porcentaje' => '-10.000']));

    expect($conteos['bajo_costo'])->toBe(1);
});

test('la previsualizacion cuenta aparte los que no tienen costo', function () {
    $servicio = ajusteConPolitica();
    productoConCosto(0, ['precio_contado' => '5000.00']);
    productoConCosto(20000);

    $conteos = $servicio->previsualizar(datosDeAjusteMasivo(['operacion' => 'recalculo', 'porcentaje' => null]));

    expect($conteos['total'])->toBe(2)
        ->and($conteos['sin_costo'])->toBe(1)
        ->and($conteos['alcanzados'])->toBe(1);
});

test('la previsualizacion cuenta aparte los que no cambian', function () {
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    // Ya está calculado, así que recalcular no lo mueve.
    $conteos = $servicio->previsualizar(datosDeAjusteMasivo(['operacion' => 'recalculo', 'porcentaje' => null]));

    expect($conteos['sin_cambio'])->toBe(1)
        ->and($conteos['alcanzados'])->toBe(0);
});

test('la previsualizacion proyecta el mismo precio que despues se escribe', function () {
    // Si no, la salvaguarda sería una estimación: la pantalla prometería un número y
    // el ajuste escribiría otro.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $proyectado = $servicio->proyectar($producto, datosDeAjusteMasivo());

    $servicio->aplicar(datosDeAjusteMasivo(), null);

    expect((float) $producto->fresh()->precio_contado)->toBe($proyectado['contado']);
});

// --- La tercera salvaguarda: el precio manual ----------------------------

test('los de precio manual quedan afuera por omision, y la previsualizacion los cuenta', function () {
    // El número que la pantalla tiene que decir antes de aplicar: los 20.011 productos
    // que ya existían entraron con la marca puesta.
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);
    $aMano = productoConCosto(20000, ['precio_manual' => true, 'precio_contado' => '999.00']);

    $conteos = $servicio->previsualizar(datosDeAjusteMasivo());

    expect($conteos['total'])->toBe(1)
        ->and($conteos['manuales_afuera'])->toBe(1);

    $servicio->aplicar(datosDeAjusteMasivo(), null);

    expect($aMano->fresh()->precio_contado)->toBe('999.00');
});

test('con la casilla marcada el ajuste alcanza a los de precio manual', function () {
    $servicio = ajusteConPolitica();
    $aMano    = productoConCosto(20000, ['precio_manual' => true, 'precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    $conteos = $servicio->previsualizar(datosDeAjusteMasivo(['incluir_manuales' => true]));

    expect($conteos['total'])->toBe(1)
        ->and($conteos['manuales_afuera'])->toBe(0);

    $servicio->aplicar(datosDeAjusteMasivo(['incluir_manuales' => true]), null);

    expect($aMano->fresh()->precio_contado)->toBe('1150.00');
});

test('incluir un producto en el ajuste no le saca el precio manual', function () {
    // El operador pidió incluirlo esta vez, y eso mueve su número; no lo devuelve al
    // recálculo automático, que es lo que la marca significa.
    $servicio = ajusteConPolitica();
    $aMano    = productoConCosto(20000, ['precio_manual' => true, 'precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    $servicio->aplicar(datosDeAjusteMasivo(['incluir_manuales' => true]), null);

    expect($aMano->fresh()->precio_manual)->toBeTrue();
});

// --- La segunda salvaguarda: el lote ------------------------------------

test('el lote congela el criterio en texto, aunque la categoria se renombre despues', function () {
    $servicio  = ajusteConPolitica();
    $categoria = Categoria::factory()->create(['nombre' => 'Memorias']);
    productoConPrecioCalculado(20000, ['categoria_id' => $categoria->id]);

    $lote = $servicio->aplicar(datosDeAjusteMasivo([
        'alcance'      => 'recorte',
        'categoria_id' => $categoria->id,
    ]), null);

    $categoria->update(['nombre' => 'Memorias RAM y SSD']);

    expect($lote->fresh()->criterio_texto)->toBe('Categoría Memorias y sus subcategorías');
});

test('el lote guarda la operacion y su parametro', function () {
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo(), null);

    expect($lote->operacion)->toBe('precio')
        ->and($lote->porcentaje)->toBe('15.000')
        ->and($lote->parametro())->toBe('15 %')
        ->and($lote->criterio['alcance'])->toBe('todos');
});

test('el lote guarda sólo el parámetro de su operación', function () {
    // Sin JavaScript los tres campos del formulario viajan. Un porcentaje guardado en
    // un lote de ganancia dejaría escrito un número que no participó del cálculo.
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo([
        'operacion'         => 'ganancia',
        'ganancia'          => '3.000',
        'porcentaje'        => '15.000',
        'redondeo_multiplo' => '100.00',
    ]), null);

    expect($lote->ganancia)->toBe('3.000')
        ->and($lote->porcentaje)->toBeNull()
        ->and($lote->redondeo_multiplo)->toBeNull()
        ->and($lote->parametro())->toBe('× 3');
});

test('un ajuste que no cambia ningun precio no deja lote', function () {
    // No es un hecho: es la ausencia de uno. Un lote así tampoco se podría deshacer,
    // porque no hay ningún precio que devolver.
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $datos = datosDeAjusteMasivo(['operacion' => 'recalculo', 'porcentaje' => null]);

    expect($servicio->aplicar($datos, null))->toBeNull()
        ->and(LoteAjuste::count())->toBe(0);
});

test('solo se puede deshacer el ultimo ajuste', function () {
    // El precio anterior que guarda el historial vale mientras no haya pasado nada
    // después: revertir el primero devolvería precios que el segundo ya movió.
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $primero = $servicio->aplicar(datosDeAjusteMasivo(), null);
    $servicio->aplicar(datosDeAjusteMasivo(['porcentaje' => '5.000']), null);

    expect(fn () => $servicio->deshacer($primero, null))
        ->toThrow(ReglaDeNegocioException::class);
});

test('un lote ya deshecho no se puede deshacer de nuevo', function () {
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo(), null);
    $servicio->deshacer($lote, null);

    expect(fn () => $servicio->deshacer($lote, null))
        ->toThrow(ReglaDeNegocioException::class);
});

test('un producto cuyo precio cambio despues del ajuste no se revierte', function () {
    // Devolverlo pisaría un hecho posterior, que es justo lo que el sistema no hace.
    $servicio = ajusteConPolitica();
    $quieto   = productoConPrecioCalculado(20000);
    $tocado   = productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo(), null);

    // Alguien le pone el precio a mano después del ajuste.
    app(PrecioService::class)->fijarPrecios($tocado->fresh(), 80000, 80000, null, 'manual');

    $reversion = $servicio->deshacer($lote, null);

    expect($reversion->alcanzados)->toBe(1)
        ->and($reversion->omitidos)->toBe(1)
        ->and($quieto->fresh()->precio_contado)->toBe('60500.00')
        ->and($tocado->fresh()->precio_contado)->toBe('80000.00');
});

test('si ningun producto se puede revertir, no se escribe la reversion', function () {
    // Si se escribiera, el lote quedaría marcado como deshecho sin haber devuelto un
    // solo precio, y nadie podría volver a intentarlo.
    $servicio = ajusteConPolitica();
    $producto = productoConPrecioCalculado(20000);

    $lote = $servicio->aplicar(datosDeAjusteMasivo(), null);

    app(PrecioService::class)->fijarPrecios($producto->fresh(), 80000, 80000, null, 'manual');

    expect($servicio->deshacer($lote, null))->toBeNull()
        ->and(LoteAjuste::count())->toBe(1)
        ->and(LoteAjuste::ultimoReversible()->id)->toBe($lote->id);
});

test('el lote queda con el usuario que lo corrio, y con null si lo corrio el sistema', function () {
    $servicio = ajusteConPolitica();
    $usuario  = admin();
    productoConPrecioCalculado(20000);
    productoConPrecioCalculado(30000);

    $conUsuario = $servicio->aplicar(datosDeAjusteMasivo(), $usuario);
    $sinUsuario = $servicio->aplicar(datosDeAjusteMasivo(['porcentaje' => '5.000']), null);

    expect($conUsuario->usuario_id)->toBe($usuario->id)
        ->and($sinUsuario->usuario_id)->toBeNull()
        ->and($sinUsuario->usuarioTexto())->toBe('El sistema');
});

// --- Escala -------------------------------------------------------------

test('la parametrizacion se lee una sola vez para todo el ajuste', function () {
    // Sin una sola instancia de PrecioService, el ajuste leería las cuatro tablas de
    // parametrización una vez por producto. Es el mismo defecto que el tramo A cerró
    // en los `@can`, y acá se multiplicaría por 20.011.
    $servicio = ajusteConPolitica();

    collect(range(1, 5))->each(fn () => productoConPrecioCalculado(20000));

    $consultas = consultasSobre('politica_precios', function () use ($servicio) {
        $servicio->aplicar(datosDeAjusteMasivo(), null);
    });

    expect($consultas)->toBe(1);
});

test('la previsualizacion no escribe nada', function () {
    $servicio = ajusteConPolitica();
    productoConPrecioCalculado(20000);

    $antes = HistorialPrecio::count();

    $servicio->previsualizar(datosDeAjusteMasivo());

    expect(HistorialPrecio::count())->toBe($antes)
        ->and(LoteAjuste::count())->toBe(0);
});
