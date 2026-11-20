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

// Desde el tramo D las dos pantallas son shells y las acciones son de la API (las de escritura se
// retiraron de la web). Se prueban las mismas dos mitades sobre cada camino.
dataset('rutas de proveedores del producto', [
    'comparador'         => ['get', 'producto-proveedores.index', false],
    'formulario edicion' => ['get', 'producto-proveedores.edit',  true],
]);

dataset('endpoints de proveedores del producto', [
    'comparador'       => ['get',    '/api/v1/productos/{p}/proveedores?forma=comparador'],
    'disponibles'      => ['get',    '/api/v1/productos/{p}/proveedores?forma=disponibles'],
    'alta'             => ['post',   '/api/v1/productos/{p}/proveedores'],
    'vinculo'          => ['get',    '/api/v1/productos/{p}/proveedores/{v}'],
    'edicion'          => ['patch',  '/api/v1/productos/{p}/proveedores/{v}'],
    'elegir proveedor' => ['post',   '/api/v1/productos/{p}/proveedores/{v}/preferido'],
    'baja'             => ['delete', '/api/v1/productos/{p}/proveedores/{v}'],
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

test('ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $url   = str_replace(['{p}', '{v}'], [$producto->id, $proveedor->id], $url);
    // Con proveedor.ver también: ver proveedores no es editar el producto.
    $otros = [...array_values(array_diff(PERMISOS_PRODUCTO, ['producto.editar'])), 'proveedor.ver'];

    $this->json($metodo, $url, datosDeVinculo(Proveedor::factory()->create()), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de proveedores del producto');

test('el permiso del endpoint alcanza para pasar', function (string $metodo, string $url) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $url = str_replace(['{p}', '{v}'], [$producto->id, $proveedor->id], $url);

    $respuesta = $this->json($metodo, $url, datosDeVinculo(Proveedor::factory()->create()), bearer(usuarioCon('producto.editar')));

    expect($respuesta->status())->not->toBe(403);
})->with('endpoints de proveedores del producto');

test('quien solo puede ver productos no recibe el enlace al comparador', function () {
    $producto = Producto::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar (M-33).
    // Desde el tramo D la fila es una plantilla: el enlace lleva __ID__. Y en la otra dirección, sí.
    $enlace = route('producto-proveedores.index', '__ID__');

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index'))
        ->assertOk()
        ->assertDontSee(route('producto-proveedores.index', $producto))
        ->assertDontSee($enlace, false);

    $this->actingAs(usuarioCon('producto.ver', 'producto.editar'))
        ->get(route('productos.index'))
        ->assertSee($enlace, false);
});

// Regla 37: los shells llegan sin el producto ni sus proveedores.
test('los shells del comparador no traen datos de negocio', function () {
    $producto  = Producto::factory()->create(['nombre' => 'Producto Secreto']);
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral Secreta']);
    $this->service->vincular($producto, datosDeVinculo($proveedor, ['codigo_proveedor' => 'COD-SECRETO']));

    foreach ([route('producto-proveedores.index', $producto), route('producto-proveedores.edit', [$producto, $proveedor])] as $url) {
        $this->actingAs(usuarioCon('producto.ver', 'producto.editar'))->get($url)
            ->assertOk()
            ->assertDontSee('Producto Secreto')
            ->assertDontSee('Austral Secreta')
            ->assertDontSee('COD-SECRETO')
            ->assertSee('data-producto="'.$producto->id.'"', false);
    }
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

    $this->getJson("/api/v1/productos/{$producto->id}/proveedores?forma=comparador", bearer(usuarioCon('producto.editar')))
        ->assertOk()
        // Los sin costo al final, no al principio como haría un null tratado como cero.
        ->assertJsonPath('datos.*.proveedor.id', [$barato->id, $caro->id, $sinCosto->id])
        ->assertJsonPath('datos.*.mas_barato', [true, false, false])
        ->assertJsonPath('datos.*.es_preferido', [false, true, false])
        // Cuánto más caro que el más barato: 20.000 contra 10.000.
        ->assertJsonPath('datos.1.diferencia', 100)
        ->assertJsonPath('datos.0.costo_ultimo', '10000.00')
        ->assertJsonPath('datos.2.costo_ultimo', null);

    // Los textos de las dos marcas siguen en la plantilla del shell.
    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.index', $producto))
        ->assertSee('Más barato')
        ->assertSee('Le pedimos a este');
});

test('el comparador no ofrece como disponible un proveedor ya cargado ni uno inactivo', function () {
    $producto  = Producto::factory()->create();
    $cargado   = Proveedor::factory()->create();
    $inactivo  = Proveedor::factory()->inactivo()->create();
    $ofrecible = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($cargado));

    $this->getJson("/api/v1/productos/{$producto->id}/proveedores?forma=disponibles", bearer(usuarioCon('producto.editar')))
        ->assertOk()
        ->assertJsonPath('datos.*.id', [$ofrecible->id]);
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

    $this->getJson("/api/v1/productos/{$producto->id}/proveedores/{$ajeno->id}", bearer(usuarioCon('producto.editar')))
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrado');
});

test('el vinculo de otro producto no se puede editar desde este', function () {
    $producto  = Producto::factory()->create();
    $otro      = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($otro, datosDeVinculo($proveedor));

    // Laravel resuelve cada parámetro por separado y no verifica que estén
    // relacionados: sin vinculoDe(), esto escribiría sobre el vínculo del OTRO
    // producto desde la pantalla de este.
    $this->patchJson("/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}", datosDeEdicionDelVinculo(['costo_ultimo' => 99]), bearer(usuarioCon('producto.editar')))
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

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", datosDeEdicionDelVinculo(), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proveedor_id', 'errores');
});

test('rechaza cargar dos veces el mismo proveedor', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", datosDeVinculo($proveedor), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proveedor_id', 'errores');

    expect($producto->proveedores()->count())->toBe(1);
});

test('rechaza un proveedor inactivo', function () {
    $producto = Producto::factory()->create();
    $baja     = Proveedor::factory()->inactivo()->create();

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", datosDeVinculo($baja), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proveedor_id', 'errores');
});

test('rechaza un costo con tres decimales y uno en cero', function (mixed $costo) {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    // Cero no es «no sé cuánto cobra»: para eso está vacío. Y el tercer decimal se
    // rechaza en vez de dejar que la base lo redondee en silencio.
    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", datosDeVinculo($proveedor, ['costo_ultimo' => $costo]), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('costo_ultimo', 'errores');
})->with(['tres decimales' => '1500,505', 'cero' => '0']);

test('un costo vacio se acepta y se guarda nulo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", datosDeVinculo($proveedor, ['costo_ultimo' => '']), bearer(usuarioCon('producto.editar')))
        ->assertCreated()
        ->assertJsonPath('datos.costo_ultimo', null)
        // El primero queda elegido aunque nadie marque la casilla, y el mensaje lo dice.
        ->assertJsonPath('datos.es_preferido', true)
        ->assertJsonPath('mensaje', "Se agregó {$proveedor->razon_social}, y es a quien se le va a pedir la reposición.");

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

    $this->patchJson("/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}", $datos, bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('costo_ultimo', 'errores');

    expect((float) $producto->proveedores()->findOrFail($proveedor->id)->pivot->costo_ultimo)->toBe(15000.0);
});

test('rechaza el intento de cambiarle el proveedor al vinculo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    // Prohibido y no ignorado: el intento se ve en lugar de desaparecer.
    $this->patchJson(
        "/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}",
        datosDeEdicionDelVinculo(['proveedor_id' => $otro->id]),
        bearer(usuarioCon('producto.editar')),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proveedor_id', 'errores');
});

test('rechaza el intento de mover el vinculo a otro producto', function () {
    $producto  = Producto::factory()->create();
    $otro      = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $this->patchJson(
        "/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}",
        datosDeEdicionDelVinculo(['producto_id' => $otro->id]),
        bearer(usuarioCon('producto.editar')),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('producto_id', 'errores');
});

// En la API el checkbox no se adivina: sin `es_preferido`, el alta se rechaza (M-31).
test('la API pide la preferencia en vez de suponerla', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $datos = datosDeVinculo($proveedor);
    unset($datos['es_preferido']);

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores", $datos, bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('es_preferido', 'errores');
});

test('la edicion avisa que destildar al elegido no lo cambia', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();
    $this->service->vincular($producto, datosDeVinculo($proveedor));

    $this->patchJson("/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}", datosDeEdicionDelVinculo(['costo_ultimo' => '18.000,50']), bearer(usuarioCon('producto.editar')))
        ->assertOk()
        ->assertJsonPath('datos.costo_ultimo', '18000.50')
        ->assertJsonPath('datos.es_preferido', true)
        ->assertJsonPath('mensaje', "Se guardó el vínculo con {$proveedor->razon_social}. Sigue siendo el elegido: para cambiarlo, marcá otro.");
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

    $this->postJson("/api/v1/productos/{$producto->id}/proveedores/{$segundo->id}/preferido", [], bearer(usuarioCon('producto.editar')))
        ->assertOk()
        ->assertJsonPath('datos.es_preferido', true)
        ->assertJsonPath('mensaje', "La reposición de «{$producto->nombre}» se le va a pedir a {$segundo->razon_social}.");

    expect($producto->fresh()->proveedorParaReponer()->id)->toBe($segundo->id);
});

