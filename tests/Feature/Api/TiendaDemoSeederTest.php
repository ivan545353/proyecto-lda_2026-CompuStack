<?php

use App\Models\Categoria;
use App\Models\Producto;
use Database\Seeders\TiendaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// TiendaDemoSeeder (tramo C): lo que la tienda muestra de los productos de demostración.

beforeEach(function () {
    Storage::fake('public');

    // La carpeta de fotos de cada test, aparte de la del repositorio.
    $this->fotos = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fotos-demo-'.uniqid();
    File::makeDirectory($this->fotos);

    // Los productos de CatalogoDemoSeeder, con su texto de relleno.
    $this->crearDemo = function () {
        $categoria = Categoria::factory()->create();

        foreach (array_keys(TiendaDemoSeeder::PRODUCTOS) as $codigo) {
            Producto::factory()->create([
                'codigo'       => $codigo,
                'categoria_id' => $categoria->id,
                'descripcion'  => "Producto de demostración: {$codigo}.",
                'destacado'    => false,
                'created_at'   => now(),
            ]);
        }
    };

    $this->sembrar = fn () => (new TiendaDemoSeeder($this->fotos))->run();

    // Una foto de mentira: al seeder sólo le importa el nombre y que se pueda copiar.
    $this->foto = fn (string $nombre, string $contenido = 'bytes') => File::put($this->fotos.DIRECTORY_SEPARATOR.$nombre, $contenido);
});

afterEach(function () {
    File::deleteDirectory($this->fotos);
});

// --- Descripciones ---------------------------------------------------------

test('reemplaza el texto de relleno por una descripción real, de cada producto', function () {
    ($this->crearDemo)();

    ($this->sembrar)();

    foreach (TiendaDemoSeeder::PRODUCTOS as $codigo => [, , $descripcion]) {
        $producto = Producto::where('codigo', $codigo)->first();

        expect($producto->descripcion)->toBe($descripcion)
            ->and($producto->descripcion)->not->toContain('Producto de demostración');
    }
});

test('las descripciones son distintas entre sí y tienen largo para una descripción breve', function () {
    $descripciones = array_column(TiendaDemoSeeder::PRODUCTOS, 2);

    expect(array_unique($descripciones))->toHaveCount(count($descripciones));

    foreach ($descripciones as $descripcion) {
        // Más que las `descripcion_breve.palabras`: el listado la corta y la ficha la muestra entera.
        expect(str_word_count($descripcion, 0, 'áéíóúñÁÉÍÓÚÑü'))->toBeGreaterThan(config('tienda.descripcion_breve.palabras'));
    }
});

test('no pisa una descripción que alguien editó', function () {
    ($this->crearDemo)();
    Producto::where('codigo', 'G505')->update(['descripcion' => 'Texto escrito por el dueño.']);

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->value('descripcion'))->toBe('Texto escrito por el dueño.');
});

test('una descripción vacía también se completa', function () {
    ($this->crearDemo)();
    Producto::where('codigo', 'G505')->update(['descripcion' => null]);

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->value('descripcion'))->toBe(TiendaDemoSeeder::PRODUCTOS['G505'][2]);
});

// --- Fechas, destacados y novedades ----------------------------------------

test('reparte las altas en los últimos 90 días, y unas pocas dentro de la ventana de novedades', function () {
    ($this->crearDemo)();

    ($this->sembrar)();

    $dias = Producto::pluck('created_at')->map(fn ($fecha) => (int) round($fecha->diffInDays(now(), true)));
    $ventana = (int) config('tienda.novedades.dias');

    expect($dias->max())->toBeLessThanOrEqual(90)
        ->and($dias->filter(fn ($d) => $d <= $ventana)->count())->toBeBetween(3, 5)
        ->and($dias->filter(fn ($d) => $d > $ventana)->count())->toBeGreaterThan(5)
        ->and($dias->unique()->count())->toBe($dias->count());   // ninguna fecha repetida
});

test('las novedades de la demo caben en el tope y no son todo el catálogo', function () {
    ($this->crearDemo)();

    ($this->sembrar)();

    $novedades = Producto::idsNovedades();

    expect(count($novedades))->toBeGreaterThanOrEqual(3)
        ->toBeLessThan(Producto::count())
        ->toBeLessThanOrEqual((int) config('tienda.novedades.tope'));
});

