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

// Desde el tramo F de la Etapa 3 el kardex es un endpoint: lo que la pantalla leía en el HTML se lee
// en el JSON que la pinta, con el mismo texto.

test('la venta de la tienda cobrada sale en el kardex con su canal y sin cajero', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedidoCobrado)($producto, 2);

    $this->getJson("/api/v1/stock/movimientos?producto_id={$producto->id}", bearer(usuarioCon('stock.ver')))
        ->assertOk()
        ->assertJsonPath('datos.0.origen_texto', 'Venta '.$venta->numeroFormateado().' · tienda online')
        ->assertJsonPath('datos.0.registrado_por', 'Tienda online (cobro automático)');
});

test('el filtro de canal separa las ventas de la tienda de las del mostrador', function () {
    $producto  = ($this->tiendaProducto)(10);
    $online    = ($this->tiendaPedidoCobrado)($producto, 2);
    $mostrador = ventaCobrada($producto, 1);
    $ver       = function (string $canal) {
        app('auth')->forgetGuards();

        return $this->getJson("/api/v1/stock/movimientos?canal={$canal}", bearer(usuarioCon('stock.ver')))->assertOk();
    };
    $origenes = fn ($respuesta) => implode(' | ', array_column($respuesta->json('datos'), 'origen_texto'));

    $enLinea = $ver('online')->assertJsonPath('criterios.canal', 'online');
    expect($origenes($enLinea))->toContain($online->numeroFormateado())->not->toContain($mostrador->numeroFormateado());

    expect($origenes($ver('mostrador')))->toContain($mostrador->numeroFormateado())->not->toContain($online->numeroFormateado());

    expect(MovimientoStock::query()->deCanal('online')->pluck('origen_id')->all())->toBe([$online->id]);
});

test('un canal que no existe no se acepta', function () {
    $this->getJson('/api/v1/stock/movimientos?canal=telefono', bearer(usuarioCon('stock.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('canal', 'errores');
});

test('un pedido sin cobrar no tiene asiento, y la ficha dice que lo reservado está en él', function () {
    $producto = ($this->tiendaProducto)(10);
    $venta    = ($this->tiendaPedido)($producto, 3);

    expect(MovimientoStock::where('origen_id', $venta->id)->where('origen_type', Venta::class)->count())->toBe(0);

    $this->getJson("/api/v1/productos/{$producto->id}/stock", bearer(usuarioCon('stock.ver')))
        ->assertOk()
        ->assertJsonPath('datos.stock_reservado', 3)
        ->assertJsonPath('datos.reservas.pedidos', 3)
        ->assertJsonPath('datos.reservas.carritos', 0);
});

test('el kardex impreso dice el canal filtrado y quién registró el cobro de la tienda', function () {
    $producto = ($this->tiendaProducto)(5);
    $venta    = ($this->tiendaPedidoCobrado)($producto, 1);

    $url = $this->getJson('/api/v1/stock/movimientos/impresion?formato=csv&canal=online', bearer(usuarioCon('stock.ver')))
        ->assertOk()
        ->json('datos.url');

    $csv = $this->get($url)->assertOk()->streamedContent();

    expect($csv)->toContain('Canal: Tienda online')
        ->and($csv)->toContain($venta->numeroFormateado().' · tienda online')
        ->and($csv)->toContain('Tienda online (cobro automático)');
});
