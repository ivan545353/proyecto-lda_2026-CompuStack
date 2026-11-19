<?php

use App\Models\Carrito;
use App\Models\CarritoLinea;
use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Services\CarritoService;
use App\Services\CredencialService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Los endpoints del carrito (tramo E): `/carrito` y `/carrito/lineas`.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaProducto = fn (int $stock = 10, array $datos = []) => Producto::factory()->conStock($stock)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        ...$datos,
    ]);

    // Una cuenta de tienda y su encabezado. Sin rol, la de Cliente; con claves, un rol con sólo esas.
    $this->tiendaCuentaCon = function (?array $claves = null): array {
        $rol = Rol::where('nombre', 'Cliente')->firstOrFail();

        if ($claves !== null) {
            $rol = Rol::create(['nombre' => 'Tienda '.uniqid(), 'ambito' => 'tienda', 'es_sistema' => false]);
            $rol->permisos()->sync(Permiso::whereIn('clave', $claves)->pluck('id'));
        }

        $cuenta = User::factory()->create(['rol_id' => $rol->id]);
        app('auth')->forgetGuards();

        return [$cuenta, ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($cuenta)['credencial']]];
    };
});

// ---------- acceso ----------

test('el carrito pide sesion', function (string $metodo, string $url) {
    $this->json($metodo, $url, ['producto_id' => 1, 'cantidad' => 1])
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sin_sesion');
})->with([
    ['GET', '/api/v1/carrito'],
    ['DELETE', '/api/v1/carrito'],
    ['POST', '/api/v1/carrito/lineas'],
    ['PATCH', '/api/v1/carrito/lineas/1'],
    ['DELETE', '/api/v1/carrito/lineas/1'],
]);

test('sin tienda.comprar es 403, y con el permiso pasa', function (string $metodo, string $url) {
    [, $sin] = ($this->tiendaCuentaCon)(['tienda.ver_precios']);
    $this->json($metodo, $url, [], $sin)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');

    [, $con] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $this->json($metodo, $url, [], $con)->assertOk();
})->with([
    ['GET', '/api/v1/carrito'],
    ['DELETE', '/api/v1/carrito'],
]);

// ---------- GET /carrito ----------

test('sin carrito, devuelve uno vacio sin crearlo', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();

    $this->getJson('/api/v1/carrito', $bearer)
        ->assertOk()
        ->assertExactJson(['datos' => [
            'id'              => null,
            'estado'          => 'activo',
            'estado_texto'    => 'En armado',
            'reservado_hasta' => null,
            'unidades'        => 0,
            'renglones'       => [],
            'totales'         => [
                'contado' => ['importe' => '0.00', 'texto' => 'Efectivo o transferencia'],
                'lista'   => ['importe' => '0.00', 'texto' => 'Mercado Pago'],
            ],
            'avisos'          => [],
        ]]);

    expect(Carrito::count())->toBe(0);
});

test('el carrito trae los dos importes calculados con los precios vigentes', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $linea = app(CarritoService::class)->agregar($cuenta, $producto->id, 2);
    $producto->update(['precio_contado' => '55000.00']);

    $respuesta = $this->getJson('/api/v1/carrito', $bearer)->assertOk();

    $respuesta->assertJsonPath('datos.unidades', 2)
        ->assertJsonPath('datos.renglones.0', [
            'id'       => $linea->id,
            'cantidad' => 2,
            'producto' => [
                'id'                   => $producto->id,
                'codigo'               => $producto->codigo,
                'nombre'               => $producto->nombre,
                'imagen'               => $respuesta->json('datos.renglones.0.producto.imagen'),
                'en_tienda'            => true,
                'disponibilidad'       => 'disponible',
                'disponibilidad_texto' => 'Disponible',
            ],
            'precios'  => [
                'contado' => ['unitario' => '55000.00', 'subtotal' => '110000.00', 'texto' => 'Efectivo o transferencia'],
                'lista'   => ['unitario' => '60500.00', 'subtotal' => '121000.00', 'texto' => 'Mercado Pago'],
            ],
        ])
        ->assertJsonPath('datos.totales.contado.importe', '110000.00')
        ->assertJsonPath('datos.avisos.0.tipo', 'precio')
        ->assertJsonPath('datos.avisos.0.anterior', '54450.00');

    // Lo que nunca viaja (regla de la API).
    $json = json_encode($respuesta->json());
    foreach (['stock', 'stock_reservado', 'costo_promedio', 'referencia'] as $clave) {
        expect($json)->not->toContain("\"{$clave}");
    }
});

test('sin tienda.ver_precios no viajan precios, totales ni avisos de precio', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $producto = ($this->tiendaProducto)();
    app(CarritoService::class)->agregar($cuenta, $producto->id, 1);
    $producto->update(['precio_lista' => '61000.00']);

    $datos = $this->getJson('/api/v1/carrito', $bearer)->assertOk()->json('datos');

    expect($datos)->not->toHaveKey('totales')
        ->and($datos['renglones'][0])->not->toHaveKey('precios')
        ->and($datos['avisos'])->toBe([]);
});

