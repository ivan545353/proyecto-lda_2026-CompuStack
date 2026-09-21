<?php

use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Filtros del listado de categorías (hallazgo A-24). En el original,
| CategoryController enviaba `estado`, que no existía ni como columna, y el
| DAO esperaba `nombre`: ningún filtro se aplicaba.
*/

test('buscar devuelve solo las categorias cuyo nombre contiene el texto', function () {
    Categoria::factory()->create(['nombre' => 'Discos SSD']);
    Categoria::factory()->create(['nombre' => 'Discos HDD']);
    Categoria::factory()->create(['nombre' => 'Monitores']);

    expect(Categoria::buscar('discos')->count())->toBe(2);
});

test('conEstado separa activas de inactivas', function () {
    Categoria::factory()->count(2)->create();
    Categoria::factory()->inactiva()->create();

    expect(Categoria::conEstado('activas')->count())->toBe(2)
        ->and(Categoria::conEstado('inactivas')->count())->toBe(1);
});

test('dePadre raiz devuelve solo las de primer nivel', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->hijaDe($raiz)->create();
    Categoria::factory()->create();

    expect(Categoria::dePadre('raiz')->count())->toBe(2);
});

test('dePadre con un id devuelve las hijas directas y no las nietas', function () {
    $raiz  = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($raiz)->create();
    Categoria::factory()->hijaDe($raiz)->create();
    Categoria::factory()->hijaDe($hija)->create();   // nieta de $raiz

    // Categoría exacta, sin recursión: es la decisión del contrato de filtros.
    expect(Categoria::dePadre($raiz->id)->count())->toBe(2)
        ->and(Categoria::dePadre((string) $raiz->id)->count())->toBe(2);
});

test('dePadre sin valor o con un valor no numerico no filtra', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->hijaDe($raiz)->create();

    expect(Categoria::dePadre(null)->count())->toBe(2)
        ->and(Categoria::dePadre('')->count())->toBe(2)
        ->and(Categoria::dePadre('abc')->count())->toBe(2);
});

test('los filtros se combinan entre si', function () {
    $raiz = Categoria::factory()->create(['nombre' => 'Almacenamiento']);
    Categoria::factory()->hijaDe($raiz)->create(['nombre' => 'Discos SSD']);
    Categoria::factory()->hijaDe($raiz)->inactiva()->create(['nombre' => 'Discos HDD']);
    Categoria::factory()->create(['nombre' => 'Discos externos']);

    expect(Categoria::dePadre($raiz->id)->buscar('discos')->conEstado('activas')->pluck('nombre')->all())
        ->toBe(['Discos SSD']);
});