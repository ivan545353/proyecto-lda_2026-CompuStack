<?php

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Endpoints del catálogo público (tramo C): categorías y listado de productos.

beforeEach(function () {
    config(['tienda.novedades.dias' => 30, 'tienda.novedades.tope' => 3]);

    // Un producto en una categoría visible (la que se pase, o una nueva).
    $this->producto = fn (array $datos = [], ?Categoria $categoria = null) => Producto::factory()->create([
        'categoria_id' => ($categoria ?? Categoria::factory()->create())->id,
        ...$datos,
    ]);

    // Los ids del listado, en el orden en que vienen.
    $this->ids = fn (string $consulta = '') => collect($this->get('/api/v1/productos'.$consulta)->assertOk()->json('datos'))
        ->pluck('id')->all();

    // Todas las claves de un JSON, a cualquier profundidad (las de objetos; los índices de listas no cuentan).
    $this->clavesDe = function (mixed $json) use (&$recorrer): array {
        $recorrer = function (mixed $nodo) use (&$recorrer): array {
            if (! is_array($nodo)) {
                return [];
            }

            $claves = [];
            foreach ($nodo as $clave => $valor) {
                if (is_string($clave)) {
                    $claves[] = $clave;
                }
                $claves = [...$claves, ...$recorrer($valor)];
            }

            return $claves;
        };

        return $recorrer($json);
    };
});

// --- GET /api/v1/categorias -----------------------------------------------

test('el árbol de categorías es público, JSON, y no hace falta mandar Accept', function () {
    Categoria::factory()->create(['nombre' => 'Componentes']);

    // $this->get() no manda Accept: es el caso del cliente que no sabe pedirlo.
    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('datos.0.nombre', 'Componentes');
});

test('la credencial no se lee: una inválida tampoco molesta', function () {
    Categoria::factory()->create();

    $this->get('/api/v1/categorias', ['Authorization' => 'Bearer basura'])->assertOk();
});

test('trae la envoltura del listado, con criterios como objeto vacío', function () {
    Categoria::factory()->count(2)->create();

    $respuesta = $this->get('/api/v1/categorias');

    $respuesta->assertOk()->assertJsonStructure([
        'datos', 'pagina', 'por_pagina', 'ultima_pagina', 'total', 'criterios',
    ])->assertJson(['pagina' => 1, 'por_pagina' => 20, 'ultima_pagina' => 1, 'total' => 2]);

    expect($respuesta->getContent())->toContain('"criterios":{}');
});

test('el árbol viene anidado: raíz, hija y nieta', function () {
    $raiz  = Categoria::factory()->create(['nombre' => 'Almacenamiento']);
    $hija  = Categoria::factory()->hijaDe($raiz)->create(['nombre' => 'Discos']);
    $nieta = Categoria::factory()->hijaDe($hija)->create(['nombre' => 'SSD']);

    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertJsonPath('datos.0.id', $raiz->id)
        ->assertJsonPath('datos.0.hijas.0.id', $hija->id)
        ->assertJsonPath('datos.0.hijas.0.hijas.0.id', $nieta->id)
        ->assertJsonPath('datos.0.hijas.0.hijas.0.nombre', 'SSD')
        ->assertJsonPath('total', 1);   // el total cuenta raíces, que es lo que se pagina
});

test('una hoja trae hijas vacío y no la clave ausente', function () {
    $raiz  = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($raiz)->create();
    $nieta = Categoria::factory()->hijaDe($hija)->create();

    $datos = $this->get('/api/v1/categorias')->json('datos.0');

    expect($datos['hijas'][0]['hijas'][0])->toHaveKey('hijas')
        ->and($datos['hijas'][0]['hijas'][0]['hijas'])->toBe([]);
});

test('cada categoría trae sólo id, nombre e hijas', function () {
    $raiz = Categoria::factory()->create(['ganancia' => 35]);
    Categoria::factory()->hijaDe($raiz)->create();

    $datos = $this->get('/api/v1/categorias')->json('datos.0');

    expect(array_keys($datos))->toBe(['id', 'nombre', 'hijas'])
        ->and(array_keys($datos['hijas'][0]))->toBe(['id', 'nombre', 'hijas']);
});

test('se ordenan por orden, después por nombre, después por id', function () {
    $tercera = Categoria::factory()->create(['orden' => 2, 'nombre' => 'Aaa']);
    $b       = Categoria::factory()->create(['orden' => 1, 'nombre' => 'Bbb']);
    $a       = Categoria::factory()->create(['orden' => 1, 'nombre' => 'Aaa']);

    $ids = collect($this->get('/api/v1/categorias')->json('datos'))->pluck('id')->all();

    expect($ids)->toBe([$a->id, $b->id, $tercera->id]);
});

