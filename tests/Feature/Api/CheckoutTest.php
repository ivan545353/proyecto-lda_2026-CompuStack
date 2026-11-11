<?php

use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Services\CarritoService;
use App\Services\CredencialService;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `POST /carrito/checkout` (tramo E): revalida y reserva.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->carritos = app(CarritoService::class);

    $this->tiendaProducto = fn (int $stock = 10, array $datos = []) => Producto::factory()->conStock($stock)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        ...$datos,
    ]);

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

test('el checkout pide sesion y tienda.comprar', function () {
    $this->postJson('/api/v1/carrito/checkout')->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');

    [, $sin] = ($this->tiendaCuentaCon)(['tienda.ver_precios']);
    $this->postJson('/api/v1/carrito/checkout', [], $sin)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
});

test('el checkout reserva y devuelve el resumen por medio, la entrega y el vencimiento', function () {
    $this->freezeSecond();
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 2);

    $datos = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos');

    expect($datos['estado'])->toBe('en_checkout')
        ->and($datos['estado_texto'])->toBe('Reservado mientras confirmás')
        ->and($datos['reservado_hasta'])->toBe(now()->addMinutes(20)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($datos['medios'])->toBe([
            ['medio' => 'contado', 'texto' => 'Efectivo o transferencia', 'total' => '108900.00'],
            ['medio' => 'pasarela', 'texto' => 'Mercado Pago', 'total' => '121000.00'],
        ])
        ->and($datos['entrega'])->toBe([
            ['modo' => 'retiro', 'texto' => 'Retiro en el local', 'costo' => '0.00'],
            ['modo' => 'envio', 'texto' => 'Envío a domicilio', 'costo' => '0.00'],
        ])
        ->and($producto->fresh()->stock_reservado)->toBe(2);
});

test('sin envio habilitado, la entrega es solo retiro', function () {
    config(['tienda.envio.habilitado' => false]);
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertOk()
        ->assertJsonPath('datos.entrega', [['modo' => 'retiro', 'texto' => 'Retiro en el local', 'costo' => '0.00']]);
});

test('el reintento devuelve el mismo resumen y no reserva dos veces', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 3);

    $primero = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos');
    $this->travel(2)->minutes();
    $segundo = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos');

    expect($segundo)->toBe($primero)
        ->and($producto->fresh()->stock_reservado)->toBe(3);
});

test('precio cambiado: 409 carrito_cambio con los cambios, y el segundo intento pasa', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 1);
    $producto->update(['precio_lista' => '62000.00']);

    $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'carrito_cambio')
        ->assertJsonPath('errores', [])
        ->assertJsonPath('cambios', [[
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'tipo' => 'precio',
            'lista' => 'lista', 'anterior' => '60500.00', 'actual' => '62000.00',
        ]]);

    $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertOk()
        ->assertJsonPath('datos.estado', 'en_checkout')
        ->assertJsonPath('datos.medios.1.total', '62000.00');
});

test('un producto que salio de la tienda es 409 carrito_cambio de tipo inactivo', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 1);
    $producto->update(['activo' => false]);

    $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('cambios.0.tipo', 'inactivo');
});

test('sin stock para todo es 409 stock_insuficiente y no reserva nada', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $hay    = ($this->tiendaProducto)(10);
    $escaso = ($this->tiendaProducto)(3);
    $this->carritos->agregar($cuenta, $hay->id, 2);
    $this->carritos->agregar($cuenta, $escaso->id, 3);
    app(StockService::class)->reservar($escaso, 1);

    $respuesta = $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'stock_insuficiente');

    // El mensaje no publica unidades.
    expect($respuesta->json('mensaje'))->not->toMatch('/hay \d/')
        ->and($hay->fresh()->stock_reservado)->toBe(0);
});

test('el checkout de un carrito vacio es 409 regla_de_negocio', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();

    $this->postJson('/api/v1/carrito/checkout', [], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');
});

test('un importe en el checkout es 422', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->postJson('/api/v1/carrito/checkout', ['total' => '1.00'], $bearer)
        ->assertStatus(422)
        ->assertJsonValidationErrors('total', 'errores');
});

test('escribir en el carrito despues del checkout suelta la reserva', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 2);
    $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk();

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => ($this->tiendaProducto)()->id, 'cantidad' => 1], $bearer)
        ->assertStatus(201)
        ->assertJsonPath('datos.estado', 'activo')
        ->assertJsonPath('datos.reservado_hasta', null);

    expect($producto->fresh()->stock_reservado)->toBe(0);
});

test('sin tienda.ver_precios, los medios no traen total', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $datos = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos');

    expect($datos['medios'])->toBe([
        ['medio' => 'contado', 'texto' => 'Efectivo o transferencia'],
        ['medio' => 'pasarela', 'texto' => 'Mercado Pago'],
    ])->and($datos)->not->toHaveKey('totales');
});

test('el checkout de un usuario no reserva el carrito de otro', function () {
    [$una] = ($this->tiendaCuentaCon)();
    [, $otra] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($una, $producto->id, 2);

    $this->postJson('/api/v1/carrito/checkout', [], $otra)->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');

    expect($producto->fresh()->stock_reservado)->toBe(0)
        ->and($this->carritos->paraLeer($una)->estado)->toBe('activo');
});
