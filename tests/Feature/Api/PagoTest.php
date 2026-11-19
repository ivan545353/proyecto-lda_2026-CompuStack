<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\Pago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\CredencialService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Los pagos de la API (tramo G): iniciar, listar, consultar y el simulador. Lo ajeno es 404, y
 * el regreso de la app no confirma nada: sólo la consulta al proveedor.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    Http::preventStrayRequests();
    config(['tienda.pagos.simulador' => true, 'services.mercadopago.access_token' => null]);
    $this->carritos = app(CarritoService::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 30000)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        'alicuota_iva'   => 21,
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

    // Un pedido confirmado por el servicio, con su reserva.
    $this->tiendaPedido = function (User $cuenta, string $medio = 'pasarela', ?Producto $producto = null): Venta {
        $this->carritos->agregar($cuenta, ($producto ?? ($this->tiendaProducto)())->id, 2);
        $this->carritos->iniciarCheckout($cuenta);
        $carrito = $this->carritos->paraLeer($cuenta);

        return $this->carritos->confirmar($cuenta, [
            'carrito_id' => $carrito->id, 'medio_pago' => $medio, 'modo_entrega' => 'retiro',
        ])['venta'];
    };

    $this->iniciar = fn (Venta $venta, array $bearer, string $pasarela = 'simulador') => $this->postJson(
        "/api/v1/pedidos/{$venta->id}/pagos", ['pasarela' => $pasarela], $bearer,
    );
});

// ---------- acceso ----------

test('iniciar pide sesion y tienda.comprar; leer pide tienda.ver_pedidos', function () {
    $this->postJson('/api/v1/pedidos/1/pagos')->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');

    [, $soloLeer] = ($this->tiendaCuentaCon)(['tienda.ver_pedidos']);
    $this->postJson('/api/v1/pedidos/1/pagos', ['pasarela' => 'simulador'], $soloLeer)->assertStatus(403);
    $this->postJson('/api/v1/simulador/intentos/1/aprobar', [], $soloLeer)->assertStatus(403);

    [, $soloComprar] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $this->getJson('/api/v1/pedidos/1/pagos', $soloComprar)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
    $this->getJson('/api/v1/pedidos/1/pagos/1', $soloComprar)->assertStatus(403);
});

test('la pasarela es obligatoria y de la lista', function (mixed $pasarela) {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);

    $this->postJson("/api/v1/pedidos/{$venta->id}/pagos", ['pasarela' => $pasarela], $bearer)
        ->assertStatus(422)
        ->assertJsonValidationErrors('pasarela', 'errores');
})->with([null, 'efectivo', 'qr']);

// ---------- el simulador, de punta a punta ----------

test('iniciar con el simulador responde 201 con el contrato', function () {
    $this->freezeSecond();
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);

    $respuesta = ($this->iniciar)($venta, $bearer)->assertCreated();

    $intento = IntentoPago::sole();
    $respuesta->assertExactJson([
        'mensaje' => 'Iniciaste el pago.',
        'datos'   => [
            'id'             => $intento->id,
            'pasarela'       => 'simulador',
            'pasarela_texto' => 'Simulador de pagos — no es un cobro real',
            'estado'         => 'pendiente',
            'estado_texto'   => 'Esperando la confirmación',
            'importe'        => '121000.00',
            'url_pago'       => null,
            'vence'          => now()->addMinutes(20)->utc()->format('Y-m-d\TH:i:s\Z'),
            'resuelto'       => null,
            'creado'         => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'pedido'         => [
                'id'                => $venta->id,
                'numero'            => $venta->numeroFormateado(),
                'estado'            => 'pendiente_pago',
                'estado_texto'      => 'Esperando el pago',
                'estado_pago'       => 'pendiente',
                'estado_pago_texto' => 'Esperando la confirmación del pago',
            ],
        ],
    ]);
});

test('dos inicios seguidos devuelven 201 y despues 200 con el mismo intento', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);

    $primero = ($this->iniciar)($venta, $bearer)->assertCreated()->json('datos.id');
    $segundo = ($this->iniciar)($venta, $bearer)->assertOk()->json('datos.id');

    expect($segundo)->toBe($primero)->and(IntentoPago::count())->toBe(1);
});

test('aprobar en el simulador deja el pedido pagado', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($cuenta, 'pasarela', $producto);
    $id       = ($this->iniciar)($venta, $bearer)->json('datos.id');

    $this->postJson("/api/v1/simulador/intentos/{$id}/aprobar", [], $bearer)
        ->assertOk()
        ->assertJsonPath('mensaje', 'El simulador aprobó el pago.')
        ->assertJsonPath('datos.estado', 'aprobado')
        ->assertJsonPath('datos.pedido.estado', 'pagada')
        ->assertJsonPath('datos.pedido.estado_pago', 'aprobado')
        ->assertJsonPath('datos.pedido.estado_pago_texto', 'Pagado');

    expect(Pago::sole()->metodo)->toBe('simulador')->and($producto->fresh()->stock)->toBe(3);
});

test('rechazar no escribe pagos, y reintentar abre otro intento del mismo pedido', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);
    $id    = ($this->iniciar)($venta, $bearer)->json('datos.id');

    $this->postJson("/api/v1/simulador/intentos/{$id}/rechazar", [], $bearer)
        ->assertOk()
        ->assertJsonPath('datos.estado', 'rechazado')
        ->assertJsonPath('datos.pedido.estado_pago', 'rechazado')
        ->assertJsonPath('datos.pedido.estado_pago_texto', 'Pago rechazado: podés reintentar');

    expect(Pago::count())->toBe(0);

    $otro = ($this->iniciar)($venta, $bearer)->assertCreated()->json('datos.id');
    $this->postJson("/api/v1/simulador/intentos/{$otro}/aprobar", [], $bearer)->assertOk();

    expect($otro)->not->toBe($id)
        ->and(Pago::count())->toBe(1)
        ->and($venta->fresh()->estado)->toBe('pagada');
});