test('la baja quita el vinculo y asciende al que queda', function () {
    $producto = Producto::factory()->create();
    $primero  = Proveedor::factory()->create();
    $segundo  = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($primero));
    $this->service->vincular($producto, datosDeVinculo($segundo));

    $this->deleteJson("/api/v1/productos/{$producto->id}/proveedores/{$primero->id}", [], bearer(usuarioCon('producto.editar')))
        ->assertOk()
        ->assertJsonPath('datos.quitado', true)
        ->assertJsonPath('mensaje', "Se quitó a {$primero->razon_social}. El más antiguo de los que quedaban pasó a ser el elegido.");

    expect($producto->proveedores()->count())->toBe(1)
        ->and($producto->fresh()->proveedorParaReponer()->id)->toBe($segundo->id);
});

test('la baja bloqueada por un pedido en curso vuelve con el motivo', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    OrdenCompra::factory()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);

    // La ReglaDeNegocioException se traduce en bootstrap/app.php, no en el
    // controlador: no hay try/catch en ningún lado (M-30). En la API es un 409 con su motivo.
    $this->deleteJson("/api/v1/productos/{$producto->id}/proveedores/{$proveedor->id}", [], bearer(usuarioCon('producto.editar')))
        ->assertConflict()
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect($producto->proveedores()->count())->toBe(1);
});

