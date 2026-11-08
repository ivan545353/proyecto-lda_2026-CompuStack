<?php

use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Componentes › Almacenamiento › SSD, más una hermana y otra raíz
    $this->raiz   = Categoria::factory()->create(['nombre' => 'Componentes']);
    $this->hija   = Categoria::factory()->hijaDe($this->raiz)->create(['nombre' => 'Almacenamiento']);
    $this->nieta  = Categoria::factory()->hijaDe($this->hija)->create(['nombre' => 'SSD']);
    $this->hermana = Categoria::factory()->hijaDe($this->raiz)->create(['nombre' => 'Fuentes']);
    $this->otra   = Categoria::factory()->create(['nombre' => 'Periféricos']);
});

test('los descendientes incluyen hijas y nietas, y nada mas', function () {
    expect($this->raiz->idsDescendientes())
        ->toEqualCanonicalizing([$this->hija->id, $this->nieta->id, $this->hermana->id])
        ->and($this->hija->idsDescendientes())->toBe([$this->nieta->id])
        ->and($this->nieta->idsDescendientes())->toBe([]);
});

test('el nivel se cuenta desde la raiz', function () {
    expect($this->raiz->nivel())->toBe(1)
        ->and($this->hija->nivel())->toBe(2)
        ->and($this->nieta->nivel())->toBe(3);
});

test('la altura del subarbol cuenta la propia categoria', function () {
    expect($this->nieta->alturaSubarbol())->toBe(1)
        ->and($this->hija->alturaSubarbol())->toBe(2)
        ->and($this->raiz->alturaSubarbol())->toBe(3);
});

test('la ruta muestra la cadena completa de padres', function () {
    $nieta = Categoria::with('padre.padre')->find($this->nieta->id);

    expect($nieta->ruta)->toBe('Componentes › Almacenamiento › SSD')
        ->and($this->otra->ruta)->toBe('Periféricos');
});

test('un ciclo cargado a mano en la base no cuelga los recorridos', function () {
    // La aplicación no va a permitir crear un ciclo (paso 7), pero la base
    // no lo impide: alguien puede hacerlo con un UPDATE directo. El recorrido
    // tiene que terminar igual. Si esto se colgara, el test no terminaría.
    DB::table('categorias')->where('id', $this->raiz->id)->update(['parent_id' => $this->nieta->id]);

    $raiz = $this->raiz->fresh();

    expect($raiz->idsDescendientes())->toHaveCount(3)
        ->and($raiz->nivel())->toBeLessThanOrEqual(4);
});