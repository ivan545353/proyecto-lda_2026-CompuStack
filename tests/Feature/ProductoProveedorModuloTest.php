<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProductoProveedorService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->service = app(ProductoProveedorService::class);
});

/** Los datos como los manda el formulario de EDICIÓN: sin el proveedor, que va en la URL. */
function datosDeEdicionDelVinculo(array $sobreescribir = []): array
{
    return array_merge([
        'costo_ultimo'     => 15000,
        'codigo_proveedor' => 'AUS-001',
        'es_preferido'     => false,
    ], $sobreescribir);
}

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Las seis exigen producto.editar. Se prueban las dos mitades: con todos los
| permisos del módulo MENOS producto.editar se deniega, y con sólo producto.editar
| se permite. Sin la primera mitad, una ruta protegida por el permiso equivocado
| pasaría desapercibida (C-2).
|
| PERMISOS_PRODUCTO se reutiliza de ProductoModuloTest: Pest carga todos los
| archivos en el mismo proceso, así que declararla de nuevo sería un error fatal.
| Y es correcto que sea la misma lista: éstas son rutas del módulo de productos.
|
*/

dataset('rutas de proveedores del producto', [
    'comparador'         => ['get',    'producto-proveedores.index',     false],
    'alta'               => ['post',   'producto-proveedores.store',     false],
    'formulario edicion' => ['get',    'producto-proveedores.edit',      true],
    'edicion'            => ['put',    'producto-proveedores.update',    true],
    'elegir proveedor'   => ['patch',  'producto-proveedores.preferido', true],
    'baja'               => ['delete', 'producto-proveedores.destroy',   true],
]);

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, bool $conProveedor) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $url   = $conProveedor ? route($ruta, [$producto, $proveedor]) : route($ruta, $producto);
    $otros = array_values(array_diff(PERMISOS_PRODUCTO, ['producto.editar']));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, datosDeVinculo($proveedor))
        ->assertForbidden();
})->with('rutas de proveedores del producto');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, bool $conProveedor) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $url = $conProveedor ? route($ruta, [$producto, $proveedor]) : route($ruta, $producto);

    $respuesta = pedirRuta($this->actingAs(usuarioCon('producto.editar')), $metodo, $url, datosDeVinculo($proveedor));

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de proveedores del producto');

test('quien solo puede ver productos no recibe el enlace al comparador', function () {
    $producto = Producto::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar (M-33).
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index'))
        ->assertOk()
        ->assertDontSee(route('producto-proveedores.index', $producto));
});

/*
|--------------------------------------------------------------------------
| El comparador
|--------------------------------------------------------------------------
*/

test('el comparador ordena por costo, marca el mas barato y marca el elegido', function () {
    $producto = Producto::factory()->create();
    $caro     = Proveedor::factory()->create();
    $barato   = Proveedor::factory()->create();
    $sinCosto = Proveedor::factory()->create();

    // El primero vinculado queda elegido, y NO es el más barato: la pantalla
    // tiene que mostrar las dos cosas sin opinar.
    $this->service->vincular($producto, datosDeVinculo($caro, ['costo_ultimo' => 20000]));
    $this->service->vincular($producto, datosDeVinculo($barato, ['costo_ultimo' => 10000]));
    $this->service->vincular($producto, datosDeVinculo($sinCosto, ['costo_ultimo' => null]));

    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.index', $producto))
        ->assertOk()
        ->assertViewHas('masBarato', fn ($p) => $p->id === $barato->id)
        // Los sin costo al final, no al principio como haría un null tratado como cero.
        ->assertViewHas('vinculos', fn ($v) => $v->pluck('id')->all() === [$barato->id, $caro->id, $sinCosto->id])
        ->assertSee('Más barato')
        ->assertSee('Le pedimos a este');
});

test('el comparador no ofrece como disponible un proveedor ya cargado ni uno inactivo', function () {
    $producto  = Producto::factory()->create();
    $cargado   = Proveedor::factory()->create();
    $inactivo  = Proveedor::factory()->inactivo()->create();
    $ofrecible = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($cargado));

    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.index', $producto))
        ->assertViewHas('disponibles', fn ($d) => $d->pluck('id')->all() === [$ofrecible->id]);
});

/*
|--------------------------------------------------------------------------
| El producto de la URL manda
|--------------------------------------------------------------------------
*/

test('un proveedor que no provee el producto da 404', function () {
    $producto = Producto::factory()->create();
    $ajeno    = Proveedor::factory()->create();

    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.edit', [$producto, $ajeno]))
        ->assertNotFound();
});

test('el vinculo de otro producto no se puede editar desde este', function () {
    $producto  = Producto::factory()->create();
    $otro      = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($otro, datosDeVinculo($proveedor));

    // Laravel resuelve cada parámetro por separado y no verifica que estén
    // relacionados: sin vinculoDe(), esto escribiría sobre el vínculo del OTRO
    // producto desde la pantalla de este.
    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('producto-proveedores.update', [$producto, $proveedor]), datosDeEdicionDelVinculo())
        ->assertNotFound();

    expect((float) $otro->proveedores()->findOrFail($proveedor->id)->pivot->costo_ultimo)->toBe(15000.0);
});

/*
|--------------------------------------------------------------------------
| La validación rechaza
|--------------------------------------------------------------------------
*/

