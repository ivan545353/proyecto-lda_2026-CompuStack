<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\MovimientoStock;
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
 * La venta de la tienda en el kardex (HL-85, D-25): se dice de qué canal es y se filtra; un pedido sin
 * cobrar no tiene asiento (regla 5), y la ficha del producto dice dónde está lo reservado.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 600)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '1000.00',
        'precio_lista'   => '1200.00',
        'alicuota_iva'   => 21,
    ]);

    // Un pedido por pasarela con su reserva, por los mismos servicios que la tienda (molde: AcreditarIntentoTest).
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

        $venta = app(VentaService::class)->crearOnline($cliente, $carrito, 'pasarela', 'retiro', null);

        // Como `CarritoService::confirmar()`: la reserva pasa del carrito al pedido, no queda en los dos.
        $carrito->estado          = 'convertido';
        $carrito->reservado_hasta = null;
        $carrito->save();

        return $venta;
    };

    // El pedido cobrado por la pasarela, sin cajero.
    $this->tiendaPedidoCobrado = function (Producto $producto, int $cantidad): Venta {
        $venta   = ($this->tiendaPedido)($producto, $cantidad);
        $intento = new IntentoPago;
        $intento->venta_id    = $venta->id;
        $intento->pasarela    = 'simulador';
        $intento->estado      = 'aprobado';
        $intento->importe     = $venta->fresh()->saldo();
        $intento->referencia  = (string) Str::uuid();
        $intento->vence_at    = now()->addMinutes(20);
        $intento->resuelto_at = now()->subMinute();
        $intento->save();

        return app(PagoService::class)->acreditarIntento($intento);
    };
});

test('la venta de la tienda cobrada sale en el kardex con su canal y sin cajero', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedidoCobrado)($producto, 2);

    $this->actingAs(usuarioCon('stock.ver'))->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertSee($venta->numeroFormateado().' · tienda online')
        ->assertSee('Tienda online (cobro automático)');
});

test('el filtro de canal separa las ventas de la tienda de las del mostrador', function () {
    $producto  = ($this->tiendaProducto)(10);
    $online    = ($this->tiendaPedidoCobrado)($producto, 2);
    $mostrador = ventaCobrada($producto, 1);
    $ver       = fn (string $canal) => $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['canal' => $canal]))->assertOk();

    $ver('online')->assertSee($online->numeroFormateado())->assertDontSee($mostrador->numeroFormateado())
        ->assertSee('Canal: Tienda online');
    $ver('mostrador')->assertSee($mostrador->numeroFormateado())->assertDontSee($online->numeroFormateado());

    expect(MovimientoStock::query()->deCanal('online')->pluck('origen_id')->all())->toBe([$online->id]);
});

test('un canal que no existe no se acepta', function () {
    $this->actingAs(usuarioCon('stock.ver'))->get(route('stock.index', ['canal' => 'telefono']))
        ->assertRedirect(route('stock.index'));
});

test('un pedido sin cobrar no tiene asiento, y la ficha dice que lo reservado está en él', function () {
    $producto = ($this->tiendaProducto)(10);
    $venta    = ($this->tiendaPedido)($producto, 3);

    expect(MovimientoStock::where('origen_id', $venta->id)->where('origen_type', Venta::class)->count())->toBe(0);

    $this->actingAs(usuarioCon('stock.ver'))->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertSeeInOrder(['data-reservas', 'De las reservadas, 3 están en pedidos', 'y 0 en compras'], false);
});

test('el kardex impreso dice el canal filtrado y quién registró el cobro de la tienda', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedidoCobrado)($producto, 1);

    $csv = $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.imprimir', ['formato' => 'csv', 'canal' => 'online']))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Canal: Tienda online')
        ->and($csv)->toContain($venta->numeroFormateado().' · tienda online')
        ->and($csv)->toContain('Tienda online (cobro automático)');
});