test('las hijas también salen ordenadas', function () {
    $raiz = Categoria::factory()->create();
    $segunda = Categoria::factory()->hijaDe($raiz)->create(['orden' => 2, 'nombre' => 'Aaa']);
    $primera = Categoria::factory()->hijaDe($raiz)->create(['orden' => 1, 'nombre' => 'Zzz']);

    $ids = collect($this->get('/api/v1/categorias')->json('datos.0.hijas'))->pluck('id')->all();

    expect($ids)->toBe([$primera->id, $segunda->id]);
});

test('una categoría inactiva no aparece', function () {
    $visible  = Categoria::factory()->create();
    $inactiva = Categoria::factory()->inactiva()->create();

    $ids = collect($this->get('/api/v1/categorias')->json('datos'))->pluck('id')->all();

    expect($ids)->toBe([$visible->id])->not->toContain($inactiva->id);
});

test('una hija inactiva no aparece y sus hermanas sí', function () {
    $raiz     = Categoria::factory()->create();
    $activa   = Categoria::factory()->hijaDe($raiz)->create();
    $inactiva = Categoria::factory()->inactiva()->hijaDe($raiz)->create();

    $ids = collect($this->get('/api/v1/categorias')->json('datos.0.hijas'))->pluck('id')->all();

    expect($ids)->toBe([$activa->id]);
});

test('una hija activa bajo un padre inactivo no aparece en ningún lado del árbol', function () {
    $padre = Categoria::factory()->inactiva()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    Categoria::factory()->hijaDe($hija)->create();

    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertJsonPath('datos', [])
        ->assertJsonPath('total', 0);
});

test('una nieta activa bajo una hija inactiva no aparece', function () {
    $raiz  = Categoria::factory()->create();
    $hija  = Categoria::factory()->inactiva()->hijaDe($raiz)->create();
    Categoria::factory()->hijaDe($hija)->create();

    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertJsonPath('datos.0.hijas', []);
});

test('sin categorías devuelve datos vacío, total 0 y una sola página', function () {
    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertJson(['datos' => [], 'total' => 0, 'ultima_pagina' => 1, 'pagina' => 1]);
});

// --- Paginado -------------------------------------------------------------

test('se pagina por raíz y las páginas no repiten ni saltean', function () {
    $raices = collect(range(1, 5))->map(fn (int $n) => Categoria::factory()->create(['orden' => $n]));

    $primera = $this->get('/api/v1/categorias?por_pagina=2&pagina=1')->assertOk();
    $tercera = $this->get('/api/v1/categorias?por_pagina=2&pagina=3')->assertOk();

    $primera->assertJson(['pagina' => 1, 'por_pagina' => 2, 'ultima_pagina' => 3, 'total' => 5]);
    expect($primera->json('datos.*.id'))->toBe($raices->take(2)->pluck('id')->all())
        ->and($tercera->json('datos.*.id'))->toBe([$raices[4]->id]);
});

test('una página después de la última devuelve datos vacío con sus metadatos, no 404', function () {
    Categoria::factory()->count(3)->create();

    $this->get('/api/v1/categorias?por_pagina=2&pagina=9')
        ->assertOk()
        ->assertJson(['datos' => [], 'pagina' => 9, 'ultima_pagina' => 2, 'total' => 3]);
});

test('por_pagina sobre el tope es 422 y no se recorta', function () {
    $tope = config('tienda.paginado.tope');

    $this->get('/api/v1/categorias?por_pagina='.($tope + 1))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonPath('errores.por_pagina.0', "Se pueden pedir hasta {$tope} por página.");
});

test('por_pagina igual al tope se acepta', function () {
    $this->get('/api/v1/categorias?por_pagina='.config('tienda.paginado.tope'))->assertOk();
});

test('un valor que no es un entero positivo es 422', function (string $consulta, string $campo) {
    $this->get('/api/v1/categorias?'.$consulta)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonStructure(['errores' => [$campo]]);
})->with([
    'pagina cero'         => ['pagina=0', 'pagina'],
    'pagina negativa'     => ['pagina=-1', 'pagina'],
    'pagina con letras'   => ['pagina=abc', 'pagina'],
    'pagina decimal'      => ['pagina=1.5', 'pagina'],
    'por_pagina cero'     => ['por_pagina=0', 'por_pagina'],
    'por_pagina letras'   => ['por_pagina=muchas', 'por_pagina'],
]);

test('sin pagina ni por_pagina usa 1 y el valor de config', function () {
    config(['tienda.paginado.por_pagina' => 3]);
    Categoria::factory()->count(4)->create();

    $this->get('/api/v1/categorias')
        ->assertOk()
        ->assertJson(['pagina' => 1, 'por_pagina' => 3, 'ultima_pagina' => 2]);
});

test('el árbol sólo se lee: otros métodos dan 405 con la forma de error', function () {
    $this->post('/api/v1/categorias')
        ->assertStatus(405)
        ->assertJsonPath('codigo', 'metodo_no_permitido');
});

// --- Escala ---------------------------------------------------------------