test('deja tres o cuatro destacados', function () {
    ($this->crearDemo)();

    ($this->sembrar)();

    expect(Producto::where('destacado', true)->count())->toBeBetween(3, 4);
});

test('un destacado que ya no corresponde se apaga: el guion de la demo se reescribe', function () {
    ($this->crearDemo)();
    Producto::where('codigo', 'EVGA-600')->update(['destacado' => true]);

    ($this->sembrar)();

    expect(Producto::where('codigo', 'EVGA-600')->value('destacado'))->toBeFalse();
});

// --- Fotos -----------------------------------------------------------------

test('copia las fotos al disco público, en orden, y la 1 es la principal', function () {
    ($this->crearDemo)();
    ($this->foto)('VG248QG-2.png', 'segunda');
    ($this->foto)('VG248QG-1.jpg', 'primera');
    ($this->foto)('VG248QG-3.webp', 'tercera');

    ($this->sembrar)();

    $rutas = Producto::where('codigo', 'VG248QG')->first()->imagenes;

    expect($rutas)->toBe(['productos/demo-vg248qg-1.jpg', 'productos/demo-vg248qg-2.png', 'productos/demo-vg248qg-3.webp']);

    Storage::disk('public')->assertExists($rutas);
    expect(Storage::disk('public')->get($rutas[0]))->toBe('primera');
});

test('el orden es numérico, no alfabético: la 10 no va antes que la 2', function () {
    ($this->crearDemo)();
    foreach ([10, 2, 1] as $n) {
        ($this->foto)("G505-{$n}.jpg");
    }

    ($this->sembrar)();

    // Tope de 5: se quedan la 1, la 2 y la 10, en ese orden.
    expect(Producto::where('codigo', 'G505')->first()->imagenes)
        ->toBe(['productos/demo-g505-1.jpg', 'productos/demo-g505-2.jpg', 'productos/demo-g505-10.jpg']);
});

test('no pasa del tope de fotos del producto', function () {
    ($this->crearDemo)();
    foreach (range(1, 8) as $n) {
        ($this->foto)("G505-{$n}.jpg");
    }

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->first()->imagenes)->toHaveCount(Producto::MAX_IMAGENES);
});

test('.jpeg se guarda como .jpg y la extensión no distingue mayúsculas', function () {
    ($this->crearDemo)();
    ($this->foto)('G505-1.JPEG');
    ($this->foto)('G505-2.PNG');

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->first()->imagenes)
        ->toBe(['productos/demo-g505-1.jpg', 'productos/demo-g505-2.png']);
});

test('ignora lo que no cumple el nombre: otros códigos, otros formatos y el LEEME', function (string $nombre) {
    ($this->crearDemo)();
    ($this->foto)($nombre);

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->value('imagenes'))->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'sin número'       => ['G505.jpg'],
    'número con letra' => ['G505-a.jpg'],
    'formato gif'      => ['G505-1.gif'],
    'otro código'      => ['XX999-1.jpg'],
    'código pegado'    => ['G5050-1.jpg'],
    'prefijo'          => ['x-G505-1.jpg'],
    'el LEEME'         => ['LEEME.md'],
]);

test('un producto sin fotos queda sin imagen y no falla', function () {
    ($this->crearDemo)();

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->value('imagenes'))->toBeNull();
});

test('si la carpeta de fotos no existe, no falla', function () {
    ($this->crearDemo)();

    (new TiendaDemoSeeder($this->fotos.'-no-existe'))->run();

    expect(Producto::where('codigo', 'G505')->value('descripcion'))->toBe(TiendaDemoSeeder::PRODUCTOS['G505'][2]);
});

test('no pisa las fotos que alguien subió desde la web', function () {
    ($this->crearDemo)();
    Producto::where('codigo', 'G505')->update(['imagenes' => ['productos/subida-a-mano.jpg']]);
    ($this->foto)('G505-1.jpg');

    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->first()->imagenes)->toBe(['productos/subida-a-mano.jpg']);
    Storage::disk('public')->assertMissing('productos/demo-g505-1.jpg');
});

