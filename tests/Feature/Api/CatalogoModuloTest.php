<?php

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Los modelos que sostienen el catálogo público (tramo C). Los endpoints, en CatalogoFiltrosTest.

beforeEach(function () {
    config([
        'tienda.novedades.dias'             => 30,
        'tienda.novedades.tope'             => 3,
        'tienda.disponibilidad.ultimas_unidades' => 3,
        'tienda.descripcion_breve.palabras' => 5,
    ]);

    // Un producto en una categoría visible, con la fecha de alta que el test necesite.
    $this->producto = fn (array $datos = [], ?Categoria $categoria = null) => Producto::factory()->create([
        'categoria_id' => ($categoria ?? Categoria::factory()->create())->id,
        ...$datos,
    ]);
});

// --- Categoria::idsVisiblesEnTienda ---------------------------------------

test('una categoría activa de primer nivel es visible', function () {
    $raiz = Categoria::factory()->create();

    expect(Categoria::idsVisiblesEnTienda())->toContain($raiz->id);
});

test('una categoría inactiva no es visible', function () {
    $inactiva = Categoria::factory()->inactiva()->create();

    expect(Categoria::idsVisiblesEnTienda())->not->toContain($inactiva->id);
});

test('una hija activa bajo un padre inactivo no es visible, y su nieta tampoco', function () {
    $padre = Categoria::factory()->inactiva()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    $nieta = Categoria::factory()->hijaDe($hija)->create();

    expect(Categoria::idsVisiblesEnTienda())->not->toContain($hija->id)->not->toContain($nieta->id);
});

test('una rama activa de punta a punta es visible', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    $nieta = Categoria::factory()->hijaDe($hija)->create();

    expect(Categoria::idsVisiblesEnTienda())->toContain($padre->id, $hija->id, $nieta->id);
});

test('desactivar el padre oculta a la hija sin tocarla', function () {
    $padre = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();

    $padre->update(['activo' => false]);

    expect($hija->fresh()->activo)->toBeTrue()
        ->and(Categoria::idsVisiblesEnTienda())->not->toContain($hija->id);
});

test('un ciclo en la base oculta la rama en vez de colgar el pedido', function () {
    $a = Categoria::factory()->create();
    $b = Categoria::factory()->hijaDe($a)->create();
    Categoria::whereKey($a->id)->update(['parent_id' => $b->id]);

    expect(Categoria::idsVisiblesEnTienda())->not->toContain($a->id)->not->toContain($b->id);
});

test('calcular los visibles es una sola consulta, sin importar cuántas categorías haya', function () {
    $padre = Categoria::factory()->create();
    Categoria::factory()->count(6)->hijaDe($padre)->create();

    expect(contarConsultas(fn () => Categoria::idsVisiblesEnTienda()))->toBe(1);
});

// --- scopeEnTienda --------------------------------------------------------

test('en tienda: sólo lo activo y en categoría visible', function () {
    $visible  = ($this->producto)();
    $inactivo = ($this->producto)(['activo' => false]);
    $oculto   = ($this->producto)([], Categoria::factory()->inactiva()->create());

    $ids = Producto::enTienda()->pluck('id')->all();

    expect($ids)->toContain($visible->id)->not->toContain($inactivo->id)->not->toContain($oculto->id);
});

test('un producto de una hija bajo un padre inactivo no está en tienda', function () {
    $padre = Categoria::factory()->inactiva()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    $producto = ($this->producto)([], $hija);

    expect(Producto::enTienda()->pluck('id')->all())->not->toContain($producto->id);
});

// --- Novedades ------------------------------------------------------------

test('novedades: dentro de la ventana, y no las más viejas', function () {
    $reciente = ($this->producto)(['created_at' => now()->subDays(5)]);
    $viejo    = ($this->producto)(['created_at' => now()->subDays(31)]);

    $ids = Producto::novedades()->pluck('id')->all();

    expect($ids)->toBe([$reciente->id])->not->toContain($viejo->id);
});

test('novedades: sólo los más recientes hasta el tope, de más nuevo a más viejo', function () {
    $creados = collect(range(1, 5))->map(
        fn (int $dias) => ($this->producto)(['created_at' => now()->subDays($dias)])
    );

    $ids = Producto::novedades()->orderByDesc('created_at')->pluck('id')->all();

    expect($ids)->toBe($creados->take(3)->pluck('id')->all());
});

test('novedades: con todos los productos en la misma fecha el tope igual limita, y desempata por id', function () {
    $fecha   = now()->subDays(2);
    $creados = collect(range(1, 6))->map(fn () => ($this->producto)(['created_at' => $fecha]));

    $esperados = $creados->sortByDesc('id')->take(3)->pluck('id')->sort()->values()->all();

    expect(Producto::novedades()->pluck('id')->sort()->values()->all())->toBe($esperados);
});