test('las consultas no crecen con la cantidad de categorías', function () {
    $armar = function (int $raices) {
        foreach (range(1, $raices) as $_) {
            $raiz = Categoria::factory()->create();
            $hija = Categoria::factory()->hijaDe($raiz)->create();
            Categoria::factory()->hijaDe($hija)->create();
        }
    };

    $armar(2);
    $con2 = contarConsultas(fn () => $this->get('/api/v1/categorias')->assertOk());

    $armar(8);
    $con10 = contarConsultas(fn () => $this->get('/api/v1/categorias')->assertOk());

    expect($con10)->toBe($con2);
});

// --- GET /api/v1/productos: forma ------------------------------------------

test('el catálogo es público: sin credencial y sin Accept responde JSON', function () {
    ($this->producto)(['nombre' => 'Teclado mecánico']);

    $this->get('/api/v1/productos')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('datos.0.nombre', 'Teclado mecánico');
});

test('una credencial inválida es 401 sesion_invalida y no se la ignora', function () {
    ($this->producto)();

    $this->get('/api/v1/productos', ['Authorization' => 'Bearer basura'])
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sesion_invalida');
});

test('cada producto trae exactamente las claves del contrato', function () {
    $categoria = Categoria::factory()->create(['nombre' => 'Periféricos']);
    $producto  = ($this->producto)(['codigo' => 'TEC-001', 'nombre' => 'Teclado', 'descripcion' => 'Con luces'], $categoria);

    $fila = $this->get('/api/v1/productos')->json('datos.0');

    expect(array_keys($fila))->toBe([
        'id', 'codigo', 'nombre', 'descripcion_breve', 'imagen', 'categoria',
        'es_novedad', 'es_destacado', 'disponibilidad', 'disponibilidad_texto',
    ])->and($fila['id'])->toBe($producto->id)
        ->and($fila['codigo'])->toBe('TEC-001')
        ->and($fila['descripcion_breve'])->toBe('Con luces')
        ->and($fila['categoria'])->toBe(['id' => $categoria->id, 'nombre' => 'Periféricos']);
});

test('la envoltura trae pagina, por_pagina, ultima_pagina, total y criterios', function () {
    ($this->producto)();

    $this->get('/api/v1/productos')
        ->assertOk()
        ->assertJsonStructure(['datos', 'pagina', 'por_pagina', 'ultima_pagina', 'total', 'criterios'])
        ->assertJson(['pagina' => 1, 'por_pagina' => 20, 'ultima_pagina' => 1, 'total' => 1]);
});

test('sin productos: datos vacío, total 0 y una sola página', function () {
    $this->get('/api/v1/productos')
        ->assertOk()
        ->assertJson(['datos' => [], 'total' => 0, 'ultima_pagina' => 1]);
});

test('una página después de la última da datos vacío con sus metadatos, no 404', function () {
    foreach (range(1, 3) as $_) {
        ($this->producto)();
    }

    $this->get('/api/v1/productos?por_pagina=2&pagina=9')
        ->assertOk()
        ->assertJson(['datos' => [], 'pagina' => 9, 'ultima_pagina' => 2, 'total' => 3]);
});

test('en productos, por_pagina sobre el tope es 422 y no se recorta', function () {
    $tope = config('tienda.paginado.tope');

    $this->get('/api/v1/productos?por_pagina='.($tope + 1))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonPath('errores.por_pagina.0', "Se pueden pedir hasta {$tope} por página.");
});

test('criterios devuelve lo aplicado, normalizado, con las cuatro claves', function () {
    $categoria = Categoria::factory()->create();

    $this->get('/api/v1/productos?q=ssd&categoria_id='.$categoria->id.'&orden=recientes&seccion=destacados')
        ->assertOk()
        ->assertJsonPath('criterios', [
            'categoria_id' => $categoria->id, 'q' => 'ssd', 'orden' => 'recientes', 'seccion' => 'destacados',
        ]);
});

test('sin parámetros los criterios son nulos y el orden por nombre', function () {
    $this->get('/api/v1/productos')
        ->assertJsonPath('criterios', ['categoria_id' => null, 'q' => null, 'orden' => 'nombre', 'seccion' => null]);
});

test('las novedades, sin orden pedido, salen de la más nueva a la más vieja', function () {
    $this->get('/api/v1/productos?seccion=novedades')
        ->assertJsonPath('criterios.orden', 'recientes');
});

test('el orden pedido gana sobre el de la sección', function () {
    $this->get('/api/v1/productos?seccion=novedades&orden=nombre')
        ->assertJsonPath('criterios.orden', 'nombre');
});

// --- Validación (la validación rechaza, no corrige) -------------------------

