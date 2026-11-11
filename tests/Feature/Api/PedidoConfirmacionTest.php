<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\CredencialService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `POST /pedidos` (tramo F): el checkout se convierte en una venta online, una sola vez.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->carritos = app(CarritoService::class);

    $this->tiendaProducto = fn (int $stock = 10, array $datos = []) => Producto::factory()->conStockYCosto($stock, 30000)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        'alicuota_iva'   => 21,
        ...$datos,
    ]);

    $this->tiendaCuentaCon = function (?array $claves = null): array {
        $rol = Rol::where('nombre', 'Cliente')->firstOrFail();

        if ($claves !== null) {
            $rol = Rol::create(['nombre' => 'Tienda '.uniqid(), 'ambito' => 'tienda', 'es_sistema' => false]);
            $rol->permisos()->sync(Permiso::whereIn('clave', $claves)->pluck('id'));
        }

        $cuenta = User::factory()->create(['rol_id' => $rol->id]);
        Cliente::factory()->create(['user_id' => $cuenta->id]);
        app('auth')->forgetGuards();

        return [$cuenta, ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($cuenta)['credencial']]];
    };

    // Un carrito con un producto, ya en checkout por el servicio: devuelve su id.
    $this->tiendaEnCheckout = function (User $cuenta, Producto $producto, int $cantidad = 2): int {
        $this->carritos->agregar($cuenta, $producto->id, $cantidad);
        $this->carritos->iniciarCheckout($cuenta);

        return $this->carritos->paraLeer($cuenta)->id;
    };

    $this->tiendaDireccion = [
        'calle' => 'San Martín', 'numero' => '1234', 'piso_depto' => null,
        'codigo_postal' => '9000', 'localidad' => 'Comodoro Rivadavia', 'provincia' => 'Chubut',
    ];
});

// ---------- acceso ----------

test('confirmar pide sesion y tienda.comprar', function () {
    $this->postJson('/api/v1/pedidos')->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');

    [, $sin] = ($this->tiendaCuentaCon)(['tienda.ver_precios']);
    $this->postJson('/api/v1/pedidos', [], $sin)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
});

// ---------- el pedido ----------

test('el id que trae el checkout es el que confirma el pedido', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $id = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos.id');

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $id, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertCreated();

    expect($id)->toBeInt()->and(Venta::sole()->carrito_id)->toBe($id);
});

test('confirmar de contado crea el pedido y responde 201 con el contrato', function () {
    $this->freezeSecond();
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto  = ($this->tiendaProducto)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, $producto, 2);

    $respuesta = $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertCreated();

    $venta = Venta::sole();

    $respuesta->assertExactJson(['datos' => [
        'id'                 => $venta->id,
        'numero'             => $venta->numeroFormateado(),
        'estado'             => 'pendiente_pago',
        'estado_texto'       => 'Esperando el pago',
        'medio_pago'         => 'contado',
        'medio_pago_texto'   => 'Efectivo o transferencia',
        'modo_entrega'       => 'retiro',
        'modo_entrega_texto' => 'Retiro en el local',
        'direccion'          => null,
        'reserva_vence'      => now()->addHours(48)->utc()->format('Y-m-d\TH:i:s\Z'),
        'creado'             => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'lineas'             => [[
            'producto_id'     => $producto->id,
            'descripcion'     => $producto->nombre,
            'cantidad'        => 2,
            'precio_unitario' => '54450.00',
            'total'           => '108900.00',
        ]],
        'importes' => ['subtotal' => '108900.00', 'costo_envio' => '0.00', 'total' => '108900.00'],
    ]]);

    expect($venta->canal)->toBe('online')
        ->and($venta->usuario_id)->toBeNull()
        ->and($venta->cliente_id)->toBe($cuenta->cliente->id)
        ->and($venta->carrito_id)->toBe($carritoId)
        ->and($venta->lineas()->first()->impuestos()->count())->toBeGreaterThan(0);
});

test('por pasarela, los renglones se congelan a precio de lista', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)(), 1);

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'pasarela', 'modo_entrega' => 'retiro',
    ], $bearer)
        ->assertCreated()
        ->assertJsonPath('datos.medio_pago_texto', 'Mercado Pago')
        ->assertJsonPath('datos.lineas.0.precio_unitario', '60500.00')
        ->assertJsonPath('datos.importes.total', '60500.00');
});

