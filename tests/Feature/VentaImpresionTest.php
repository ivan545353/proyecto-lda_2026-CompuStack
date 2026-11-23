<?php

use App\Models\Venta;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('la planilla de ventas sale de la mas nueva a la mas vieja, con el total de la base', function () {
    $producto = productoConPrecios(contado: 1000);
    $vieja    = ventaCobrada($producto, 1);
    $nueva    = ventaCobrada($producto, 2);

    // Tramo H: el enlace firmado lo da la API (D-22) y el navegador lo abre sin credencial.
    $enlace = $this->getJson('/api/v1/ventas/impresion?formato=csv', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->json('datos.url');

    app('auth')->forgetGuards();

    $csv = $this->get($enlace)->assertOk()->streamedContent();

    $filas = array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );

    expect($filas[0][0])->toBe('Listado de ventas')
        ->and($filas[4])->toBe(['Número', 'Fecha', 'Cliente', 'Vendedor', 'Estado', 'Total'])
        ->and($filas[5][0])->toBe($nueva->numeroFormateado())
        ->and($filas[6][0])->toBe($vieja->numeroFormateado())
        ->and(end($filas)[0])->toBe('Total: 2 ventas')
        ->and(end($filas)[5])->toBe(number_format((float) Venta::sum('total'), 2, ',', ''));
});

test('una opcion mal pedida se rechaza y no da enlace', function () {
    // Desde el tramo H la pantalla no se recarga: el rechazo es un 422 con su mensaje y no hay enlace.
    $this->getJson('/api/v1/ventas/impresion?estado=pagada&columnas[]=numero&columnas[]=margen', bearer(usuarioCon('venta.ver')))
        ->assertStatus(422)
        ->assertJsonMissingPath('datos.url');
});