test('un parámetro inválido es 422 con su campo', function (string $consulta, string $campo) {
    $this->get('/api/v1/productos?'.$consulta)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonStructure(['errores' => [$campo]]);
})->with([
    'orden inexistente'    => ['orden=azar', 'orden'],
    'orden por precio (D)' => ['orden=precio_asc', 'orden'],
    'sección inexistente'  => ['seccion=ofertas', 'seccion'],
    'q de 101 caracteres'  => ['q='.str_repeat('a', 101), 'q'],
    'q como arreglo'       => ['q[]=a', 'q'],
    'categoria con letras' => ['categoria_id=abc', 'categoria_id'],
    'categoria cero'       => ['categoria_id=0', 'categoria_id'],
    'categoria negativa'   => ['categoria_id=-3', 'categoria_id'],
    'pagina cero'          => ['pagina=0', 'pagina'],
]);

test('q de exactamente 100 caracteres se acepta', function () {
    $this->get('/api/v1/productos?q='.str_repeat('a', 100))->assertOk();
});

test('el mensaje de un orden inválido dice cuáles hay', function () {
    $this->get('/api/v1/productos?orden=azar')
        ->assertJsonPath('errores.orden.0', 'Ese orden no existe. Podés usar: nombre, recientes.');
});

test('el catálogo sólo se lee: otros métodos dan 405', function () {
    $this->post('/api/v1/productos')->assertStatus(405)->assertJsonPath('codigo', 'metodo_no_permitido');
});

// --- Qué productos se ven ---------------------------------------------------

test('un producto inactivo no aparece', function () {
    $visible  = ($this->producto)();
    $inactivo = ($this->producto)(['activo' => false]);

    expect(($this->ids)())->toBe([$visible->id])->not->toContain($inactivo->id);
});

test('un producto de una categoría inactiva no aparece', function () {
    $visible = ($this->producto)();
    ($this->producto)([], Categoria::factory()->inactiva()->create());

    expect(($this->ids)())->toBe([$visible->id]);
});

test('un producto de una hija activa bajo un padre inactivo no aparece', function () {
    $padre = Categoria::factory()->inactiva()->create();
    $hija  = Categoria::factory()->hijaDe($padre)->create();
    ($this->producto)([], $hija);

    expect(($this->ids)())->toBe([])
        ->and($this->get('/api/v1/productos?categoria_id='.$hija->id)->json('total'))->toBe(0);
});

// --- Filtro por categoría ---------------------------------------------------

test('filtrar por una categoría trae sus productos y los de sus hijas y nietas', function () {
    $raiz  = Categoria::factory()->create();
    $hija  = Categoria::factory()->hijaDe($raiz)->create();
    $nieta = Categoria::factory()->hijaDe($hija)->create();
    $otra  = Categoria::factory()->create();

    $enRaiz  = ($this->producto)(['nombre' => 'A raíz'], $raiz);
    $enHija  = ($this->producto)(['nombre' => 'B hija'], $hija);
    $enNieta = ($this->producto)(['nombre' => 'C nieta'], $nieta);
    ($this->producto)(['nombre' => 'D ajeno'], $otra);

    expect(($this->ids)('?categoria_id='.$raiz->id))->toBe([$enRaiz->id, $enHija->id, $enNieta->id]);
});

test('filtrar por una hija no trae los de su padre', function () {
    $raiz = Categoria::factory()->create();
    $hija = Categoria::factory()->hijaDe($raiz)->create();
    ($this->producto)([], $raiz);
    $enHija = ($this->producto)([], $hija);

    expect(($this->ids)('?categoria_id='.$hija->id))->toBe([$enHija->id]);
});

test('una categoría inexistente devuelve vacío y no el catálogo entero', function () {
    ($this->producto)();

    $this->get('/api/v1/productos?categoria_id=99999999')
        ->assertOk()
        ->assertJson(['datos' => [], 'total' => 0]);
});

test('una categoría inactiva devuelve vacío', function () {
    $inactiva = Categoria::factory()->inactiva()->create();
    ($this->producto)([], $inactiva);

    expect(($this->ids)('?categoria_id='.$inactiva->id))->toBe([]);
});

test('una hija inactiva no trae productos pero su hermana activa sí', function () {
    $raiz     = Categoria::factory()->create();
    $inactiva = Categoria::factory()->inactiva()->hijaDe($raiz)->create();
    $activa   = Categoria::factory()->hijaDe($raiz)->create();
    ($this->producto)([], $inactiva);
    $visible = ($this->producto)([], $activa);

    expect(($this->ids)('?categoria_id='.$raiz->id))->toBe([$visible->id]);
});

// --- Búsqueda ---------------------------------------------------------------

test('la búsqueda encuentra por nombre, sin importar mayúsculas', function () {
    $ssd = ($this->producto)(['nombre' => 'Disco SSD Kingston', 'codigo' => 'K-1']);
    ($this->producto)(['nombre' => 'Teclado', 'codigo' => 'T-1']);

    expect(($this->ids)('?q=ssd'))->toBe([$ssd->id]);
});

test('la búsqueda encuentra por código', function () {
    $buscado = ($this->producto)(['nombre' => 'Teclado', 'codigo' => 'TEC-0042']);
    ($this->producto)(['nombre' => 'Mouse', 'codigo' => 'MOU-0001']);

    expect(($this->ids)('?q=TEC-0042'))->toBe([$buscado->id]);
});