test('si se retira una foto de la carpeta, el seeder la saca del producto y del disco', function () {
    ($this->crearDemo)();
    ($this->foto)('G505-1.jpg');
    ($this->foto)('G505-2.jpg');
    ($this->sembrar)();

    File::delete($this->fotos.DIRECTORY_SEPARATOR.'G505-2.jpg');
    ($this->sembrar)();

    expect(Producto::where('codigo', 'G505')->first()->imagenes)->toBe(['productos/demo-g505-1.jpg']);
    Storage::disk('public')->assertMissing('productos/demo-g505-2.jpg');
});

// --- Idempotencia y alcance --------------------------------------------------

test('correrlo dos veces deja lo mismo, sin duplicar fotos ni archivos', function () {
    ($this->crearDemo)();
    ($this->foto)('VG248QG-1.jpg');
    ($this->foto)('VG248QG-2.jpg');

    ($this->sembrar)();
    $primera = Producto::orderBy('id')->get(['codigo', 'descripcion', 'destacado', 'imagenes'])->toArray();
    $archivos = Storage::disk('public')->allFiles();

    ($this->sembrar)();

    expect(Producto::orderBy('id')->get(['codigo', 'descripcion', 'destacado', 'imagenes'])->toArray())->toBe($primera)
        ->and(Storage::disk('public')->allFiles())->toBe($archivos)
        ->and(Producto::count())->toBe(count(TiendaDemoSeeder::PRODUCTOS));
});

test('no toca los productos que no son de demostración', function () {
    ($this->crearDemo)();
    $ajeno = Producto::factory()->create([
        'codigo'      => 'VOL-00001',
        'descripcion' => 'Producto de demostración: uno de volumen.',
        'destacado'   => false,
        'created_at'  => now()->subDays(2),
    ]);
    ($this->foto)('VOL-00001-1.jpg');

    ($this->sembrar)();

    $despues = $ajeno->fresh();

    expect($despues->descripcion)->toBe('Producto de demostración: uno de volumen.')
        ->and($despues->destacado)->toBeFalse()
        ->and($despues->imagenes)->toBeNull()
        ->and($despues->created_at->isSameDay(now()->subDays(2)))->toBeTrue();
});

test('si falta un producto de la lista avisa y sigue con los demás', function () {
    ($this->crearDemo)();
    Producto::where('codigo', 'G505')->delete();

    ($this->sembrar)();

    expect(Producto::where('codigo', 'VG248QG')->value('descripcion'))->toBe(TiendaDemoSeeder::PRODUCTOS['VG248QG'][2]);
});

test('la lista de la demo coincide con los productos de CatalogoDemoSeeder', function () {
    $codigos = array_map(
        fn (array $fila) => $fila[0],
        (new ReflectionClass(\Database\Seeders\CatalogoDemoSeeder::class))->getConstant('PRODUCTOS'),
    );

    expect(array_keys(TiendaDemoSeeder::PRODUCTOS))->toEqualCanonicalizing($codigos);
});

// --- De punta a punta: lo que ve la API ---------------------------------------

test('con la demo sembrada, la API muestra destacados, novedades y fotos', function () {
    ($this->crearDemo)();
    ($this->foto)('SSD-WD1TB-1.jpg');
    ($this->sembrar)();

    $destacados = $this->get('/api/v1/productos?seccion=destacados')->assertOk();
    $novedades  = $this->get('/api/v1/productos?seccion=novedades')->assertOk();

    expect($destacados->json('total'))->toBeBetween(3, 4)
        ->and($novedades->json('total'))->toBeBetween(3, 5);

    $ssd = collect($this->get('/api/v1/productos')->json('datos'))->firstWhere('codigo', 'SSD-WD1TB');

    expect($ssd['imagen'])->toEndWith('/storage/productos/demo-ssd-wd1tb-1.jpg')
        ->and($ssd['es_novedad'])->toBeTrue()
        ->and($ssd['es_destacado'])->toBeTrue();
});

test('DatabaseSeeder lo llama después del catálogo de demostración', function () {
    $orden = (new ReflectionClass(\Database\Seeders\DatabaseSeeder::class))->getFileName();
    $texto = file_get_contents($orden);

    expect(strpos($texto, 'TiendaDemoSeeder::class'))->toBeGreaterThan(strpos($texto, 'CatalogoDemoSeeder::class'))
        ->and(strpos($texto, 'TiendaDemoSeeder::class'))->toBeLessThan(strpos($texto, 'OperacionesDemoSeeder::class'));
});
