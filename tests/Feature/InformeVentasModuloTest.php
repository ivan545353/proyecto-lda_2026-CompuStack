<?php

use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

dataset('informes de ventas', [
    'ranking de vendedores'  => ['ventas.vendedores'],
    'productos más vendidos' => ['ventas.productos'],
]);

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
|
| Los dos informes muestran el conjunto, así que piden panel.ver_global: el mismo
| permiso con el que se veían cuando estaban en el panel. venta.ver no alcanza,
| porque lo tiene también el Vendedor, que sólo ve lo suyo.
|
*/

test('ningun otro permiso del modulo habilita los informes', function (string $ruta) {
    $otros = array_values(array_diff(permisosDelModulo('panel'), ['panel.ver_global']));

    $this->actingAs(usuarioCon(...$otros))->get(route($ruta))->assertForbidden();
})->with('informes de ventas');

test('ver las ventas no alcanza para ver los informes del conjunto', function (string $ruta) {
    $this->actingAs(usuarioCon('venta.ver', 'panel.ver_propio'))->get(route($ruta))->assertForbidden();
})->with('informes de ventas');

test('el permiso del panel general alcanza para los informes', function (string $ruta) {
    $this->actingAs(usuarioCon('panel.ver_global'))->get(route($ruta))->assertOk();
})->with('informes de ventas');

/*
|--------------------------------------------------------------------------
| El período llega de la URL a la consulta
|--------------------------------------------------------------------------
*/

test('el periodo de la URL llega al ranking', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('venta.crear');

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($producto, 3, vendedor: $vendedor);
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-04-10 10:00'));
    ventaCobrada($producto, 1);
    $this->travelBack();

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('ventas.vendedores', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
        ->assertOk()
        ->assertViewHas('filas', fn ($filas) => $filas->total() === 1
            && $filas->first()->usuario_id === $vendedor->id
            && round((float) $filas->first()->facturado, 2) === 3000.0);
});

test('el periodo de la URL llega a los mas vendidos', function () {
    $marzo = productoConPrecios(contado: 1000);
    $abril = productoConPrecios(contado: 1000);

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($marzo, 2);
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-04-10 10:00'));
    ventaCobrada($abril, 5);
    $this->travelBack();

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('ventas.productos', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
        ->assertOk()
        ->assertViewHas('filas', fn ($filas) => $filas->total() === 1
            && $filas->first()->producto_id === $marzo->id);
});

test('un periodo invalido vuelve al informe limpio con un aviso', function (string $ruta) {
    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route($ruta, ['desde' => '2026-03-31', 'hasta' => '2026-03-01']))
        ->assertRedirect(route($ruta))
        ->assertSessionHas('error');
})->with('informes de ventas');

test('el informe pagina de a 15 y los enlaces conservan el periodo', function () {
    $vendedor = usuarioCon('venta.crear');

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    foreach (range(1, 16) as $i) {
        ventaCobrada(productoConPrecios(contado: 1000), 1, vendedor: $vendedor, cajero: $vendedor);
    }
    $this->travelBack();

    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('ventas.productos', ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
        ->assertOk()
        ->assertViewHas('filas', fn ($filas) => $filas->count() === 15 && $filas->total() === 16)
        ->getContent();

    // Sin withQueryString(), pasar a la página 2 volvería al mes en curso (A-25). Se mira
    // el enlace de la página 2 y no la URL suelta, que también está en «Volver al panel».
    expect($html)->toMatch('/href="[^"]*hasta=2026-03-31[^"]*page=2"/');
});

/*
|--------------------------------------------------------------------------
| Lo que muestra la pantalla
|--------------------------------------------------------------------------
*/

test('el ranking muestra el total del conjunto con su ticket promedio', function () {
    $producto = productoConPrecios(contado: 1000);
    ventaCobrada($producto, 3);
    ventaCobrada($producto, 1);

    // Ventas cobradas y ticket promedio salieron del panel: se leen acá.
    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('ventas.vendedores'))
        ->assertOk()
        ->assertSeeText('Ticket promedio')
        ->getContent();

    preg_match('/<tfoot\b[^>]*data-total[^>]*>(.*?)<\/tfoot>/s', $html, $pie);

    expect($pie[1] ?? '')->toContain('$ 4.000,00')
        ->and($pie[1] ?? '')->toContain('$ 2.000,00');
});

test('el vacio del mes y el vacio del periodo elegido dicen cosas distintas', function (string $ruta) {
    $usuario = usuarioCon('panel.ver_global');

    $delMes = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();
    $elegido = $this->actingAs($usuario)
        ->get(route($ruta, ['desde' => '2026-03-01', 'hasta' => '2026-03-31']))
        ->assertOk()
        ->getContent();

    preg_match('/data-vacio[^>]*>(.*?)<\/td>/s', $delMes, $vacioDelMes);
    preg_match('/data-vacio[^>]*>(.*?)<\/td>/s', $elegido, $vacioElegido);

    expect($vacioDelMes[1] ?? '')->toMatch('/Todavía\s+no\s+hay/')
        ->and($vacioElegido[1] ?? '')->toMatch('/entre\s+esas\s+fechas/');
})->with('informes de ventas');