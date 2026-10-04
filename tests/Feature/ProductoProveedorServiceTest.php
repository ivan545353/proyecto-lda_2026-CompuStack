<?php

use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProductoProveedorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ProductoProveedorService::class);
});

/** Cuántos proveedores del producto están marcados como preferidos. */
function preferidosDe(Producto $producto): int
{
    return DB::table('producto_proveedor')
        ->where('producto_id', $producto->id)
        ->where('es_preferido', true)
        ->count();
}

/*
|--------------------------------------------------------------------------
| La invariante del proveedor preferido
|--------------------------------------------------------------------------
|
| Si el producto tiene al menos un proveedor, exactamente uno es el preferido. El
| esquema no puede expresarla, así que la garantiza el servicio. Es la misma forma
| que la dirección predeterminada de un cliente.
|
*/

test('el primer proveedor queda preferido aunque nadie lo pida', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    expect(preferidosDe($producto))->toBe(1)
        ->and($producto->proveedores()->findOrFail($proveedor->id)->pivot->es_preferido)->toBeTruthy();
});

test('el segundo proveedor no roba la preferencia si no se lo pide', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo));

    expect(preferidosDe($producto))->toBe(1)
        ->and($producto->fresh()->proveedorParaReponer()->id)->toBe($primero->id);
});

test('marcar otro como preferido desmarca al anterior', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo, ['es_preferido' => true]));

    // Dos marcados dejarían a la reposición sin saber a quién pedirle.
    expect(preferidosDe($producto))->toBe(1)
        ->and($producto->fresh()->proveedorParaReponer()->id)->toBe($segundo->id);
});

test('desmarcar al preferido no lo desmarca', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));
    $this->service->actualizar($producto, $proveedor->id, datosDeVinculo($proveedor, [
        'es_preferido' => false,
        'costo_ultimo' => 9000,
    ]));

    // Se conserva: para cambiarlo hay que marcar otro. Guardar algo distinto de lo
    // que el usuario marcó sin avisarle sería peor que no dejarlo.
    expect(preferidosDe($producto))->toBe(1);
});

test('actualizar cambia el costo y el codigo del proveedor', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor, ['costo_ultimo' => 1000]));
    $this->service->actualizar($producto, $proveedor->id, datosDeVinculo($proveedor, [
        'costo_ultimo'     => 1200.5,
        'codigo_proveedor' => 'XYZ-99',
    ]));

    $pivot = $producto->proveedores()->findOrFail($proveedor->id)->pivot;

    expect($pivot->costo_ultimo)->toBe('1200.50')
        ->and($pivot->codigo_proveedor)->toBe('XYZ-99');
});

test('desvincular al preferido asciende al mas antiguo de los que quedan', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();
    $tercero  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo));
    $this->service->vincular($producto, datosDeVinculo($tercero, ['es_preferido' => true]));

    $this->service->desvincular($producto, $tercero->id);

    expect(preferidosDe($producto))->toBe(1)
        ->and($producto->fresh()->proveedorParaReponer()->id)->toBe($primero->id);
});

test('desvincular al unico proveedor no deja ninguno preferido y no rompe', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));
    $this->service->desvincular($producto, $proveedor->id);

    // La invariante es condicional: "si tiene al menos uno". Sin proveedores no hay
    // nada que marcar.
    expect(preferidosDe($producto))->toBe(0)
        ->and($producto->fresh()->proveedorParaReponer())->toBeNull();
});

test('un proveedor de otro producto responde 404', function () {
    $producto = Producto::factory()->create();
    $ajeno    = Proveedor::factory()->create();

    // El vínculo se resuelve a través de la relación del padre: un proveedor que
    // existe pero no es de este producto no se puede editar ni desvincular.
    expect(fn () => $this->service->actualizar($producto, $ajeno->id, datosDeVinculo($ajeno)))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->service->desvincular($producto, $ajeno->id))
        ->toThrow(ModelNotFoundException::class);
});

