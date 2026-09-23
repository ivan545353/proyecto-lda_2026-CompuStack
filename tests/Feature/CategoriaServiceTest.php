<?php

use App\Models\Categoria;
use App\Models\Producto;
use App\Services\CategoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CategoriaService::class);
});

test('dos subcategorias con el mismo nombre en ramas distintas conviven', function () {
    // El choque que el esquema tiene escondido: el nombre es único por
    // padre, pero el slug es único global. Sin el sufijo, la segunda "SSD"
    // terminaba en un error 23000 del driver.
    $almacenamiento = Categoria::factory()->create(['nombre' => 'Almacenamiento']);
    $notebooks      = Categoria::factory()->create(['nombre' => 'Notebooks']);

    $primera = $this->service->crear(['parent_id' => $almacenamiento->id, 'nombre' => 'SSD', 'orden' => 0, 'activo' => true]);
    $segunda = $this->service->crear(['parent_id' => $notebooks->id,      'nombre' => 'SSD', 'orden' => 0, 'activo' => true]);

    expect($primera->slug)->toBe('ssd')
        ->and($segunda->slug)->toBe('ssd-2');
});

test('editar sin cambiar el nombre no mueve el slug', function () {
    $categoria = $this->service->crear(['nombre' => 'Monitores', 'orden' => 0, 'activo' => true]);

    $editada = $this->service->actualizar($categoria, ['nombre' => 'Monitores', 'orden' => 5, 'activo' => false]);

    expect($editada->slug)->toBe('monitores')
        ->and($editada->orden)->toBe(5);
});

test('editar no pisa el peso por defecto, que no esta en el formulario', function () {
    $categoria = Categoria::factory()->create(['peso_default_gramos' => 1200]);

    $this->service->actualizar($categoria, ['nombre' => 'Otro nombre', 'orden' => 0, 'activo' => true]);

    expect($categoria->fresh()->peso_default_gramos)->toBe(1200);
});

test('una categoria con subcategorias se desactiva en lugar de borrarse', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();

    expect($this->service->eliminar($padre))->toBeFalse();

    // La hija sigue colgando del padre: no quedó convertida en raíz.
    expect($padre->fresh()->activo)->toBeFalse()
        ->and($hija->fresh()->parent_id)->toBe($padre->id)
        ->and($hija->fresh()->activo)->toBeTrue();   // desactivar no se propaga
});

test('una categoria con productos se desactiva en lugar de borrarse', function () {
    $categoria = Categoria::factory()->create();
    Producto::factory()->create(['categoria_id' => $categoria->id]);

    expect($this->service->eliminar($categoria))->toBeFalse();
    expect($categoria->fresh()->activo)->toBeFalse();
});

test('una categoria sin dependencias se borra', function () {
    $categoria = Categoria::factory()->create();

    expect($this->service->eliminar($categoria))->toBeTrue();
    $this->assertModelMissing($categoria);
});

test('los padres posibles excluyen a la propia categoria y a sus descendientes', function () {
    $raiz = Categoria::factory()->create();
    $hija = Categoria::factory()->hijaDe($raiz)->create();
    $otra = Categoria::factory()->create();

    $ids = $this->service->padresPosibles($raiz)->pluck('id')->all();

    expect($ids)->toContain($otra->id)
        ->and(in_array($raiz->id, $ids, true))->toBeFalse()
        ->and(in_array($hija->id, $ids, true))->toBeFalse();
});

test('los padres posibles excluyen a las del ultimo nivel', function () {
    $n1 = Categoria::factory()->create();
    $n2 = Categoria::factory()->hijaDe($n1)->create();
    $n3 = Categoria::factory()->hijaDe($n2)->create();

    $ids = $this->service->padresPosibles()->pluck('id')->all();

    expect($ids)->toContain($n1->id)->toContain($n2->id)
        ->and(in_array($n3->id, $ids, true))->toBeFalse();
});

test('el padre actual se ofrece aunque este inactivo, y las demas inactivas no', function () {
    $padre        = Categoria::factory()->inactiva()->create();
    $hija         = Categoria::factory()->hijaDe($padre)->create();
    $otraInactiva = Categoria::factory()->inactiva()->create();

    $ids = $this->service->padresPosibles($hija)->pluck('id')->all();

    // Si el padre actual faltara, guardar la hija sin tocar nada la mudaría a la raíz.
    expect(in_array($padre->id, $ids, true))->toBeTrue()
        ->and(in_array($otraInactiva->id, $ids, true))->toBeFalse();
});

test('desactivar una categoria no toca su contenido por defecto', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    $producto = Producto::factory()->create(['categoria_id' => $hija->id]);

    $this->service->actualizar($padre, ['nombre' => $padre->nombre, 'orden' => 0, 'activo' => false]);

    expect($padre->fresh()->activo)->toBeFalse()
        ->and($hija->fresh()->activo)->toBeTrue()
        ->and($producto->fresh()->activo)->toBeTrue();
});

test('con la opcion, desactivar alcanza a toda la rama', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    $nieta = Categoria::factory()->hijaDe($hija)->create();
    $producto = Producto::factory()->create(['categoria_id' => $nieta->id]);
    $ajeno    = Producto::factory()->create();

    $this->service->actualizar($padre, ['nombre' => $padre->nombre, 'orden' => 0, 'activo' => false], desactivarContenido: true);

    expect($hija->fresh()->activo)->toBeFalse()
        ->and($nieta->fresh()->activo)->toBeFalse()
        ->and($producto->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue();
});

test('la opcion no hace nada si la categoria queda activa', function () {
    $categoria = Categoria::factory()->create();
    $producto  = Producto::factory()->create(['categoria_id' => $categoria->id]);

    $this->service->actualizar($categoria, ['nombre' => 'Otro', 'orden' => 0, 'activo' => true], desactivarContenido: true);

    expect($producto->fresh()->activo)->toBeTrue();
});

test('contenidoActivo cuenta la rama y no las inactivas', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    Categoria::factory()->hijaDe($padre)->inactiva()->create();
    Producto::factory()->count(2)->create(['categoria_id' => $hija->id]);
    Producto::factory()->inactivo()->create(['categoria_id' => $hija->id]);

    expect($padre->contenidoActivo())->toBe(['subcategorias' => 1, 'productos' => 2]);
});