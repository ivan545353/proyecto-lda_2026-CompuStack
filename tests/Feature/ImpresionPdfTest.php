<?php

use App\Impresion\ListadoProductos;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Services\ImpresionService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Como en VentaModuloTest: se prueba el HTML que dompdf consume, rindiendo la vista, y no
| los bytes del PDF. Lo que importa es qué dice el papel.
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->servicio = app(ImpresionService::class);
    $this->listado  = new ListadoProductos();

    $this->html = function ($usuario, array $entrada = [], ?Builder $consulta = null, array $filtros = [], int $tope = ImpresionService::TOPE_PDF): string {
        $opciones = $this->servicio->opciones($this->listado, $entrada, $usuario);
        $datos    = $this->servicio->datosDelPdf(
            $this->listado,
            $consulta ?? Producto::query()->orderBy('nombre'),
            $opciones,
            $usuario,
            $filtros,
            $tope,
        );

        return view('impresion.listado-pdf', $datos)->render();
    };
});

test('el pdf dice de quien es, quien lo emitio y con que alcance', function () {
    config(['empresa.nombre' => 'Insumos del Centro']);
    $usuario = usuarioCon('producto.ver');

    $sin = ($this->html)($usuario);
    $con = ($this->html)($usuario, filtros: ['Marca' => 'Logitech']);

    expect($sin)->toContain('Insumos del Centro')
        ->toContain('Catálogo de productos')
        ->toContain(e("por {$usuario->apellido}, {$usuario->nombre}"))
        ->toContain('Sin ningún filtro aplicado')
        ->and($con)->toMatch('/<strong>\s*Marca:\s*<\/strong>\s*Logitech/')
        ->not->toContain('Sin ningún filtro aplicado');
});

test('imprime solo las columnas elegidas, en el encabezado y en cada fila', function () {
    Producto::factory()->create(['codigo' => 'SSD-1TB', 'nombre' => 'Disco de prueba', 'precio_contado' => 95000]);

    $html = ($this->html)(usuarioCon('producto.ver'), ['columnas' => ['codigo', 'precio_contado']]);

    expect($html)->toMatch('/<th[^>]*>\s*Código\s*<\/th>/')
        ->toMatch('/<th[^>]*>\s*Precio contado\s*<\/th>/')
        ->not->toMatch('/<th[^>]*>\s*Producto\s*<\/th>/')
        ->toMatch('/<td[^>]*>\s*SSD-1TB\s*<\/td>\s*<td class="derecha">\s*\$ 95\.000,00\s*<\/td>/')
        ->not->toContain('Disco de prueba');
});

test('sin pedirlo, o sin precio.ver_costo, el pdf no tiene columna de costo, ni vacia', function () {
    Producto::factory()->conStockYCosto(5, 1200)->create();

    $vendedor = ($this->html)(usuarioCon('producto.ver'));
    $porOmision = ($this->html)(usuarioCon('producto.ver', 'precio.ver_costo'));
    $pedido = ($this->html)(usuarioCon('producto.ver', 'precio.ver_costo'), ['columnas' => ['codigo', 'costo']]);

    expect($vendedor)->not->toContain('Costo')
        ->and($porOmision)->not->toContain('Costo')
        ->and($pedido)->toMatch('/<th[^>]*>\s*Costo \(sin IVA\)\s*<\/th>/');
});

test('un costo que no hay se imprime como raya y no como cero', function () {
    Producto::factory()->create(['codigo' => 'A-SIN']);

    $html = ($this->html)(usuarioCon('producto.ver', 'precio.ver_costo'), ['columnas' => ['codigo', 'costo']]);

    expect($html)->toMatch('/A-SIN\s*<\/td>\s*<td class="derecha">\s*—\s*<\/td>/')
        ->not->toContain('$ 0,00');
});

test('con mas filas que el tope el pdf lo declara y el total es de todas', function () {
    foreach (['Alfa', 'Beta', 'Gama'] as $nombre) {
        Producto::factory()->create(['codigo' => "C-{$nombre}", 'nombre' => $nombre]);
    }

    $recortado = ($this->html)(usuarioCon('producto.ver'), tope: 2);
    $entero    = ($this->html)(usuarioCon('producto.ver'), tope: 3);

    expect($recortado)->toContain('data-recorte')
        ->toContain('C-Alfa')->toContain('C-Beta')->not->toContain('C-Gama')
        ->toMatch('/Total:\s*3 productos/')
        ->and($entero)->not->toContain('data-recorte')
        ->toContain('C-Gama');
});

test('el vacio por filtro y el vacio por falta de datos dicen cosas distintas', function () {
    $usuario = usuarioCon('producto.ver');

    expect(($this->html)($usuario, filtros: ['Marca' => 'Logitech']))->toContain('data-vacio="filtro"')
        ->and(($this->html)($usuario))->toContain('data-vacio="datos"')
        ->not->toContain('Total:');
});

