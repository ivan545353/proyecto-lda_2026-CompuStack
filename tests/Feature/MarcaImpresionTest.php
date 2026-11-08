<?php

use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('imprimir las marcas pide marca.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('marca'), ['marca.ver']));

    $this->actingAs(usuarioCon(...$otros))->get(route('marcas.imprimir'))->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('marca.ver'))->get(route('marcas.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('marcas-');
});

test('la planilla de marcas trae cuantos productos tiene cada una, con lo que filtra', function () {
    $logitech = Marca::factory()->create(['nombre' => 'Logitech']);
    Producto::factory()->count(2)->create(['marca_id' => $logitech->id]);
    Marca::factory()->create(['nombre' => 'Genius']);
    Marca::factory()->create(['activo' => false]);

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.imprimir', ['formato' => 'csv', 'estado' => 'activas']))
        ->assertOk()
        ->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Estado: Activas')
        ->and($filas[4])->toBe(['Marca', 'Estado', 'Productos'])
        ->and($filas[5])->toBe(['Logitech', 'Activa', '2'])
        ->and($filas[6])->toBe(['Genius', 'Activa', '0'])
        ->and(end($filas)[0])->toBe('Total: 2 marcas');
});

test('el boton de imprimir de marcas lleva los filtros puestos y no la pagina', function () {
    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index', ['estado' => 'activas', 'page' => 2]))
        ->assertOk()
        ->assertSee(route('marcas.imprimir', ['estado' => 'activas']))
        ->assertDontSee(route('marcas.imprimir', ['estado' => 'activas', 'page' => 2]));
});