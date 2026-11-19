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

dataset('rutas de productos', [
    'listado'         => ['get',    'productos.index',   'producto.ver',      false],
    'formulario alta' => ['get',    'productos.create',  'producto.crear',    false],
    'alta'            => ['post',   'productos.store',   'producto.crear',    false],
    'formulario edic' => ['get',    'productos.edit',    'producto.editar',   true],
    'edicion'         => ['put',    'productos.update',  'producto.editar',   true],
    'baja'            => ['delete', 'productos.destroy', 'producto.eliminar', true],
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

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $producto = Producto::factory()->create();

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index'))
        ->assertOk()
        ->assertDontSee(route('productos.create'))
        ->assertDontSee(route('productos.edit', $producto));
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

    $usuario = usuarioCon('producto.ver');

    $this->actingAs($usuario)->get(route('productos.index', ['q' => 'disco']))
        ->assertViewHas('productos', fn ($p) => $p->total() === 1);

    $this->actingAs($usuario)->get(route('productos.index', ['q' => 'g505']))
        ->assertViewHas('productos', fn ($p) => $p->total() === 1);
});

test('el parametro categoria_id incluye las subcategorias', function () {
    $almacenamiento = Categoria::factory()->create();
    $ssd            = Categoria::factory()->hijaDe($almacenamiento)->create();

    Producto::factory()->create(['categoria_id' => $ssd->id]);
    Producto::factory()->create();

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['categoria_id' => $almacenamiento->id]))
        ->assertViewHas('productos', fn ($p) => $p->total() === 1);
});

test('los parametros marca_id, estado y stock llegan a sus filtros', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->conStock(5)->create(['marca_id' => $marca->id]);
    Producto::factory()->sinStock()->inactivo()->create();

    $usuario = usuarioCon('producto.ver');

    foreach ([
        ['marca_id' => $marca->id],
        ['estado' => 'activos'],
        ['stock' => 'disponible'],
    ] as $filtro) {
        $this->actingAs($usuario)->get(route('productos.index', $filtro))
            ->assertViewHas('productos', fn ($p) => $p->total() === 1);
    }
});

test('el parametro proveedor_id llega al filtro', function () {
    $proveedor = Proveedor::factory()->create();

    Producto::factory()->conProveedor($proveedor)->create();
    Producto::factory()->create();

    // El nivel que A-24 necesitaba: el scope puede funcionar y el cableado no.
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['proveedor_id' => $proveedor->id]))
        ->assertViewHas('productos', fn ($p) => $p->total() === 1);
});

test('el parametro stock=sin_proveedor llega al filtro', function () {
    Producto::factory()->conStock(0)->create(['stock_minimo' => 5]);
    Producto::factory()->conStock(0)->conProveedor(Proveedor::factory()->create())
        ->create(['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['stock' => 'sin_proveedor']))
        ->assertViewHas('productos', fn ($p) => $p->total() === 1);
});

test('un filtro que no es valido vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['stock' => 'cualquiera']))
        ->assertRedirect(route('productos.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 20 y los enlaces conservan el filtro', function () {
    Producto::factory()->count(25)->create(['activo' => true]);

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['estado' => 'activos']))
        ->assertViewHas('productos', fn ($p) => $p->count() === 20
            && $p->total() === 25
            && str_contains($p->nextPageUrl(), 'estado=activos'));
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
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['precio_contado' => $escrito]))
        ->assertSessionHasNoErrors();

    expect(Producto::first()->precio_contado)->toBe($guardado);
})->with('precios bien escritos');

test('rechaza un precio ambiguo en vez de adivinar', function () {
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['precio_contado' => '1,500.50']))
        ->assertSessionHasErrors('precio_contado');

    expect(Producto::count())->toBe(0);
});

test('rechaza un precio con tres decimales en vez de redondearlo', function () {
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['precio_contado' => '1500.505']))
        ->assertSessionHasErrors('precio_contado');
});

test('el precio de lista ya no se carga a mano', function () {
    // Prohibido y no ignorado: un formulario viejo que todavía lo mande recibe
    // un mensaje, en vez de guardar un precio de lista que nadie eligió.
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['precio_lista' => '120000']))
        ->assertSessionHasErrors('precio_lista');

    expect(Producto::count())->toBe(0);
});

test('un producto nuevo no puede nacer calculando su precio solo', function () {
    // No tiene costo —esa columna sólo la mueve StockService al recibir
    // mercadería—, así que el motor no tendría con qué calcularlo.
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['precio_manual' => 0, 'precio_contado' => null]))
        ->assertSessionHasErrors('precio_manual');
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

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosProducto([
            'codigo'         => $producto->codigo,
            'nombre'         => $producto->nombre,
            'categoria_id'   => $producto->categoria_id,
            'precio_contado' => '1.500,50',        // como lo muestra el formulario
            'alicuota_iva'   => '10.50',
        ]))
        ->assertSessionHasNoErrors();

    expect($producto->fresh()->alicuota_iva)->toBe('10.50');
});