test('los comodines de LIKE se buscan como texto: % no trae todo', function () {
    ($this->producto)(['nombre' => 'Teclado']);
    $con = ($this->producto)(['nombre' => 'Oferta 50% off']);

    expect(($this->ids)('?q='.urlencode('%')))->toBe([$con->id])
        ->and(($this->ids)('?q='.urlencode('_')))->toBe([]);
});

test('una búsqueda sin resultados es una página vacía, no un error', function () {
    ($this->producto)(['nombre' => 'Teclado']);

    $this->get('/api/v1/productos?q=zzzzzz')->assertOk()->assertJson(['datos' => [], 'total' => 0]);
});

test('la búsqueda se combina con la categoría', function () {
    $a = Categoria::factory()->create();
    $b = Categoria::factory()->create();
    $esperado = ($this->producto)(['nombre' => 'Disco rígido'], $a);
    ($this->producto)(['nombre' => 'Disco óptico'], $b);
    ($this->producto)(['nombre' => 'Mouse'], $a);

    expect(($this->ids)('?q=disco&categoria_id='.$a->id))->toBe([$esperado->id]);
});

test('un q de sólo espacios no filtra', function () {
    $p = ($this->producto)();

    expect(($this->ids)('?q='.urlencode('   ')))->toBe([$p->id]);
});

// --- Orden ------------------------------------------------------------------

test('por nombre, y sin orden pedido también', function () {
    $c = ($this->producto)(['nombre' => 'Ccc']);
    $a = ($this->producto)(['nombre' => 'Aaa']);
    $b = ($this->producto)(['nombre' => 'Bbb']);

    expect(($this->ids)())->toBe([$a->id, $b->id, $c->id])
        ->and(($this->ids)('?orden=nombre'))->toBe([$a->id, $b->id, $c->id]);
});

test('por recientes, del más nuevo al más viejo', function () {
    $viejo = ($this->producto)(['created_at' => now()->subDays(40)]);
    $medio = ($this->producto)(['created_at' => now()->subDays(10)]);
    $nuevo = ($this->producto)(['created_at' => now()->subDay()]);

    expect(($this->ids)('?orden=recientes'))->toBe([$nuevo->id, $medio->id, $viejo->id]);
});

test('el desempate por id vuelve estable el orden entre páginas', function (string $orden) {
    $fecha = now()->subDay();
    foreach (range(1, 7) as $_) {
        ($this->producto)(['nombre' => 'Igual', 'created_at' => $fecha]);
    }

    $completo = ($this->ids)("?orden={$orden}");
    $paginas  = [
        ...($this->ids)("?orden={$orden}&por_pagina=3&pagina=1"),
        ...($this->ids)("?orden={$orden}&por_pagina=3&pagina=2"),
        ...($this->ids)("?orden={$orden}&por_pagina=3&pagina=3"),
    ];

    expect($paginas)->toBe($completo)->toHaveCount(7)->and(array_unique($paginas))->toHaveCount(7);
})->with(['nombre', 'recientes']);

// --- Secciones --------------------------------------------------------------

test('destacados trae sólo los marcados', function () {
    $marcado = ($this->producto)(['destacado' => true]);
    ($this->producto)(['destacado' => false]);

    expect(($this->ids)('?seccion=destacados'))->toBe([$marcado->id]);
});

test('un destacado inactivo no aparece', function () {
    ($this->producto)(['destacado' => true, 'activo' => false]);

    expect(($this->ids)('?seccion=destacados'))->toBe([]);
});

test('novedades respeta la ventana', function () {
    $reciente = ($this->producto)(['created_at' => now()->subDays(5)]);
    ($this->producto)(['created_at' => now()->subDays(31)]);

    expect(($this->ids)('?seccion=novedades'))->toBe([$reciente->id]);
});

test('novedades respeta el tope y viene de la más nueva a la más vieja', function () {
    $creados = collect(range(1, 5))->map(fn (int $d) => ($this->producto)(['created_at' => now()->subDays($d)]));

    $respuesta = $this->get('/api/v1/productos?seccion=novedades')->assertOk();

    expect($respuesta->json('datos.*.id'))->toBe($creados->take(3)->pluck('id')->all())
        ->and($respuesta->json('total'))->toBe(3);
});

test('novedades con todos los productos en la misma fecha: el tope igual limita', function () {
    $fecha   = now()->subDays(2);
    $creados = collect(range(1, 6))->map(fn () => ($this->producto)(['created_at' => $fecha]));

    $esperados = $creados->sortByDesc('id')->take(3)->pluck('id')->all();

    expect(($this->ids)('?seccion=novedades'))->toBe($esperados);
});

test('novedades combinada con una categoría no abre el tope', function () {
    $a = Categoria::factory()->create();
    foreach (range(1, 4) as $d) {
        ($this->producto)(['created_at' => now()->subDays($d)], Categoria::factory()->create());
    }
    // Dentro de la ventana pero fuera del tope: aunque sea de la categoría pedida, no es novedad.
    ($this->producto)(['created_at' => now()->subDays(20)], $a);

    expect(($this->ids)('?seccion=novedades&categoria_id='.$a->id))->toBe([]);
});

