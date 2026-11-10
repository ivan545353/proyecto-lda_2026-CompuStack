<?php

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Escala del catálogo público: ni las consultas ni el peso dependen del tamaño del catálogo
|--------------------------------------------------------------------------
|
| Un N+1 en el listado de la API no rompe ningún test de contenido: la respuesta sale bien y
| completa. Sólo hace más consultas, y con 20.000 productos y 50 por página eso se paga en cada
| pantalla de la app. Dos propiedades, medidas por comparación y no contra un número fijo (la
| técnica de `EscalaTest` y de `PesoHtmlTest`: un tope absoluto se sube sin pensar cuando un
| cambio legítimo lo rompe):
|
|   1. Las consultas de una página son las mismas con pocas filas que con una página llena.
|   2. La respuesta de la primera página pesa lo mismo con 25 productos que con 80: lo que
|      pesa es la página, no el catálogo.
|
| Cada producto nace en su propia categoría, con foto y marca propias, porque ahí es donde
| aparece una consulta por fila: la categoría del renglón, la imagen, la marca de la ficha.
|
*/

beforeEach(function () {
    // Como en producción: la caché va a la base. Con la caché en memoria de phpunit.xml, una
    // lectura de caché por fila saldría gratis y el defecto que se vigila no existiría.
    config(['cache.default' => 'database', 'tienda.novedades.dias' => 30, 'tienda.novedades.tope' => 10]);

    // Todas las categorías cuelgan de una raíz: el filtro por categoría recorre un árbol real.
    $this->raiz = Categoria::factory()->create(['nombre' => 'Raíz de prueba']);

    /**
     * `$cuantos` productos con nombre «Teclado NNNN», cada uno en su categoría, con foto,
     * descripción larga, un tercio destacados y fechas de alta repartidas en 25 días.
     */
    $this->catalogo = function (int $cuantos, int $desde = 0): void {
        foreach (range($desde, $desde + $cuantos - 1) as $n) {
            Producto::factory()->conStock($n % 7)->create([
                'categoria_id' => Categoria::factory()->hijaDe($this->raiz),
                'nombre'       => sprintf('Teclado %04d', $n),
                'descripcion'  => str_repeat('palabra de relleno ', 40),
                'imagenes'     => ["productos/t{$n}-1.jpg", "productos/t{$n}-2.jpg"],
                'destacado'    => $n % 3 === 0,
                'created_at'   => now()->subDays($n % 25),
            ]);
        }
    };
});

/*
| Los endpoints públicos, con `por_pagina=50` (el tope): la segunda medición renderiza una
| página completa, no cinco filas.
*/
dataset('consultas del catálogo', [
    'listado'                => ['/api/v1/productos?por_pagina=50'],
    'orden por recientes'    => ['/api/v1/productos?por_pagina=50&orden=recientes'],
    'sección destacados'     => ['/api/v1/productos?por_pagina=50&seccion=destacados'],
    'sección novedades'      => ['/api/v1/productos?por_pagina=50&seccion=novedades'],
    'búsqueda'               => ['/api/v1/productos?por_pagina=50&q=teclado'],
    'búsqueda de una letra'  => ['/api/v1/productos?por_pagina=50&q=a'],
    'con categoría'          => ['/api/v1/productos?por_pagina=50&categoria_id=RAIZ'],
    'categorías'             => ['/api/v1/categorias?por_pagina=50'],
]);

test('las consultas de una página no crecen con las filas', function (string $url) {
    ($this->catalogo)(5);
    $url = str_replace('RAIZ', (string) $this->raiz->id, $url);

    // Calentamiento sin medir: la primera petición compila lo que compila una vez. Lo que se
    // compara es el efecto de las filas, no el del primer uso.
    $this->get($url)->assertOk();

    $pocas = contarConsultas(fn () => $this->get($url)->assertOk());

    // Cincuenta en total: una página completa.
    ($this->catalogo)(45, 5);

    $muchas = contarConsultas(fn () => $this->get($url)->assertOk());

    expect($muchas)->toBe($pocas);
})->with('consultas del catálogo');

test('la segunda medición realmente trae más filas', function () {
    // Si las dos mediciones trajeran lo mismo, el test de arriba pasaría sin probar nada.
    ($this->catalogo)(5);
    $antes = count($this->get('/api/v1/productos?por_pagina=50')->json('datos'));

    ($this->catalogo)(45, 5);
    $despues = count($this->get('/api/v1/productos?por_pagina=50')->json('datos'));

    expect($antes)->toBe(5)->and($despues)->toBe(50);
});

test('la ficha hace las mismas consultas con un catálogo chico que con uno grande', function () {
    ($this->catalogo)(5);
    $id = Producto::orderBy('id')->value('id');

    $this->get("/api/v1/productos/{$id}")->assertOk();
    $pocas = contarConsultas(fn () => $this->get("/api/v1/productos/{$id}")->assertOk());

    ($this->catalogo)(45, 5);
    $muchas = contarConsultas(fn () => $this->get("/api/v1/productos/{$id}")->assertOk());

    expect($muchas)->toBe($pocas);
});

