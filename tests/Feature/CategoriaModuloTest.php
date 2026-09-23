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

dataset('rutas de categorias', [
    'listado'         => ['get',    'categorias.index',   'categoria.ver',      false],
    'formulario alta' => ['get',    'categorias.create',  'categoria.crear',    false],
    'alta'            => ['post',   'categorias.store',   'categoria.crear',    false],
    'formulario edic' => ['get',    'categorias.edit',    'categoria.editar',   true],
    'edicion'         => ['put',    'categorias.update',  'categoria.editar',   true],
    'baja'            => ['delete', 'categorias.destroy', 'categoria.eliminar', true],
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

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $categoria = Categoria::factory()->create();

    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index'))
        ->assertOk()
        ->assertDontSee(route('categorias.create'))
        ->assertDontSee(route('categorias.edit', $categoria));
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

    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['q' => 'ssd']))
        ->assertViewHas('categorias', fn ($categorias) => $categorias->total() === 1);
});

test('el parametro estado llega al listado y filtra', function () {
    Categoria::factory()->count(2)->create();
    Categoria::factory()->inactiva()->create();

    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['estado' => 'inactivas']))
        ->assertViewHas('categorias', fn ($categorias) => $categorias->total() === 1);
});

test('parent_id raiz muestra solo las categorias principales', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->hijaDe($raiz)->create();
    Categoria::factory()->create();

    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['parent_id' => 'raiz']))
        ->assertViewHas('categorias', fn ($categorias) => $categorias->total() === 2);
});

test('entrar a una categoria muestra sus subcategorias y la ubicacion completa', function () {
    $componentes    = Categoria::factory()->create(['nombre' => 'Componentes']);
    $almacenamiento = Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Almacenamiento']);
    Categoria::factory()->hijaDe($almacenamiento)->create(['nombre' => 'Discos SSD']);
    Categoria::factory()->create(['nombre' => 'Periféricos']);

    // Es la navegación del árbol: el enlace de cada fila es este mismo filtro.
    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['parent_id' => $almacenamiento->id]))
        ->assertOk()
        ->assertViewHas('categorias', fn ($categorias) => $categorias->total() === 1
            && $categorias->first()->nombre === 'Discos SSD')
        ->assertViewHas('padreFiltrado', fn ($padre) => $padre->is($almacenamiento))
        ->assertSeeInOrder(['Componentes', 'Almacenamiento', 'Discos SSD']);
});