// --- Las marcas del producto ------------------------------------------------

test('es_novedad y es_destacado marcan cada fila', function () {
    $nuevo = ($this->producto)(['created_at' => now()->subDay(), 'destacado' => true]);
    $viejo = ($this->producto)(['created_at' => now()->subDays(90), 'destacado' => false]);

    $filas = collect($this->get('/api/v1/productos?orden=recientes')->json('datos'))->keyBy('id');

    expect($filas[$nuevo->id]['es_novedad'])->toBeTrue()
        ->and($filas[$nuevo->id]['es_destacado'])->toBeTrue()
        ->and($filas[$viejo->id]['es_novedad'])->toBeFalse()
        ->and($filas[$viejo->id]['es_destacado'])->toBeFalse();
});

test('la marca es_novedad coincide con la sección novedades', function () {
    foreach (range(1, 6) as $d) {
        ($this->producto)(['created_at' => now()->subDays($d)]);
    }

    $marcados = collect($this->get('/api/v1/productos?orden=recientes')->json('datos'))
        ->where('es_novedad', true)->pluck('id')->all();

    expect($marcados)->toBe(($this->ids)('?seccion=novedades'));
});

test('la descripción breve se corta en las palabras de config', function () {
    config(['tienda.descripcion_breve.palabras' => 3]);
    ($this->producto)(['descripcion' => 'uno dos tres cuatro cinco']);

    $this->get('/api/v1/productos')->assertJsonPath('datos.0.descripcion_breve', 'uno dos tres…');
});

// --- Disponibilidad y lo que nunca viaja ------------------------------------

test('la disponibilidad viaja por niveles, con su texto y sin el número', function (int $stock, int $reservado, string $nivel, string $texto) {
    Producto::factory()->conStock($stock, $reservado)->create(['categoria_id' => Categoria::factory()->create()->id]);

    $fila = $this->get('/api/v1/productos')->json('datos.0');

    expect($fila['disponibilidad'])->toBe($nivel)
        ->and($fila['disponibilidad_texto'])->toBe($texto)
        ->and($fila)->not->toHaveKeys(['stock', 'stock_reservado', 'stock_disponible', 'stock_minimo']);
})->with([
    'disponible'       => [20, 0, 'disponible', 'Disponible'],
    'últimas unidades' => [2, 0, 'ultimas_unidades', 'Últimas unidades'],
    'sin stock'        => [0, 0, 'sin_stock', 'Sin stock'],
    'todo reservado'   => [4, 4, 'sin_stock', 'Sin stock'],
]);

test('una cantidad exacta de stock no aparece en ninguna parte de la respuesta', function () {
    Producto::factory()->conStock(777)->create(['categoria_id' => Categoria::factory()->create()->id]);

    expect($this->get('/api/v1/productos')->getContent())->not->toContain('777');
});

// --- Imagen -----------------------------------------------------------------

test('la imagen principal es la primera de la galería, con URL absoluta', function () {
    ($this->producto)(['imagenes' => ['productos/primera.jpg', 'productos/segunda.jpg']]);

    $imagen = $this->get('/api/v1/productos')->json('datos.0.imagen');

    expect($imagen)->toStartWith('http')->toEndWith('/storage/productos/primera.jpg');
});

test('la URL de la imagen usa el host del pedido y no APP_URL', function () {
    config(['app.url' => 'http://localhost:8000']);
    ($this->producto)(['imagenes' => ['productos/a.jpg']]);

    $imagen = $this->get('http://10.0.2.2:8000/api/v1/productos')->json('datos.0.imagen');

    expect($imagen)->toBe('http://10.0.2.2:8000/storage/productos/a.jpg');
});

test('sin foto la imagen es la de por omisión y nunca null', function (?array $imagenes) {
    ($this->producto)(['imagenes' => $imagenes]);

    expect($this->get('/api/v1/productos')->json('datos.0.imagen'))->toEndWith('/img/producto-sin-imagen.svg');
})->with([[null], [[]]]);

test('ninguna clave prohibida aparece en todo el árbol del listado', function () {
    Producto::factory()->conStockYCosto(5, 12345.67)->create([
        'categoria_id' => Categoria::factory()->create(['ganancia' => 35])->id,
        'destacado'    => true,
    ]);

    $claves = ($this->clavesDe)($this->get('/api/v1/productos')->json());

    expect($claves)->not->toContain('precios', 'costo', 'costo_promedio', 'ganancia', 'stock', 'stock_reservado',
        'stock_minimo', 'precio_lista', 'precio_contado', 'precio_manual', 'proveedores');
});

// --- GET /api/v1/productos/{id}: la ficha ------------------------------------