test('novedades: un inactivo no consume el tope', function () {
    $inactivo = ($this->producto)(['activo' => false, 'created_at' => now()->subDay()]);
    $activos  = collect(range(2, 4))->map(fn (int $d) => ($this->producto)(['created_at' => now()->subDays($d)]));

    $ids = Producto::novedades()->pluck('id')->all();

    expect($ids)->not->toContain($inactivo->id)->toHaveCount(3);
});

test('novedades: el tope y la ventana salen de config', function () {
    config(['tienda.novedades.tope' => 1, 'tienda.novedades.dias' => 10]);
    $nuevo = ($this->producto)(['created_at' => now()->subDays(1)]);
    ($this->producto)(['created_at' => now()->subDays(2)]);
    ($this->producto)(['created_at' => now()->subDays(20)]);

    expect(Producto::novedades()->pluck('id')->all())->toBe([$nuevo->id]);
});

test('esNovedad coincide con la sección, con y sin los ids ya leídos', function () {
    $nuevo = ($this->producto)(['created_at' => now()->subDay()]);
    $viejo = ($this->producto)(['created_at' => now()->subDays(60)]);

    $ids = Producto::idsNovedades();

    expect($nuevo->esNovedad())->toBeTrue()
        ->and($viejo->esNovedad())->toBeFalse()
        ->and($nuevo->esNovedad($ids))->toBeTrue()
        ->and($viejo->esNovedad($ids))->toBeFalse();
});

test('con los ids ya leídos, esNovedad no consulta la base', function () {
    $producto = ($this->producto)(['created_at' => now()->subDay()]);
    $ids      = Producto::idsNovedades();

    expect(contarConsultas(fn () => $producto->esNovedad($ids)))->toBe(0);
});

// --- Destacados -----------------------------------------------------------

test('destacados: sólo los marcados', function () {
    $marcado = ($this->producto)(['destacado' => true]);
    $comun   = ($this->producto)(['destacado' => false]);

    $ids = Producto::destacados()->pluck('id')->all();

    expect($ids)->toBe([$marcado->id])->not->toContain($comun->id);
});

// --- Orden ----------------------------------------------------------------

test('orden por nombre, y a igual nombre por id', function () {
    $b  = ($this->producto)(['nombre' => 'Bbb teclado']);
    $a2 = ($this->producto)(['nombre' => 'Aaa mouse']);
    $a1 = ($this->producto)(['nombre' => 'Aaa mouse']);

    $ids = Producto::ordenTienda('nombre')->pluck('id')->all();

    expect($ids)->toBe([$a2->id, $a1->id, $b->id]);
});

test('orden por recientes, y a igual fecha el de mayor id primero', function () {
    $fecha = now()->subDays(3);
    $viejo = ($this->producto)(['created_at' => now()->subDays(9)]);
    $p1    = ($this->producto)(['created_at' => $fecha]);
    $p2    = ($this->producto)(['created_at' => $fecha]);

    $ids = Producto::ordenTienda('recientes')->pluck('id')->all();

    expect($ids)->toBe([$p2->id, $p1->id, $viejo->id]);
});

test('el desempate vuelve estable el orden entre páginas', function () {
    $fecha = now()->subDays(1);
    collect(range(1, 7))->each(fn () => ($this->producto)(['nombre' => 'Igual', 'created_at' => $fecha]));

    foreach (Producto::ORDENES_TIENDA as $orden) {
        $porPagina = Producto::ordenTienda($orden)->pluck('id')->all();
        $paginas   = array_merge(
            Producto::ordenTienda($orden)->forPage(1, 3)->pluck('id')->all(),
            Producto::ordenTienda($orden)->forPage(2, 3)->pluck('id')->all(),
            Producto::ordenTienda($orden)->forPage(3, 3)->pluck('id')->all(),
        );

        expect($paginas)->toBe($porPagina);
    }
});

test('un orden desconocido se rechaza en vez de caer en uno por omisión', function () {
    Producto::ordenTienda('azar');
})->throws(InvalidArgumentException::class);

test('los órdenes que admite la tienda son los que el scope conoce', function () {
    foreach (Producto::ORDENES_TIENDA as $orden) {
        expect(fn () => Producto::ordenTienda($orden)->toSql())->not->toThrow(InvalidArgumentException::class);
    }
});

// --- Descripción breve (D-12) --------------------------------------------

test('la descripción breve corta en las primeras palabras y marca el corte', function () {
    $producto = ($this->producto)(['descripcion' => 'uno dos tres cuatro cinco seis siete']);

    expect($producto->descripcionBreve())->toBe('uno dos tres cuatro cinco…');
});

test('una descripción que entra completa no lleva marca de corte', function () {
    $producto = ($this->producto)(['descripcion' => 'uno dos tres cuatro cinco']);

    expect($producto->descripcionBreve())->toBe('uno dos tres cuatro cinco');
});

