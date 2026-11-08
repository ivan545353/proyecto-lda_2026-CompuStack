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

    $csv = $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.exportar', ['formato' => 'csv']))
        ->assertOk()
        ->streamedContent();

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

test('una opcion mal pedida vuelve al listado de ventas con sus filtros', function () {
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.exportar', ['estado' => 'pagada', 'columnas' => ['numero', 'margen']]))
        ->assertRedirect(route('ventas.index', ['estado' => 'pagada']))
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'No se imprimió'));
});