test('la pantalla no ofrece quitar un proveedor con pedido en curso', function () {
    $producto  = Producto::factory()->create();
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral']);

    $this->service->vincular($producto, datosDeVinculo($proveedor));

    OrdenCompra::factory()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);

    // No se ofrece el camino que el sistema va a negar, y el botón se reemplaza
    // por el motivo en lugar de desaparecer sin explicación.
    // Desde el tramo D la fila la arma la pantalla: lo que decide es `pedido_en_curso`.
    $orden = OrdenCompra::query()->latest('id')->first();

    $this->getJson("/api/v1/productos/{$producto->id}/proveedores?forma=comparador", bearer(usuarioCon('producto.editar')))
        ->assertOk()
        ->assertJsonPath('datos.0.pedido_en_curso.numero', $orden->numeroFormateado())
        ->assertJsonPath('datos.0.pedido_en_curso.estado_texto', $orden->estadoTexto());

    $this->actingAs(usuarioCon('producto.editar'))
        ->get(route('producto-proveedores.index', $producto))
        ->assertOk()
        ->assertDontSee('Quitar a Austral')
        ->assertSee('data-campo="bloqueo"', false);
});

/*
|--------------------------------------------------------------------------
| A quién pedirle: la forma de compras
|--------------------------------------------------------------------------
*/

test('a quien pedirle sale con proveedor.ver, en orden, y el costo sólo con su permiso', function () {
    $producto = Producto::factory()->create();
    $barato   = Proveedor::factory()->create(['razon_social' => 'B']);
    $elegido  = Proveedor::factory()->create(['razon_social' => 'A']);
    $baja     = Proveedor::factory()->create();

    $this->service->vincular($producto, datosDeVinculo($elegido, ['costo_ultimo' => 20000, 'es_preferido' => true]));
    $this->service->vincular($producto, datosDeVinculo($barato, ['costo_ultimo' => 10000]));
    $this->service->vincular($producto, datosDeVinculo($baja));
    $baja->update(['activo' => false]);

    $url = "/api/v1/productos/{$producto->id}/proveedores";

    $this->getJson($url, bearer(usuarioCon('proveedor.ver', 'precio.ver_costo')))
        ->assertOk()
        // El elegido primero, después por costo; el inactivo no se ofrece.
        ->assertJsonPath('datos.*.id', [$elegido->id, $barato->id])
        ->assertJsonPath('datos.1.costo_ultimo', '10000.00');

    app('auth')->forgetGuards();
    expect($this->getJson($url, bearer(usuarioCon('proveedor.ver')))->assertOk()->json('datos.0'))
        ->not->toHaveKey('costo_ultimo');

    app('auth')->forgetGuards();
    $this->getJson($url, bearer(usuarioCon('producto.editar')))->assertForbidden();
});