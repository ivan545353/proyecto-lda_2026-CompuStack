<?php

use App\Models\Marca;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de marcas
|--------------------------------------------------------------------------
|
| Cubre el hallazgo A-24. Cada filtro del contrato tiene su test: si alguien
| renombra un scope, cambia el parámetro o rompe la condición, esto falla.
| En el sistema original tres módulos tenían filtros que no filtraban y el
| bug convivió con la versión final justamente porque no existía este archivo.
|
*/

test('buscar devuelve solo las marcas cuyo nombre contiene el texto', function () {
    Marca::factory()->create(['nombre' => 'Kingston', 'slug' => 'kingston']);
    Marca::factory()->create(['nombre' => 'King Tech', 'slug' => 'king-tech']);
    Marca::factory()->create(['nombre' => 'Logitech', 'slug' => 'logitech']);

    $resultado = Marca::buscar('king')->pluck('nombre');

    expect($resultado)->toHaveCount(2)
        ->and($resultado)->toContain('Kingston', 'King Tech');
});

test('buscar sin texto no filtra nada', function () {
    Marca::factory()->count(3)->create();

    expect(Marca::buscar(null)->count())->toBe(3)
        ->and(Marca::buscar('')->count())->toBe(3)
        ->and(Marca::buscar('   ')->count())->toBe(3);
});

test('buscar trata el comodin de LIKE como texto literal', function () {
    Marca::factory()->create(['nombre' => 'Marca 100%', 'slug' => 'marca-100']);
    Marca::factory()->create(['nombre' => 'Otra marca', 'slug' => 'otra-marca']);

    // Sin escapar, '%' traería las dos: el buscador devolvería la tabla entera
    // a quien busque un porcentaje.
    expect(Marca::buscar('100%')->count())->toBe(1);
});

test('conEstado separa activas de inactivas', function () {
    Marca::factory()->count(2)->create();
    Marca::factory()->inactiva()->create();

    expect(Marca::conEstado('activas')->count())->toBe(2)
        ->and(Marca::conEstado('inactivas')->count())->toBe(1);
});

test('conEstado sin valor o con un valor desconocido no filtra', function () {
    Marca::factory()->count(2)->create();
    Marca::factory()->inactiva()->create();

    expect(Marca::conEstado(null)->count())->toBe(3)
        ->and(Marca::conEstado('')->count())->toBe(3)
        ->and(Marca::conEstado('cualquier-cosa')->count())->toBe(3);
});

test('los filtros se combinan entre si', function () {
    Marca::factory()->create(['nombre' => 'Kingston', 'slug' => 'kingston']);
    Marca::factory()->inactiva()->create(['nombre' => 'King Tech', 'slug' => 'king-tech']);
    Marca::factory()->create(['nombre' => 'Logitech', 'slug' => 'logitech']);

    expect(Marca::buscar('king')->conEstado('activas')->pluck('nombre')->all())
        ->toBe(['Kingston']);
});