test('rechaza el alta sin proveedor', function () {
    $producto = Producto::factory()->create();

    $this->actingAs(usuarioCon('producto.editar'))
        ->post(route('producto-proveedores.store', $producto), datosDeEdicionDelVinculo())
        ->assertSessionHasErrors('proveedor_id');
});

test('rechaza cargar dos veces el mismo proveedor', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $this->actingAs(usuarioCon('producto.editar'))
        ->post(route('producto-proveedores.store', $producto), datosDeVinculo($proveedor))
        ->assertSessionHasErrors('proveedor_id');

    expect($producto->proveedores()->count())->toBe(1);
});

test('rechaza un proveedor inactivo', function () {
    $producto = Producto::factory()->create();
    $baja     = Proveedor::factory()->inactivo()->create();

    $this->actingAs(usuarioCon('producto.editar'))
        ->post(route('producto-proveedores.store', $producto), datosDeVinculo($baja))
        ->assertSessionHasErrors('proveedor_id');
});

test('rechaza un costo con tres decimales y uno en cero', function (mixed $costo) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    // Cero no es «no sé cuánto cobra»: para eso está vacío. Y el tercer decimal se
    // rechaza en vez de dejar que la base lo redondee en silencio.
    $this->actingAs(usuarioCon('producto.editar'))
        ->post(route('producto-proveedores.store', $producto), datosDeVinculo($proveedor, ['costo_ultimo' => $costo]))
        ->assertSessionHasErrors('costo_ultimo');
})->with(['tres decimales' => '1500,505', 'cero' => '0']);

test('un costo vacio se acepta y se guarda nulo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->actingAs(usuarioCon('producto.editar'))
        ->post(route('producto-proveedores.store', $producto), datosDeVinculo($proveedor, ['costo_ultimo' => '']))
        ->assertSessionHasNoErrors();

    expect($producto->proveedores()->findOrFail($proveedor->id)->pivot->costo_ultimo)->toBeNull();
});

test('omitir el costo se rechaza en vez de blanquear el guardado', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    // La diferencia entre «lo quiero vacío» y «no mandé el campo». Sin `present`,
    // esto guardaría null sin que nadie lo pidiera: M-31 con otra ropa.
    $datos = datosDeEdicionDelVinculo();
    unset($datos['costo_ultimo']);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('producto-proveedores.update', [$producto, $proveedor]), $datos)
        ->assertSessionHasErrors('costo_ultimo');

    expect((float) $producto->proveedores()->findOrFail($proveedor->id)->pivot->costo_ultimo)->toBe(15000.0);
});

test('rechaza el intento de cambiarle el proveedor al vinculo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    // Prohibido y no ignorado: el intento se ve en lugar de desaparecer.
    $this->actingAs(usuarioCon('producto.editar'))
        ->put(
            route('producto-proveedores.update', [$producto, $proveedor]),
            datosDeEdicionDelVinculo(['proveedor_id' => $otro->id]),
        )
        ->assertSessionHasErrors('proveedor_id');
});

test('rechaza el intento de mover el vinculo a otro producto', function () {
    $producto  = Producto::factory()->create();
    $otro      = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(
            route('producto-proveedores.update', [$producto, $proveedor]),
            datosDeEdicionDelVinculo(['producto_id' => $otro->id]),
        )
        ->assertSessionHasErrors('producto_id');
});

/*
|--------------------------------------------------------------------------
| Las acciones hacen lo que dicen
|--------------------------------------------------------------------------
*/

test('elegir proveedor cambia a quien se le pide y lo dice', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo));

    $this->actingAs(usuarioCon('producto.editar'))
        ->patch(route('producto-proveedores.preferido', [$producto, $segundo]))
        ->assertRedirect(route('producto-proveedores.index', $producto))
        ->assertSessionHas('exito');

    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($segundo->id);
});

test('la baja quita el vinculo y asciende al que queda', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo));

    $this->actingAs(usuarioCon('producto.editar'))
        ->delete(route('producto-proveedores.destroy', [$producto, $primero]))
        ->assertRedirect(route('producto-proveedores.index', $producto))
        ->assertSessionHas('exito');

    expect($producto->proveedores()->count())->toBe(1)
        ->and($producto->fresh()->proveedorParaReponer()->id)->toBe($segundo->id);
});

test('la baja bloqueada por un pedido en curso vuelve con el motivo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    OrdenCompra::factory()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);

    // La ReglaDeNegocioException se traduce en bootstrap/app.php, no en el
    // controlador: no hay try/catch en ningún lado (M-30).
    $this->actingAs(usuarioCon('producto.editar'))
        ->from(route('producto-proveedores.index', $producto))
        ->delete(route('producto-proveedores.destroy', [$producto, $proveedor]))
        ->assertRedirect(route('producto-proveedores.index', $producto))
        ->assertSessionHas('error');

    expect($producto->proveedores()->count())->toBe(1);
});

test('la pantalla no ofrece quitar un proveedor con pedido en curso', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral']);

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    OrdenCompra::factory()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);

    // No se ofrece el camino que el sistema va a negar, y el botón se reemplaza
    // por el motivo en lugar de desaparecer sin explicación.
    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.index', $producto))
        ->assertOk()
        ->assertDontSee('Quitar a Austral')
        ->assertSee('No se puede quitar');
});