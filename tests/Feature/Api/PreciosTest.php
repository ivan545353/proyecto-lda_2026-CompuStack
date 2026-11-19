<?php

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\CredencialService;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Los precios del catálogo: viajan sólo con `tienda.ver_precios` (regla 21, docs/api.md §5).

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaProducto = fn (array $datos = []) => Producto::factory()->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        ...$datos,
    ]);

    // Encabezado Bearer de una cuenta de tienda con el rol que se pase (por omisión, Cliente).
    $this->tiendaBearer = function (?Rol $rol = null): array {
        $datos = $rol === null ? datosDeClienteDeTienda() : datosDeClienteDeTienda(['rol_id' => $rol->id]);
        $cuenta = app(UsuarioService::class)->crear($datos);

        return ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($cuenta)['credencial']];
    };

    // Un rol de tienda sin ningún permiso: tiene sesión, pero no ve precios.
    $this->tiendaRolSinPermiso = fn () => Rol::create([
        'nombre' => 'Tienda sin precios', 'ambito' => 'tienda', 'es_sistema' => false,
    ]);

    $this->tiendaEsperado = [
        'contado' => ['importe' => '54450.00', 'texto' => 'Efectivo o transferencia'],
        'lista'   => ['importe' => '60500.00', 'texto' => 'Mercado Pago'],
    ];
});

test('con el permiso, el listado trae los dos precios como cadena con su texto', function () {
    ($this->tiendaProducto)();

    $this->getJson('/api/v1/productos', ($this->tiendaBearer)())
        ->assertOk()
        ->assertJsonPath('datos.0.precios', $this->tiendaEsperado);
});

test('con el permiso, la ficha trae los dos precios como cadena con su texto', function () {
    $producto = ($this->tiendaProducto)();

    $this->getJson('/api/v1/productos/'.$producto->id, ($this->tiendaBearer)())
        ->assertOk()
        ->assertJsonPath('datos.precios', $this->tiendaEsperado);
});

test('sin credencial la clave precios no está, ni en null', function (string $ruta) {
    $producto = ($this->tiendaProducto)();
    $url      = str_replace('{id}', (string) $producto->id, $ruta);
    $datos    = $this->getJson($url)->assertOk()->json('datos');

    $fila = $ruta === '/api/v1/productos' ? $datos[0] : $datos;
    expect($fila)->not->toHaveKey('precios');
})->with(['listado' => '/api/v1/productos', 'ficha' => '/api/v1/productos/{id}']);

test('con sesión de un rol sin el permiso la clave precios no está', function (string $ruta) {
    $producto = ($this->tiendaProducto)();
    $url      = str_replace('{id}', (string) $producto->id, $ruta);
    $datos    = $this->getJson($url, ($this->tiendaBearer)(($this->tiendaRolSinPermiso)()))->assertOk()->json('datos');

    $fila = $ruta === '/api/v1/productos' ? $datos[0] : $datos;
    expect($fila)->not->toHaveKey('precios');
})->with(['listado' => '/api/v1/productos', 'ficha' => '/api/v1/productos/{id}']);

test('con el permiso viajan los precios y nada más de lo prohibido', function (string $ruta) {
    $producto = ($this->tiendaProducto)();
    $url      = str_replace('{id}', (string) $producto->id, $ruta);
    $datos    = $this->getJson($url, ($this->tiendaBearer)())->assertOk()->json('datos');

    $fila = $ruta === '/api/v1/productos' ? $datos[0] : $datos;
    expect(array_keys($fila['precios']))->toBe(['contado', 'lista'])
        ->and(array_keys($fila))->not->toContain('costo', 'costo_promedio', 'ganancia', 'stock', 'stock_reservado',
            'stock_minimo', 'precio_lista', 'precio_contado', 'precio_manual', 'proveedores');
})->with(['listado' => '/api/v1/productos', 'ficha' => '/api/v1/productos/{id}']);

test('un importe sin decimales viaja con los dos decimales', function () {
    ($this->tiendaProducto)(['precio_contado' => 1000, 'precio_lista' => 1200]);

    $this->getJson('/api/v1/productos', ($this->tiendaBearer)())
        ->assertJsonPath('datos.0.precios.contado.importe', '1000.00')
        ->assertJsonPath('datos.0.precios.lista.importe', '1200.00');
});

// --- Orden por precio: exige el permiso, porque el orden revela el precio -----

test('ordenar por precio sin sesión es 401 sin_sesion, sin consultar productos', function (string $orden) {
    ($this->tiendaProducto)();

    $consultas = consultasSobre('`productos`', function () use ($orden) {
        $this->getJson('/api/v1/productos?orden='.$orden)
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'sin_sesion');
    });

    expect($consultas)->toBe(0);
})->with(['precio_asc', 'precio_desc']);

test('ordenar por precio sin el permiso es 403 sin_permiso, sin consultar productos', function (string $orden) {
    ($this->tiendaProducto)();
    $encabezado = ($this->tiendaBearer)(($this->tiendaRolSinPermiso)());

    $consultas = consultasSobre('`productos`', function () use ($orden, $encabezado) {
        $this->getJson('/api/v1/productos?orden='.$orden, $encabezado)
            ->assertStatus(403)
            ->assertJsonPath('codigo', 'sin_permiso');
    });

    expect($consultas)->toBe(0);
})->with(['precio_asc', 'precio_desc']);

test('ordenar por precio con una credencial inválida es 401 sesion_invalida', function () {
    $this->getJson('/api/v1/productos?orden=precio_asc', ['Authorization' => 'Bearer 1|no-sirve'])
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sesion_invalida');
});

test('con el permiso ordena por el precio de contado, y a igual precio por id', function () {
    // El de lista va al revés del de contado: si el orden lo usara, el resultado se invertiría.
    $caro   = ($this->tiendaProducto)(['precio_contado' => 3000, 'precio_lista' => 1000]);
    $barato = ($this->tiendaProducto)(['precio_contado' => 1000, 'precio_lista' => 3000]);
    $medioA = ($this->tiendaProducto)(['precio_contado' => 2000, 'precio_lista' => 2000]);
    $medioB = ($this->tiendaProducto)(['precio_contado' => 2000, 'precio_lista' => 2000]);
    $encabezado = ($this->tiendaBearer)();

    $ids = fn (string $orden) => collect($this->getJson('/api/v1/productos?orden='.$orden, $encabezado)
        ->assertOk()
        ->assertJsonPath('criterios.orden', $orden)
        ->json('datos'))->pluck('id')->all();

    expect($ids('precio_asc'))->toBe([$barato->id, $medioA->id, $medioB->id, $caro->id]);
    app('auth')->forgetGuards();
    expect($ids('precio_desc'))->toBe([$caro->id, $medioB->id, $medioA->id, $barato->id]);
});
