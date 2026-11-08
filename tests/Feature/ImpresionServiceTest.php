<?php

use App\Impresion\Columna;
use App\Impresion\ListadoProductos;
use App\Models\Producto;
use App\Services\ImpresionService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->servicio = app(ImpresionService::class);
    $this->listado  = new ListadoProductos();

    // El CSV de la consulta, partido en filas y celdas como lo lee una planilla.
    $this->csv = function ($usuario, array $entrada = [], ?Builder $consulta = null, array $filtros = [], int $tanda = ImpresionService::TANDA): array {
        $opciones = $this->servicio->opciones($this->listado, ['formato' => 'csv', ...$entrada], $usuario);
        $salida   = fopen('php://memory', 'w+');

        $this->servicio->escribirCsv($this->listado, $consulta ?? Producto::query(), $opciones, $usuario, $filtros, $salida, $tanda);

        rewind($salida);
        $texto = stream_get_contents($salida);
        fclose($salida);

        expect(str_starts_with($texto, "\xEF\xBB\xBF"))->toBeTrue();

        return array_map(
            fn (string $linea) => str_getcsv($linea, ';', '"', ''),
            explode("\r\n", rtrim(substr($texto, 3), "\r\n")),
        );
    };
});

/*
|--------------------------------------------------------------------------
| Las opciones: lo que no se pide toma el valor por omisión, lo que no existe se rechaza
|--------------------------------------------------------------------------
*/

test('sin opciones sale el pdf con las columnas por omision y sin costos aunque haya permiso', function () {
    $opciones = $this->servicio->opciones($this->listado, [], usuarioCon('producto.ver', 'precio.ver_costo'));

    expect($opciones->formato)->toBe('pdf')
        ->and($opciones->orientacion)->toBe('vertical')
        ->and($opciones->hoja)->toBe('a4')
        ->and($opciones->densidad)->toBe('normal')
        ->and($opciones->claves())->toBe(['codigo', 'nombre', 'marca', 'precio_contado', 'disponible']);
});

test('sin precio.ver_costo las columnas de costo no existen para el usuario', function () {
    $sin = array_map(fn (Columna $c) => $c->clave, $this->listado->columnasPara(usuarioCon('producto.ver')));
    $con = array_map(fn (Columna $c) => $c->clave, $this->listado->columnasPara(usuarioCon('producto.ver', 'precio.ver_costo')));

    expect($sin)->not->toContain('costo')->not->toContain('margen')
        ->and($con)->toContain('costo')->toContain('margen');
});

test('pedir el costo sin precio.ver_costo se rechaza y no se imprime sin el', function () {
    $this->servicio->opciones($this->listado, ['columnas' => ['codigo', 'costo']], usuarioCon('producto.ver'));
})->throws(ValidationException::class, 'pide un permiso que no tenés');

test('con precio.ver_costo el costo se puede pedir', function () {
    $opciones = $this->servicio->opciones(
        $this->listado,
        ['columnas' => ['codigo', 'costo', 'margen']],
        usuarioCon('producto.ver', 'precio.ver_costo'),
    );

    expect($opciones->claves())->toBe(['codigo', 'costo', 'margen']);
});

test('las columnas salen en el orden del listado y no en el del pedido', function () {
    $opciones = $this->servicio->opciones($this->listado, ['columnas' => ['nombre', 'codigo']], usuarioCon('producto.ver'));

    expect($opciones->claves())->toBe(['codigo', 'nombre']);
});

dataset('opciones que no existen', [
    'columna'     => [['columnas' => ['codigo', 'ganancia']]],
    'columnas'    => [['columnas' => 'codigo']],
    'repetida'    => [['columnas' => ['codigo', 'codigo']]],
    'formato'     => [['formato' => 'xlsx']],
    'orientacion' => [['orientacion' => 'diagonal']],
    'hoja'        => [['hoja' => 'a3']],
    'densidad'    => [['densidad' => 'minima']],
]);

test('una opcion que no existe se rechaza en lugar de imprimir otra cosa', function (array $entrada) {
    $this->servicio->opciones($this->listado, $entrada, usuarioCon('producto.ver'));
})->with('opciones que no existen')->throws(ValidationException::class);

/*
|--------------------------------------------------------------------------
| El CSV: sin tope, de a tandas, con lo que siempre va
|--------------------------------------------------------------------------
*/

test('el csv trae todas las filas aunque pasen varias tandas, ordenadas por codigo', function () {
    foreach (['C-3', 'A-1', 'E-5', 'B-2', 'D-4'] as $codigo) {
        Producto::factory()->create(['codigo' => $codigo]);
    }

    $filas = ($this->csv)(usuarioCon('producto.ver'), tanda: 2);

    expect($filas[4])->toBe(['Código', 'Producto', 'Marca', 'Precio contado', 'Disponible'])
        ->and(array_column(array_slice($filas, 5, 5), 0))->toBe(['A-1', 'B-2', 'C-3', 'D-4', 'E-5'])
        ->and($filas[10])->toBe([null])
        ->and($filas[11][0])->toBe('Total: 5 productos');
});

test('el csv dice quien lo emitio, cuando y con que filtros', function () {
    $usuario = usuarioCon('producto.ver');

    $sin = ($this->csv)($usuario);
    $con = ($this->csv)($usuario, filtros: ['Marca' => 'Logitech', 'Estado' => 'Activos']);

    expect($sin[0][0])->toBe('Catálogo de productos')
        ->and($sin[1][0])->toContain("por {$usuario->apellido}, {$usuario->nombre}")
        ->and($sin[1][0])->toContain(now()->format('d/m/Y'))
        ->and($sin[2][0])->toBe('Alcance: sin ningún filtro aplicado.')
        ->and($con[2][0])->toBe('Alcance: Marca: Logitech · Estado: Activos');
});