test('un filtro que no es valido vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['parent_id' => 'abc']))
        ->assertRedirect(route('categorias.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan la ubicacion', function () {
    $raiz = Categoria::factory()->create();
    Categoria::factory()->count(20)->hijaDe($raiz)->create();

    $this->actingAs(usuarioCon('categoria.ver'))
        ->get(route('categorias.index', ['parent_id' => $raiz->id]))
        ->assertViewHas('categorias', fn ($categorias) => $categorias->count() === 15
            && $categorias->total() === 20
            && str_contains($categorias->nextPageUrl(), "parent_id={$raiz->id}"));
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

    $this->actingAs(usuarioCon('categoria.crear'))
        ->post(route('categorias.store'), ['nombre' => 'Componentes', 'orden' => 0, 'activo' => 1])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una categoría con ese nombre en el mismo grupo.']);

    expect(Categoria::where('nombre', 'Componentes')->count())->toBe(1);
});

test('el mismo nombre se permite en otro grupo y se vuelve a ese grupo', function () {
    $componentes    = Categoria::factory()->create(['nombre' => 'Componentes']);
    Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Fuentes', 'slug' => 'fuentes']);
    $almacenamiento = Categoria::factory()->create(['nombre' => 'Almacenamiento']);

    $this->actingAs(usuarioCon('categoria.crear'))
        ->post(route('categorias.store'), [
            'nombre'    => 'Fuentes',
            'parent_id' => $almacenamiento->id,
            'orden'     => 0,
            'activo'    => 1,
        ])
        ->assertSessionHasNoErrors()
        // Vuelve al grupo donde el usuario estaba cargando, no a la raíz.
        ->assertRedirect(route('categorias.index', ['parent_id' => $almacenamiento->id]));

    expect(Categoria::where('nombre', 'Fuentes')->pluck('slug')->sort()->values()->all())
        ->toBe(['fuentes', 'fuentes-2']);
});

test('una categoria no puede estar dentro de si misma', function () {
    $categoria = Categoria::factory()->create();

    $this->actingAs(usuarioCon('categoria.editar'))
        ->put(route('categorias.update', $categoria), [...DATOS_CATEGORIA, 'parent_id' => $categoria->id])
        ->assertSessionHasErrors(['parent_id' => 'Una categoría no puede estar dentro de sí misma.']);
});

test('una subcategoria no puede contener a la categoria que la contiene', function () {
    $componentes = Categoria::factory()->create(['nombre' => 'Componentes']);
    $placas      = Categoria::factory()->hijaDe($componentes)->create(['nombre' => 'Placas de video']);

    // El selector no ofrece esta opción. La petición se arma a mano, como la
    // armaría alguien que evita la interfaz.
    $this->actingAs(usuarioCon('categoria.editar'))
        ->put(route('categorias.update', $componentes), [
            'nombre'    => 'Componentes',
            'parent_id' => $placas->id,
            'orden'     => 0,
            'activo'    => 1,
        ])
        ->assertSessionHasErrors([
            'parent_id' => '«Placas de video» es una subcategoría de esta, así que no puede contenerla.',
        ]);

    expect($componentes->fresh()->parent_id)->toBeNull();
});

test('no se puede crear un cuarto nivel', function () {
    $n1 = Categoria::factory()->create();
    $n2 = Categoria::factory()->hijaDe($n1)->create();
    $n3 = Categoria::factory()->hijaDe($n2)->create();

    $this->actingAs(usuarioCon('categoria.crear'))
        ->post(route('categorias.store'), [...DATOS_CATEGORIA, 'parent_id' => $n3->id])
        ->assertSessionHasErrors('parent_id');

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
    $this->actingAs(usuarioCon('categoria.editar'))
        ->put(route('categorias.update', $b2), [
            'nombre'    => $b2->nombre,
            'parent_id' => $a2->id,
            'orden'     => 0,
            'activo'    => 1,
        ])
        ->assertSessionHasErrors('parent_id');

    expect(session('errors')->first('parent_id'))->toContain('junto con sus subcategorías')
        ->and($b2->fresh()->parent_id)->toBe($b->id);
});

test('una categoria inactiva no recibe subcategorias nuevas', function () {
    $inactiva = Categoria::factory()->inactiva()->create(['nombre' => 'Impresión']);

    $this->actingAs(usuarioCon('categoria.crear'))
        ->post(route('categorias.store'), [...DATOS_CATEGORIA, 'parent_id' => $inactiva->id])
        ->assertSessionHasErrors(['parent_id' => '«Impresión» está inactiva. Activala primero o elegí otra.']);
});

test('ante un error se conserva la categoria elegida', function () {
    $padre = Categoria::factory()->create();

    // M-31 en la interfaz: equivocarse en un campo no borra lo cargado en otro.
    $this->actingAs(usuarioCon('categoria.crear'))
        ->from(route('categorias.create'))
        ->post(route('categorias.store'), ['nombre' => '', 'parent_id' => (string) $padre->id, 'orden' => 0, 'activo' => 1])
        ->assertRedirect(route('categorias.create'))
        ->assertSessionHasErrors('nombre')
        ->assertSessionHasInput('parent_id', (string) $padre->id);
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta desde dentro de una categoria la trae elegida', function () {
    $componentes = Categoria::factory()->create();

    $this->actingAs(usuarioCon('categoria.crear'))
        ->get(route('categorias.create', ['parent_id' => $componentes->id]))
        ->assertOk()
        ->assertViewHas('categoria', fn ($categoria) => $categoria->parent_id === $componentes->id);
});

test('editar una subcategoria de un padre inactivo no la mueve', function () {
    // El bug que evita padresPosibles(): si el padre inactivo no apareciera en
    // el selector, guardar sin tocar nada mudaría la categoría a la raíz.
    $perifericos = Categoria::factory()->inactiva()->create(['nombre' => 'Periféricos']);
    $monitores   = Categoria::factory()->hijaDe($perifericos)->create(['nombre' => 'Monitores']);

    $usuario = usuarioCon('categoria.editar');

    $this->actingAs($usuario)
        ->get(route('categorias.edit', $monitores))
        ->assertViewHas('padres', fn ($padres) => $padres->contains($perifericos));

    $this->actingAs($usuario)
        ->put(route('categorias.update', $monitores), [
            'nombre'    => 'Monitores gamer',
            'parent_id' => $perifericos->id,
            'orden'     => 0,
            'activo'    => 1,
        ])
        ->assertSessionHasNoErrors();

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

    $this->actingAs(usuarioCon('categoria.eliminar'))
        ->delete(route('categorias.destroy', $categoria))
        ->assertRedirect(route('categorias.index', ['parent_id' => $padre->id]))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'eliminada'));

    $this->assertModelMissing($categoria);
});

test('una categoria con subcategorias o productos se desactiva y el mensaje lo dice', function () {
    $conHijas = Categoria::factory()->create();
    Categoria::factory()->hijaDe($conHijas)->create();

    $conProductos = Categoria::factory()->create();
    Producto::factory()->create(['categoria_id' => $conProductos->id]);

    $usuario = usuarioCon('categoria.eliminar');

    foreach ([$conHijas, $conProductos] as $categoria) {
        $this->actingAs($usuario)
            ->delete(route('categorias.destroy', $categoria))
            ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

        $this->assertModelExists($categoria);
        expect($categoria->fresh()->activo)->toBeFalse();
    }
});