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

    $usuario    = usuarioCon('panel.ver_global', 'compra.ver');
    $credencial = bearer($usuario);

    // Tramo H: los dos números, de la API; la nota de los borradores la arma el JS con el segundo
    // («1 borrador lo generó la reposición…») en el lugar que el shell le deja, con su enlace.
    $this->getJson('/api/v1/panel/general', $credencial)
        ->assertJsonPath('datos.compras_abiertas', 2)
        ->assertJsonPath('datos.borradores_de_reposicion', 1);

    app('auth')->forgetGuards();

    $this->actingAs($usuario)
        ->get(route('panel.general'))
        ->assertSee('data-texto-detalle', false)
        ->assertSee(e(route('compras.index', ['estado' => 'borrador', 'origen' => 'reposicion'])), false);

    // Desde el tramo G el listado del enlace es la API: el mismo filtro, el mismo número.
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/compras?estado='.OrdenCompra::ABIERTAS, $credencial)
        ->assertJsonPath('total', 2);

    $this->getJson('/api/v1/compras?estado=borrador&origen=reposicion', $credencial)
        ->assertJsonPath('total', 1);
});

test('sin borradores de la reposicion la tarjeta no agrega la nota', function () {
    OrdenCompra::factory()->create();   // la cargó una persona

    // Tramo H: sin borradores de la reposición, la nota (que nace oculta) no se muestra.
    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global', 'compra.ver')))
        ->assertOk()
        ->assertJsonPath('datos.borradores_de_reposicion', 0);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('panel.ver_global', 'compra.ver'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSee('data-detalle hidden', false)
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

    // Tramo H: la frase la arma el servidor (`Comparacion`) y llega en `comparaciones`.
    $comparaciones = $this->getJson('/api/v1/panel/general?desde=2026-03-01&hasta=2026-03-31', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->json('datos.comparaciones');

    // Cobrado, facturado y margen suben lo mismo: 3000 contra 2000, y 1200 contra 800.
    $textos = array_column($comparaciones, 'texto');

    expect(count(array_filter($textos, fn ($t) => str_contains($t, '50 % más que entre el 29/01 y el 28/02'))))->toBe(3);
});

test('sin datos del periodo anterior no se inventa un porcentaje', function () {
    ventaCobrada(productoConPrecios(contado: 1000), 3);

    $comparaciones = $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->json('datos.comparaciones');

    $textos = implode("\n", array_column($comparaciones, 'texto'));

    expect(substr_count($textos, 'Sin datos del período anterior'))->toBe(3)
        ->and($textos)->not->toContain('100 % más')
        ->and(array_column($comparaciones, 'porcentaje'))->toBe([null, null, null]);
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

    // Tramo H: el número, de la API; el enlace, del shell (lleva el id de quien entra).
    $this->getJson('/api/v1/panel', bearer($vendedor))
        ->assertOk()
        ->assertJsonPath('datos.presupuestos_abiertos', 1);

    app('auth')->forgetGuards();

    $html = $this->actingAs($vendedor)
        ->get(route('panel'))
        ->assertOk()
        ->getContent();

    expect(($this->enlazaA)($html, $destino))->toBeTrue();

    // Tramo H: el listado es un shell; lo que el enlace filtra se pide a la API con la misma consulta.
    app('auth')->forgetGuards();

    $this->getJson("/api/v1/ventas?estado=presupuesto&vendedor_id={$vendedor->id}", bearer($vendedor))
        ->assertOk()
        ->assertJsonPath('total', 1);
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