test('con envio, suma el costo de config y congela la direccion', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)(), 1);

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'envio',
        'direccion'  => $this->tiendaDireccion,
    ], $bearer)
        ->assertCreated()
        ->assertJsonPath('datos.importes.costo_envio', '8000.00')
        ->assertJsonPath('datos.importes.total', '62450.00')
        ->assertJsonPath('datos.direccion', $this->tiendaDireccion);
});

test('dos confirmaciones del mismo carrito dan un pedido: la segunda es 200 con el mismo numero', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());
    $cuerpo    = ['carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro'];

    $primera = $this->postJson('/api/v1/pedidos', $cuerpo, $bearer)->assertCreated()->json('datos.numero');
    $segunda = $this->postJson('/api/v1/pedidos', $cuerpo, $bearer)->assertOk()->json('datos.numero');

    expect($segunda)->toBe($primera)->and(Venta::count())->toBe(1);
});

test('sin tienda.ver_precios, el pedido viaja sin importes', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $datos = $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertCreated()->json('datos');

    expect($datos)->not->toHaveKey('importes')
        ->and($datos['lineas'][0])->not->toHaveKeys(['precio_unitario', 'total']);
});

test('nunca viajan el costo, el vendedor ni el stock', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $json = $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->getContent();

    expect($json)->not->toContain('costo_unitario', 'costo_promedio', 'usuario_id', 'stock', 'ganancia');
});

// ---------- conflictos ----------

test('un precio cambiado es 409 carrito_cambio y no crea el pedido', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto  = ($this->tiendaProducto)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, $producto);
    $producto->update(['precio_contado' => '56000.00']);

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertStatus(409)->assertJsonPath('codigo', 'carrito_cambio');

    expect(Venta::count())->toBe(0);
});

test('un checkout vencido es 409 checkout_vencido', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());
    $this->travel(21)->minutes();

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertStatus(409)->assertJsonPath('codigo', 'checkout_vencido');

    expect(Venta::count())->toBe(0);
});

test('el carrito de otra cuenta es 404, igual que uno que no existe', function () {
    [$duena] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($duena, ($this->tiendaProducto)());
    [, $otra] = ($this->tiendaCuentaCon)();

    foreach ([$carritoId, 999999] as $id) {
        $this->postJson('/api/v1/pedidos', [
            'carrito_id' => $id, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
        ], $otra)->assertNotFound()->assertJsonPath('codigo', 'no_encontrado');
    }

    expect(Venta::count())->toBe(0);
});

// ---------- validación ----------

test('un importe en la solicitud es 422', function (string $campo) {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro', $campo => '1.00',
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors($campo, 'errores');

    expect(Venta::count())->toBe(0);
})->with(['total', 'precio_unitario' => 'precio', 'costo_envio']);

test('faltan el carrito, el medio o la entrega: 422', function () {
    [, $bearer] = ($this->tiendaCuentaCon)();

    $this->postJson('/api/v1/pedidos', [], $bearer)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['carrito_id', 'medio_pago', 'modo_entrega'], 'errores');
});

test('un medio o una entrega fuera de la lista es 422', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'cheque', 'modo_entrega' => 'drone',
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors(['medio_pago', 'modo_entrega'], 'errores');
});

test('un envio sin direccion completa es 422', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'envio',
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors('direccion', 'errores');

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'envio',
        'direccion'  => [...$this->tiendaDireccion, 'calle' => '', 'codigo_postal' => str_repeat('9', 11)],
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors(['direccion.calle', 'direccion.codigo_postal'], 'errores');
});

test('con retiro, mandar una direccion se rechaza', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
        'direccion'  => $this->tiendaDireccion,
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors('direccion', 'errores');
});

test('sin envio habilitado, pedir envio es 422', function () {
    config(['tienda.envio.habilitado' => false]);
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $carritoId = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)());

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'envio',
        'direccion'  => $this->tiendaDireccion,
    ], $bearer)->assertStatus(422)->assertJsonValidationErrors('modo_entrega', 'errores');
});

// ---------- la frase de la etapa ----------

test('el pedido confirmado se cobra en el mostrador y deja el mismo asiento que una venta', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto  = ($this->tiendaProducto)(2);
    $carritoId = ($this->tiendaEnCheckout)($cuenta, $producto, 2);

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->assertCreated();

    $venta = Venta::sole();
    app(\App\Services\PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $asiento = $venta->movimientos()->sole();

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($asiento->tipo)->toBe('venta')
        ->and($asiento->cantidad)->toBe(-2)
        ->and($producto->fresh()->stock)->toBe(0)
        ->and($producto->fresh()->stock_reservado)->toBe(0);
});
