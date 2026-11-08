<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Venta;
use App\Services\PanelService;
use App\Services\VentaService;
use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Proveedor;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(PanelService::class);


    $this->desde = Carbon::parse('2026-03-01')->startOfDay();
    $this->hasta = Carbon::parse('2026-03-31')->endOfDay();
});


test('los dos juegos de estados parten ESTADOS sin superponerse ni dejar huecos', function () {
    $vendidos = Venta::ESTADOS_VENDIDOS;
    $sinCobro = Venta::ESTADOS_SIN_COBRO;

    expect(array_values(array_intersect($vendidos, $sinCobro)))->toBe([])
        ->and(array_values(array_diff(array_keys(Venta::ESTADOS), $vendidos, $sinCobro)))->toBe([]);
});

test('un presupuesto no entra en ninguna metrica', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    Venta::factory()->conLinea(productoConPrecios(contado: 1000), 3)->create();

    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // Que tampoco entre en los más vendidos lo fija ahora InformeVentasFiltrosTest.
    expect($panel['cantidad'])->toBe(0)
        ->and($panel['facturado'])->toBe(0.0)
        ->and($panel['margenBruto'])->toBe(0.0);
});

test('cuenta solo las ventas del vendedor que pregunta', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $mia      = usuarioCon('panel.ver_propio');
    $ajena    = usuarioCon('panel.ver_propio');

    ventaCobrada($producto, 3, vendedor: $mia);
    ventaCobrada($producto, 2, vendedor: $ajena);

    $this->travelBack();

    $panel = $this->service->paraVendedor($mia, $this->desde, $this->hasta);

    expect($panel['vendi']['cantidad'])->toBe(1)
        ->and($panel['vendi']['facturado'])->toBe(3000.0);
});

test('el ticket promedio es el promedio de los totales del periodo', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');

    ventaCobrada($producto, 3, vendedor: $vendedor);   // 3000
    ventaCobrada($producto, 1, vendedor: $vendedor);   // 1000

    $this->travelBack();

    $panel = $this->service->paraVendedor($vendedor, $this->desde, $this->hasta);

    expect($panel['vendi']['facturado'])->toBe(4000.0)
        ->and($panel['vendi']['ticket'])->toBe(2000.0);
});

test('lo cobrado sale de pagos.usuario_id y no de ventas.usuario_id', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');
    $cajero   = usuarioCon('panel.ver_propio');

    ventaCobrada($producto, 3, vendedor: $vendedor, cajero: $cajero);

    $this->travelBack();

    $delVendedor = $this->service->paraVendedor($vendedor, $this->desde, $this->hasta);
    $delCajero   = $this->service->paraVendedor($cajero, $this->desde, $this->hasta);

    // Quién vendió y quién cobró son dos columnas de dos tablas (M-19). Sin el segundo
    // bloque, el panel del Cajero —que cobra y no vende— sería todo ceros.
    expect($delVendedor['vendi']['cantidad'])->toBe(1)
        ->and($delVendedor['cobre']['neto'])->toBe(0.0)
        ->and($delCajero['vendi']['cantidad'])->toBe(0)
        ->and($delCajero['cobre']['neto'])->toBe(3000.0)
        ->and($delCajero['cobre']['movimientos'])->toBe(1);
});

test('el contra-asiento de una devolucion baja lo cobrado del cajero', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $cajero   = usuarioCon('panel.ver_propio');

    $venta = ventaCobrada($producto, 3, cajero: $cajero);

    app(VentaService::class)->devolver($venta, [
        'cantidades' => [$venta->lineas->first()->id => 1],
        'metodo'     => 'efectivo',
    ], $cajero);

    $this->travelBack();

    $panel = $this->service->paraVendedor($cajero, $this->desde, $this->hasta);

    // 3000 cobrados menos 1000 devueltos, en dos movimientos.
    expect($panel['cobre']['neto'])->toBe(2000.0)
        ->and($panel['cobre']['movimientos'])->toBe(2);
});

