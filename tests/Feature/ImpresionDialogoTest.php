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
    'catálogo'    => ['productos.index', 'productos.imprimir', 'producto.ver'],
    'ventas'      => ['ventas.index', 'ventas.exportar', 'venta.ver'],
    'compras'     => ['compras.index', 'compras.imprimir', 'compra.ver'],
    'clientes'    => ['clientes.index', 'clientes.imprimir', 'cliente.ver'],
    'proveedores' => ['proveedores.index', 'proveedores.imprimir', 'proveedor.ver'],
    'categorías'  => ['categorias.index', 'categorias.imprimir', 'categoria.ver'],
    'marcas'      => ['marcas.index', 'marcas.imprimir', 'marca.ver'],
    'kardex'      => ['stock.index', 'stock.imprimir', 'stock.ver'],
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