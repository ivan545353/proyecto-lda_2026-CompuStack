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

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(PanelService::class);

    // Período de referencia: marzo de 2026. Fechas fijas y no relativas, para que
    // ningún test dependa del día en que se corre la suite.
    $this->desde = Carbon::parse('2026-03-01')->startOfDay();
    $this->hasta = Carbon::parse('2026-03-31')->endOfDay();
});

// El PanelService de referencia del plan-accion filtraba ['pagada', 'entregada'] y se
// escribió antes de que existiera la devolución: dejaba afuera una venta parcialmente
// devuelta, que sí vendió.

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

    expect($panel['cantidad'])->toBe(0)
        ->and($panel['facturado'])->toBe(0.0)
        ->and($panel['margenBruto'])->toBe(0.0)
        ->and($panel['masVendidos'])->toHaveCount(0);
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

test('el ranking de vendedores agrupa por vendedor y ordena por facturado', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $flojo    = usuarioCon('venta.crear');
    $fuerte   = usuarioCon('venta.crear');

    ventaCobrada($producto, 1, vendedor: $flojo);
    ventaCobrada($producto, 3, vendedor: $fuerte);
    ventaCobrada($producto, 2, vendedor: $fuerte);

    $this->travelBack();

    $ranking = $this->service->paraAdministrativo($this->desde, $this->hasta)['rankingVendedores'];

    expect($ranking)->toHaveCount(2)
        ->and($ranking->first()->usuario_id)->toBe($fuerte->id)
        ->and((int) $ranking->first()->cantidad)->toBe(2)
        ->and(round((float) $ranking->first()->facturado, 2))->toBe(5000.0)
        ->and($ranking->last()->usuario_id)->toBe($flojo->id);
});

test('los mas vendidos descuentan lo devuelto y excluyen lo que volvio entero', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $queda  = productoConPrecios(contado: 1000);
    $vuelve = productoConPrecios(contado: 500);

    ventaCobrada($queda, 5);

    $devuelta = ventaCobrada($vuelve, 4);
    app(VentaService::class)->devolver($devuelta, [
        'cantidades' => [$devuelta->lineas->first()->id => 4],
        'metodo'     => 'efectivo',
    ], admin());

    $this->travelBack();

    $mas = $this->service->paraAdministrativo($this->desde, $this->hasta)['masVendidos'];

    // El devuelto por completo da cero unidades netas y no es uno de los más vendidos:
    // el HAVING lo saca en vez de dejarlo en la lista con un cero.
    expect($mas)->toHaveCount(1)
        ->and($mas->first()->producto_id)->toBe($queda->id)
        ->and((int) $mas->first()->unidades)->toBe(5)
        ->and(round((float) $mas->first()->importe, 2))->toBe(5000.0);
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

    // El criterio de terminado de la fase escrito como test: cada número del panel es
    // una agregación. El original descargaba categorías, productos, ventas y usuarios
    // completos al navegador y contaba con .filter() (A-26).
    foreach ($consultas as $consulta) {
        expect($consulta)->not->toContain('select *')
            ->and($consulta)->toMatch('/count\(|sum\(|avg\(|group by/i');
    }
});