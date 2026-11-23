<?php

use App\Models\Marca;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // El HTML del diálogo, desde su <form data-impresion> hasta el cierre de ese form.
    $this->dialogo = function (string $html): string {
        expect(preg_match('/<form[^>]*data-impresion[\s>].*?<\/form>/s', $html, $encontrado))->toBe(1);

        return $encontrado[0];
    };
});

dataset('listados con dialogo', [
    'ventas'      => ['ventas.index', 'ventas.exportar', 'venta.ver'],
]);

// Los migrados a la API (tramo D): el botón y el diálogo piden el enlace firmado al endpoint.
// Ya no funcionan sin JavaScript: la credencial viaja como Bearer, y sólo la manda el JS (HL-111).
dataset('listados con dialogo por la API', [
    'catálogo'   => ['productos.index', '/productos/impresion', 'producto.ver'],
    'categorías' => ['categorias.index', '/categorias/impresion', 'categoria.ver'],
    'marcas'     => ['marcas.index', '/marcas/impresion', 'marca.ver'],
    'proveedores' => ['proveedores.index', '/proveedores/impresion', 'proveedor.ver'],
    'clientes'   => ['clientes.index', '/clientes/impresion', 'cliente.ver'],
    // Tramo F.
    'kardex'     => ['stock.index', '/stock/movimientos/impresion', 'stock.ver'],
    // Tramo G.
    'compras'    => ['compras.index', '/compras/impresion', 'compra.ver'],
]);

test('el dialogo vive afuera del formulario de filtros y apunta a la ruta de impresion', function (string $listado, string $imprimir, string $permiso) {
    $html = $this->actingAs(usuarioCon($permiso))->get(route($listado))->assertOk()->getContent();

    // El stack del layout lo rinde después del pie: no puede quedar adentro de otro <form>.
    expect(strpos($html, 'data-impresion>'))->toBeGreaterThan(strpos($html, '</main>'))
        ->and(($this->dialogo)($html))->toContain('action="'.route($imprimir).'"');
})->with('listados con dialogo');

test('el boton que abre el dialogo nace oculto y el de imprimir funciona sin javascript', function (string $listado, string $imprimir, string $permiso) {
    $html = $this->actingAs(usuarioCon($permiso))->get(route($listado))->assertOk()->getContent();

    preg_match('/<button[^>]*data-impresion-abre[^>]*>/', $html, $abre);

    expect($abre[0])->toMatch('/\shidden[\s>]/')
        ->and($html)->toContain('href="'.route($imprimir).'"');
})->with('listados con dialogo');

test('en un listado migrado, el dialogo vive afuera del formulario de filtros y apunta al endpoint', function (string $listado, string $endpoint, string $permiso) {
    $html = $this->actingAs(usuarioCon($permiso))->get(route($listado))->assertOk()->getContent();

    expect(strpos($html, 'data-impresion>'))->toBeGreaterThan(strpos($html, '</main>'))
        ->and(($this->dialogo)($html))->toContain('data-imprimir-api="'.$endpoint.'"')->not->toContain('action=');
})->with('listados con dialogo por la API');

test('en un listado migrado, el boton que abre el dialogo nace oculto y el de imprimir apunta al endpoint', function (string $listado, string $endpoint, string $permiso) {
    $html = $this->actingAs(usuarioCon($permiso))->get(route($listado))->assertOk()->getContent();

    preg_match('/<button[^>]*data-impresion-abre[^>]*>/', $html, $abre);
    preg_match('/<button[^>]*data-imprimir\s[^>]*>/', $html, $imprimir);

    expect($abre[0])->toMatch('/\shidden[\s>]/')
        ->and($imprimir[0])->toContain('data-imprimir-api="'.$endpoint.'"');
})->with('listados con dialogo por la API');

test('el dialogo lleva los filtros puestos y no la pagina', function () {
    $marca = Marca::factory()->create();

    $html = $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index', ['marca_id' => $marca->id, 'estado' => 'activos', 'page' => 2]))
        ->getContent();

    expect(($this->dialogo)($html))
        ->toMatch('/<input type="hidden" name="marca_id" value="'.$marca->id.'">/')
        ->toMatch('/<input type="hidden" name="estado" value="activos">/')
        ->not->toContain('name="page"');
});

test('el dialogo trae las opciones por omision elegidas, y el costo solo para quien lo puede ver', function () {
    $vendedor = ($this->dialogo)($this->actingAs(usuarioCon('producto.ver'))->get(route('productos.index'))->getContent());
    $conCosto = ($this->dialogo)($this->actingAs(usuarioCon('producto.ver', 'precio.ver_costo'))->get(route('productos.index'))->getContent());

    expect($vendedor)->toMatch('/value="pdf"\s+checked/')
        ->toMatch('/value="codigo"\s+checked/')
        ->not->toMatch('/value="categoria"\s+checked/')
        ->not->toContain('value="costo"')
        ->and($conCosto)->toContain('value="costo"')
        ->not->toMatch('/value="costo"\s+checked/');
});

test('agrupar se ofrece cerrado y cada grupo en una hoja nueva nace apagado', function () {
    $dialogo = ($this->dialogo)($this->actingAs(usuarioCon('producto.ver'))->get(route('productos.index'))->getContent());

    preg_match('/<select[^>]*name="agrupar".*?<\/select>/s', $dialogo, $agrupar);
    preg_match('/<input[^>]*name="salto"[^>]*>/', $dialogo, $salto);

    expect(substr_count($agrupar[0], '<option'))->toBe(3)
                // Con \s+: el formateador de Blade parte «Por {{ … }}» en dos líneas.
        ->and($agrupar[0])->toMatch('/value="categoria">\s*Por\s+categoría\s*</')
        ->toMatch('/value="marca">\s*Por\s+marca\s*</')
        ->not->toContain('proveedor')
        ->and($salto[0])->toMatch('/\sdisabled[\s>]/');
});

test('el dialogo no crece con el catalogo', function () {
    $usuario = usuarioCon('producto.ver');
    \App\Models\Producto::factory()->count(3)->create();
    $pocos = strlen(($this->dialogo)($this->actingAs($usuario)->get(route('productos.index'))->getContent()));

    \App\Models\Producto::factory()->count(30)->create();
    $muchos = strlen(($this->dialogo)($this->actingAs($usuario)->get(route('productos.index'))->getContent()));

    expect($muchos)->toBe($pocos);
});