<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\IntentoPagoService;
use App\Services\StockService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * `IntentoPagoService` (tramo G): abrir, consultar, aplicar y acreditar, con el simulador y con
 * Mercado Pago contra `Http::fake()`. Y el estado del pago derivado de los datos (regla 9).
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    Http::preventStrayRequests();
    config(['tienda.pagos.simulador' => true, 'services.mercadopago.access_token' => null]);
    $this->intentos = app(IntentoPagoService::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 600)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '1000.00',
        'precio_lista'   => '1200.00',
        'alicuota_iva'   => 21,
    ]);

    $this->tiendaPedido = function (Producto $producto, int $cantidad, string $medio = 'pasarela', ?Cliente $cliente = null): Venta {
        $cuenta  = $cliente?->usuario ?? User::factory()->conRol('Cliente')->create();
        $cliente ??= Cliente::factory()->create(['user_id' => $cuenta->id]);
        $carritos = app(CarritoService::class);

        $carritos->agregar($cuenta, $producto->id, $cantidad);
        $carrito = $carritos->delUsuario($cuenta);
        app(StockService::class)->reservar($producto, $cantidad);
        $carrito->estado          = 'en_checkout';
        $carrito->reservado_hasta = now()->addMinutes(20);
        $carrito->save();

        return app(VentaService::class)->crearOnline($cliente, $carrito, $medio, 'retiro', null);
    };

    $this->conMercadoPago = function (array $pagos = [], bool $preferencia = true) {
        config(['services.mercadopago.access_token' => 'APP_USR-de-prueba']);
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => $preferencia
                ? Http::response(['id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1'], 201)
                : Http::response(['message' => 'invalid'], 400),
            'api.mercadopago.com/v1/payments/search*' => Http::response(['results' => $pagos]),
        ]);
    };

    $this->pagoMp = fn (string $status, string $monto = '2400.00') => [
        'id' => 987, 'status' => $status, 'status_detail' => 'accredited',
        'transaction_amount' => (float) $monto, 'currency_id' => 'ARS',
    ];
});

// ---------- iniciar() ----------

test('iniciar con el simulador abre un intento pendiente por el saldo, sin url', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 2);

    ['intento' => $intento, 'creado' => $creado] = $this->intentos->iniciar($venta, 'simulador');

    expect($creado)->toBeTrue()
        ->and($intento->estado)->toBe('pendiente')
        ->and($intento->pasarela)->toBe('simulador')
        ->and($intento->importe)->toBe('2400.00')
        ->and($intento->url_pago)->toBeNull()
        ->and($intento->referencia)->toBeString()
        ->and($intento->vence_at->equalTo(now()->addMinutes(20)->startOfSecond()))->toBeTrue();
});

test('iniciar estira la reserva hasta el vencimiento del intento', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $this->travel(10)->minutes();

    ['intento' => $intento] = $this->intentos->iniciar($venta, 'simulador');

    expect($venta->fresh()->reserva_vence->equalTo($intento->vence_at))->toBeTrue();
});

test('dos inicios seguidos devuelven el mismo intento pendiente', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);

    $primero = $this->intentos->iniciar($venta, 'simulador');
    $segundo = $this->intentos->iniciar($venta, 'simulador');

    expect($segundo['creado'])->toBeFalse()
        ->and($segundo['intento']->id)->toBe($primero['intento']->id)
        ->and(IntentoPago::count())->toBe(1);
});

test('un pedido que no espera un pago por pasarela no admite intentos', function (string $caso) {
    $producto = ($this->tiendaProducto)();
    $venta    = ($this->tiendaPedido)($producto, 1, $caso === 'contado' ? 'contado' : 'pasarela');

    match ($caso) {
        'cancelado'       => app(VentaService::class)->cancelar($venta),
        'reserva vencida' => $this->travel(21)->minutes(),
        default           => null,
    };

    expect(fn () => $this->intentos->iniciar($venta, 'simulador'))->toThrow(ReglaDeNegocioException::class);
    expect(IntentoPago::count())->toBe(0);
})->with(['contado', 'cancelado', 'reserva vencida']);