test('el csv imprime lo que filtra la consulta y el total sale de la base', function () {
    Producto::factory()->count(3)->create();
    Producto::factory()->count(2)->inactivo()->create();

    $filas = ($this->csv)(usuarioCon('producto.ver'), consulta: Producto::query()->conEstado('activos'));

    expect(count($filas))->toBe(5 + 3 + 2)
        ->and(end($filas)[0])->toBe('Total: 3 productos');
});

test('un costo que no hay sale vacio y no como cero', function () {
    $sinCosto = Producto::factory()->create(['codigo' => 'A-SIN']);
    Producto::factory()->conStockYCosto(5, 1500.5)->create(['codigo' => 'B-CON']);

    $filas = ($this->csv)(usuarioCon('producto.ver', 'precio.ver_costo'), ['columnas' => ['codigo', 'costo', 'margen']]);

    expect((float) $sinCosto->fresh()->costo_promedio)->toBe(0.0)
        ->and($filas[5])->toBe(['A-SIN', '', ''])
        ->and($filas[6][0])->toBe('B-CON')
        ->and($filas[6][1])->toBe('1500,50')
        ->and($filas[6][2])->not->toBe('');
});

test('un texto que empieza como formula se escribe neutralizado', function () {
    Producto::factory()->create(['nombre' => '=HIPERVINCULO("x")']);

    $filas = ($this->csv)(usuarioCon('producto.ver'), ['columnas' => ['nombre']]);

    expect($filas[5][0])->toBe("'=HIPERVINCULO(\"x\")");
});

test('las consultas del csv no crecen con las filas', function () {
    $usuario = usuarioCon('producto.ver');
    Producto::factory()->count(3)->create();

    // Calentamiento: la primera lectura de permisos se memoriza en el usuario.
    ($this->csv)($usuario);

    $pocas = contarConsultas(fn () => ($this->csv)($usuario));

    Producto::factory()->count(12)->create();

    $muchas = contarConsultas(fn () => ($this->csv)($usuario));

    expect($muchas)->toBe($pocas);
});

test('imprimir no toca el stock ni el costo', function () {
    $producto = Producto::factory()->conStockYCosto(7, 900, reservado: 2)->create();

    ($this->csv)(usuarioCon('producto.ver', 'precio.ver_costo'), ['columnas' => ['codigo', 'disponible', 'costo']]);

    $despues = $producto->fresh();
    expect($despues->stock)->toBe(7)
        ->and($despues->stock_reservado)->toBe(2)
        ->and((float) $despues->costo_promedio)->toBe(900.0);
});


/*
|--------------------------------------------------------------------------
| Agrupar: una opción del papel, con sus subtotales contados por la base
|--------------------------------------------------------------------------
*/

test('el catalogo se agrupa por categoria o por marca, y no por proveedor', function () {
    $usuario = usuarioCon('producto.ver');

    $porMarca = $this->servicio->opciones($this->listado, ['agrupar' => 'marca', 'salto' => '1'], $usuario);
    $sinAgrupar = $this->servicio->opciones($this->listado, [], $usuario);

    expect($porMarca->agrupacion->clave)->toBe('marca')
        ->and($porMarca->saltoDeHoja)->toBeTrue()
        ->and($sinAgrupar->agrupacion)->toBeNull()
        ->and($sinAgrupar->saltoDeHoja)->toBeFalse()
        ->and(array_keys($this->listado->agrupaciones()))->toBe(['categoria', 'marca']);

    expect(fn () => $this->servicio->opciones($this->listado, ['agrupar' => 'proveedor'], $usuario))
        ->toThrow(ValidationException::class, 'no se puede agrupar de esa forma');
});

test('una hoja nueva por grupo sin elegir como agrupar se rechaza', function () {
    $this->servicio->opciones($this->listado, ['salto' => '1'], usuarioCon('producto.ver'));
})->throws(ValidationException::class, 'elegí cómo agrupar');

dataset('opciones del papel', [
    'orientacion' => [['orientacion' => 'horizontal']],
    'hoja'        => [['hoja' => 'oficio']],
    'densidad'    => [['densidad' => 'compacta']],
    'agrupar'     => [['agrupar' => 'marca']],
    'salto'       => [['salto' => '1']],
]);

test('una opcion del papel pedida para la planilla se rechaza', function (array $entrada) {
    $this->servicio->opciones($this->listado, ['formato' => 'csv', ...$entrada], usuarioCon('producto.ver'));
})->with('opciones del papel')->throws(ValidationException::class, 'es una opción del PDF');

test('los subtotales salen de la base, de todas las coincidencias, y sin marca es un grupo', function () {
    $logitech = \App\Models\Marca::factory()->create();
    $genius   = \App\Models\Marca::factory()->create();
    Producto::factory()->count(3)->create(['marca_id' => $logitech->id]);
    Producto::factory()->count(2)->create(['marca_id' => $genius->id]);
    Producto::factory()->create(['marca_id' => null]);
    Producto::factory()->inactivo()->create(['marca_id' => $genius->id]);

    $opciones   = $this->servicio->opciones($this->listado, ['agrupar' => 'marca'], usuarioCon('producto.ver'));
    $subtotales = $this->servicio->subtotales(Producto::query()->conEstado('activos'), $opciones);

    expect($subtotales)->toHaveCount(3)
        ->and($subtotales[(string) $logitech->id]['cantidad'])->toBe(3)
        ->and($subtotales[(string) $genius->id]['cantidad'])->toBe(2)
        ->and($subtotales['']['cantidad'])->toBe(1);
});