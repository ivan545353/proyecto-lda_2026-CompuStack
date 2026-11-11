<?php

use App\Models\Carrito;
use App\Models\Categoria;
use App\Models\Cliente;
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

uses(RefreshDatabase::class);

/**
 * El pedido de la tienda en VentaService (tramo F): nace con la reserva del carrito, la consume
 * al cobrarse y la suelta al cancelarse o vencer.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->ventas = app(VentaService::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 600)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '1000.00',
        'precio_lista'   => '1200.00',
        'alicuota_iva'   => 21,
    ]);

    // Un carrito en checkout con su reserva tomada por la única puerta, y el cliente de su cuenta.
    $this->tiendaCheckout = function (Producto $producto, int $cantidad): array {
        $cuenta  = User::factory()->conRol('Cliente')->create();
        $cliente = Cliente::factory()->create(['user_id' => $cuenta->id]);
        $carritos = app(CarritoService::class);

        $carritos->agregar($cuenta, $producto->id, $cantidad);
        $carrito = $carritos->delUsuario($cuenta);
        app(StockService::class)->reservar($producto, $cantidad);
        $carrito->estado          = 'en_checkout';
        $carrito->reservado_hasta = now()->addMinutes(20);
        $carrito->save();

        return [$cliente, $carrito];
    };

    $this->tiendaPedido = function (Producto $producto, int $cantidad, string $medio = 'contado'): Venta {
        [$cliente, $carrito] = ($this->tiendaCheckout)($producto, $cantidad);

        return $this->ventas->crearOnline($cliente, $carrito, $medio, 'retiro', null);
    };

    $this->tiendaDireccion = [
        'calle' => 'San Martín', 'numero' => '1234', 'piso_depto' => '2 B',
        'codigo_postal' => '9000', 'localidad' => 'Comodoro Rivadavia', 'provincia' => 'Chubut',
    ];
});

// ---------- crearOnline() ----------

test('el pedido de contado nace online, sin vendedor y en pendiente de pago', function () {
    $this->freezeSecond();
    $producto = ($this->tiendaProducto)();
    [$cliente, $carrito] = ($this->tiendaCheckout)($producto, 2);

    $venta = $this->ventas->crearOnline($cliente, $carrito, 'contado', 'retiro', null);

    expect($venta->canal)->toBe('online')
        ->and($venta->usuario_id)->toBeNull()
        ->and($venta->cliente_id)->toBe($cliente->id)
        ->and($venta->estado)->toBe('pendiente_pago')
        ->and($venta->carrito_id)->toBe($carrito->id)
        ->and($venta->medio_pago)->toBe('contado')
        ->and($venta->reserva_vence->equalTo(now()->addHours(48)))->toBeTrue()
        ->and($venta->lineas)->toHaveCount(1)
        ->and($venta->lineas[0]->precio_unitario)->toBe('1000.00')
        ->and($venta->total)->toBe('2000.00');
});

test('el pedido por pasarela cotiza con la lista y reserva por minutos', function () {
    $this->freezeSecond();
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 2, 'pasarela');

    expect($venta->lineas[0]->precio_unitario)->toBe('1200.00')
        ->and($venta->total)->toBe('2400.00')
        ->and($venta->reserva_vence->equalTo(now()->addMinutes(20)))->toBeTrue();
});

test('el renglon congela el precio, el costo y los impuestos', function () {
    $producto = ($this->tiendaProducto)();
    $linea    = ($this->tiendaPedido)($producto, 1)->lineas[0];

    $producto->update(['precio_contado' => '5000.00']);

    expect($linea->fresh()->precio_unitario)->toBe('1000.00')
        ->and($linea->costo_unitario)->toBe('600.00')
        ->and($linea->descripcion)->toBe($producto->nombre)
        ->and($linea->impuestos()->count())->toBeGreaterThan(0)
        ->and(round((float) $linea->impuestos()->sum('importe') + (float) $linea->neto, 2))->toBe(1000.0);
});

test('confirmar no mueve la reserva: pasa del carrito al pedido', function () {
    $producto = ($this->tiendaProducto)(10);
    ($this->tiendaPedido)($producto, 3);

    expect($producto->fresh()->stock_reservado)->toBe(3)
        ->and($producto->fresh()->stock)->toBe(10)
        ->and(MovimientoStock::where('producto_id', $producto->id)->count())->toBe(0);
});

test('el envio suma su costo y congela la direccion', function () {
    $producto = ($this->tiendaProducto)();
    [$cliente, $carrito] = ($this->tiendaCheckout)($producto, 1);

    $venta = $this->ventas->crearOnline($cliente, $carrito, 'contado', 'envio', $this->tiendaDireccion);

    expect($venta->modo_entrega)->toBe('envio')
        ->and($venta->costo_envio)->toBe('8000.00')
        ->and($venta->total)->toBe('9000.00')
        ->and($venta->entrega_calle)->toBe('San Martín')
        ->and($venta->entrega_piso_depto)->toBe('2 B')
        ->and($venta->entrega_provincia)->toBe('Chubut');
});

test('el retiro no cobra envio ni guarda direccion', function () {
    $venta = ($this->tiendaPedido)(($this->tiendaProducto)(), 1);

    expect($venta->costo_envio)->toBe('0.00')
        ->and($venta->entrega_calle)->toBeNull();
});

test('un envio sin direccion no se registra', function () {
    $producto = ($this->tiendaProducto)();
    [$cliente, $carrito] = ($this->tiendaCheckout)($producto, 1);

    expect(fn () => $this->ventas->crearOnline($cliente, $carrito, 'contado', 'envio', null))
        ->toThrow(\App\Exceptions\ReglaDeNegocioException::class);

    expect(Venta::count())->toBe(0);
});

test('un medio desconocido no se registra', function () {
    $producto = ($this->tiendaProducto)();
    [$cliente, $carrito] = ($this->tiendaCheckout)($producto, 1);

    expect(fn () => $this->ventas->crearOnline($cliente, $carrito, 'cheque', 'retiro', null))
        ->toThrow(\App\Exceptions\ReglaDeNegocioException::class);
});

// ---------- el cobro en el mostrador ----------

test('la venta online cobrada en el mostrador consume la reserva con el stock justo', function () {
    // Todo el stock está reservado por el pedido: sin la reserva propia, el disponible es cero.
    $producto = ($this->tiendaProducto)(3);
    $venta    = ($this->tiendaPedido)($producto, 3);

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $producto->refresh();
    $venta->refresh();
    $asiento = $venta->movimientos()->sole();

    expect($venta->estado)->toBe('pagada')
        ->and($venta->reserva_vence)->toBeNull()
        ->and($producto->stock)->toBe(0)
        ->and($producto->stock_reservado)->toBe(0)
        ->and($asiento->tipo)->toBe('venta')
        ->and($asiento->cantidad)->toBe(-3);
});

test('una reserva vencida pero no liberada todavia se consume', function () {
    // HL-10: la hora pasó y el comando no corrió; las unidades siguen siendo del pedido.
    $producto = ($this->tiendaProducto)(2);
    $venta    = ($this->tiendaPedido)($producto, 2);
    $this->travel(49)->hours();

    $this->ventas->marcarPagada($venta, null);

    expect($producto->fresh()->stock)->toBe(0)
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and($venta->fresh()->estado)->toBe('pagada');
});

test('el mostrador sigue descontando sin reserva', function () {
    $producto = ($this->tiendaProducto)(5);
    $otro     = ($this->tiendaProducto)(5);
    ($this->tiendaPedido)($otro, 2);

    ventaCobrada($producto, 2);

    expect($producto->fresh()->stock)->toBe(3)
        ->and($otro->fresh()->stock_reservado)->toBe(2);
});

// ---------- faltantes ----------

test('un pedido reservado no avisa faltantes de su propia reserva', function () {
    $producto = ($this->tiendaProducto)(3);
    $venta    = ($this->tiendaPedido)($producto, 3);

    expect($venta->fresh()->faltantesDeStock())->toBeEmpty();
});

test('un presupuesto del mostrador si ve que el pedido se llevo el stock', function () {
    $producto = ($this->tiendaProducto)(3);
    ($this->tiendaPedido)($producto, 3);

    $presupuesto = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    expect($presupuesto->fresh()->faltantesDeStock())->toHaveCount(1);
});

// ---------- cancelar() y cancelarVencidos() ----------

test('cancelar el pedido libera la reserva', function () {
    $producto = ($this->tiendaProducto)(10);
    $venta    = ($this->tiendaPedido)($producto, 4);

    $this->ventas->cancelar($venta);

    expect($venta->fresh()->estado)->toBe('cancelada')
        ->and($venta->fresh()->reserva_vence)->toBeNull()
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and($producto->fresh()->stock)->toBe(10);
});

test('cancelar un pedido ya pagado no libera nada', function () {
    $producto = ($this->tiendaProducto)(10);
    $venta    = ($this->tiendaPedido)($producto, 2);
    $this->ventas->marcarPagada($venta, null);

    expect(fn () => $this->ventas->cancelar($venta))
        ->toThrow(\App\Exceptions\TransicionInvalidaException::class);

    expect($producto->fresh()->stock_reservado)->toBe(0)
        ->and($producto->fresh()->stock)->toBe(8);
});

test('el vencimiento cancela un contado vencido y libera, y deja el vigente', function () {
    $producto = ($this->tiendaProducto)(10);
    $vencido  = ($this->tiendaPedido)($producto, 3);
    $this->travel(47)->hours();
    $vigente  = ($this->tiendaPedido)($producto, 2);
    $this->travel(2)->hours();

    expect($this->ventas->cancelarVencidos())->toBe(1);

    expect($vencido->fresh()->estado)->toBe('cancelada')
        ->and($vigente->fresh()->estado)->toBe('pendiente_pago')
        ->and($producto->fresh()->stock_reservado)->toBe(2);
});

test('el vencimiento no toca presupuestos ni pedidos cobrados', function () {
    $producto    = ($this->tiendaProducto)(10);
    $cobrado     = ($this->tiendaPedido)($producto, 1);
    $this->ventas->marcarPagada($cobrado, null);
    $presupuesto = $this->ventas->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());
    $this->travel(3)->days();

    expect($this->ventas->cancelarVencidos())->toBe(0)
        ->and($cobrado->fresh()->estado)->toBe('pagada')
        ->and($presupuesto->fresh()->estado)->toBe('presupuesto');
});

// ---------- informes ----------

test('el ranking de vendedores no cuenta la venta online y el panel general si', function () {
    $producto = ($this->tiendaProducto)(10);
    ventaCobrada($producto, 1);
    $pedido = ($this->tiendaPedido)($producto, 2);
    $this->ventas->marcarPagada($pedido, null);

    $desde = now()->startOfDay();
    $hasta = now()->endOfDay();

    $ranking = app(\App\Services\InformeVentasService::class)->totalDeVendedores($desde, $hasta);
    $panel   = app(\App\Services\PanelService::class)->paraAdministrativo($desde, $hasta);

    expect((int) $ranking->cantidad)->toBe(1)
        ->and((float) $ranking->facturado)->toBe(1000.0)
        ->and($panel['cantidad'])->toBe(2)
        ->and($panel['facturado'])->toBe(3000.0);
});