/*
|--------------------------------------------------------------------------
| A quién se le pide la reposición
|--------------------------------------------------------------------------
*/

test('la reposicion le pide al preferido aunque no sea el mas barato', function () {
    $producto = Producto::factory()->create();
    $caro     = Proveedor::factory()->create(['razon_social' => 'Caro pero cumple']);
    $barato   = Proveedor::factory()->create(['razon_social' => 'Barato y lento']);

    $this->service->vincular($producto, datosDeVinculo($barato, ['costo_ultimo' => 1000]));
    $this->service->vincular($producto, datosDeVinculo($caro, ['costo_ultimo' => 1500, 'es_preferido' => true]));

    // A veces el barato entrega en tres semanas, y eso no es más barato.
    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($caro->id)
        ->and($producto->fresh()->proveedorMasBarato()->id)->toBe($barato->id);
});

test('sin preferido marcado la reposicion le pide al mas barato', function () {
    $producto = Producto::factory()->create();
    $caro     = Proveedor::factory()->create();
    $barato   = Proveedor::factory()->create();

    $producto->proveedores()->attach($caro->id, ['costo_ultimo' => 1500]);
    $producto->proveedores()->attach($barato->id, ['costo_ultimo' => 900]);

    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($barato->id);
});

test('la reposicion ignora a los proveedores inactivos', function () {
    $producto = Producto::factory()->create();
    $activo   = Proveedor::factory()->create(['razon_social' => 'Sigue trabajando']);
    $inactivo = Proveedor::factory()->inactivo()->create(['razon_social' => 'Dado de baja']);

    // El inactivo es el preferido Y el más barato: igual no se le manda nada.
    $this->service->vincular($producto, datosDeVinculo($activo, ['costo_ultimo' => 2000]));
    $this->service->vincular($producto, datosDeVinculo($inactivo, ['costo_ultimo' => 500, 'es_preferido' => true]));

    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($activo->id);
});

test('sin ningun costo cargado la eleccion es determinista', function () {
    $producto = Producto::factory()->create();
    $zeta     = Proveedor::factory()->create(['razon_social' => 'Zeta Insumos']);
    $alfa     = Proveedor::factory()->create(['razon_social' => 'Alfa Distribuciones']);

    $producto->proveedores()->attach($zeta->id);
    $producto->proveedores()->attach($alfa->id);

    // Sin costos, por razón social: la decisión no puede depender del orden en que
    // se cargaron los vínculos, o el job pediría a uno distinto cada noche.
    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($alfa->id);
});

test('un producto que se repone y no tiene proveedor queda señalado', function () {
    $conProveedor = Producto::factory()->create(['stock_minimo' => 5]);
    $sinProveedor = Producto::factory()->create(['stock_minimo' => 5]);
    $sinMinimo    = Producto::factory()->create(['stock_minimo' => 0]);

    $this->service->vincular($conProveedor, datosDeVinculo(Proveedor::factory()->create()));

    // Sin esto, la reposición lo detectaría como crítico todas las noches y no le
    // podría pedir a nadie, en silencio.
    expect($sinProveedor->noSePuedeReponer())->toBeTrue()
        ->and($conProveedor->noSePuedeReponer())->toBeFalse()
        ->and($sinMinimo->noSePuedeReponer())->toBeFalse();
});

test('el registro del costo no crea el vinculo si ya no existe', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    // Que llegue mercadería de un proveedor desvinculado después de la orden es
    // posible, y no es razón para volver a vincularlo solo.
    $this->service->registrarCosto($producto, $proveedor->id, 777);

    expect($producto->proveedores()->count())->toBe(0);
});

test('las operaciones bloquean la fila del producto', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    // Un test de una sola conexión no reproduce la carrera de dos pestañas; lo que
    // sí se puede afirmar es que la consulta pide el bloqueo. Sobre el PRODUCTO y
    // no sobre la pivote: así se serializa también el alta del primer vínculo.
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $consultas = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();

    expect(strtolower($consultas))->toContain('for update')
        ->and(strtolower($consultas))->toContain('productos');
});