test('la ficha es pública, JSON y viene en la envoltura de un recurso', function () {
    $producto = ($this->producto)(['nombre' => 'Teclado mecánico']);

    $respuesta = $this->get('/api/v1/productos/'.$producto->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('datos.id', $producto->id)
        ->assertJsonPath('datos.nombre', 'Teclado mecánico');

    expect(array_keys($respuesta->json()))->toBe(['datos']);
});

test('la ficha trae las claves del listado y además descripción, galería y marca', function () {
    $producto = ($this->producto)();

    $claves = array_keys($this->get('/api/v1/productos/'.$producto->id)->json('datos'));

    expect($claves)->toBe([
        'id', 'codigo', 'nombre', 'descripcion_breve', 'imagen', 'categoria',
        'es_novedad', 'es_destacado', 'disponibilidad', 'disponibilidad_texto',
        'descripcion', 'galeria', 'marca',
    ]);
});

test('la ficha trae la descripción completa y el listado sólo la breve', function () {
    config(['tienda.descripcion_breve.palabras' => 2]);
    $producto = ($this->producto)(['descripcion' => 'uno dos tres cuatro']);

    $this->get('/api/v1/productos/'.$producto->id)
        ->assertJsonPath('datos.descripcion', 'uno dos tres cuatro')
        ->assertJsonPath('datos.descripcion_breve', 'uno dos…');
});

test('sin descripción la ficha trae una cadena vacía y no null', function () {
    $producto = ($this->producto)(['descripcion' => null]);

    $this->get('/api/v1/productos/'.$producto->id)
        ->assertJsonPath('datos.descripcion', '')
        ->assertJsonPath('datos.descripcion_breve', '');
});

test('la galería sigue el orden del producto y la primera es la imagen principal', function () {
    $producto = ($this->producto)(['imagenes' => ['productos/uno.jpg', 'productos/dos.jpg', 'productos/tres.jpg']]);

    $datos = $this->get('/api/v1/productos/'.$producto->id)->json('datos');

    expect($datos['galeria'])->toHaveCount(3)
        ->and($datos['galeria'][0])->toEndWith('/storage/productos/uno.jpg')
        ->and($datos['galeria'][1])->toEndWith('/storage/productos/dos.jpg')
        ->and($datos['galeria'][2])->toEndWith('/storage/productos/tres.jpg')
        ->and($datos['imagen'])->toBe($datos['galeria'][0]);
});

test('las URL de la galería usan el host del pedido', function () {
    config(['app.url' => 'http://localhost:8000']);
    $producto = ($this->producto)(['imagenes' => ['productos/a.jpg', 'productos/b.jpg']]);

    $galeria = $this->get('http://10.0.2.2:8000/api/v1/productos/'.$producto->id)->json('datos.galeria');

    expect($galeria)->toBe([
        'http://10.0.2.2:8000/storage/productos/a.jpg',
        'http://10.0.2.2:8000/storage/productos/b.jpg',
    ]);
});

test('sin fotos la galería trae la imagen por omisión, nunca vacía', function (?array $imagenes) {
    $producto = ($this->producto)(['imagenes' => $imagenes]);

    $datos = $this->get('/api/v1/productos/'.$producto->id)->json('datos');

    expect($datos['galeria'])->toHaveCount(1)
        ->and($datos['galeria'][0])->toEndWith('/img/producto-sin-imagen.svg')
        ->and($datos['imagen'])->toBe($datos['galeria'][0]);
})->with([[null], [[]]]);

test('la marca viaja con id y nombre, y es null si el producto no tiene', function () {
    $conMarca = ($this->producto)();
    $sinMarca = ($this->producto)(['marca_id' => null]);

    $this->get('/api/v1/productos/'.$conMarca->id)
        ->assertJsonPath('datos.marca', ['id' => $conMarca->marca->id, 'nombre' => $conMarca->marca->nombre]);

    $respuesta = $this->get('/api/v1/productos/'.$sinMarca->id);
    expect($respuesta->json('datos'))->toHaveKey('marca')
        ->and($respuesta->json('datos.marca'))->toBeNull();
});

test('una marca dada de baja sigue nombrándose en la ficha', function () {
    $producto = ($this->producto)();
    $producto->marca->update(['activo' => false]);

    $this->get('/api/v1/productos/'.$producto->id)
        ->assertJsonPath('datos.marca.nombre', $producto->marca->nombre);
});

test('la ficha dice la categoría, las marcas y la disponibilidad como el listado', function () {
    $categoria = Categoria::factory()->create(['nombre' => 'Monitores']);
    $producto = Producto::factory()->conStock(2)->create([
        'categoria_id' => $categoria->id,
        'destacado'    => true,
        'created_at'   => now()->subDay(),
    ]);

    $datos = $this->get('/api/v1/productos/'.$producto->id)->json('datos');

    expect($datos['categoria'])->toBe(['id' => $categoria->id, 'nombre' => 'Monitores'])
        ->and($datos['es_destacado'])->toBeTrue()
        ->and($datos['es_novedad'])->toBeTrue()
        ->and($datos['disponibilidad'])->toBe('ultimas_unidades')
        ->and($datos['disponibilidad_texto'])->toBe('Últimas unidades');
});

test('es_novedad en la ficha sigue la misma regla que el listado: ventana y tope', function () {
    $viejo   = ($this->producto)(['created_at' => now()->subDays(90)]);
    $creados = collect(range(1, 5))->map(fn (int $d) => ($this->producto)(['created_at' => now()->subDays($d)]));

    // Con tope 3, el cuarto más reciente está dentro de la ventana pero fuera del tope.
    expect($this->get('/api/v1/productos/'.$creados[0]->id)->json('datos.es_novedad'))->toBeTrue()
        ->and($this->get('/api/v1/productos/'.$creados[3]->id)->json('datos.es_novedad'))->toBeFalse()
        ->and($this->get('/api/v1/productos/'.$viejo->id)->json('datos.es_novedad'))->toBeFalse();
});

test('la disponibilidad de la ficha viaja por niveles y sin el número', function (int $stock, int $reservado, string $nivel) {
    $producto = Producto::factory()->conStock($stock, $reservado)->create(['categoria_id' => Categoria::factory()->create()->id]);

    $datos = $this->get('/api/v1/productos/'.$producto->id)->json('datos');

    expect($datos['disponibilidad'])->toBe($nivel)
        ->and($datos)->not->toHaveKeys(['stock', 'stock_reservado', 'stock_disponible', 'stock_minimo']);
})->with([
    'disponible'       => [20, 0, 'disponible'],
    'últimas unidades' => [3, 0, 'ultimas_unidades'],
    'sin stock'        => [0, 0, 'sin_stock'],
    'todo reservado'   => [6, 6, 'sin_stock'],
]);

test('un producto existente que no se ve es 404: inactivo, categoría oculta o rama oculta', function (string $caso) {
    $categoria = match ($caso) {
        'categoría inactiva' => Categoria::factory()->inactiva()->create(),
        'padre inactivo'     => Categoria::factory()->hijaDe(Categoria::factory()->inactiva()->create())->create(),
        default              => Categoria::factory()->create(),
    };
    $producto = ($this->producto)(['activo' => $caso !== 'producto inactivo'], $categoria);

    $this->get('/api/v1/productos/'.$producto->id)
        ->assertStatus(404)
        ->assertJsonPath('codigo', 'no_encontrado');
})->with(['producto inactivo', 'categoría inactiva', 'padre inactivo']);

test('un producto que no existe es 404 con la forma de error', function () {
    $this->get('/api/v1/productos/99999999')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'mensaje' => 'No encontramos lo que buscás.',
            'codigo'  => 'no_encontrado',
            'errores' => [],
        ]);
});