test('la densidad compacta se declara en el documento', function () {
    $usuario = usuarioCon('producto.ver');

    expect(($this->html)($usuario))->toMatch('/<body class="normal">/')
        ->and(($this->html)($usuario, ['densidad' => 'compacta']))->toMatch('/<body class="compacta">/');
});

test('las consultas del pdf no crecen con las filas', function () {
    $usuario = usuarioCon('producto.ver');
    Producto::factory()->count(3)->create();
    ($this->html)($usuario);

    $pocas = contarConsultas(fn () => ($this->html)($usuario));

    Producto::factory()->count(12)->create();

    $muchas = contarConsultas(fn () => ($this->html)($usuario));

    expect($muchas)->toBe($pocas);
});

test('el pdf sale con la hoja y la orientacion pedidas y numera sus hojas', function () {
    $usuario  = usuarioCon('producto.ver');
    $opciones = $this->servicio->opciones($this->listado, ['orientacion' => 'horizontal', 'hoja' => 'oficio'], $usuario);

    $dompdf = $this->servicio->pdf($this->listado, Producto::query(), $opciones, $usuario, [])->getDomPDF();

    expect($dompdf->getPaperOrientation())->toBe('landscape')
        ->and(max($dompdf->getPaperSize()))->toEqual(1008.0)
        ->and($dompdf->getCallbacks())->toHaveKey('end_document');
});


/*
|--------------------------------------------------------------------------
| El papel agrupado
|--------------------------------------------------------------------------
*/

test('agrupado por marca cada grupo lleva su titulo y su subtotal, en orden de nombre', function () {
    $zeta = Marca::factory()->create(['nombre' => 'Zeta']);
    $alfa = Marca::factory()->create(['nombre' => 'Alfa']);
    Producto::factory()->count(2)->create(['marca_id' => $zeta->id]);
    Producto::factory()->create(['marca_id' => $alfa->id]);
    Producto::factory()->create(['marca_id' => null]);

    $html = ($this->html)(usuarioCon('producto.ver'), ['agrupar' => 'marca']);

    preg_match_all('/<div class="grupo" data-grupo>\s*([^<]+?)\s*</', $html, $titulos);

    expect($titulos[1])->toBe(['Sin marca', 'Alfa', 'Zeta'])
        ->and(preg_match_all('/Subtotal:\s*1 producto\s/', $html))->toBe(2)
        ->and($html)->toMatch('/Subtotal:\s*2 productos/')
        ->toMatch('/Total:\s*4 productos/');
});

test('adentro de cada grupo se respeta el orden del listado', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->create(['marca_id' => $marca->id, 'nombre' => 'Beta', 'codigo' => 'X-BETA']);
    Producto::factory()->create(['marca_id' => $marca->id, 'nombre' => 'Alfa', 'codigo' => 'X-ALFA']);

    $html = ($this->html)(usuarioCon('producto.ver'), ['agrupar' => 'marca']);

    expect(strpos($html, 'X-ALFA'))->toBeLessThan(strpos($html, 'X-BETA'));
});

test('el subtotal es de todo el grupo aunque el tope corte sus filas', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->count(3)->create(['marca_id' => $marca->id]);

    $html = ($this->html)(usuarioCon('producto.ver'), ['agrupar' => 'marca'], tope: 2);

    // Sólo las filas de datos: sus celdas llevan `class`, la del encabezado de la empresa lleva `style`.
    expect(preg_match_all('/<tr>\s*<td class="/', $html))->toBe(2)
        ->and($html)->toMatch('/Subtotal:\s*3 productos/')
        ->toContain('data-recorte');
});

test('cada grupo en una hoja nueva, salvo el primero', function () {
    Producto::factory()->count(3)->create();

    $html = ($this->html)(usuarioCon('producto.ver'), ['agrupar' => 'marca', 'salto' => '1']);

    expect(substr_count($html, 'data-grupo'))->toBe(3)
        ->and(substr_count($html, 'class="grupo salto"'))->toBe(2);
});

test('agrupado por categoria el titulo es la categoria y debajo va su ruta', function () {
    $padre = Categoria::factory()->create(['nombre' => 'Componentes']);
    $hija  = Categoria::factory()->hijaDe($padre)->create(['nombre' => 'Placas de video']);
    Producto::factory()->create(['categoria_id' => $hija->id]);

    $html = ($this->html)(usuarioCon('producto.ver'), ['agrupar' => 'categoria']);

    expect($html)->toMatch('/data-grupo>\s*Placas de video\s*<span class="tenue">\s*Componentes › Placas de video\s*<\/span>/');
});

test('agrupado, las consultas no crecen con las filas ni con los grupos', function () {
    $usuario = usuarioCon('producto.ver');
    Producto::factory()->count(3)->create();
    ($this->html)($usuario, ['agrupar' => 'categoria']);

    $pocas = contarConsultas(fn () => ($this->html)($usuario, ['agrupar' => 'categoria']));

    Producto::factory()->count(12)->create();

    $muchas = contarConsultas(fn () => ($this->html)($usuario, ['agrupar' => 'categoria']));

    expect($muchas)->toBe($pocas);
});