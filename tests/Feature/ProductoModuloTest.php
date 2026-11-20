<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    Storage::fake('public');
});

const PERMISOS_PRODUCTO = ['producto.ver', 'producto.crear', 'producto.editar', 'producto.eliminar'];

/** Un cuerpo válido, como lo manda el formulario. */
function datosProducto(array $cambios = []): array
{
    return array_merge([
        'codigo'              => 'SSD-1TB',
        'nombre'              => 'Disco SSD 1TB',
        'categoria_id'        => Categoria::factory()->create()->id,
        'precio_contado'      => '95000',
        'precio_manual'       => 1,
        'alicuota_iva'        => '21.00',
        'stock_minimo'        => 0,
        'cantidad_reposicion' => 0,
        'activo'              => 1,
    ], $cambios);
}

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta (C-2)
|--------------------------------------------------------------------------
*/

// Las pantallas, que quedan en la web como shells (regla 37).
dataset('rutas de productos', [
    'listado'         => ['get',    'productos.index',   'producto.ver',      false],
    'formulario alta' => ['get',    'productos.create',  'producto.crear',    false],
    'formulario edic' => ['get',    'productos.edit',    'producto.editar',   true],
]);

// Las operaciones, en la API (tramo D de la Etapa 3). `{producto}` se reemplaza por el del test.
dataset('endpoints de productos', [
    'listado'    => ['get',    '/api/v1/productos?forma=gestion',             'producto.ver'],
    'ficha'      => ['get',    '/api/v1/productos/{producto}?forma=gestion',  'producto.ver'],
    'impresion'  => ['get',    '/api/v1/productos/impresion',                 'producto.ver'],
    'opciones'   => ['get',    '/api/v1/productos/opciones',                  'producto.ver'],
    'alta'       => ['post',   '/api/v1/productos',                           'producto.crear'],
    'edicion'    => ['patch',  '/api/v1/productos/{producto}',                'producto.editar'],
    'baja'       => ['delete', '/api/v1/productos/{producto}',                'producto.eliminar'],
    'recalcular' => ['post',   '/api/v1/productos/{producto}/recalcular',     'precio.recalcular'],
]);

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conProducto) {
    $producto = Producto::factory()->create();
    $url      = $conProducto ? route($ruta, $producto) : route($ruta);
    $otros    = array_values(array_diff(PERMISOS_PRODUCTO, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, datosProducto())->assertForbidden();
})->with('rutas de productos');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conProducto) {
    $producto = Producto::factory()->create();
    $url      = $conProducto ? route($ruta, $producto) : route($ruta);

    expect(pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, datosProducto())->status())
        ->not->toBe(403);
})->with('rutas de productos');

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $producto = Producto::factory()->create();
    $otros    = array_values(array_diff(PERMISOS_PRODUCTO, [$permiso]));

    $this->json($metodo, str_replace('{producto}', $producto->id, $url), datosProducto(), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de productos');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $producto = Producto::factory()->create();

    $respuesta = $this->json($metodo, str_replace('{producto}', $producto->id, $url), datosProducto(), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de productos');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $producto = Producto::factory()->create();

    // Desde el tramo D la fila es una plantilla del shell: lo que se afirma es que la plantilla
    // no trae el botón. Y en la otra dirección, que sí lo trae para quien tiene el permiso.
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index'))
        ->assertOk()
        ->assertDontSee(route('productos.create'))
        ->assertDontSee(route('productos.edit', $producto))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    $this->actingAs(usuarioCon(...PERMISOS_PRODUCTO))
        ->get(route('productos.index'))
        ->assertSee(route('productos.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de productos no traen datos de negocio', function () {
    $producto = Producto::factory()->conStockYCosto(4, 777)->create(['nombre' => 'Producto Secreto', 'codigo' => 'SECRETO-1']);
    $usuario  = usuarioCon(...[...PERMISOS_PRODUCTO, 'precio.ver_costo', 'categoria.ver', 'marca.ver', 'proveedor.ver']);

    foreach ([route('productos.index'), route('productos.edit', $producto)] as $url) {
        $this->actingAs($usuario)->get($url)
            ->assertOk()
            ->assertDontSee('Producto Secreto')
            ->assertDontSee('SECRETO-1')
            ->assertDontSee($producto->categoria->nombre);
    }

    $this->actingAs($usuario)->get(route('productos.edit', $producto))
        ->assertSee('data-producto="'.$producto->id.'"', false);
});

// Cada filtro que lista otro módulo se ofrece a quien puede verlo: sus opciones salen de su endpoint (C-2).
test('los filtros de otros modulos se ofrecen con su permiso', function () {
    $this->actingAs(usuarioCon('producto.ver'))->get(route('productos.index'))
        ->assertOk()
        ->assertDontSee('data-opciones="categorias"', false)
        ->assertDontSee('data-opciones="marcas"', false)
        ->assertDontSee('data-opciones="proveedores"', false);

    $this->actingAs(usuarioCon('producto.ver', 'categoria.ver', 'marca.ver', 'proveedor.ver'))->get(route('productos.index'))
        ->assertSee('data-opciones="categorias"', false)
        ->assertSee('data-opciones="marcas"', false)
        ->assertSee('data-opciones="proveedores"', false);
});

/*
|--------------------------------------------------------------------------
| Filtros sobre HTTP (A-24, A-25)
|--------------------------------------------------------------------------
|
| El caso original: ItemController mandaba `categoriaId` y el DAO leía
| `categoria`. Los scopes tienen su test en ProductoFiltrosTest; estos prueban
| que cada parámetro de la URL llega al suyo.
|
*/

test('el parametro q busca por nombre y por codigo', function () {
    Producto::factory()->create(['nombre' => 'Disco SSD 1TB', 'codigo' => 'SSD-1TB']);
    Producto::factory()->create(['nombre' => 'Mouse', 'codigo' => 'G505']);

    $encabezado = bearer(usuarioCon('producto.ver'));

    $this->getJson('/api/v1/productos?forma=gestion&q=disco', $encabezado)
        ->assertJsonPath('total', 1)->assertJsonPath('datos.0.codigo', 'SSD-1TB');

    $this->getJson('/api/v1/productos?forma=gestion&q=g505', $encabezado)
        ->assertJsonPath('total', 1)->assertJsonPath('datos.0.codigo', 'G505');
});

test('el parametro categoria_id incluye las subcategorias', function () {
    $almacenamiento = Categoria::factory()->create();
    $ssd            = Categoria::factory()->hijaDe($almacenamiento)->create();

    Producto::factory()->create(['categoria_id' => $ssd->id]);
    Producto::factory()->create();

    $this->getJson("/api/v1/productos?forma=gestion&categoria_id={$almacenamiento->id}", bearer(usuarioCon('producto.ver')))
        ->assertJsonPath('total', 1);
});

test('los parametros marca_id, estado y stock llegan a sus filtros', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->conStock(5)->create(['marca_id' => $marca->id]);
    Producto::factory()->sinStock()->inactivo()->create();

    $encabezado = bearer(usuarioCon('producto.ver'));

    foreach ([
        ['marca_id' => $marca->id],
        ['estado' => 'activos'],
        ['stock' => 'disponible'],
    ] as $filtro) {
        $this->getJson('/api/v1/productos?forma=gestion&'.http_build_query($filtro), $encabezado)
            ->assertJsonPath('total', 1);
    }
});

test('el parametro proveedor_id llega al filtro', function () {
    $proveedor = Proveedor::factory()->create();

    Producto::factory()->conProveedor($proveedor)->create();
    Producto::factory()->create();

    // El nivel que A-24 necesitaba: el scope puede funcionar y el cableado no.
    $this->getJson("/api/v1/productos?forma=gestion&proveedor_id={$proveedor->id}", bearer(usuarioCon('producto.ver')))
        ->assertJsonPath('total', 1);
});

test('el parametro stock=sin_proveedor llega al filtro', function () {
    Producto::factory()->conStock(0)->create(['stock_minimo' => 5]);
    Producto::factory()->conStock(0)->conProveedor(Proveedor::factory()->create())
        ->create(['stock_minimo' => 5]);

    $this->getJson('/api/v1/productos?forma=gestion&stock=sin_proveedor', bearer(usuarioCon('producto.ver')))
        ->assertJsonPath('total', 1);
});

// En la API, el aviso es el 422: el listado no muestra otra cosa que la pedida.
test('un filtro que no es valido se rechaza con aviso', function () {
    $this->getJson('/api/v1/productos?forma=gestion&stock=cualquiera', bearer(usuarioCon('producto.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('stock', 'errores');
});

test('el listado pagina de a 20 y los enlaces conservan el filtro', function () {
    Producto::factory()->count(25)->create(['activo' => true]);

    // `criterios` es lo que la pantalla repite al pedir la página siguiente.
    $this->getJson('/api/v1/productos?forma=gestion&estado=activos', bearer(usuarioCon('producto.ver')))
        ->assertJsonCount(20, 'datos')
        ->assertJsonPath('total', 25)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.estado', 'activos');
});

/*
|--------------------------------------------------------------------------
| Precios en formato argentino
|--------------------------------------------------------------------------
|
| Interpretar mal "1.500" como 1,5 guardaría un producto de mil quinientos
| pesos a uno con cincuenta, sin ningún error: la versión más cara de M-31.
|
*/

dataset('precios bien escritos', [
    'entero'           => ['95000', '95000.00'],
    'coma decimal'     => ['1500,50', '1500.50'],
    'punto de miles'   => ['1.500', '1500.00'],
    'miles y decimal'  => ['1.500,50', '1500.50'],
    'punto decimal'    => ['1500.50', '1500.50'],
]);

test('interpreta el precio como lo escribe el usuario', function (string $escrito, string $guardado) {
    $this->postJson('/api/v1/productos', datosProducto(['precio_contado' => $escrito]), bearer(usuarioCon('producto.crear')))
        ->assertSuccessful();

    expect(Producto::first()->precio_contado)->toBe($guardado);
})->with('precios bien escritos');

test('rechaza un precio ambiguo en vez de adivinar', function () {
    $this->postJson('/api/v1/productos', datosProducto(['precio_contado' => '1,500.50']), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('precio_contado', 'errores');

    expect(Producto::count())->toBe(0);
});

test('rechaza un precio con tres decimales en vez de redondearlo', function () {
    $this->postJson('/api/v1/productos', datosProducto(['precio_contado' => '1500.505']), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('precio_contado', 'errores');
});

test('el precio de lista ya no se carga a mano', function () {
    // Prohibido y no ignorado: un formulario viejo que todavía lo mande recibe
    // un mensaje, en vez de guardar un precio de lista que nadie eligió.
    $this->postJson('/api/v1/productos', datosProducto(['precio_lista' => '120000']), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('precio_lista', 'errores');

    expect(Producto::count())->toBe(0);
});

test('un producto nuevo no puede nacer calculando su precio solo', function () {
    // No tiene costo —esa columna sólo la mueve StockService al recibir
    // mercadería—, así que el motor no tendría con qué calcularlo.
    $this->postJson('/api/v1/productos', datosProducto(['precio_manual' => 0, 'precio_contado' => null]), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('precio_manual', 'errores');
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

test('guardar un producto existente sin cambiar nada funciona', function () {
    // La trampa de la alícuota: la base devuelve "21.00" y una lista
    // [0, 10.5, 21] compara textos, así que "21.00" no coincidiría con "21".
    // Sin la normalización, ningún producto existente se podría volver a
    // guardar: pasaría todas las pruebas de alta y fallaría al editar.
    $producto = Producto::factory()->create(['alicuota_iva' => '10.50', 'precio_contado' => '1500.50']);

    $this->patchJson("/api/v1/productos/{$producto->id}", datosProducto([
        'codigo'         => $producto->codigo,
        'nombre'         => $producto->nombre,
        'categoria_id'   => $producto->categoria_id,
        'precio_contado' => '1.500,50',        // como lo muestra el formulario
        'alicuota_iva'   => '10.50',
    ]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    expect($producto->fresh()->alicuota_iva)->toBe('10.50');
});

test('el codigo se guarda en mayusculas y no se repite sin importar como se escriba', function () {
    $encabezado = bearer(usuarioCon('producto.crear'));

    $this->postJson('/api/v1/productos', datosProducto(['codigo' => 'ssd-1tb']), $encabezado)->assertCreated();

    expect(Producto::first()->codigo)->toBe('SSD-1TB');

    $this->postJson('/api/v1/productos', datosProducto(['codigo' => 'Ssd-1Tb', 'nombre' => 'Otro']), $encabezado)
        ->assertUnprocessable()->assertJsonValidationErrors('codigo', 'errores');
});

test('si hay stock minimo, la cantidad a reponer no puede ser cero', function () {
    // Un producto con mínimo pero sin cantidad a pedir nunca se repondría:
    // el error aparecería semanas después, con el producto agotado.
    $this->postJson('/api/v1/productos', datosProducto(['stock_minimo' => 5, 'cantidad_reposicion' => 0]), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('cantidad_reposicion', 'errores');
});

test('el stock no se puede cargar desde el formulario', function () {
    // Sólo lo mueve el StockService, dejando movimiento en el kardex (A-13).
    $this->postJson('/api/v1/productos', datosProducto(['stock' => 999, 'costo_promedio' => 500]), bearer(usuarioCon('producto.crear')));

    expect(Producto::first()->stock)->toBe(0)
        ->and(Producto::first()->costo_promedio)->toBe('0.00');
});

test('una marca inactiva no se puede elegir, pero la que ya tenia se conserva', function () {
    $inactiva = Marca::factory()->inactiva()->create();
    $otra     = Marca::factory()->inactiva()->create();
    $producto = Producto::factory()->create(['marca_id' => $inactiva->id]);

    $encabezado = bearer(usuarioCon('producto.editar'));
    $base = datosProducto([
        'codigo'       => $producto->codigo,
        'nombre'       => $producto->nombre,
        'categoria_id' => $producto->categoria_id,
    ]);

    // Su marca actual, aunque esté inactiva: se puede seguir editando.
    $this->patchJson("/api/v1/productos/{$producto->id}", [...$base, 'marca_id' => $inactiva->id], $encabezado)
        ->assertSuccessful();

    // Otra marca inactiva: no.
    $this->patchJson("/api/v1/productos/{$producto->id}", [...$base, 'marca_id' => $otra->id], $encabezado)
        ->assertUnprocessable()->assertJsonValidationErrors('marca_id', 'errores');
});

test('ante un error se conserva lo que el usuario habia escrito', function () {
    // M-31 en la interfaz: equivocarse en un campo no borra los demás. El 422 no recarga la página,
    // así que el formulario conserva lo escrito; la API sólo marca el campo que falla.
    $this->postJson('/api/v1/productos', datosProducto(['nombre' => '', 'precio_contado' => '1.500,50']), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('nombre', 'errores')
        ->assertJsonMissingPath('errores.precio_contado');

    expect(Producto::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Destacado (tramo C): la casilla que alimenta la sección «Destacados» de la tienda
|--------------------------------------------------------------------------
|
| Destacar es una acción de edición (C-2): se ve y se guarda sólo con producto.editar.
|
*/

/** El cuerpo de la edición de un producto existente, con lo que el test quiera cambiar. */
function datosParaEditar(Producto $producto, array $cambios = []): array
{
    return datosProducto([
        'codigo'       => $producto->codigo,
        'nombre'       => $producto->nombre,
        'categoria_id' => $producto->categoria_id,
        ...$cambios,
    ]);
}

test('la edición marca un producto como destacado', function () {
    $producto = Producto::factory()->create(['destacado' => false]);

    $this->patchJson("/api/v1/productos/{$producto->id}", datosParaEditar($producto, ['destacado' => 1]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    expect($producto->fresh()->destacado)->toBeTrue();
});

test('la edición quita el destacado cuando la casilla llega destildada', function () {
    $producto = Producto::factory()->create(['destacado' => true]);

    // La pantalla manda la casilla siempre, en false si está destildada. Sin la clave, el producto
    // queda como estaba (tramo D): es lo que ya hacía el servicio.
    $this->patchJson("/api/v1/productos/{$producto->id}", datosParaEditar($producto, ['destacado' => 0]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    expect($producto->fresh()->destacado)->toBeFalse();
});

test('el alta de quien puede crear y editar guarda el destacado', function () {
    $this->postJson('/api/v1/productos', datosProducto(['destacado' => 1]), bearer(usuarioCon('producto.crear', 'producto.editar')))
        ->assertSuccessful();

    expect(Producto::first()->destacado)->toBeTrue();
});

test('el alta sin la casilla deja el producto sin destacar', function () {
    $this->postJson('/api/v1/productos', datosProducto(), bearer(usuarioCon('producto.crear', 'producto.editar')));

    expect(Producto::first()->destacado)->toBeFalse();
});

test('quien sólo puede crear no logra destacar ni con un pedido armado a mano', function () {
    $this->postJson('/api/v1/productos', datosProducto(['destacado' => 1]), bearer(usuarioCon('producto.crear')))
        ->assertSuccessful();

    expect(Producto::count())->toBe(1)
        ->and(Producto::first()->destacado)->toBeFalse();
});

test('editar otros campos no toca el destacado si la casilla llega tildada', function () {
    $producto = Producto::factory()->create(['destacado' => true, 'nombre' => 'Nombre viejo']);

    $this->patchJson("/api/v1/productos/{$producto->id}", datosParaEditar($producto, ['nombre' => 'Nombre nuevo', 'destacado' => 1]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    expect($producto->fresh())->nombre->toBe('Nombre nuevo')->destacado->toBeTrue();
});

test('la casilla se ve en la edición con producto.editar y marcada si el producto es destacado', function () {
    $destacado = Producto::factory()->create(['destacado' => true]);
    $comun     = Producto::factory()->create(['destacado' => false]);
    $usuario   = usuarioCon('producto.editar');

    $this->actingAs($usuario)->get(route('productos.edit', $destacado))
        ->assertOk()
        ->assertSee('name="destacado"', false)
        ->assertSee('Destacado en la tienda');

    // Desde el tramo D la casilla la marca la pantalla con la ficha: lo que decide si va marcada
    // es `destacado` en la API.
    $encabezado = bearer(usuarioCon('producto.ver'));
    $this->getJson("/api/v1/productos/{$destacado->id}?forma=gestion", $encabezado)->assertJsonPath('datos.destacado', true);
    $this->getJson("/api/v1/productos/{$comun->id}?forma=gestion", $encabezado)->assertJsonPath('datos.destacado', false);
});

test('quien sólo puede crear no recibe la casilla en el formulario de alta', function () {
    $this->actingAs(usuarioCon('producto.crear'))
        ->get(route('productos.create'))
        ->assertOk()
        ->assertDontSee('name="destacado"', false)
        ->assertDontSee('Destacado en la tienda');
});

test('quien puede crear y editar recibe la casilla en el alta, sin marcar', function () {
    $contenido = $this->actingAs(usuarioCon('producto.crear', 'producto.editar'))
        ->get(route('productos.create'))
        ->assertOk()
        ->assertSee('name="destacado"', false)
        ->getContent();

    expect($contenido)->not->toMatch('/<input[^>]*id="destacado"[^>]*checked/');
});

test('ante un error la casilla destildada no vuelve marcada', function () {
    $producto = Producto::factory()->create(['destacado' => true]);

    // El usuario había destildado. El 422 no recarga la página, así que la casilla sigue como la
    // dejó: lo que se afirma es que el error es sólo del nombre y que no se guardó nada a medias.
    $this->patchJson("/api/v1/productos/{$producto->id}", datosParaEditar($producto, ['nombre' => '', 'destacado' => 0]), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()->assertJsonValidationErrors('nombre', 'errores')
        ->assertJsonMissingPath('errores.destacado');

    expect($producto->fresh()->destacado)->toBeTrue();
});

test('el servicio no apaga un destacado si quien lo llama no manda la clave', function () {
    $producto = Producto::factory()->create(['destacado' => true, 'precio_manual' => true]);

    app(\App\Services\ProductoService::class)->actualizar($producto, datosParaEditar($producto, ['nombre' => 'Sólo el nombre']));

    expect($producto->fresh())->nombre->toBe('Sólo el nombre')->destacado->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Imágenes
|--------------------------------------------------------------------------
|
| El formulario manda los archivos en imagenes[] y la lista final en
| imagenes_orden. Ver ProductoRequest::ordenDeImagenes().
|
*/

test('el orden del formulario decide cual imagen es la principal', function () {
    // Con archivos, multipart: `post` y no `postJson`.
    $this->post('/api/v1/productos', datosProducto([
        'imagenes' => [
            UploadedFile::fake()->image('primera.png'),
            UploadedFile::fake()->image('segunda.png'),
        ],
        'imagenes_orden' => json_encode(['nueva:1', 'nueva:0']),
    ]), bearer(usuarioCon('producto.crear')))
        ->assertSuccessful();

    $imagenes = Producto::first()->imagenes;

    expect($imagenes)->toHaveCount(2);
    Storage::disk('public')->assertExists($imagenes[0]);
});

test('editar reordena, agrega y quita en una sola pasada', function () {
    $a = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $b = UploadedFile::fake()->image('b.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a, $b]]);

    // Con archivos, `POST` + `_method=PATCH`: PHP no lee los archivos de un PATCH multipart (HL-110).
    $this->post("/api/v1/productos/{$producto->id}", datosProducto([
        '_method'        => 'PATCH',
        'codigo'         => $producto->codigo,
        'nombre'         => $producto->nombre,
        'categoria_id'   => $producto->categoria_id,
        'imagenes'       => [UploadedFile::fake()->image('c.png')],
        'imagenes_orden' => json_encode(['nueva:0', "actual:{$b}"]),
    ]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    $imagenes = $producto->fresh()->imagenes;

    expect($imagenes)->toHaveCount(2)
        ->and($imagenes[1])->toBe($b);

    Storage::disk('public')->assertMissing($a);   // la que salió del orden
});

test('el orden no puede nombrar una imagen que el producto no tiene', function () {
    $producto = Producto::factory()->create(['imagenes' => null]);

    $this->patchJson("/api/v1/productos/{$producto->id}", datosProducto([
        'codigo'         => $producto->codigo,
        'nombre'         => $producto->nombre,
        'categoria_id'   => $producto->categoria_id,
        'imagenes_orden' => json_encode(['actual:../../.env']),
    ]), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()->assertJsonValidationErrors('orden.0', 'errores');
});

test('un orden ilegible se rechaza en vez de tomarse como sin orden', function () {
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $this->patchJson("/api/v1/productos/{$producto->id}", datosProducto([
        'codigo'         => $producto->codigo,
        'nombre'         => $producto->nombre,
        'categoria_id'   => $producto->categoria_id,
        'imagenes_orden' => '{roto',
    ]), bearer(usuarioCon('producto.editar')))
        ->assertUnprocessable()->assertJsonValidationErrors('orden', 'errores');

    // Nada se tocó: la imagen sigue donde estaba.
    expect($producto->fresh()->imagenes)->toBe([$a]);
});

test('no se pueden dejar mas imagenes que el maximo', function () {
    $archivos = array_map(fn ($i) => UploadedFile::fake()->image("$i.png"), range(0, Producto::MAX_IMAGENES));

    $this->post('/api/v1/productos', datosProducto([
        'imagenes'       => $archivos,
        'imagenes_orden' => json_encode(array_map(fn ($i) => "nueva:$i", array_keys($archivos))),
    ]), bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()->assertJsonValidationErrors('orden', 'errores');

    expect(Producto::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('productos'))->toBe([]);
});

test('sin orden, las imagenes nuevas se agregan al final', function () {
    // Un cliente que no manda el orden (la app, un script): se puede agregar, no reordenar ni quitar.
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $this->post("/api/v1/productos/{$producto->id}", datosProducto([
        '_method'      => 'PATCH',
        'codigo'       => $producto->codigo,
        'nombre'       => $producto->nombre,
        'categoria_id' => $producto->categoria_id,
        'imagenes'     => [UploadedFile::fake()->image('b.png')],
    ]), bearer(usuarioCon('producto.editar')))
        ->assertSuccessful();

    expect($producto->fresh()->imagenes)->toHaveCount(2)
        ->and($producto->fresh()->imagenes[0])->toBe($a);
});

/*
|--------------------------------------------------------------------------
| Baja (M-16)
|--------------------------------------------------------------------------
*/

test('un producto sin stock ni historial se elimina', function () {
    $producto = Producto::factory()->sinStock()->create();

    $respuesta = $this->deleteJson("/api/v1/productos/{$producto->id}", [], bearer(usuarioCon('producto.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true);

    expect($respuesta->json('mensaje'))->toContain('eliminado');

    $this->assertModelMissing($producto);
});

test('un producto con stock o con historial se desactiva y el mensaje lo dice', function () {
    $conStock    = Producto::factory()->conStock(3)->create();
    $conHistoria = Producto::factory()->sinStock()->create();

    DB::table('movimientos_stock')->insert([
        'producto_id' => $conHistoria->id, 'tipo' => 'ajuste', 'cantidad' => -2,
        'stock_resultante' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $encabezado = bearer(usuarioCon('producto.eliminar'));

    foreach ([$conStock, $conHistoria] as $producto) {
        $respuesta = $this->deleteJson("/api/v1/productos/{$producto->id}", [], $encabezado)
            ->assertOk()
            ->assertJsonPath('datos.eliminado', false);
        app('auth')->forgetGuards();

        expect($respuesta->json('mensaje'))->toContain('se desactivó');

        $this->assertModelExists($producto);
        expect($producto->fresh()->activo)->toBeFalse();
    }
});

test('el listado avisa cuando un producto se repone y no se le puede comprar a nadie', function () {
    $conProveedor = Producto::factory()
        ->conProveedor(Proveedor::factory()->create())
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 10]);

    $sinProveedor = Producto::factory()
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 10]);

    // El aviso viaja por fila: la pantalla dice «Sin proveedor» donde la API lo marca.
    $filas = collect($this->getJson('/api/v1/productos?forma=gestion', bearer(usuarioCon('producto.ver')))
        ->assertOk()->json('datos'))->keyBy('id');

    expect($filas[$sinProveedor->id]['sin_proveedor'])->toBeTrue()
        ->and($filas[$conProveedor->id]['sin_proveedor'])->toBeFalse();
});
/*
|--------------------------------------------------------------------------
| Un recurso, representación por permiso (regla 36, tramo D)
|--------------------------------------------------------------------------
|
| `GET /productos` es el catálogo de la tienda; con `forma=gestion`, el de la administración.
| Lo que no se puede ver no viaja, ni en null: cada clave protegida, en las dos direcciones.
|
*/

test('sin forma=gestion es el catalogo de la tienda de siempre, aunque quien pregunte tenga producto.ver', function () {
    $visible  = Producto::factory()->conStockYCosto(5, 1000)->create();
    $inactivo = Producto::factory()->inactivo()->create();
    $usuario  = usuarioCon('producto.ver', 'precio.ver_costo', 'stock.ver', 'producto.editar');

    $respuesta = $this->getJson('/api/v1/productos', bearer($usuario))->assertOk();
    $fila      = $respuesta->json('datos.0');

    expect(collect($respuesta->json('datos'))->pluck('id')->all())->toBe([$visible->id])
        ->and($fila)->not->toHaveKeys(['costo_promedio', 'margen', 'stock', 'stock_reservado', 'activo', 'disponible'])
        ->and($respuesta->json('criterios'))->not->toHaveKey('forma');

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/productos/{$inactivo->id}", bearer($usuario))->assertNotFound();
});

// El visitante recibe 401 y no 403 (paso 10 del tramo D): lo que le falta es ingresar, igual que en
// una ruta protegida, y así el buscador conserva el 401 sin sesión que fijaba BusquedaTest.
test('forma=gestion sin producto.ver es 403 para la cuenta de tienda, y 401 para el visitante', function () {
    $producto = Producto::factory()->create();
    $tienda   = app(\App\Services\UsuarioService::class)->crear(datosDeClienteDeTienda());

    foreach (['/api/v1/productos?forma=gestion', "/api/v1/productos/{$producto->id}?forma=gestion"] as $url) {
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('codigo', 'sin_sesion');
        $this->getJson($url, bearer($tienda))->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
        app('auth')->forgetGuards();
    }
});

test('la gestion ve los inactivos, en el listado y en la ficha', function () {
    $inactivo   = Producto::factory()->inactivo()->create(['nombre' => 'Discontinuado']);
    $encabezado = bearer(usuarioCon('producto.ver'));

    $this->getJson('/api/v1/productos?forma=gestion&estado=inactivos', $encabezado)
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('datos.0.activo', false);

    $this->getJson("/api/v1/productos/{$inactivo->id}?forma=gestion", $encabezado)
        ->assertOk()->assertJsonPath('datos.nombre', 'Discontinuado');
});

test('quien tiene producto.ver ve lo de la pantalla de siempre: precios, disponible y minimo', function () {
    Producto::factory()->conStock(7, 2)->create(['precio_contado' => '1500.50', 'stock_minimo' => 3]);

    $this->getJson('/api/v1/productos?forma=gestion', bearer(usuarioCon('producto.ver')))
        ->assertOk()
        ->assertJsonPath('datos.0.precios.contado.importe', '1500.50')
        ->assertJsonPath('datos.0.disponible', 5)
        ->assertJsonPath('datos.0.stock_minimo', 3);
});

dataset('claves protegidas de productos', [
    'costo en el listado'     => ['precio.ver_costo', '/api/v1/productos?forma=gestion', 'datos.0', ['costo_promedio', 'margen']],
    'costo en la ficha'       => ['precio.ver_costo', '/api/v1/productos/{producto}?forma=gestion', 'datos', ['costo_promedio', 'margen', 'calculo']],
    'stock en el listado'     => ['stock.ver', '/api/v1/productos?forma=gestion', 'datos.0', ['stock', 'stock_reservado']],
    'stock en la ficha'       => ['stock.ver', '/api/v1/productos/{producto}?forma=gestion', 'datos', ['stock', 'stock_reservado']],
    'ganancia en la ficha'    => ['producto.editar', '/api/v1/productos/{producto}?forma=gestion', 'datos', ['ganancia', 'ganancia_aplicada', 'historial']],
]);

test('sin el permiso, la clave protegida no viaja, ni en null', function (string $permiso, string $url, string $donde, array $claves) {
    $producto = Producto::factory()->conStockYCosto(4, 1000)->create();

    $json = $this->getJson(str_replace('{producto}', $producto->id, $url), bearer(usuarioCon('producto.ver')))
        ->assertOk()->json($donde);

    expect($json)->not->toHaveKeys($claves);
})->with('claves protegidas de productos');

test('con el permiso, la clave protegida viaja', function (string $permiso, string $url, string $donde, array $claves) {
    $producto = Producto::factory()->conStockYCosto(4, 1000)->create();

    $json = $this->getJson(str_replace('{producto}', $producto->id, $url), bearer(usuarioCon('producto.ver', $permiso)))
        ->assertOk()->json($donde);

    expect($json)->toHaveKeys($claves);
})->with('claves protegidas de productos');

test('el costo y el calculo viajan como cadena', function () {
    $producto = Producto::factory()->conStockYCosto(4, 1000)->create();

    $ficha = $this->getJson("/api/v1/productos/{$producto->id}?forma=gestion", bearer(usuarioCon('producto.ver', 'precio.ver_costo')))
        ->assertOk()->json('datos');

    expect($ficha['costo_promedio'])->toBe('1000.00')
        ->and($ficha['calculo']['costo_neto'])->toBe('1000.00')
        ->and($ficha['calculo']['contado']['final'])->toBeString();
});

test('la ficha trae las imagenes con la ruta que el orden devuelve', function () {
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $this->getJson("/api/v1/productos/{$producto->id}?forma=gestion", bearer(usuarioCon('producto.ver')))
        ->assertOk()
        ->assertJsonPath('datos.imagenes.0.ruta', $a)
        ->assertJsonPath('datos.imagenes.0.url', url('storage/'.$a));
});

test('el alta devuelve la ficha de gestion y el mensaje', function () {
    $this->postJson('/api/v1/productos', datosProducto(), bearer(usuarioCon('producto.crear')))
        ->assertCreated()
        ->assertJsonPath('datos.codigo', 'SSD-1TB')
        ->assertJsonPath('datos.precio_manual', true)
        ->assertJsonPath('mensaje', 'Producto «Disco SSD 1TB» creado.');
});

test('activo y precio_manual tienen que venir: la ausencia no es «no»', function () {
    $datos = datosProducto();
    unset($datos['activo'], $datos['precio_manual']);

    $this->postJson('/api/v1/productos', $datos, bearer(usuarioCon('producto.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['activo', 'precio_manual'], 'errores');
});

test('las opciones del formulario traen la alicuota dada de baja que el producto ya tiene', function () {
    $producto = Producto::factory()->create(['alicuota_iva' => '99.00']);

    $alicuotas = collect($this->getJson("/api/v1/productos/opciones?producto={$producto->id}", bearer(usuarioCon('producto.ver')))
        ->assertOk()->json('datos.alicuotas'))->keyBy('valor');

    expect($alicuotas['99.00']['texto'])->toContain('dada de baja');
});

test('recalcular un precio manual no es un error: queda como estaba y el mensaje lo dice', function () {
    $producto = Producto::factory()->create(['precio_manual' => true, 'precio_contado' => '1500.00']);

    $respuesta = $this->postJson("/api/v1/productos/{$producto->id}/recalcular", [], bearer(usuarioCon('precio.recalcular')))
        ->assertOk()
        ->assertJsonPath('datos.aplicado', false)
        ->assertJsonPath('datos.resultado', 'manual');

    expect($respuesta->json('mensaje'))->toContain('fijado a mano')
        ->and($producto->fresh()->precio_contado)->toBe('1500.00');
});