test('el simulador no resuelve dos veces el mismo intento', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $id = ($this->iniciar)(($this->tiendaPedido)($cuenta), $bearer)->json('datos.id');
    $this->postJson("/api/v1/simulador/intentos/{$id}/rechazar", [], $bearer)->assertOk();

    $this->postJson("/api/v1/simulador/intentos/{$id}/aprobar", [], $bearer)
        ->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');
});

test('con el simulador apagado no se inicia ni se resuelve', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);
    $id    = ($this->iniciar)($venta, $bearer)->json('datos.id');
    config(['tienda.pagos.simulador' => false]);

    ($this->iniciar)(($this->tiendaPedido)(($this->tiendaCuentaCon)()[0]), $bearer)->assertStatus(404);
    $this->postJson("/api/v1/simulador/intentos/{$id}/aprobar", [], $bearer)
        ->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');
    expect(Pago::count())->toBe(0);
});

// ---------- listar y consultar ----------

test('el listado de pagos pagina, el ultimo primero', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);
    $primero = ($this->iniciar)($venta, $bearer)->json('datos.id');
    $this->postJson("/api/v1/simulador/intentos/{$primero}/rechazar", [], $bearer);
    $segundo = ($this->iniciar)($venta, $bearer)->json('datos.id');

    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos", $bearer)
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('pagina', 1)
        ->assertJsonPath('datos.0.id', $segundo)
        ->assertJsonPath('datos.1.id', $primero)
        ->assertJsonPath('datos.1.estado', 'rechazado')
        ->assertJsonPath('datos.0.pedido.estado_pago', 'pendiente');

    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos?por_pagina=1&pagina=3", $bearer)
        ->assertOk()->assertJsonPath('datos', []);
});

test('un pedido de contado no admite intentos', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta, 'contado');

    ($this->iniciar)($venta, $bearer)->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');
    expect(IntentoPago::count())->toBe(0);
});

test('sin tienda.ver_precios el importe no viaja', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)(['tienda.comprar', 'tienda.ver_pedidos']);
    $venta = ($this->tiendaPedido)($cuenta);

    $datos = ($this->iniciar)($venta, $bearer)->assertCreated()->json('datos');

    expect($datos)->not->toHaveKey('importe')->and($datos)->toHaveKey('pasarela_texto');
});

// ---------- lo ajeno es 404 ----------

test('pedido ajeno, intento ajeno e intento del simulador ajeno: 404', function () {
    [$duena, $suyo] = ($this->tiendaCuentaCon)();
    [, $otro]       = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($duena);
    $id    = ($this->iniciar)($venta, $suyo)->json('datos.id');
    app('auth')->forgetGuards();   // si no, el guard sigue con la cuenta de la petición anterior

    ($this->iniciar)($venta, $otro)->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos", $otro)->assertStatus(404);
    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos/{$id}", $otro)->assertStatus(404);
    $this->postJson("/api/v1/simulador/intentos/{$id}/aprobar", [], $otro)->assertStatus(404);

    expect(IntentoPago::sole()->estado)->toBe('pendiente');
});

test('un intento de otro pedido propio no se lee bajo este pedido', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $uno  = ($this->tiendaPedido)($cuenta);
    $dos  = ($this->tiendaPedido)($cuenta);
    $id   = ($this->iniciar)($uno, $bearer)->json('datos.id');

    $this->getJson("/api/v1/pedidos/{$dos->id}/pagos/{$id}", $bearer)->assertStatus(404);
    $this->getJson("/api/v1/pedidos/{$uno->id}/pagos/{$id}", $bearer)->assertOk();
});

// ---------- Mercado Pago: sólo la consulta confirma ----------

test('mercado pago devuelve la url, y consultar el intento lo confirma contra el proveedor', function () {
    config(['services.mercadopago.access_token' => 'APP_USR-de-prueba']);
    $resultados = [];
    Http::fake([
        'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'p', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=p'], 201),
        'api.mercadopago.com/v1/payments/search*'  => function () use (&$resultados) {
            return Http::response(['results' => $resultados]);
        },
    ]);
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);

    $datos = ($this->iniciar)($venta, $bearer, 'mercadopago')->assertCreated()->json('datos');
    expect($datos['url_pago'])->toStartWith('https://www.mercadopago.com.ar/');

    // El cliente volvió a la app, pero el proveedor todavía no tiene el pago: nada cambia.
    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos/{$datos['id']}", $bearer)
        ->assertOk()->assertJsonPath('datos.estado', 'pendiente');
    expect(Pago::count())->toBe(0)->and($venta->fresh()->estado)->toBe('pendiente_pago');

    $resultados = [['id' => 555, 'status' => 'approved', 'status_detail' => 'accredited',
        'transaction_amount' => 121000.0, 'currency_id' => 'ARS']];

    $this->getJson("/api/v1/pedidos/{$venta->id}/pagos/{$datos['id']}", $bearer)
        ->assertOk()
        ->assertJsonPath('datos.estado', 'aprobado')
        ->assertJsonPath('datos.url_pago', null)
        ->assertJsonPath('datos.pedido.estado', 'pagada');

    expect(Pago::sole()->mp_payment_id)->toBe('555');
});

test('con mercado pago sin credencial no se ofrece', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();

    ($this->iniciar)(($this->tiendaPedido)($cuenta), $bearer, 'mercadopago')
        ->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');
});