test('los presupuestos abiertos son una instantanea y no dependen del periodo', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');

    $this->travelTo(Carbon::parse('2026-01-05 10:00'));
    Venta::factory()->conLinea($producto, 2)->create(['usuario_id' => $vendedor->id]);
    $this->travelBack();

    $panel = $this->service->paraVendedor($vendedor, $this->desde, $this->hasta);

    // El presupuesto es de enero y el período es marzo: igual espera respuesta hoy.
    expect($panel['presupuestosAbiertos'])->toBe(1)
        ->and($panel['vendi']['cantidad'])->toBe(0);
});

test('una venta fuera del periodo no entra', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');

    $this->travelTo(Carbon::parse('2026-02-28 23:00'));
    ventaCobrada($producto, 3, vendedor: $vendedor, cajero: $vendedor);
    $this->travelBack();

    $panel = $this->service->paraVendedor($vendedor, $this->desde, $this->hasta);

    expect($panel['vendi']['cantidad'])->toBe(0)
        ->and($panel['cobre']['neto'])->toBe(0.0);
});

test('el facturado suma todas las ventas del periodo sin importar el vendedor', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    ventaCobrada($producto, 3);
    ventaCobrada($producto, 2);

    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    expect($panel['cantidad'])->toBe(2)
        ->and($panel['facturado'])->toBe(5000.0)
        ->and($panel['ticket'])->toBe(2500.0);
});

test('el facturado no descuenta las devoluciones y lo cobrado si', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 3);

    app(VentaService::class)->devolver($venta, [
        'cantidades' => [$venta->lineas->first()->id => 3],
        'metodo'     => 'efectivo',
    ], admin());

    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // La venta quedó en `devuelta`, que cuenta como vendida: su total sigue sumando en
    // el facturado, porque SUM(ventas.total) no sabe nada de devoluciones. Lo cobrado
    // vuelve a cero solo, porque el contra-asiento entra en la misma suma.
    expect($panel['facturado'])->toBe(3000.0)
        ->and($panel['cobrado'])->toBe(0.0)
        ->and($panel['margenBruto'])->toBe(0.0);
});

test('lo cobrado se filtra por la fecha del pago y no por la de la venta', function () {
    $producto = productoConPrecios(contado: 1000);

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    $venta = ventaCobrada($producto, 3);
    $linea = $venta->lineas->first();
    $this->travelBack();

    // La devolución ocurre en abril: la plata sale en abril, no en marzo.
    $this->travelTo(Carbon::parse('2026-04-05 10:00'));
    app(VentaService::class)->devolver($venta->fresh(), [
        'cantidades' => [$linea->id => 3],
        'metodo'     => 'efectivo',
    ], admin());
    $this->travelBack();

    $marzo = $this->service->paraAdministrativo($this->desde, $this->hasta);
    $abril = $this->service->paraAdministrativo(
        Carbon::parse('2026-04-01')->startOfDay(),
        Carbon::parse('2026-04-30')->endOfDay(),
    );

    expect($marzo['cobrado'])->toBe(3000.0)
        ->and($abril['cobrado'])->toBe(-3000.0)
        ->and($abril['facturado'])->toBe(0.0);
});

test('el margen descuenta lo devuelto linea por linea', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000, costo: 600);
    $venta    = ventaCobrada($producto, 3);

    app(VentaService::class)->devolver($venta, [
        'cantidades' => [$venta->lineas->first()->id => 1],
        'metodo'     => 'efectivo',
    ], admin());

    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // Quedan 2 unidades vendidas, a 400 de margen cada una.
    expect($panel['margenBruto'])->toBe(800.0);
});

test('el margen es bruto y los descuentos se informan aparte', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    ventaCobrada(productoConPrecios(contado: 1000, costo: 600), 3, porcentajeDescuento: 10);

    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // El margen sale de los importes de la línea, que no conocen `ventas.descuento`:
    // 3 × (1000 − 600) = 1200, sin descontar los 300 del descuento. Por eso el panel
    // lo rotula como bruto y muestra los descuentos al lado, en vez de informar un
    // margen optimista sin decirlo. El PanelService del plan-accion no lo contemplaba.
    expect($panel['margenBruto'])->toBe(1200.0)
        ->and($panel['descuentos'])->toBe(300.0)
        ->and($panel['facturado'])->toBe(2700.0);
});