test('las consultas de una página son pocas: una cantidad que no depende de las filas ni crece sola', function () {
    // No fija un número exacto (se rompería con cualquier cambio legítimo): fija el orden de
    // magnitud. Una consulta por fila con 50 filas pasaría de cualquier cota razonable.
    ($this->catalogo)(50);
    $this->get('/api/v1/productos?por_pagina=50')->assertOk();

    $consultas = contarConsultas(fn () => $this->get('/api/v1/productos?por_pagina=50')->assertOk());

    expect($consultas)->toBeLessThan(15);
});

/*
| El peso de la respuesta. Los productos que se suman después se ordenan DESPUÉS de los
| primeros (nombre «Zeta…»), así que la primera página trae exactamente las mismas filas y lo
| único que cambia es el catálogo detrás. Los totales tienen los mismos dígitos (25 y 80;
| 2 y 4 páginas), porque `total` y `ultima_pagina` viajan en el cuerpo.
*/

test('la primera página del listado pesa lo mismo con 25 productos que con 80', function () {
    ($this->catalogo)(25);

    $pocos = $this->get('/api/v1/productos?por_pagina=20');
    $pocos->assertOk()->assertJson(['total' => 25, 'ultima_pagina' => 2]);

    foreach (range(0, 54) as $n) {
        Producto::factory()->create([
            'categoria_id' => Categoria::factory()->hijaDe($this->raiz),
            'nombre'       => sprintf('Zeta %04d', $n),
            'descripcion'  => str_repeat('palabra de relleno ', 40),
            'created_at'   => now()->subDays(60),
        ]);
    }

    $muchos = $this->get('/api/v1/productos?por_pagina=20');
    $muchos->assertOk()->assertJson(['total' => 80, 'ultima_pagina' => 4]);

    expect(strlen($muchos->getContent()))->toBe(strlen($pocos->getContent()))
        ->and($muchos->json('datos'))->toBe($pocos->json('datos'));
});

test('la descripción de un producto no infla el listado: la breve se corta en palabras', function () {
    config(['tienda.descripcion_breve.palabras' => 25]);
    Producto::factory()->create([
        'categoria_id' => Categoria::factory()->hijaDe($this->raiz),
        'descripcion'  => str_repeat('palabra ', 600),   // 4.800 caracteres
    ]);

    $fila = $this->get('/api/v1/productos')->json('datos.0');

    // 25 palabras y la marca de corte; la ficha es la que trae el texto entero.
    expect(str_word_count($fila['descripcion_breve']))->toBe(25)
        ->and(strlen($fila['descripcion_breve']))->toBeLessThan(250);
});

test('un texto de 5.000 caracteres sin espacios tampoco infla el listado', function () {
    Producto::factory()->create([
        'categoria_id' => Categoria::factory()->hijaDe($this->raiz),
        'descripcion'  => str_repeat('x', 5000),
    ]);

    $respuesta = $this->get('/api/v1/productos');

    // Es el caso del pendiente #83: sin el tope de caracteres, la fila pesaría más de 5 KB.
    expect(mb_strlen($respuesta->json('datos.0.descripcion_breve')))->toBeLessThanOrEqual(251)
        ->and(strlen($respuesta->getContent()))->toBeLessThan(1500);
});

test('el listado no trae la galería ni la descripción completa: eso es de la ficha', function () {
    ($this->catalogo)(1);

    $fila = $this->get('/api/v1/productos')->json('datos.0');

    expect($fila)->not->toHaveKeys(['galeria', 'descripcion', 'marca']);
});

test('la primera página de categorías pesa lo mismo con 25 raíces que con 80', function () {
    $this->raiz->delete();   // este test mide raíces: la de prueba no cuenta

    foreach (range(0, 24) as $n) {
        Categoria::factory()->create(['nombre' => sprintf('Categoría %04d', $n), 'orden' => $n]);
    }

    $pocas = $this->get('/api/v1/categorias?por_pagina=20');
    $pocas->assertOk()->assertJson(['total' => 25, 'ultima_pagina' => 2]);

    foreach (range(0, 54) as $n) {
        Categoria::factory()->create(['nombre' => sprintf('Categoría Z%03d', $n), 'orden' => 100 + $n]);
    }

    $muchas = $this->get('/api/v1/categorias?por_pagina=20');
    $muchas->assertOk()->assertJson(['total' => 80, 'ultima_pagina' => 4]);

    expect(strlen($muchas->getContent()))->toBe(strlen($pocas->getContent()))
        ->and($muchas->json('datos'))->toBe($pocas->json('datos'));
});