test('una pasarela deshabilitada no se inicia', function (string $pasarela) {
    config(['tienda.pagos.simulador' => false]);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);

    expect(fn () => $this->intentos->iniciar($venta, $pasarela))->toThrow(ReglaDeNegocioException::class);
})->with(['simulador', 'mercadopago']);

test('mercado pago guarda la url de la preferencia', function () {
    ($this->conMercadoPago)();
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 2);

    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');

    expect($intento->estado)->toBe('pendiente')
        ->and($intento->url_pago)->toStartWith('https://www.mercadopago.com.ar/');
});

test('si la preferencia no se crea el intento queda en error y se puede reintentar', function () {
    ($this->conMercadoPago)([], preferencia: false);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);

    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');

    expect($intento->estado)->toBe('error')
        ->and($venta->fresh()->estadoDelPago())->toBe('pendiente')
        ->and($venta->fresh()->situacionDelPago())->toBe('falta_pagar');

    expect($this->intentos->iniciar($venta, 'simulador')['creado'])->toBeTrue();
});

// ---------- simulador ----------

test('aprobar en el simulador deja el pedido pagado con un pago del simulador', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'simulador');

    $resuelto = $this->intentos->resolverSimulado($intento, 'aprobado');

    expect($resuelto->estado)->toBe('aprobado')
        ->and($resuelto->acreditado_at)->not->toBeNull()
        ->and($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->estadoDelPago())->toBe('aprobado')
        ->and(Pago::sole()->metodo)->toBe('simulador')
        ->and($producto->fresh()->stock)->toBe(3);
});

test('rechazar no escribe pagos y conserva la reserva; reintentar abre otro intento', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);
    ['intento' => $primero] = $this->intentos->iniciar($venta, 'simulador');

    $this->intentos->resolverSimulado($primero, 'rechazado');

    expect(Pago::count())->toBe(0)
        ->and($primero->fresh()->estado)->toBe('rechazado')
        ->and($producto->fresh()->stock_reservado)->toBe(2)
        ->and($venta->fresh()->estado)->toBe('pendiente_pago')
        ->and($venta->fresh()->estadoDelPago())->toBe('rechazado');

    ['intento' => $segundo, 'creado' => $creado] = $this->intentos->iniciar($venta, 'simulador');
    $this->intentos->resolverSimulado($segundo, 'aprobado');

    expect($creado)->toBeTrue()
        ->and($segundo->id)->not->toBe($primero->id)
        ->and($segundo->venta_id)->toBe($venta->id)
        ->and(Pago::count())->toBe(1)
        ->and($producto->fresh()->stock)->toBe(3)
        ->and($producto->fresh()->stock_reservado)->toBe(0);
});

test('el simulador no resuelve un intento ya resuelto ni uno de mercado pago', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'simulador');
    $this->intentos->resolverSimulado($intento, 'rechazado');

    expect(fn () => $this->intentos->resolverSimulado($intento->fresh(), 'aprobado'))->toThrow(ReglaDeNegocioException::class);

    ($this->conMercadoPago)();
    ['intento' => $mp] = $this->intentos->iniciar($venta, 'mercadopago');

    expect(fn () => $this->intentos->resolverSimulado($mp, 'aprobado'))->toThrow(ReglaDeNegocioException::class)
        ->and(Pago::count())->toBe(0);
});

// ---------- verificar() con Mercado Pago ----------

test('la consulta aprobada acredita el pago de mercado pago', function () {
    ($this->conMercadoPago)([($this->pagoMp)('approved')]);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 2);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');

    $verificado = $this->intentos->verificar($intento);

    expect($verificado->estado)->toBe('aprobado')
        ->and($verificado->payment_id)->toBe('987')
        ->and($venta->fresh()->estado)->toBe('pagada')
        ->and(Pago::sole()->mp_payment_id)->toBe('987');
});

test('verificar un intento ya resuelto no vuelve a preguntar', function () {
    ($this->conMercadoPago)([($this->pagoMp)('rejected')]);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');
    $this->intentos->verificar($intento);
    $consultas = count(Http::recorded());

    $this->intentos->verificar($intento->fresh());

    expect(count(Http::recorded()))->toBe($consultas)->and($intento->fresh()->estado)->toBe('rechazado');
});