test('lo cobrado por metodo agrupa y deja negativo el medio por el que volvio la plata', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 3, metodo: 'transferencia');

    // El medio de la devolución lo elige quien devuelve: entró por transferencia y
    // sale de la caja en efectivo.
    app(VentaService::class)->devolver($venta, [
        'cantidades' => [$venta->lineas->first()->id => 1],
        'metodo'     => 'efectivo',
    ], admin());

    $this->travelBack();

    $porMetodo = $this->service->paraAdministrativo($this->desde, $this->hasta)['porMetodo']
        ->keyBy('metodo');

    expect($porMetodo)->toHaveCount(2)
        ->and(round((float) $porMetodo['transferencia']->neto, 2))->toBe(3000.0)
        ->and(round((float) $porMetodo['efectivo']->neto, 2))->toBe(-1000.0);
});

test('el facturado por dia agrupa por fecha de emision', function () {
    $producto = productoConPrecios(contado: 1000);

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($producto, 1);
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-03-12 10:00'));
    ventaCobrada($producto, 2);
    ventaCobrada($producto, 3);
    $this->travelBack();

    $porDia = $this->service->paraAdministrativo($this->desde, $this->hasta)['porDia'];

    expect($porDia)->toHaveCount(2)
        ->and($porDia->first()->dia)->toBe('2026-03-10')
        ->and(round((float) $porDia->first()->facturado, 2))->toBe(1000.0)
        ->and((int) $porDia->last()->cantidad)->toBe(2)
        ->and(round((float) $porDia->last()->facturado, 2))->toBe(5000.0);
});

test('el stock critico y las compras abiertas son instantaneas', function () {
    Producto::factory()->conStock(1)->create(['stock_minimo' => 5]);
    Producto::factory()->conStock(50)->create(['stock_minimo' => 5]);

    // La orden es de enero y el período es marzo: sigue esperando igual.
    $this->travelTo(Carbon::parse('2026-01-05 10:00'));
    OrdenCompra::factory()->create();
    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    expect($panel['stockCritico'])->toBe(1)
        ->and($panel['comprasAbiertas'])->toBe(1);
});

test('el conteo no cuenta lo que no se repone solo ni lo que ya viene en camino', function () {
    $proveedor = Proveedor::factory()->create();

    Producto::factory()->conStock(1)->conProveedor($proveedor, costo: 1000)
        ->create(['stock_minimo' => 5]);

    // Mínimo cero: stockCritico() lo daría por crítico porque 0 <= 0, y el comando
    // de las 07:00 nunca lo pidió. El panel lo contaba.
    Producto::factory()->conStock(0)->create(['stock_minimo' => 0]);

    $enCamino = Producto::factory()->conStock(0)->conProveedor($proveedor, costo: 1000)
        ->create(['stock_minimo' => 5]);
    OrdenCompra::factory()->conLinea($enCamino, 10)->create(['proveedor_id' => $proveedor->id]);

    // El panel, el comando y la pantalla de sugeridos dicen el mismo número: es
    // para lo que paraReponer() existe.
    expect($this->service->paraAdministrativo($this->desde, $this->hasta)['stockCritico'])->toBe(1)
        ->and(Producto::paraReponer()->count())->toBe(1);
});

test('ninguna consulta del panel trae filas completas', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada(productoConPrecios(contado: 1000), 3);
    $this->travelBack();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->service->paraAdministrativo($this->desde, $this->hasta);

    $consultas = collect(DB::getQueryLog())->pluck('query');

    DB::disableQueryLog();

    expect($consultas)->not->toBeEmpty();

    foreach ($consultas as $consulta) {
        $externa = preg_replace('/exists \(.*\)/is', 'exists (…)', $consulta);

        expect($externa)->not->toContain('select *')
            ->and($consulta)->toMatch('/count\(|sum\(|avg\(|group by/i');
    }
});