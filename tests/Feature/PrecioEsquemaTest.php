<?php

use App\Models\Producto;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\HistorialPrecio;
use App\Models\Categoria;
use App\Models\Impuesto;
use App\Models\LoteAjuste;

uses(RefreshDatabase::class);

/**
 * Verifican el esquema de la parametrización de precios, no el comportamiento.
 * `tipoDeColumna()` es la ayuda de EsquemaDatosTest: Pest carga todos los
 * archivos de prueba en el mismo proceso antes de correr ninguno.
 */

test('existen las tres tablas de la parametrizacion de precios', function () {
    foreach (['impuestos', 'factores_costo', 'politica_precios'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue("Falta la tabla {$tabla}");
    }
});

test('el catalogo de impuestos trae las cuatro alicuotas de IVA', function () {
    $alicuotas = DB::table('impuestos')
        ->where('alcance', 'producto')
        ->where('activo', true)
        ->orderBy('orden')
        ->pluck('alicuota')
        ->map(fn ($valor) => (string) $valor)
        ->all();

    expect($alicuotas)->toBe(['21.00', '10.50', '27.00', '0.00']);
});

test('las opciones de alicuota del modelo exponen las alicuotas que un producto puede tener', function () {
    // Reemplaza a Producto::ALICUOTAS_IVA: las opciones que el formulario y la
    // validación de productos admiten se leen del catálogo de impuestos.
    expect(Impuesto::opcionesDeAlicuota())->toBe([
        '21.00' => 'IVA 21 %',
        '10.50' => 'IVA 10,5 %',
        '27.00' => 'IVA 27 %',
        '0.00'  => 'Exento',
    ]);
});

test('la alicuota del catalogo entra en la columna del producto', function () {
    // Los dos tipos tienen que ser el mismo: un 999,99 % en el catálogo que la
    // columna del producto no puede guardar sería una opción que al elegirla falla.
    expect(tipoDeColumna('impuestos', 'alicuota'))
        ->toBe(tipoDeColumna('productos', 'alicuota_iva'));
});

test('el impuesto declara su clasificacion de la ley 27743', function () {
    $tipo = tipoDeColumna('impuestos', 'comprobante');

    foreach (['iva', 'otros_nacionales', 'no_se_muestra'] as $valor) {
        expect($tipo)->toContain("'{$valor}'");
    }
});

test('la politica de precios existe, es una sola y arranca neutra', function () {
    $politica = DB::table('politica_precios')->get();

    expect($politica)->toHaveCount(1)
        ->and((float) $politica->first()->ganancia_global)->toBe(1.0)
        ->and((float) $politica->first()->recargo_lista)->toBe(0.0)
        ->and((float) $politica->first()->redondeo_multiplo)->toBe(0.01)
        ->and($politica->first()->redondeo_modo)->toBe('cercano');
});

test('la base rechaza una segunda fila de politica de precios', function () {
    // La invariante vive en el esquema y no en un servicio que alguien puede
    // saltear. Es lo que el sistema viejo no tenía (A-18).
    expect(fn () => DB::table('politica_precios')->insert([
        'id'                => 2,
        'ganancia_global'   => '2.000',
        'recargo_lista'     => '0.000',
        'redondeo_multiplo' => '0.01',
        'redondeo_modo'     => 'cercano',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]))->toThrow(QueryException::class);
});

test('los factores de costo arrancan vacios', function () {
    // Con la tabla vacía el motor da el mismo precio que hoy: la fase no mete
    // un recargo que nadie cargó.
    expect(DB::table('factores_costo')->count())->toBe(0);
});

test('el producto declara su ganancia propia y su precio manual', function () {
    foreach (['ganancia', 'precio_manual'] as $columna) {
        expect(Schema::hasColumn('productos', $columna))->toBeTrue("Falta productos.{$columna}");
    }
});

test('un producto nuevo no nace con el precio fijado a mano', function () {
    // Los 20.011 que ya existían entraron en true por la migración; los nuevos
    // los calcula el motor, que es el sentido de la fase.
    expect(Producto::factory()->create()->precio_manual)->toBeFalse();
});

test('la ganancia del producto y la de la categoria admiten null', function () {
    // Null es «heredá», y tiene que ser distinguible de un cero cargado a mano:
    // una ganancia de 0 sería regalar el producto.
    expect(Producto::factory()->create()->ganancia)->toBeNull()
        ->and(Categoria::factory()->create()->ganancia)->toBeNull();
});

test('el historial de precios declara los cinco motivos, incluidos los del tramo D', function () {
    $tipo = tipoDeColumna('historial_precios', 'motivo');

    foreach (['alta', 'recalculo', 'manual', 'ajuste_masivo', 'deshacer'] as $motivo) {
        expect($tipo)->toContain("'{$motivo}'");
    }
});

test('el historial no tiene fecha de modificacion', function () {
    // Un hecho pasado no se edita: hay created_at y no hay updated_at.
    expect(Schema::hasColumn('historial_precios', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('historial_precios', 'updated_at'))->toBeFalse();
});

test('borrar un producto se lleva su historial de precios', function () {
    // Con RESTRICT, un producto que alguna vez cambió de precio no se podría
    // borrar nunca y ProductoService perdería la baja física.
    $producto = Producto::factory()->create();

    HistorialPrecio::create([
        'producto_id'             => $producto->id,
        'usuario_id'              => null,
        'motivo'                  => 'manual',
        'precio_contado_anterior' => 1000,
        'precio_contado_nuevo'    => 1200,
        'precio_lista_anterior'   => 1000,
        'precio_lista_nuevo'      => 1200,
    ]);

    $producto->delete();

    expect(HistorialPrecio::where('producto_id', $producto->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| El lote del tramo D
|--------------------------------------------------------------------------
*/

test('existe la tabla de lotes de ajuste', function () {
    expect(Schema::hasTable('lotes_ajuste'))->toBeTrue();
});

test('el lote declara sus seis operaciones, incluida deshacer', function () {
    // Declaradas con la tabla vacía porque agregar un valor después la reescribe,
    // el mismo criterio con el que `historial_precios` declaró `ajuste_masivo` y
    // `deshacer` en el tramo C.
    $tipo = tipoDeColumna('lotes_ajuste', 'operacion');

    foreach (['costo', 'precio', 'ganancia', 'recalculo', 'redondeo', 'deshacer'] as $operacion) {
        expect($tipo)->toContain("'{$operacion}'");
    }
});

test('el lote no tiene fecha de modificacion', function () {
    // Un ajuste es un hecho pasado: si se revierte, se escribe otro lote.
    expect(Schema::hasColumn('lotes_ajuste', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('lotes_ajuste', 'updated_at'))->toBeFalse();
});

test('el historial de precios declara a que lote pertenece', function () {
    // Es la columna que el tramo C dejó anunciada y no creó.
    expect(Schema::hasColumn('historial_precios', 'lote_id'))->toBeTrue();
});

test('el historial registra el costo y la ganancia, y las cuatro admiten null', function () {
    // Null significa «este cambio no tocó eso», y por eso un cambio de precio sin
    // ajuste masivo sigue siendo una fila válida. Qué tocó el lote lo dice su
    // operación, no estas columnas.
    $producto = Producto::factory()->create();

    $asiento = HistorialPrecio::create([
        'producto_id'             => $producto->id,
        'usuario_id'              => null,
        'motivo'                  => 'manual',
        'precio_contado_anterior' => 1000,
        'precio_contado_nuevo'    => 1200,
        'precio_lista_anterior'   => 1000,
        'precio_lista_nuevo'      => 1200,
    ]);

    expect($asiento->lote_id)->toBeNull()
        ->and($asiento->costo_anterior)->toBeNull()
        ->and($asiento->costo_nuevo)->toBeNull()
        ->and($asiento->ganancia_anterior)->toBeNull()
        ->and($asiento->ganancia_nuevo)->toBeNull();
});

test('el costo y la ganancia del historial entran en las columnas del producto', function () {
    // Los dos pares tienen que ser el mismo tipo: un costo que el producto acepta y
    // su historial no sería un historial que no puede contar lo que pasó.
    expect(tipoDeColumna('historial_precios', 'costo_anterior'))
        ->toBe(tipoDeColumna('productos', 'costo_promedio'))
        ->and(tipoDeColumna('historial_precios', 'ganancia_anterior'))
        ->toBe(tipoDeColumna('productos', 'ganancia'));
});

test('un lote con cambios de precio colgados no se puede borrar', function () {
    // RESTRICT: borrar el lote dejaría los cambios sin el acto que los explica, que
    // es lo único que `lote_id` agrega.
    $producto = Producto::factory()->create();

    $lote = LoteAjuste::create([
        'usuario_id'     => null,
        'operacion'      => 'precio',
        'porcentaje'     => '15.000',
        'criterio'       => ['todos' => true],
        'criterio_texto' => 'Todos los productos',
        'alcanzados'     => 1,
        'omitidos'       => 0,
    ]);

    HistorialPrecio::create([
        'producto_id'             => $producto->id,
        'lote_id'                 => $lote->id,
        'motivo'                  => 'ajuste_masivo',
        'precio_contado_anterior' => 1000,
        'precio_contado_nuevo'    => 1150,
        'precio_lista_anterior'   => 1000,
        'precio_lista_nuevo'      => 1150,
    ]);

    expect(fn () => $lote->delete())->toThrow(QueryException::class);
});

test('el ultimo reversible es null cuando no hubo ningun ajuste', function () {
    expect(LoteAjuste::ultimoReversible())->toBeNull();
});