test('dos usuarios no ven el carrito del otro', function () {
    [$una] = ($this->tiendaCuentaCon)();
    [, $otra] = ($this->tiendaCuentaCon)();
    app(CarritoService::class)->agregar($una, ($this->tiendaProducto)()->id, 3);

    $this->getJson('/api/v1/carrito', $otra)->assertOk()->assertJsonPath('datos.renglones', []);
});

// ---------- POST /carrito/lineas ----------

test('agregar crea el renglon con 201 y agregar de nuevo suma con 200', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 2], $bearer)
        ->assertStatus(201)
        ->assertJsonPath('mensaje', 'Agregaste el producto al carrito.')
        ->assertJsonPath('datos.unidades', 2);

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 3], $bearer)
        ->assertOk()
        ->assertJsonPath('datos.renglones.0.cantidad', 5)
        ->assertJsonCount(1, 'datos.renglones');
});

test('agregar un producto que no esta en la tienda es 404', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();
    $inactivo = ($this->tiendaProducto)(datos: ['activo' => false]);

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $inactivo->id, 'cantidad' => 1], $bearer)
        ->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => 999999, 'cantidad' => 1], $bearer)
        ->assertStatus(404);
});

test('agregar sin stock es 409 stock_insuficiente', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)(1);

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 2], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'stock_insuficiente');

    expect(CarritoLinea::count())->toBe(0);
});

test('pasarse del maximo al sumar es 409 regla_de_negocio', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)(50);
    app(CarritoService::class)->agregar($cuenta, $producto->id, 8);

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 3], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');
});

test('una solicitud mal formada es 422', function (array $cuerpo, string $campo) {
    [, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 1, ...$cuerpo], $bearer)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors($campo, 'errores');

    expect(CarritoLinea::count())->toBe(0);
})->with([
    'sin cantidad'      => [['cantidad' => null], 'cantidad'],
    'cantidad cero'     => [['cantidad' => 0], 'cantidad'],
    'cantidad decimal'  => [['cantidad' => 1.5], 'cantidad'],
    'sobre el maximo'   => [['cantidad' => 11], 'cantidad'],
    'producto texto'    => [['producto_id' => 'mouse'], 'producto_id'],
]);

test('un importe en la solicitud es 422, no se ignora', function (string $campo) {
    [, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $producto->id, 'cantidad' => 1, $campo => '1.00'], $bearer)
        ->assertStatus(422)
        ->assertJsonValidationErrors($campo, 'errores');

    $linea = CarritoLinea::query()->first();
    expect($linea)->toBeNull();
})->with(['precio', 'precio_contado', 'precio_lista', 'contado_referencia', 'importe', 'total']);

// ---------- PATCH y DELETE /carrito/lineas/{linea} ----------

test('cambiar la cantidad devuelve el carrito con la nueva', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $linea = app(CarritoService::class)->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->patchJson("/api/v1/carrito/lineas/{$linea->id}", ['cantidad' => 4], $bearer)
        ->assertOk()
        ->assertJsonPath('datos.renglones.0.cantidad', 4)
        ->assertJsonPath('datos.totales.contado.importe', '217800.00');
});

test('un importe al cambiar la cantidad tambien es 422', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $linea = app(CarritoService::class)->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->patchJson("/api/v1/carrito/lineas/{$linea->id}", ['cantidad' => 2, 'precio_lista' => '1.00'], $bearer)
        ->assertStatus(422)
        ->assertJsonValidationErrors('precio_lista', 'errores');
});

test('quitar saca el renglon', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $linea = app(CarritoService::class)->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->deleteJson("/api/v1/carrito/lineas/{$linea->id}", [], $bearer)
        ->assertOk()
        ->assertJsonPath('mensaje', 'Quitaste el producto del carrito.')
        ->assertJsonPath('datos.renglones', []);
});

test('un renglon ajeno es 404 al cambiarlo y al quitarlo, y no cambia', function () {
    [$duena] = ($this->tiendaCuentaCon)();
    [, $otra] = ($this->tiendaCuentaCon)();
    $linea = app(CarritoService::class)->agregar($duena, ($this->tiendaProducto)()->id, 2);

    $this->patchJson("/api/v1/carrito/lineas/{$linea->id}", ['cantidad' => 1], $otra)->assertStatus(404);
    $this->deleteJson("/api/v1/carrito/lineas/{$linea->id}", [], $otra)->assertStatus(404);

    expect($linea->fresh()->cantidad)->toBe(2);
});

// ---------- DELETE /carrito ----------

test('vaciar saca todos los renglones y el carrito queda', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    app(CarritoService::class)->agregar($cuenta, ($this->tiendaProducto)()->id, 1);
    app(CarritoService::class)->agregar($cuenta, ($this->tiendaProducto)()->id, 2);

    $this->deleteJson('/api/v1/carrito', [], $bearer)
        ->assertOk()
        ->assertJsonPath('mensaje', 'Vaciaste el carrito.')
        ->assertJsonPath('datos.unidades', 0);

    expect(CarritoLinea::count())->toBe(0)
        ->and(Carrito::where('user_id', $cuenta->id)->count())->toBe(1);
});
