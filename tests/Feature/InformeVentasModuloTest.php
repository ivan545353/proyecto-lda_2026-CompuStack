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

// Tramo H: las filas van por la API, con el mismo permiso que la pantalla.
dataset('endpoints de informes', [
    'ranking de vendedores'  => ['/api/v1/informes/vendedores'],
    'productos más vendidos' => ['/api/v1/informes/productos'],
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

test('ningun otro permiso del modulo habilita los endpoints de informes', function (string $ruta) {
    $otros = array_values(array_diff(permisosDelModulo('panel'), ['panel.ver_global']));

    $this->getJson($ruta, bearer(usuarioCon('venta.ver', ...$otros)))->assertForbidden();
})->with('endpoints de informes');

test('el permiso del panel general alcanza para los endpoints de informes', function (string $ruta) {
    $this->getJson($ruta, bearer(usuarioCon('panel.ver_global')))->assertOk();
})->with('endpoints de informes');

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

    // Tramo H: el shell pide a la API la consulta de su URL, tal cual.
    $this->getJson('/api/v1/informes/vendedores?desde=2026-03-01&hasta=2026-03-31', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.usuario_id', $vendedor->id)
        ->assertJsonPath('datos.0.facturado', '3000.00');
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

    $this->getJson('/api/v1/informes/productos?desde=2026-03-01&hasta=2026-03-31', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.producto_id', $marzo->id);
});

test('un periodo invalido se rechaza con el error en su campo', function (string $ruta) {
    // Tramo H: antes redirigía al mes en curso con un aviso; por la API es un 422 que el
    // filtro marca en su campo (patrón HL-120).
    $this->getJson("{$ruta}?desde=2026-03-31&hasta=2026-03-01", bearer(usuarioCon('panel.ver_global')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('hasta', 'errores');
})->with('endpoints de informes');

test('el informe pagina de a 15 y los enlaces conservan el periodo', function () {
    $vendedor = usuarioCon('venta.crear');

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    foreach (range(1, 16) as $i) {
        ventaCobrada(productoConPrecios(contado: 1000), 1, vendedor: $vendedor, cajero: $vendedor);
    }
    $this->travelBack();

    $credencial = bearer(usuarioCon('panel.ver_global'));

    $this->getJson('/api/v1/informes/productos?desde=2026-03-01&hasta=2026-03-31', $credencial)
        ->assertOk()
        ->assertJsonCount(15, 'datos')
        ->assertJsonPath('por_pagina', 15)
        ->assertJsonPath('total', 16);

    // Sin el período, pasar a la página 2 volvería al mes en curso (A-25). Tramo H: los enlaces
    // de página los arma el JS sobre la consulta de la URL, así que la página 2 sigue siendo de marzo.
    $this->getJson('/api/v1/informes/productos?desde=2026-03-01&hasta=2026-03-31&pagina=2', $credencial)
        ->assertOk()
        ->assertJsonCount(1, 'datos')
        ->assertJsonPath('criterios.hasta', '2026-03-31');
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

    // Ventas cobradas y ticket promedio salieron del panel: se leen acá. Tramo H: el total
    // llega en `totales` y el shell trae su fila al pie.
    $this->getJson('/api/v1/informes/vendedores', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('totales.cantidad', 2)
        ->assertJsonPath('totales.facturado', '4000.00')
        ->assertJsonPath('totales.ticket', '2000.00');

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('ventas.vendedores'))
        ->assertOk()
        ->assertSeeText('Ticket promedio')
        ->assertSee('data-total hidden', false);
});

test('el vacio del mes y el vacio del periodo elegido dicen cosas distintas', function (string $ruta) {
    $usuario = usuarioCon('panel.ver_global');

    // Tramo H: los dos textos son del shell; cuál se muestra lo decide el JS según la URL traiga
    // período, que es lo mismo que la API informa en `criterios.elegido`.
    $html = $this->actingAs($usuario)->get(route($ruta))->assertOk()->getContent();

    preg_match('/data-vacio="([^"]*)"/', $html, $vacioDelMes);
    preg_match('/data-vacio-filtrado="([^"]*)"/', $html, $vacioElegido);

    expect($vacioDelMes[1] ?? '')->toMatch('/Todavía\s+no\s+hay/')
        ->and($vacioElegido[1] ?? '')->toMatch('/entre\s+esas\s+fechas/');

    app('auth')->forgetGuards();

    $endpoint = $ruta === 'ventas.vendedores' ? '/api/v1/informes/vendedores' : '/api/v1/informes/productos';

    $this->getJson($endpoint, bearer($usuario))->assertJsonPath('criterios.elegido', false)->assertJsonPath('datos', []);
    $this->getJson("{$endpoint}?desde=2026-03-01&hasta=2026-03-31", bearer($usuario))->assertJsonPath('criterios.elegido', true);
})->with('informes de ventas');

test('los shells de los informes no traen datos de negocio', function (string $ruta) {
    // Regla 37: la página llega con su estructura; las filas, por la API.
    $producto = productoConPrecios(contado: 1234);
    ventaCobrada($producto, 3);

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route($ruta))
        ->assertOk()
        ->assertDontSee($producto->nombre)
        ->assertDontSee('3.702,00');
})->with('informes de ventas');
