<?php

use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// La impresión se pide a la API y se abre por enlace firmado (D-22, tramo D de la Etapa 3).
beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('imprimir las marcas pide marca.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('marca'), ['marca.ver']));

    $this->getJson('/api/v1/marcas/impresion', bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    app('auth')->forgetGuards();

    $url = $this->getJson('/api/v1/marcas/impresion', bearer(usuarioCon('marca.ver')))
        ->assertOk()->json('datos.url');

    $respuesta = $this->get($url)->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('marcas-');
});

test('la planilla de marcas trae cuantos productos tiene cada una, con lo que filtra', function () {
    $logitech = Marca::factory()->create(['nombre' => 'Logitech']);
    Producto::factory()->count(2)->create(['marca_id' => $logitech->id]);
    Marca::factory()->create(['nombre' => 'Genius']);
    Marca::factory()->create(['activo' => false]);

    $url = $this->getJson('/api/v1/marcas/impresion?formato=csv&estado=activas', bearer(usuarioCon('marca.ver')))
        ->assertOk()->json('datos.url');

    $filas = ($this->filasDe)($this->get($url)->assertOk()->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Estado: Activas')
        ->and($filas[4])->toBe(['Marca', 'Estado', 'Productos'])
        ->and($filas[5])->toBe(['Logitech', 'Activa', '2'])
        ->and($filas[6])->toBe(['Genius', 'Activa', '0'])
        ->and(end($filas)[0])->toBe('Total: 2 marcas');
});

test('el enlace de imprimir de marcas lleva los filtros puestos y no la pagina', function () {
    $url = $this->getJson('/api/v1/marcas/impresion?estado=activas&pagina=2', bearer(usuarioCon('marca.ver')))
        ->assertOk()->json('datos.url');

    expect($url)->toContain('estado=activas')->not->toContain('pagina=');
});

test('una opcion de impresion mal pedida es 422 y no da enlace', function () {
    $this->getJson('/api/v1/marcas/impresion?columnas[]=inventada', bearer(usuarioCon('marca.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');
});
