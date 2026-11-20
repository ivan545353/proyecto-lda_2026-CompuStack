<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Desde el tramo D se imprime por la API (D-22): el endpoint da el enlace firmado y el
    // navegador lo abre sin credencial. Esto es pedir el enlace y abrirlo.
    $this->imprimir = function (array $consulta = [], ?\App\Models\User $usuario = null) {
        $url = $this->getJson('/api/v1/productos/impresion?'.http_build_query($consulta), bearer($usuario ?? usuarioCon('producto.ver')))
            ->assertOk()->json('datos.url');
        app('auth')->forgetGuards();

        return $this->get($url);
    };
});

test('imprimir el catalogo pide producto.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('producto'), ['producto.ver']));

    $this->getJson('/api/v1/productos/impresion', bearer(usuarioCon('precio.ver_costo', ...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    app('auth')->forgetGuards();

    ($this->imprimir)()->assertOk();
});

test('sin opciones devuelve un pdf para descargar y no cambia nada', function () {
    $producto = Producto::factory()->conStockYCosto(4, 800)->create();

    $respuesta = ($this->imprimir)()->assertOk();
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

    $respuesta = ($this->imprimir)(['formato' => 'csv'])->assertOk();
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

    $csv = ($this->imprimir)(['formato' => 'csv', 'marca_id' => $marca->id])->streamedContent();

    expect($csv)->toContain('Alcance: Marca: Logitech')
        ->toContain('A-SI')
        ->not->toContain('B-NO');
});

// En la API, el motivo es el 422 de la columna, y no hay enlace: la pantalla sigue donde estaba.
test('pedir costos sin precio.ver_costo dice el motivo y no da enlace', function () {
    $marca = Marca::factory()->create();

    $respuesta = $this->getJson('/api/v1/productos/impresion?'.http_build_query(['marca_id' => $marca->id, 'columnas' => ['codigo', 'costo']]), bearer(usuarioCon('producto.ver')))
        ->assertUnprocessable()
        ->assertJsonMissingPath('datos');

    expect($respuesta->json('errores.columnas.0'))->toContain('pide un permiso que no tenés');
});

test('con precio.ver_costo el enlace imprime la columna de costo', function () {
    Producto::factory()->conStockYCosto(4, 800)->create(['codigo' => 'CON-COSTO']);

    $csv = ($this->imprimir)(['formato' => 'csv', 'columnas' => ['codigo', 'costo']], usuarioCon('producto.ver', 'precio.ver_costo'))
        ->assertOk()->streamedContent();

    expect($csv)->toContain('Costo (sin IVA)')->toContain('CON-COSTO');
});

test('una opcion que no existe es 422 en lugar de imprimir otra cosa', function () {
    $this->getJson('/api/v1/productos/impresion?formato=xlsx', bearer(usuarioCon('producto.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');
});

test('el enlace de imprimir lleva los filtros puestos y no la pagina', function () {
    $marca = Marca::factory()->create();

    $url = $this->getJson("/api/v1/productos/impresion?marca_id={$marca->id}&pagina=2&forma=gestion", bearer(usuarioCon('producto.ver')))
        ->assertOk()->json('datos.url');

    expect($url)->toContain("marca_id={$marca->id}")->not->toContain('pagina=')->not->toContain('forma=');
});

test('si quien pidio el enlace pierde el permiso, el enlace ya no imprime', function () {
    $usuario = usuarioCon('producto.ver');
    $url     = $this->getJson('/api/v1/productos/impresion', bearer($usuario))->assertOk()->json('datos.url');
    app('auth')->forgetGuards();

    // Como lo hace RolService al editar el rol: los permisos cambian y su caché se olvida.
    $usuario->rol->permisos()->detach();
    \Illuminate\Support\Facades\Cache::forget(\App\Models\User::claveCache($usuario->rol_id));

    $this->get($url)->assertForbidden();
});