test('un id que no es un número es 404 y no un error del servidor', function (string $id) {
    $this->get('/api/v1/productos/'.$id)->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
})->with(['abc', '1abc', '-1', '1.5']);

test('un id enorme es 404 y no revienta', function () {
    $this->get('/api/v1/productos/99999999999999999999')->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
});

test('la ficha de un inactivo es idéntica a la de un inexistente: no se revela que existe', function () {
    $inactivo = ($this->producto)(['activo' => false]);

    $deInactivo   = $this->get('/api/v1/productos/'.$inactivo->id);
    $deInexistente = $this->get('/api/v1/productos/99999999');

    expect($deInactivo->getContent())->toBe($deInexistente->getContent());
});

test('la ficha, con una credencial inválida, es 401 sesion_invalida', function () {
    $producto = ($this->producto)();

    $this->get('/api/v1/productos/'.$producto->id, ['Authorization' => 'Bearer basura'])
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sesion_invalida');
});

test('la ficha sólo se lee: otros métodos dan 405', function () {
    $producto = ($this->producto)();

    $this->delete('/api/v1/productos/'.$producto->id)
        ->assertStatus(405)
        ->assertJsonPath('codigo', 'metodo_no_permitido');
});

test('ninguna clave prohibida aparece en todo el árbol de la ficha', function () {
    $producto = Producto::factory()->conStockYCosto(5, 12345.67)->create([
        'categoria_id' => Categoria::factory()->create(['ganancia' => 35])->id,
        'destacado'    => true,
        'imagenes'     => ['productos/a.jpg'],
    ]);

    $claves = ($this->clavesDe)($this->get('/api/v1/productos/'.$producto->id)->json());

    expect($claves)->not->toContain('precios', 'costo', 'costo_promedio', 'ganancia', 'stock', 'stock_reservado',
        'stock_minimo', 'precio_lista', 'precio_contado', 'precio_manual', 'proveedores');
});

test('una cantidad exacta de stock no aparece en la ficha', function () {
    $producto = Producto::factory()->conStock(777)->create(['categoria_id' => Categoria::factory()->create()->id]);

    expect($this->get('/api/v1/productos/'.$producto->id)->getContent())->not->toContain('777');
});