test('un aprobado por otro importe queda en revision y no se acredita solo', function () {
    ($this->conMercadoPago)([($this->pagoMp)('approved', '100.00')]);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 2);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');

    $verificado = $this->intentos->verificar($intento);

    expect($verificado->estado)->toBe('aprobado')
        ->and($verificado->detalle)->toBe(IntentoPago::IMPORTE_DISTINTO)
        ->and(Pago::count())->toBe(0)
        ->and($venta->fresh()->estadoDelPago())->toBe('en_revision')
        ->and($this->intentos->reintentarAcreditaciones())->toBe(0)
        ->and(Pago::count())->toBe(0);
});

test('sin respuesta del proveedor el intento no vence aunque pase su plazo', function () {
    ($this->conMercadoPago)();
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');
    $this->travel(21)->minutes();
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    expect($this->intentos->verificar($intento)->estado)->toBe('pendiente')
        ->and($venta->fresh()->estadoDelPago())->toBe('pendiente')
        ->and($venta->fresh()->situacionDelPago())->toBe('esperando');
});

test('con respuesta y el plazo cumplido, el pendiente pasa a vencido', function () {
    ($this->conMercadoPago)([]);
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'mercadopago');
    $this->travel(21)->minutes();

    expect($this->intentos->verificar($intento)->estado)->toBe('vencido')
        ->and($venta->fresh()->situacionDelPago())->toBe('falta_pagar')
        ->and(Pago::count())->toBe(0);
});

// ---------- lo que queda en el aire ----------

test('un aprobado cuya acreditacion falla queda aprobado sin acreditar y se acredita despues', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'simulador');
    $producto->update(['activo' => false]);   // marcarPagada() se niega a vender un producto dado de baja

    $resuelto = $this->intentos->resolverSimulado($intento, 'aprobado');

    expect($resuelto->estado)->toBe('aprobado')
        ->and($resuelto->acreditado_at)->toBeNull()
        ->and(Pago::count())->toBe(0)
        ->and($venta->fresh()->estadoDelPago())->toBe('en_revision');

    $producto->update(['activo' => true]);

    expect($this->intentos->reintentarAcreditaciones())->toBe(1)
        ->and($venta->fresh()->estado)->toBe('pagada')
        ->and(Pago::count())->toBe(1)
        ->and($producto->fresh()->stock)->toBe(3);
});

test('verificar vencidos cierra los pendientes del simulador cuyo plazo paso', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    ['intento' => $intento] = $this->intentos->iniciar($venta, 'simulador');
    $otro = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $this->travel(15)->minutes();
    ['intento' => $vigente] = $this->intentos->iniciar($otro, 'simulador');
    $this->travel(6)->minutes();

    expect($this->intentos->verificarVencidos())->toBe(1)
        ->and($intento->fresh()->estado)->toBe('vencido')
        ->and($vigente->fresh()->estado)->toBe('pendiente');
});

// ---------- estado del pago (regla 9) ----------

test('el estado del pago de un pedido de contado y de uno cancelado', function () {
    $contado = ($this->tiendaPedido)(($this->tiendaProducto)(), 1, 'contado');
    $sinIntentos = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $cancelado = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    app(VentaService::class)->cancelar($cancelado);

    expect([$contado->fresh()->estadoDelPago(), $contado->fresh()->situacionDelPago()])->toBe(['pendiente', 'contado'])
        ->and([$sinIntentos->fresh()->estadoDelPago(), $sinIntentos->fresh()->situacionDelPago()])->toBe(['pendiente', 'falta_pagar'])
        ->and([$cancelado->fresh()->estadoDelPago(), $cancelado->fresh()->situacionDelPago()])->toBe(['sin_cobrar', 'sin_cobrar']);
});

test('del cliente trae sólo sus pedidos de la tienda', function () {
    $cuenta  = User::factory()->conRol('Cliente')->create();
    $cliente = Cliente::factory()->create(['user_id' => $cuenta->id]);
    $suyo    = ($this->tiendaPedido)(($this->tiendaProducto)(), 1, 'pasarela', $cliente);
    ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    Venta::factory()->create(['cliente_id' => $cliente->id]);   // del mostrador

    expect(Venta::delCliente($cliente)->pluck('id')->all())->toBe([$suyo->id]);
});
