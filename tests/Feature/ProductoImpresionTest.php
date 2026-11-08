<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('imprimir el catalogo pide producto.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('producto'), ['producto.ver']));

    $this->actingAs(usuarioCon('precio.ver_costo', ...$otros))
        ->get(route('productos.imprimir'))
        ->assertForbidden();

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.imprimir'))
        ->assertOk();
});

test('sin opciones devuelve un pdf para descargar y no cambia nada', function () {
    $producto = Producto::factory()->conStockYCosto(4, 800)->create();

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))->get(route('productos.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('attachment')
        ->toContain('catalogo-')->toContain('.pdf');

    $despues = $producto->fresh();
    expect($despues->stock)->toBe(4)
        ->and((float) $despues->costo_promedio)->toBe(800.0);
});

test('el csv sale completo, sin el tope del pdf', function () {
    $categoria = Categoria::factory()->create();
    $marca     = Marca::factory()->create();
    Producto::factory()->count(501)->create(['categoria_id' => $categoria->id, 'marca_id' => $marca->id]);

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.imprimir', ['formato' => 'csv']));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('text/csv')
        ->and($respuesta->headers->get('content-disposition'))->toContain('.csv');

    $lineas = explode("\r\n", rtrim($respuesta->streamedContent(), "\r\n"));

    expect(count($lineas))->toBe(5 + 501 + 2)
        ->and(end($lineas))->toStartWith('"Total: 501 productos"');
});

test('el csv imprime lo que el listado filtra, y lo dice', function () {
    $marca = Marca::factory()->create(['nombre' => 'Logitech']);
    Producto::factory()->create(['codigo' => 'A-SI', 'marca_id' => $marca->id]);
    Producto::factory()->create(['codigo' => 'B-NO']);

    $csv = $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.imprimir', ['formato' => 'csv', 'marca_id' => $marca->id]))
        ->streamedContent();

    expect($csv)->toContain('Alcance: Marca: Logitech')
        ->toContain('A-SI')
        ->not->toContain('B-NO');
});

test('pedir costos sin precio.ver_costo vuelve al listado con el motivo y no imprime', function () {
    $marca = Marca::factory()->create();

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.imprimir', ['marca_id' => $marca->id, 'columnas' => ['codigo', 'costo']]))
        ->assertRedirect(route('productos.index', ['marca_id' => $marca->id]))
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'pide un permiso que no tenés'));
});

test('una opcion que no existe vuelve al listado en lugar de imprimir otra cosa', function () {
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.imprimir', ['formato' => 'xlsx']))
        ->assertRedirect(route('productos.index'))
        ->assertSessionHas('error');
});

test('el boton de imprimir lleva los filtros puestos y no la pagina', function () {
    $marca = Marca::factory()->create();

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['marca_id' => $marca->id, 'page' => 2]))
        ->assertOk()
        ->assertSee(route('productos.imprimir', ['marca_id' => $marca->id]))
        ->assertDontSee(route('productos.imprimir', ['marca_id' => $marca->id, 'page' => 2]));
});