test('sin descripción la breve es una cadena vacía', function (?string $descripcion) {
    $producto = ($this->producto)(['descripcion' => $descripcion]);

    expect($producto->descripcionBreve())->toBe('');
})->with([null, '', "  \n "]);

test('la descripción breve junta saltos de línea y espacios repetidos', function () {
    $producto = ($this->producto)(['descripcion' => "uno\n\ndos   tres"]);

    expect($producto->descripcionBreve())->toBe('uno dos tres');
});

test('la cantidad de palabras sale de config', function () {
    config(['tienda.descripcion_breve.palabras' => 2]);
    $producto = ($this->producto)(['descripcion' => 'uno dos tres']);

    expect($producto->descripcionBreve())->toBe('uno dos…');
});

test('un texto sin espacios no pasa del tope de caracteres: es una sola «palabra»', function () {
    config(['tienda.descripcion_breve.palabras' => 25, 'tienda.descripcion_breve.caracteres' => 250]);
    $producto = ($this->producto)(['descripcion' => str_repeat('a', 5000)]);

    $breve = $producto->descripcionBreve();

    expect(mb_strlen($breve))->toBe(251)               // 250 caracteres y la marca de corte
        ->and($breve)->toEndWith('…');
});

test('el tope de caracteres sale de config', function () {
    config(['tienda.descripcion_breve.caracteres' => 10]);
    $producto = ($this->producto)(['descripcion' => 'abcdefghijklmnop']);

    expect($producto->descripcionBreve())->toBe('abcdefghij…');
});

test('un texto que entra justo en el tope no lleva marca de corte', function () {
    config(['tienda.descripcion_breve.caracteres' => 10, 'tienda.descripcion_breve.palabras' => 25]);
    $producto = ($this->producto)(['descripcion' => 'abcdefghij']);

    expect($producto->descripcionBreve())->toBe('abcdefghij');
});

test('el corte por caracteres no parte una letra con tilde', function () {
    config(['tienda.descripcion_breve.caracteres' => 5]);

    // «ñandú» tiene letras de dos bytes: cortar en bytes dejaría un carácter inválido.
    $producto = ($this->producto)(['descripcion' => 'ñandúñandú ñandú']);

    expect(mb_check_encoding($producto->descripcionBreve(), 'UTF-8'))->toBeTrue()
        ->and($producto->descripcionBreve())->toBe('ñandú…');
});

test('si el corte cae justo después de un espacio, no queda un espacio antes de la marca', function () {
    config(['tienda.descripcion_breve.caracteres' => 4]);
    $producto = ($this->producto)(['descripcion' => 'uno dos tres']);

    expect($producto->descripcionBreve())->toBe('uno…');
});

test('con el tope por omisión, el texto real de la demo no se recorta por caracteres', function () {
    // Si el tope de caracteres tocara un texto normal, la regla de las 25 palabras dejaría de mandar.
    $porOmision = require base_path('config/tienda.php');

    foreach (\Database\Seeders\TiendaDemoSeeder::PRODUCTOS as $codigo => [, , $texto]) {
        $palabras = array_slice(preg_split('/\s+/u', $texto), 0, $porOmision['descripcion_breve']['palabras']);

        expect(mb_strlen(implode(' ', $palabras)))->toBeLessThanOrEqual($porOmision['descripcion_breve']['caracteres']);
    }
});

// --- Disponibilidad -------------------------------------------------------

test('la disponibilidad tiene tres niveles y nunca el número', function (int $stock, int $reservado, string $nivel) {
    $producto = Producto::factory()->conStock($stock, $reservado)->create();

    expect($producto->fresh()->disponibilidad())->toBe($nivel);
})->with([
    'más del umbral'              => [10, 0, 'disponible'],
    'justo arriba del umbral'     => [4, 0, 'disponible'],
    'en el umbral'                => [3, 0, 'ultimas_unidades'],
    'una unidad'                  => [1, 0, 'ultimas_unidades'],
    'sin stock'                   => [0, 0, 'sin_stock'],
    'todo reservado'              => [5, 5, 'sin_stock'],
    'reservado lleva al umbral'   => [8, 5, 'ultimas_unidades'],
    'reservado de más'            => [2, 3, 'sin_stock'],
]);

test('el umbral de últimas unidades sale de config', function () {
    config(['tienda.disponibilidad.ultimas_unidades' => 1]);
    $producto = Producto::factory()->conStock(2)->create();

    expect($producto->fresh()->disponibilidad())->toBe('disponible');
});

test('cada nivel de disponibilidad tiene su texto', function () {
    foreach (['disponible', 'ultimas_unidades', 'sin_stock'] as $nivel) {
        expect(\App\Support\Api\TextosTienda::DISPONIBILIDAD)->toHaveKey($nivel);
    }
});
