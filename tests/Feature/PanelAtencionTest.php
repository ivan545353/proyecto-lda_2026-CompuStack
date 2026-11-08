<?php

use App\Models\OrdenCompra;
use App\Models\Venta;
use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Cuántos números de la pantalla son enlace: el ancla la pone _tarjeta a propósito.
    $this->enlaces = fn (string $html): int => preg_match_all('/\bdata-atencion\b/', $html);

    // Si el número enlaza a esa URL. El menú también enlaza a los sugeridos, así que
    // buscar la URL sola no probaría nada de la tarjeta.
    $this->enlazaA = fn (string $html, string $url): bool => preg_match(
        '/<a href="'.preg_quote(e($url), '/').'"[^>]*\bdata-atencion\b/',
        $html,
    ) === 1;
});

/*
|--------------------------------------------------------------------------
| La zona de atención del panel general
|--------------------------------------------------------------------------
|
| Cada número de atención es un enlace al listado ya filtrado, y sólo para quien
| puede ver ese listado. Sin el permiso el número se ve igual: es el mismo criterio
| que el listado de proveedores con sus conteos.
|
*/

test('cada numero de atencion enlaza a su destino para quien puede verlo', function () {
    $html = $this->actingAs(usuarioCon('panel.ver_global', 'compra.crear', 'compra.ver'))
        ->get(route('panel.general'))
        ->assertOk()
        ->getContent();

    expect(($this->enlazaA)($html, route('compras.sugeridos')))->toBeTrue()
        ->and(($this->enlazaA)($html, route('compras.index', ['estado' => OrdenCompra::ABIERTAS])))->toBeTrue();
});

test('sin el permiso del destino el numero se ve y no es enlace', function () {
    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSeeText('Para reponer')
        ->assertSeeText('Órdenes de compra abiertas')
        ->getContent();

    expect(($this->enlaces)($html))->toBe(0);
});

test('cada enlace pide el permiso de su propio destino', function () {
    // Los sugeridos piden compra.crear y el listado pide compra.ver: con uno solo de
    // los dos, la otra tarjeta no puede ser enlace.
    $html = $this->actingAs(usuarioCon('panel.ver_global', 'compra.ver'))
        ->get(route('panel.general'))
        ->assertOk()
        ->getContent();

    expect(($this->enlazaA)($html, route('compras.sugeridos')))->toBeFalse()
        ->and(($this->enlazaA)($html, route('compras.index', ['estado' => OrdenCompra::ABIERTAS])))->toBeTrue();
});

test('el numero de la tarjeta y el listado del enlace cuentan lo mismo', function () {
    OrdenCompra::factory()->create(['usuario_creo_id' => null]);   // borrador de la reposición
    OrdenCompra::factory()->aprobada()->create();
    OrdenCompra::factory()->recibida()->create();                  // cerrada: no cuenta

    $usuario = usuarioCon('panel.ver_global', 'compra.ver');

    $this->actingAs($usuario)
        ->get(route('panel.general'))
        ->assertSeeText('1 borrador lo generó la reposición')
        ->assertViewHas('metricas', fn (array $m) => $m['comprasAbiertas'] === 2
            && $m['borradoresDeReposicion'] === 1);

    $this->actingAs($usuario)
        ->get(route('compras.index', ['estado' => OrdenCompra::ABIERTAS]))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 2);

    $this->actingAs($usuario)
        ->get(route('compras.index', ['estado' => 'borrador', 'origen' => 'reposicion']))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1);
});

test('sin borradores de la reposicion la tarjeta no agrega la nota', function () {
    OrdenCompra::factory()->create();   // la cargó una persona

    $this->actingAs(usuarioCon('panel.ver_global', 'compra.ver'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertDontSeeText('generó la reposición');
});

/*
|--------------------------------------------------------------------------
| Las cinco tarjetas y su comparación
|--------------------------------------------------------------------------
*/

test('el panel general deja cinco tarjetas y saca las que se leen de un listado', function () {
    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSeeText('Cobrado en el período')
        ->assertSeeText('Facturado en el período')
        ->assertSeeText('Margen bruto')
        ->assertDontSeeText('Ticket promedio')
        ->assertDontSeeText('Ventas cobradas');
});

test('cada numero del periodo dice contra que periodo se compara', function () {
    $producto = productoConPrecios(contado: 1000, costo: 600);

    $this->travelTo(Carbon::parse('2026-02-20 10:00'));
    ventaCobrada($producto, 2);
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($producto, 3);
    $this->travelBack();

    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
        ->assertOk()
        ->getContent();

    // Cobrado, facturado y margen suben lo mismo: 3000 contra 2000, y 1200 contra 800.
    expect(substr_count($html, '50 % más que entre el 29/01 y el 28/02'))->toBe(3);
});

test('sin datos del periodo anterior no se inventa un porcentaje', function () {
    ventaCobrada(productoConPrecios(contado: 1000), 3);

    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'Sin datos del período anterior'))->toBe(3)
        ->and($html)->not->toContain('100 % más');
});

/*
|--------------------------------------------------------------------------
| El panel propio
|--------------------------------------------------------------------------
*/

test('los presupuestos abiertos enlazan a los propios para quien ve ventas', function () {
    $vendedor = usuarioCon('panel.ver_propio', 'venta.ver');
    $producto = productoConPrecios(contado: 1000);

    Venta::factory()->conLinea($producto, 1)->create(['usuario_id' => $vendedor->id]);
    Venta::factory()->conLinea($producto, 1)->create();              // de otro vendedor

    $destino = route('ventas.index', ['estado' => 'presupuesto', 'vendedor_id' => $vendedor->id]);

    $html = $this->actingAs($vendedor)
        ->get(route('panel'))
        ->assertOk()
        ->assertViewHas('metricas', fn (array $m) => $m['presupuestosAbiertos'] === 1)
        ->getContent();

    expect(($this->enlazaA)($html, $destino))->toBeTrue();

    $this->actingAs($vendedor)
        ->get($destino)
        ->assertViewHas('ventas', fn ($v) => $v->total() === 1);
});

test('sin venta.ver los presupuestos abiertos no son enlace', function () {
    $html = $this->actingAs(usuarioCon('panel.ver_propio'))
        ->get(route('panel'))
        ->assertOk()
        ->assertSeeText('Presupuestos abiertos')
        ->getContent();

    expect(($this->enlaces)($html))->toBe(0);
});

test('cada numero del periodo del panel propio lleva su comparacion', function () {
    $html = $this->actingAs(usuarioCon('panel.ver_propio'))
        ->get(route('panel'))
        ->assertOk()
        ->getContent();

    // Cinco números del período; los presupuestos abiertos son de hoy y no se comparan.
    expect(preg_match_all('/\bdata-comparacion="/', $html))->toBe(5);
});


/*
|--------------------------------------------------------------------------
| Los informes que salieron del panel
|--------------------------------------------------------------------------
*/

test('el panel general enlaza a los dos informes con el periodo puesto', function () {
    $periodo = ['desde' => '2026-03-01', 'hasta' => '2026-03-31'];

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general', $periodo))
        ->assertOk()
        ->assertSee(route('ventas.vendedores', $periodo))
        ->assertSee(route('ventas.productos', $periodo))
        ->assertDontSee('ordenados por lo facturado')   // el caption de la tabla que salió
        ->assertSee('data-informes', false);
});

test('los enlaces a los informes estan tambien en un periodo sin ventas', function () {
    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSeeText('No hubo ventas cobradas')
        ->assertSee(route('ventas.vendedores'));
});