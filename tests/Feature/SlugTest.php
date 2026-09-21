<?php

use App\Models\Marca;
use App\Support\Slug;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('genera el slug a partir del nombre', function () {
    expect(Slug::unicoPara(Marca::class, 'Western Digital'))->toBe('western-digital');
});

test('agrega un sufijo cuando el slug ya existe', function () {
    Marca::factory()->create(['nombre' => 'King Tech', 'slug' => 'king-tech']);

    expect(Slug::unicoPara(Marca::class, 'King Tech'))->toBe('king-tech-2');
});

test('el sufijo sigue avanzando mientras haya colisiones', function () {
    Marca::factory()->create(['nombre' => 'A', 'slug' => 'king-tech']);
    Marca::factory()->create(['nombre' => 'B', 'slug' => 'king-tech-2']);

    expect(Slug::unicoPara(Marca::class, 'King Tech'))->toBe('king-tech-3');
});

test('al editar, el registro no colisiona consigo mismo', function () {
    $marca = Marca::factory()->create(['nombre' => 'Kingston', 'slug' => 'kingston']);

    expect(Slug::unicoPara(Marca::class, 'Kingston', $marca->id))->toBe('kingston');
});

test('un nombre sin caracteres utiles no deja el slug vacio', function () {
    expect(Slug::unicoPara(Marca::class, '???'))->toBe('sin-nombre');
});

test('el slug no supera el largo de la columna', function () {
    expect(strlen(Slug::unicoPara(Marca::class, str_repeat('componentes ', 20))))
        ->toBeLessThanOrEqual(120);
});