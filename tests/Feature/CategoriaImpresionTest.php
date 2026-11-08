<?php

use App\Impresion\ListadoCategorias;
use App\Models\Categoria;
use App\Models\Producto;
use App\Services\ImpresionService;
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

test('imprimir las categorias pide categoria.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('categoria'), ['categoria.ver']));

    $this->actingAs(usuarioCon(...$otros))->get(route('categorias.imprimir'))->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('categoria.ver'))->get(route('categorias.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('categorias-');
});

test('las subcategorias de una categoria salen con su ubicacion y sus conteos de la base', function () {
    $componentes = Categoria::factory()->create(['nombre' => 'Componentes']);
    $placas      = Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Placas de video']);
    Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Discos']);
    Categoria::factory()->hijaDe($placas)->create(['nombre' => 'Gama alta']);
    Producto::factory()->count(3)->create(['categoria_id' => $placas->id]);

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.imprimir', ['formato' => 'csv', 'parent_id' => $componentes->id]))
        ->assertOk()
        ->streamedContent());

    $porNombre = collect(array_slice($filas, 5, 2))->keyBy(0);

    expect($filas[2][0])->toBe('Alcance: Mostrar: Subcategorías de Componentes')
        ->and($filas[4])->toBe(['Categoría', 'Dentro de', 'Estado', 'Subcategorías', 'Productos'])
        ->and($porNombre['Placas de video'])->toBe(['Placas de video', 'Componentes', 'Activa', '1', '3'])
        ->and($porNombre['Discos'])->toBe(['Discos', 'Componentes', 'Activa', '0', '0'])
        ->and(end($filas)[0])->toBe('Total: 2 categorías');
});

test('una categoria principal no esta dentro de nada, y el filtro lo dice', function () {
    Categoria::factory()->create(['nombre' => 'Periféricos']);

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.imprimir', ['formato' => 'csv', 'parent_id' => 'raiz']))
        ->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Mostrar: Sólo las categorías principales')
        ->and($filas[5])->toBe(['Periféricos', '', 'Activa', '0', '0']);
});

test('la ruta de una nieta no hace una consulta por fila', function () {
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoCategorias();
    $usuario  = usuarioCon('categoria.ver');
    $opciones = $servicio->opciones($listado, [], $usuario);

    $arbol = function (): void {
        $raiz = Categoria::factory()->create();
        $hija = Categoria::factory()->hijaDe($raiz)->create();
        Categoria::factory()->hijaDe($hija)->create();
    };
    $pdf = fn () => view('impresion.listado-pdf',
        $servicio->datosDelPdf($listado, Categoria::query()->orderBy('nombre'), $opciones, $usuario, []))->render();

    $arbol();
    $pdf();
    $pocas = contarConsultas($pdf);

    $arbol();
    $arbol();
    $arbol();
    $muchas = contarConsultas($pdf);

    expect($muchas)->toBe($pocas);
});

test('el boton de imprimir de categorias lleva los filtros puestos y no la pagina', function () {
    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['estado' => 'activas', 'page' => 2]))
        ->assertOk()
        ->assertSee(route('categorias.imprimir', ['estado' => 'activas']))
        ->assertDontSee(route('categorias.imprimir', ['estado' => 'activas', 'page' => 2]));
});