test('el codigo se guarda en mayusculas y no se repite sin importar como se escriba', function () {
    $usuario = usuarioCon('producto.crear');

    $this->actingAs($usuario)->post(route('productos.store'), datosProducto(['codigo' => 'ssd-1tb']));

    expect(Producto::first()->codigo)->toBe('SSD-1TB');

    $this->actingAs($usuario)
        ->post(route('productos.store'), datosProducto(['codigo' => 'Ssd-1Tb', 'nombre' => 'Otro']))
        ->assertSessionHasErrors('codigo');
});

test('si hay stock minimo, la cantidad a reponer no puede ser cero', function () {
    // Un producto con mínimo pero sin cantidad a pedir nunca se repondría:
    // el error aparecería semanas después, con el producto agotado.
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['stock_minimo' => 5, 'cantidad_reposicion' => 0]))
        ->assertSessionHasErrors('cantidad_reposicion');
});

test('el stock no se puede cargar desde el formulario', function () {
    // Sólo lo mueve el StockService, dejando movimiento en el kardex (A-13).
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['stock' => 999, 'costo_promedio' => 500]));

    expect(Producto::first()->stock)->toBe(0)
        ->and(Producto::first()->costo_promedio)->toBe('0.00');
});

test('una marca inactiva no se puede elegir, pero la que ya tenia se conserva', function () {
    $inactiva = Marca::factory()->inactiva()->create();
    $otra     = Marca::factory()->inactiva()->create();
    $producto = Producto::factory()->create(['marca_id' => $inactiva->id]);

    $usuario = usuarioCon('producto.editar');
    $base = datosProducto([
        'codigo'       => $producto->codigo,
        'nombre'       => $producto->nombre,
        'categoria_id' => $producto->categoria_id,
    ]);

    // Su marca actual, aunque esté inactiva: se puede seguir editando.
    $this->actingAs($usuario)
        ->put(route('productos.update', $producto), [...$base, 'marca_id' => $inactiva->id])
        ->assertSessionHasNoErrors();

    // Otra marca inactiva: no.
    $this->actingAs($usuario)
        ->put(route('productos.update', $producto), [...$base, 'marca_id' => $otra->id])
        ->assertSessionHasErrors('marca_id');
});

test('ante un error se conserva lo que el usuario habia escrito', function () {
    // M-31 en la interfaz: equivocarse en un campo no borra los demás.
    $this->actingAs(usuarioCon('producto.crear'))
        ->from(route('productos.create'))
        ->post(route('productos.store'), datosProducto(['nombre' => '', 'precio_contado' => '1.500,50']))
        ->assertSessionHasErrors('nombre')
        ->assertSessionHasInput('precio_contado', '1.500,50');
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

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosParaEditar($producto, ['destacado' => 1]))
        ->assertSessionHasNoErrors();

    expect($producto->fresh()->destacado)->toBeTrue();
});

test('la edición quita el destacado cuando la casilla llega destildada', function () {
    $producto = Producto::factory()->create(['destacado' => true]);

    // Un checkbox destildado no se envía: la ausencia de la clave es «no».
    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosParaEditar($producto))
        ->assertSessionHasNoErrors();

    expect($producto->fresh()->destacado)->toBeFalse();
});

test('el alta de quien puede crear y editar guarda el destacado', function () {
    $this->actingAs(usuarioCon('producto.crear', 'producto.editar'))
        ->post(route('productos.store'), datosProducto(['destacado' => 1]))
        ->assertSessionHasNoErrors();

    expect(Producto::first()->destacado)->toBeTrue();
});

test('el alta sin la casilla deja el producto sin destacar', function () {
    $this->actingAs(usuarioCon('producto.crear', 'producto.editar'))
        ->post(route('productos.store'), datosProducto());

    expect(Producto::first()->destacado)->toBeFalse();
});

test('quien sólo puede crear no logra destacar ni con un pedido armado a mano', function () {
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto(['destacado' => 1]))
        ->assertSessionHasNoErrors();

    expect(Producto::count())->toBe(1)
        ->and(Producto::first()->destacado)->toBeFalse();
});

test('editar otros campos no toca el destacado si la casilla llega tildada', function () {
    $producto = Producto::factory()->create(['destacado' => true, 'nombre' => 'Nombre viejo']);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosParaEditar($producto, ['nombre' => 'Nombre nuevo', 'destacado' => 1]))
        ->assertSessionHasNoErrors();

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

    expect($this->actingAs($usuario)->get(route('productos.edit', $destacado))->getContent())
        ->toMatch('/<input[^>]*id="destacado"[^>]*checked/')
        ->and($this->actingAs($usuario)->get(route('productos.edit', $comun))->getContent())
        ->not->toMatch('/<input[^>]*id="destacado"[^>]*checked/');
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

    $this->actingAs(usuarioCon('producto.editar'))
        ->from(route('productos.edit', $producto))
        ->put(route('productos.update', $producto), datosParaEditar($producto, ['nombre' => '']))
        ->assertSessionHasErrors('nombre');

    // El usuario había destildado: después del error sigue destildada, no vuelve al valor guardado.
    expect($this->get(route('productos.edit', $producto))->getContent())
        ->not->toMatch('/<input[^>]*id="destacado"[^>]*checked/');
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
    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto([
            'imagenes' => [
                UploadedFile::fake()->image('primera.png'),
                UploadedFile::fake()->image('segunda.png'),
            ],
            'imagenes_orden' => json_encode(['nueva:1', 'nueva:0']),
        ]))
        ->assertSessionHasNoErrors();

    $imagenes = Producto::first()->imagenes;

    expect($imagenes)->toHaveCount(2);
    Storage::disk('public')->assertExists($imagenes[0]);
});

