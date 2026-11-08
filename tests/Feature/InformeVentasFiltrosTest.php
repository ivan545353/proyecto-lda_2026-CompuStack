<?php

use App\Models\Venta;
use App\Services\InformeVentasService;
use App\Services\VentaService;
use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(InformeVentasService::class);

    $this->desde = Carbon::parse('2026-03-01')->startOfDay();
    $this->hasta = Carbon::parse('2026-03-31')->endOfDay();
});

/*
|--------------------------------------------------------------------------
| Los dos informes que salieron del panel
|--------------------------------------------------------------------------
|
| Las tres reglas que PanelServiceTest fijaba sobre el ranking y los más vendidos se
| mudaron acá con el tramo L, contra las consultas que ahora se paginan.
|
*/

test('el ranking de vendedores agrupa por vendedor y ordena por facturado', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    $flojo    = usuarioCon('venta.crear');
    $fuerte   = usuarioCon('venta.crear');

    ventaCobrada($producto, 1, vendedor: $flojo);
    ventaCobrada($producto, 3, vendedor: $fuerte);
    ventaCobrada($producto, 2, vendedor: $fuerte);

    $this->travelBack();

    $ranking = $this->service->vendedores($this->desde, $this->hasta)->get();

    expect($ranking)->toHaveCount(2)
        ->and($ranking->first()->usuario_id)->toBe($fuerte->id)
        ->and((int) $ranking->first()->cantidad)->toBe(2)
        ->and(round((float) $ranking->first()->facturado, 2))->toBe(5000.0)
        ->and(round((float) $ranking->first()->ticket, 2))->toBe(2500.0)
        ->and($ranking->last()->usuario_id)->toBe($flojo->id);
});

test('la fila de totales suma lo mismo que las filas del ranking', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    $producto = productoConPrecios(contado: 1000);
    ventaCobrada($producto, 1, vendedor: usuarioCon('venta.crear'));
    ventaCobrada($producto, 3, vendedor: usuarioCon('venta.crear'));

    $this->travelBack();

    $filas = $this->service->vendedores($this->desde, $this->hasta)->get();
    $total = $this->service->totalDeVendedores($this->desde, $this->hasta);

    // Es donde quedaron «ventas cobradas» y «ticket promedio» del conjunto.
    expect((int) $total->cantidad)->toBe((int) $filas->sum('cantidad'))
        ->and(round((float) $total->facturado, 2))->toBe(4000.0)
        ->and(round((float) $total->ticket, 2))->toBe(2000.0);
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

    $mas = $this->service->productos($this->desde, $this->hasta)->get();

    // El devuelto por completo da cero unidades netas y no es uno de los más vendidos:
    // el HAVING lo saca en vez de dejarlo en la lista con un cero.
    expect($mas)->toHaveCount(1)
        ->and($mas->first()->producto_id)->toBe($queda->id)
        ->and((int) $mas->first()->unidades)->toBe(5)
        ->and(round((float) $mas->first()->importe, 2))->toBe(5000.0);
});

test('un presupuesto no entra en ninguno de los dos informes', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    Venta::factory()->conLinea(productoConPrecios(contado: 1000), 3)->create();
    $this->travelBack();

    expect($this->service->vendedores($this->desde, $this->hasta)->get())->toHaveCount(0)
        ->and($this->service->productos($this->desde, $this->hasta)->get())->toHaveCount(0)
        ->and((int) $this->service->totalDeVendedores($this->desde, $this->hasta)->cantidad)->toBe(0);
});

test('una venta fuera del periodo no entra en los informes', function () {
    $producto = productoConPrecios(contado: 1000);

    $this->travelTo(Carbon::parse('2026-02-28 23:00'));
    ventaCobrada($producto, 3);
    $this->travelBack();

    expect($this->service->vendedores($this->desde, $this->hasta)->get())->toHaveCount(0)
        ->and($this->service->productos($this->desde, $this->hasta)->get())->toHaveCount(0);
});

test('el orden desempata por id para que la paginacion no repita filas', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));

    // Tres productos con las mismas unidades: sin desempate, MariaDB puede devolverlos
    // en otro orden en cada página.
    $productos = collect(range(1, 3))->map(fn () => productoConPrecios(contado: 1000));
    $productos->each(fn ($producto) => ventaCobrada($producto, 2));

    $this->travelBack();

    expect($this->service->productos($this->desde, $this->hasta)->get()->pluck('producto_id')->all())
        ->toBe($productos->pluck('id')->sort()->values()->all());
});

test('ninguna consulta de los informes trae filas completas', function () {
    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada(productoConPrecios(contado: 1000), 3);
    $this->travelBack();

    $consultas = collect([
        $this->service->vendedores($this->desde, $this->hasta)->toSql(),
        $this->service->productos($this->desde, $this->hasta)->toSql(),
    ]);

    foreach ($consultas as $consulta) {
        expect($consulta)->not->toContain('select *')
            ->and($consulta)->toMatch('/count\(|sum\(|group by/i');
    }
});