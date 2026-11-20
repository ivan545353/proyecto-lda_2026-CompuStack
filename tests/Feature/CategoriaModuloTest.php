<?php

use App\Models\Categoria;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

const PERMISOS_CATEGORIA = ['categoria.ver', 'categoria.crear', 'categoria.editar', 'categoria.eliminar'];
const DATOS_CATEGORIA    = ['nombre' => 'Categoría de prueba', 'orden' => 0, 'activo' => 1];

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta (C-2)
|--------------------------------------------------------------------------
|
| Mismo criterio que en marcas: con todos los permisos del módulo MENOS el de
| la ruta se deniega, y con SÓLO ese se permite. Si una ruta quedara protegida
| por el permiso equivocado, falla una de las dos mitades.
|
*/

// Las pantallas, que quedan en la web como shells (regla 37).
dataset('rutas de categorias', [
    'listado'         => ['get',    'categorias.index',   'categoria.ver',      false],
    'formulario alta' => ['get',    'categorias.create',  'categoria.crear',    false],
    'formulario edic' => ['get',    'categorias.edit',    'categoria.editar',   true],
]);

// Las operaciones, en la API (tramo D de la Etapa 3). `{categoria}` se reemplaza por la del test.
dataset('endpoints de categorias', [
    'listado'         => ['get',    '/api/v1/categorias?forma=lista',       'categoria.ver'],
    'impresion'       => ['get',    '/api/v1/categorias/impresion',         'categoria.ver'],
    'padres posibles' => ['get',    '/api/v1/categorias/padres-posibles',   'categoria.ver'],
    'ficha'           => ['get',    '/api/v1/categorias/{categoria}',       'categoria.ver'],
    'alta'            => ['post',   '/api/v1/categorias',                   'categoria.crear'],
    'edicion'         => ['patch',  '/api/v1/categorias/{categoria}',       'categoria.editar'],
    'baja'            => ['delete', '/api/v1/categorias/{categoria}',       'categoria.eliminar'],
]);

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conCategoria) {
    $categoria = Categoria::factory()->create();
    $url       = $conCategoria ? route($ruta, $categoria) : route($ruta);
    $otros     = array_values(array_diff(PERMISOS_CATEGORIA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, DATOS_CATEGORIA)->assertForbidden();
})->with('rutas de categorias');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conCategoria) {
    $categoria = Categoria::factory()->create();
    $url       = $conCategoria ? route($ruta, $categoria) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, DATOS_CATEGORIA);

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de categorias');

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $categoria = Categoria::factory()->create();
    $otros     = array_values(array_diff(PERMISOS_CATEGORIA, [$permiso]));

    $this->json($metodo, str_replace('{categoria}', $categoria->id, $url), [...DATOS_CATEGORIA, 'parent_id' => null], bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de categorias');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $categoria = Categoria::factory()->create();

    $respuesta = $this->json($metodo, str_replace('{categoria}', $categoria->id, $url), [...DATOS_CATEGORIA, 'parent_id' => null], bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de categorias');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $categoria = Categoria::factory()->create();

    // Desde el tramo D la fila es una plantilla del shell: lo que se afirma es que la plantilla
    // no trae el botón. Y en la otra dirección, que sí lo trae para quien tiene el permiso.
    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index'))
        ->assertOk()
        ->assertDontSee(route('categorias.create'))
        ->assertDontSee(route('categorias.edit', $categoria))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    $this->actingAs(usuarioCon(...PERMISOS_CATEGORIA))
        ->get(route('categorias.index'))
        ->assertSee(route('categorias.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de categorias no traen datos de negocio', function () {
    $padre     = Categoria::factory()->create(['nombre' => 'Rama Secreta']);
    $categoria = Categoria::factory()->hijaDe($padre)->create(['nombre' => 'Hoja Secreta']);
    $usuario   = usuarioCon(...PERMISOS_CATEGORIA);

    $this->actingAs($usuario)->get(route('categorias.index', ['parent_id' => $padre->id]))
        ->assertOk()->assertDontSee('Rama Secreta')->assertDontSee('Hoja Secreta')
        ->assertSee('data-pantalla="categorias"', false);

    $this->actingAs($usuario)->get(route('categorias.edit', $categoria))
        ->assertOk()->assertDontSee('Rama Secreta')->assertDontSee('Hoja Secreta')
        ->assertSee('data-categoria="'.$categoria->id.'"', false);
});

/*
|--------------------------------------------------------------------------
| Filtros, navegación y paginación sobre HTTP (A-24, A-25)
|--------------------------------------------------------------------------
|
| En el original, CategoryController mandaba `estado` —que no existía ni como
| columna— y el DAO esperaba `nombre`. Estos tests prueban que cada parámetro
| llega a su scope.
|
*/

test('el parametro q llega al listado y filtra', function () {
    Categoria::factory()->create(['nombre' => 'Discos SSD']);
    Categoria::factory()->create(['nombre' => 'Monitores']);

    $this->getJson('/api/v1/categorias?forma=lista&q=ssd', bearer(usuarioCon('categoria.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.nombre', 'Discos SSD');
});

test('el parametro estado llega al listado y filtra', function () {
    Categoria::factory()->count(2)->create();
    Categoria::factory()->inactiva()->create();

    $this->getJson('/api/v1/categorias?forma=lista&estado=inactivas', bearer(usuarioCon('categoria.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('parent_id raiz muestra solo las categorias principales', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->hijaDe($raiz)->create();
    Categoria::factory()->create();

    $this->getJson('/api/v1/categorias?forma=lista&parent_id=raiz', bearer(usuarioCon('categoria.ver')))
        ->assertOk()
        ->assertJsonPath('total', 2);
});

test('entrar a una categoria muestra sus subcategorias y la ubicacion completa', function () {
    $componentes    = Categoria::factory()->create(['nombre' => 'Componentes']);
    $almacenamiento = Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Almacenamiento']);
    Categoria::factory()->hijaDe($almacenamiento)->create(['nombre' => 'Discos SSD']);
    Categoria::factory()->create(['nombre' => 'Periféricos']);

    // Es la navegación del árbol: el enlace de cada fila es este mismo filtro. La miga de pan la
    // arma la pantalla con la ficha del padre; la ubicación completa viaja en `ruta`.
    $encabezado = bearer(usuarioCon('categoria.ver'));

    $this->getJson("/api/v1/categorias?forma=lista&parent_id={$almacenamiento->id}", $encabezado)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.nombre', 'Discos SSD')
        ->assertJsonPath('datos.0.ruta', 'Componentes › Almacenamiento › Discos SSD');

    $this->getJson("/api/v1/categorias/{$almacenamiento->id}", $encabezado)
        ->assertOk()
        ->assertJsonPath('datos.nombre', 'Almacenamiento')
        ->assertJsonPath('datos.ancestros', [['id' => $componentes->id, 'nombre' => 'Componentes']]);
});

// En la API, el aviso es el 422: el listado no muestra otra cosa que la pedida.
test('un filtro que no es valido se rechaza con aviso', function () {
    $this->getJson('/api/v1/categorias?forma=lista&parent_id=abc', bearer(usuarioCon('categoria.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('parent_id', 'errores');
});

test('el listado pagina de a 15 y los enlaces conservan la ubicacion', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->count(20)->hijaDe($raiz)->create();

    // `criterios` es lo que la pantalla repite al pedir la página siguiente: sin él, perdería la ubicación.
    $this->getJson("/api/v1/categorias?forma=lista&parent_id={$raiz->id}", bearer(usuarioCon('categoria.ver')))
        ->assertJsonCount(15, 'datos')
        ->assertJsonPath('total', 20)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.parent_id', (string) $raiz->id);
});

/*
|--------------------------------------------------------------------------
| Validación de la jerarquía
|--------------------------------------------------------------------------
|
| Ninguna de estas reglas la puede garantizar la base. Todas se prueban
| enviando la petición directamente, sin pasar por el selector de la vista,
| porque el selector es comodidad y el control está en el servidor.
|
*/

test('el indice de la base no protege a las categorias principales', function () {
    // Documenta por qué la regla existe en el Request: insertando directo,
    // MariaDB acepta dos "Componentes" de primer nivel, porque en un índice
    // UNIQUE dos NULL nunca se consideran iguales.
    Categoria::factory()->create(['nombre' => 'Componentes']);
    Categoria::factory()->create(['nombre' => 'Componentes']);

    expect(Categoria::whereNull('parent_id')->where('nombre', 'Componentes')->count())->toBe(2);
});

test('no se permiten dos categorias principales con el mismo nombre', function () {
    Categoria::factory()->create(['nombre' => 'Componentes']);

    $this->postJson('/api/v1/categorias', ['nombre' => 'Componentes', 'orden' => 0, 'activo' => true], bearer(usuarioCon('categoria.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nombre.0', 'Ya existe una categoría con ese nombre en el mismo grupo.');

    expect(Categoria::where('nombre', 'Componentes')->count())->toBe(1);
});

test('el mismo nombre se permite en otro grupo y se vuelve a ese grupo', function () {
    $componentes    = Categoria::factory()->create(['nombre' => 'Componentes']);
    Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Fuentes', 'slug' => 'fuentes']);
    $almacenamiento = Categoria::factory()->create(['nombre' => 'Almacenamiento']);

    $this->postJson('/api/v1/categorias', [
        'nombre'    => 'Fuentes',
        'parent_id' => $almacenamiento->id,
        'orden'     => 0,
        'activo'    => true,
    ], bearer(usuarioCon('categoria.crear')))
        ->assertCreated()
        // Vuelve al grupo donde el usuario estaba cargando, no a la raíz: la pantalla lo lee de acá.
        ->assertJsonPath('datos.parent_id', $almacenamiento->id);

    expect(Categoria::where('nombre', 'Fuentes')->pluck('slug')->sort()->values()->all())
        ->toBe(['fuentes', 'fuentes-2']);
});

test('una categoria no puede estar dentro de si misma', function () {
    $categoria = Categoria::factory()->create();

    $this->patchJson("/api/v1/categorias/{$categoria->id}", [...DATOS_CATEGORIA, 'parent_id' => $categoria->id], bearer(usuarioCon('categoria.editar')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.parent_id.0', 'Una categoría no puede estar dentro de sí misma.');
});

test('una subcategoria no puede contener a la categoria que la contiene', function () {
    $componentes = Categoria::factory()->create(['nombre' => 'Componentes']);
    $placas      = Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Placas de video']);

    // El selector no ofrece esta opción. La petición se arma a mano, como la
    // armaría alguien que evita la interfaz.
    $this->patchJson("/api/v1/categorias/{$componentes->id}", [
        'nombre'    => 'Componentes',
        'parent_id' => $placas->id,
        'orden'     => 0,
        'activo'    => true,
    ], bearer(usuarioCon('categoria.editar')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.parent_id.0', '«Placas de video» es una subcategoría de esta, así que no puede contenerla.');

    expect($componentes->fresh()->parent_id)->toBeNull();
});

test('no se puede crear un cuarto nivel', function () {
    $n1 = Categoria::factory()->create();
    $n2 = Categoria::factory()->hijaDe($n1)->create();
    $n3 = Categoria::factory()->hijaDe($n2)->create();

    $this->postJson('/api/v1/categorias', [...DATOS_CATEGORIA, 'parent_id' => $n3->id], bearer(usuarioCon('categoria.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id', 'errores');

    expect($n3->hijas()->count())->toBe(0);
});

test('mover una categoria con subcategorias controla el grupo completo', function () {
    // A › A2            (A2 es nivel 2)
    // B › B2 › B3       (B2 tiene una hija: su subárbol mide 2)
    $a  = Categoria::factory()->create();
    $a2 = Categoria::factory()->hijaDe($a)->create(['nombre' => 'A2']);
    $b  = Categoria::factory()->create();
    $b2 = Categoria::factory()->hijaDe($b)->create();
    Categoria::factory()->hijaDe($b2)->create();

    // B2 cabría sola debajo de A2 (nivel 3), pero su hija quedaría en el 4.
    $respuesta = $this->patchJson("/api/v1/categorias/{$b2->id}", [
        'nombre'    => $b2->nombre,
        'parent_id' => $a2->id,
        'orden'     => 0,
        'activo'    => true,
    ], bearer(usuarioCon('categoria.editar')))
        ->assertUnprocessable();

    expect($respuesta->json('errores.parent_id.0'))->toContain('junto con sus subcategorías')
        ->and($b2->fresh()->parent_id)->toBe($b->id);
});

test('una categoria inactiva no recibe subcategorias nuevas', function () {
    $inactiva = Categoria::factory()->inactiva()->create(['nombre' => 'Impresión']);

    $this->postJson('/api/v1/categorias', [...DATOS_CATEGORIA, 'parent_id' => $inactiva->id], bearer(usuarioCon('categoria.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.parent_id.0', '«Impresión» está inactiva. Activala primero o elegí otra.');
});

test('ante un error se conserva la categoria elegida', function () {
    $padre = Categoria::factory()->create();

    // M-31 en la interfaz: equivocarse en un campo no borra lo cargado en otro. El 422 no recarga
    // la página, así que el formulario conserva lo elegido; la API sólo marca el campo que falla.
    $this->postJson('/api/v1/categorias', ['nombre' => '', 'parent_id' => $padre->id, 'orden' => 0, 'activo' => true], bearer(usuarioCon('categoria.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre', 'errores')
        ->assertJsonMissingPath('errores.parent_id');
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta desde dentro de una categoria la trae elegida', function () {
    $componentes = Categoria::factory()->create();

    // El shell lleva el grupo de la URL; la pantalla lo elige al cargar los padres posibles.
    $this->actingAs(usuarioCon('categoria.crear'))
        ->get(route('categorias.create', ['parent_id' => $componentes->id]))
        ->assertOk()
        ->assertSee('data-padre-inicial="'.$componentes->id.'"', false);
});

test('editar una subcategoria de un padre inactivo no la mueve', function () {
    // El bug que evita padresPosibles(): si el padre inactivo no apareciera en
    // el selector, guardar sin tocar nada mudaría la categoría a la raíz.
    $perifericos = Categoria::factory()->inactiva()->create(['nombre' => 'Periféricos']);
    $monitores   = Categoria::factory()->hijaDe($perifericos)->create(['nombre' => 'Monitores']);

    $padres = $this->getJson("/api/v1/categorias/padres-posibles?categoria={$monitores->id}", bearer(usuarioCon('categoria.ver')))
        ->assertOk()
        ->json('datos.*.id');
    expect($padres)->toContain($perifericos->id);
    app('auth')->forgetGuards();

    $this->patchJson("/api/v1/categorias/{$monitores->id}", [
        'nombre'    => 'Monitores gamer',
        'parent_id' => $perifericos->id,
        'orden'     => 0,
        'activo'    => true,
    ], bearer(usuarioCon('categoria.editar')))
        ->assertOk()
        ->assertJsonPath('datos.parent_id', $perifericos->id);

    expect($monitores->fresh()->parent_id)->toBe($perifericos->id);
});

/*
|--------------------------------------------------------------------------
| Bajas (M-16)
|--------------------------------------------------------------------------
*/

test('una categoria sin dependencias se elimina y se vuelve a su grupo', function () {
    $padre     = Categoria::factory()->create();
    $categoria = Categoria::factory()->hijaDe($padre)->create();

    $respuesta = $this->deleteJson("/api/v1/categorias/{$categoria->id}", [], bearer(usuarioCon('categoria.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminada', true)
        // El grupo al que vuelve la pantalla.
        ->assertJsonPath('datos.parent_id', $padre->id);

    expect($respuesta->json('mensaje'))->toContain('eliminada');

    $this->assertModelMissing($categoria);
});

test('una categoria con subcategorias o productos se desactiva y el mensaje lo dice', function () {
    $conHijas = Categoria::factory()->create();
    Categoria::factory()->hijaDe($conHijas)->create();

    $conProductos = Categoria::factory()->create();
    Producto::factory()->create(['categoria_id' => $conProductos->id]);

    $encabezado = bearer(usuarioCon('categoria.eliminar'));

    foreach ([$conHijas, $conProductos] as $categoria) {
        $respuesta = $this->deleteJson("/api/v1/categorias/{$categoria->id}", [], $encabezado)
            ->assertOk()
            ->assertJsonPath('datos.eliminada', false);
        app('auth')->forgetGuards();

        expect($respuesta->json('mensaje'))->toContain('se desactivó');

        $this->assertModelExists($categoria);
        expect($categoria->fresh()->activo)->toBeFalse();
    }
});
/*
|--------------------------------------------------------------------------
| Un recurso, representación por permiso (regla 36, tramo D)
|--------------------------------------------------------------------------
|
| `GET /categorias` es el árbol de la tienda; con `forma=lista`, el listado de la administración.
|
*/

test('sin forma=lista es el arbol de la tienda de siempre, y los filtros de gestion no se leen', function () {
    $activa = Categoria::factory()->create(['nombre' => 'Monitores']);
    Categoria::factory()->inactiva()->create(['nombre' => 'Discontinuada']);

    $respuesta = $this->getJson('/api/v1/categorias?estado=inactivas&q=disc', bearer(usuarioCon('categoria.ver')))
        ->assertOk();

    expect($respuesta->json('datos'))->toBe([['id' => $activa->id, 'nombre' => 'Monitores', 'hijas' => []]])
        ->and($respuesta->json('criterios'))->toBe([]);
});

test('forma=lista sin categoria.ver es 403, tambien para el visitante y la cuenta de tienda', function () {
    $this->getJson('/api/v1/categorias?forma=lista')
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    $tienda = app(\App\Services\UsuarioService::class)->crear(datosDeClienteDeTienda());
    $this->getJson('/api/v1/categorias?forma=lista', bearer($tienda))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
});

test('la lista trae las inactivas, y con_hijas solo las que tienen subcategorias', function () {
    $padre = Categoria::factory()->inactiva()->create(['nombre' => 'Periféricos']);
    Categoria::factory()->hijaDe($padre)->create(['nombre' => 'Monitores']);
    $encabezado = bearer(usuarioCon('categoria.ver'));

    $this->getJson('/api/v1/categorias?forma=lista', $encabezado)
        ->assertOk()->assertJsonPath('total', 2);

    $this->getJson('/api/v1/categorias?forma=lista&con_hijas=1', $encabezado)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.nombre', 'Periféricos')
        ->assertJsonPath('datos.0.activo', false)
        ->assertJsonPath('datos.0.subcategorias', 1);
});

test('la ficha dice que contenido activo tiene antes de desactivarla', function () {
    $categoria = Categoria::factory()->create();
    Categoria::factory()->hijaDe($categoria)->create();
    Producto::factory()->count(2)->create(['categoria_id' => $categoria->id]);

    $this->getJson("/api/v1/categorias/{$categoria->id}", bearer(usuarioCon('categoria.ver')))
        ->assertOk()
        ->assertJsonPath('datos.contenido_activo', ['subcategorias' => 1, 'productos' => 2])
        ->assertJsonPath('datos.ancestros', []);
});