test('editar reordena, agrega y quita en una sola pasada', function () {
    $a = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $b = UploadedFile::fake()->image('b.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a, $b]]);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosProducto([
            'codigo'         => $producto->codigo,
            'nombre'         => $producto->nombre,
            'categoria_id'   => $producto->categoria_id,
            'imagenes'       => [UploadedFile::fake()->image('c.png')],
            'imagenes_orden' => json_encode(['nueva:0', "actual:{$b}"]),
        ]))
        ->assertSessionHasNoErrors();

    $imagenes = $producto->fresh()->imagenes;

    expect($imagenes)->toHaveCount(2)
        ->and($imagenes[1])->toBe($b);

    Storage::disk('public')->assertMissing($a);   // la que salió del orden
});

test('el orden no puede nombrar una imagen que el producto no tiene', function () {
    $producto = Producto::factory()->create(['imagenes' => null]);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosProducto([
            'codigo'         => $producto->codigo,
            'nombre'         => $producto->nombre,
            'categoria_id'   => $producto->categoria_id,
            'imagenes_orden' => json_encode(['actual:../../.env']),
        ]))
        ->assertSessionHasErrors('orden.0');
});

test('un orden ilegible se rechaza en vez de tomarse como sin orden', function () {
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosProducto([
            'codigo'         => $producto->codigo,
            'nombre'         => $producto->nombre,
            'categoria_id'   => $producto->categoria_id,
            'imagenes_orden' => '{roto',
        ]))
        ->assertSessionHasErrors('orden');

    // Nada se tocó: la imagen sigue donde estaba.
    expect($producto->fresh()->imagenes)->toBe([$a]);
});

test('no se pueden dejar mas imagenes que el maximo', function () {
    $archivos = array_map(fn ($i) => UploadedFile::fake()->image("$i.png"), range(0, Producto::MAX_IMAGENES));

    $this->actingAs(usuarioCon('producto.crear'))
        ->post(route('productos.store'), datosProducto([
            'imagenes'       => $archivos,
            'imagenes_orden' => json_encode(array_map(fn ($i) => "nueva:$i", array_keys($archivos))),
        ]))
        ->assertSessionHasErrors('orden');

    expect(Producto::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('productos'))->toBe([]);
});

test('sin orden, las imagenes nuevas se agregan al final', function () {
    // Es el caso sin JavaScript: se puede agregar, no reordenar ni quitar.
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $this->actingAs(usuarioCon('producto.editar'))
        ->put(route('productos.update', $producto), datosProducto([
            'codigo'       => $producto->codigo,
            'nombre'       => $producto->nombre,
            'categoria_id' => $producto->categoria_id,
            'imagenes'     => [UploadedFile::fake()->image('b.png')],
        ]))
        ->assertSessionHasNoErrors();

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

    $this->actingAs(usuarioCon('producto.eliminar'))
        ->delete(route('productos.destroy', $producto))
        ->assertSessionHas('exito', fn ($m) => str_contains($m, 'eliminado'));

    $this->assertModelMissing($producto);
});

test('un producto con stock o con historial se desactiva y el mensaje lo dice', function () {
    $conStock    = Producto::factory()->conStock(3)->create();
    $conHistoria = Producto::factory()->sinStock()->create();

    DB::table('movimientos_stock')->insert([
        'producto_id' => $conHistoria->id, 'tipo' => 'ajuste', 'cantidad' => -2,
        'stock_resultante' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $usuario = usuarioCon('producto.eliminar');

    foreach ([$conStock, $conHistoria] as $producto) {
        $this->actingAs($usuario)
            ->delete(route('productos.destroy', $producto))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'se desactivó'));

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

    $usuario = usuarioCon('producto.ver');

    // Se filtra de a uno con ?q= para que el aviso de una fila no se confunda con
    // el de la otra: assertSee mira la página entera.
    $this->actingAs($usuario)
        ->get(route('productos.index', ['q' => $sinProveedor->codigo]))
        ->assertOk()
        ->assertSee('Sin proveedor');

    $this->actingAs($usuario)
        ->get(route('productos.index', ['q' => $conProveedor->codigo]))
        ->assertOk()
        ->assertDontSee('Sin proveedor');
});