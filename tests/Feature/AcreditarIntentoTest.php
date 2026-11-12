<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Carrito;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\PagoService;
use App\Services\StockService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `PagoService::acreditarIntento()` (tramo G, HL-3): el pago de la pasarela, sin cajero. Aparte de
 * `PagoServiceTest` (Etapa 1), que sigue fijando que `cobrar()` no cambió.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->pagos = app(PagoService::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 600)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '1000.00',
        'precio_lista'   => '1200.00',
        'alicuota_iva'   => 21,
    ]);

    // Un pedido por pasarela con su reserva, por los mismos servicios que la tienda.
    $this->tiendaPedido = function (Producto $producto, int $cantidad): Venta {
        $cuenta   = User::factory()->conRol('Cliente')->create();
        $cliente  = Cliente::factory()->create(['user_id' => $cuenta->id]);
        $carritos = app(CarritoService::class);

        $carritos->agregar($cuenta, $producto->id, $cantidad);
        $carrito = $carritos->delUsuario($cuenta);
        app(StockService::class)->reservar($producto, $cantidad);
        $carrito->estado          = 'en_checkout';
        $carrito->reservado_hasta = now()->addMinutes(20);
        $carrito->save();

        return app(VentaService::class)->crearOnline($cliente, $carrito, 'pasarela', 'retiro', null);
    };

    // El intento aprobado, escrito a mano: `IntentoPagoService` llega en el paso siguiente.
    $this->tiendaIntento = function (Venta $venta, string $pasarela = 'simulador', array $mas = []): IntentoPago {
        $intento = new IntentoPago;
        $intento->venta_id    = $venta->id;
        $intento->pasarela    = $pasarela;
        $intento->estado      = $mas['estado'] ?? 'aprobado';
        $intento->importe     = $mas['importe'] ?? $venta->fresh()->saldo();
        $intento->referencia  = (string) Str::uuid();
        $intento->payment_id  = $pasarela === 'mercadopago' ? '1325467890' : null;
        $intento->vence_at    = now()->addMinutes(20);
        $intento->resuelto_at = now()->subMinute();
        $intento->save();

        return $intento;
    };
});

test('acreditar un intento del simulador deja el pedido pagado con un solo pago sin cajero', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);
    $intento  = ($this->tiendaIntento)($venta);

    $pagada = $this->pagos->acreditarIntento($intento);

    $pago = Pago::where('venta_id', $venta->id)->sole();

    expect($pagada->estado)->toBe('pagada')
        ->and($pagada->reserva_vence)->toBeNull()
        ->and($pago->metodo)->toBe('simulador')
        ->and($pago->monto)->toBe('2400.00')
        ->and($pago->intento_pago_id)->toBe($intento->id)
        ->and($pago->usuario_id)->toBeNull()
        ->and($pago->mp_payment_id)->toBeNull()
        ->and($pago->fecha->equalTo($intento->resuelto_at))->toBeTrue()
        ->and($intento->fresh()->acreditado_at)->not->toBeNull();
});

test('el stock sale de la reserva y el kardex no tiene usuario', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);

    $this->pagos->acreditarIntento(($this->tiendaIntento)($venta));

    $asiento = MovimientoStock::where('origen_type', Venta::class)->where('origen_id', $venta->id)->sole();

    expect($producto->fresh()->stock)->toBe(3)
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and($asiento->cantidad)->toBe(-2)
        ->and($asiento->usuario_id)->toBeNull();
});

test('un intento de mercado pago guarda el identificador del proveedor', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);

    $this->pagos->acreditarIntento(($this->tiendaIntento)($venta, 'mercadopago'));

    $pago = Pago::where('venta_id', $venta->id)->sole();

    expect($pago->metodo)->toBe('mercadopago')
        ->and($pago->mp_payment_id)->toBe('1325467890');
});

test('acreditar dos veces el mismo intento escribe un solo pago y descuenta una vez', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 2);
    $intento  = ($this->tiendaIntento)($venta);

    $this->pagos->acreditarIntento($intento);
    $otra = $this->pagos->acreditarIntento($intento);

    expect($otra->estado)->toBe('pagada')
        ->and(Pago::where('intento_pago_id', $intento->id)->count())->toBe(1)
        ->and($producto->fresh()->stock)->toBe(3);
});

test('un intento que no esta aprobado no se acredita', function (string $estado) {
    $venta   = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $intento = ($this->tiendaIntento)($venta, 'simulador', ['estado' => $estado]);

    expect(fn () => $this->pagos->acreditarIntento($intento))->toThrow(ReglaDeNegocioException::class);
    expect(Pago::count())->toBe(0)->and($venta->fresh()->estado)->toBe('pendiente_pago');
})->with(['pendiente', 'rechazado', 'vencido', 'error']);

test('un importe distinto del saldo no se acredita y el intento sigue aprobado sin acreditar', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedido)($producto, 1);
    $intento  = ($this->tiendaIntento)($venta, 'simulador', ['importe' => '999.00']);

    expect(fn () => $this->pagos->acreditarIntento($intento))->toThrow(ReglaDeNegocioException::class);

    expect(Pago::count())->toBe(0)
        ->and($intento->fresh()->aprobadoSinAcreditar())->toBeTrue()
        ->and($producto->fresh()->stock_reservado)->toBe(1);
});

test('un pedido cancelado no se acredita', function () {
    $venta   = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $intento = ($this->tiendaIntento)($venta);
    app(VentaService::class)->cancelar($venta);

    expect(fn () => $this->pagos->acreditarIntento($intento))->toThrow(ReglaDeNegocioException::class);
    expect(Pago::count())->toBe(0);
});

test('la ficha de la venta dice que el pago es del simulador', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);
    $this->pagos->acreditarIntento(($this->tiendaIntento)($venta));

    $this->actingAs(admin())
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Simulador — no es cobro real');
});
