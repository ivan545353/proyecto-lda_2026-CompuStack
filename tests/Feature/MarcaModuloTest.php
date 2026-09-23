<?php

use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Disco en memoria: los tests no escriben en storage/ real ni dejan
    // archivos huérfanos entre corridas.
    Storage::fake('public');
});

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Cierra C-2 a nivel de módulo. El middleware original traducía cada acción a
| una de cuatro banderas con `?? "can_update"`, así que una acción podía quedar
| habilitada por un permiso que no era el suyo. Acá se prueban las dos mitades:
| con todos los permisos del módulo MENOS el de la ruta, se deniega; con SÓLO
| el de la ruta, se permite. Si una ruta quedara protegida por el permiso
| equivocado, falla una de las dos.
|
*/

dataset('rutas de marcas', [
    'listado'         => ['get',    'marcas.index',   'marca.ver',      false],
    'formulario alta' => ['get',    'marcas.create',  'marca.crear',    false],
    'alta'            => ['post',   'marcas.store',   'marca.crear',    false],
    'formulario edic' => ['get',    'marcas.edit',    'marca.editar',   true],
    'edicion'         => ['put',    'marcas.update',  'marca.editar',   true],
    'baja'            => ['delete', 'marcas.destroy', 'marca.eliminar', true],
]);

const PERMISOS_MARCA = ['marca.ver', 'marca.crear', 'marca.editar', 'marca.eliminar'];



test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conMarca) {
    $marca = Marca::factory()->create();
    $url   = $conMarca ? route($ruta, $marca) : route($ruta);

    $otros = array_values(array_diff(PERMISOS_MARCA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, ['nombre' => 'Marca de prueba', 'activo' => 1])->assertForbidden();
})->with('rutas de marcas');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conMarca) {
    $marca = Marca::factory()->create();
    $url   = $conMarca ? route($ruta, $marca) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, ['nombre' => 'Marca de prueba', 'activo' => 1]);
    expect($respuesta->status())->not->toBe(403);
})->with('rutas de marcas');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $marca = Marca::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se
    // probó arriba. Esto verifica que la interfaz no ofrezca lo que va a
    // rechazar (hallazgo M-33: el frontend original mostraba pantallas que el
    // backend después rechazaba).
    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index'))
        ->assertOk()
        ->assertDontSee(route('marcas.create'))
        ->assertDontSee(route('marcas.edit', $marca));
});

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en MarcaFiltrosTest; estos prueban
| el cableado entre la URL y el scope, que es exactamente lo que estaba roto en
| el sistema original.
|
*/

test('el parametro q llega al listado y filtra', function () {
    Marca::factory()->create(['nombre' => 'Kingston', 'slug' => 'kingston']);
    Marca::factory()->create(['nombre' => 'Logitech', 'slug' => 'logitech']);

    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index', ['q' => 'king']))
        ->assertOk()
        ->assertViewHas('marcas', fn ($marcas) => $marcas->total() === 1
            && $marcas->first()->nombre === 'Kingston');
});

test('el parametro estado llega al listado y filtra', function () {
    Marca::factory()->count(2)->create();
    Marca::factory()->inactiva()->create();

    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index', ['estado' => 'inactivas']))
        ->assertViewHas('marcas', fn ($marcas) => $marcas->total() === 1);
});

test('un filtro inexistente vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index', ['estado' => 'cualquiera']))
        ->assertRedirect(route('marcas.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    Marca::factory()->count(20)
        ->sequence(fn ($s) => ['nombre' => "Marca {$s->index}", 'slug' => "marca-{$s->index}"])
        ->create();

    $usuario = usuarioCon('marca.ver');

    $this->actingAs($usuario)
        ->get(route('marcas.index', ['q' => 'marca']))
        ->assertViewHas('marcas', fn ($marcas) => $marcas->count() === 15
            && $marcas->total() === 20
            // Sin withQueryString(), la página 2 perdería el filtro.
            && str_contains($marcas->nextPageUrl(), 'q=marca'));

    $this->actingAs($usuario)
        ->get(route('marcas.index', ['q' => 'marca', 'page' => 2]))
        ->assertViewHas('marcas', fn ($marcas) => $marcas->count() === 5);
});

/*
|--------------------------------------------------------------------------
| Validación: rechaza, no vacía
|--------------------------------------------------------------------------
|
| M-31. Los setters originales convertían en cadena vacía lo que no validaba
| y el dato se guardaba igual. Acá la petición se rechaza, no se guarda nada y
| el usuario conserva lo que escribió.
|
*/

test('un nombre invalido se rechaza, no se guarda y conserva lo escrito', function () {
    $this->actingAs(usuarioCon('marca.crear'))
        ->from(route('marcas.create'))
        ->post(route('marcas.store'), ['nombre' => 'A', 'activo' => 1])
        ->assertRedirect(route('marcas.create'))
        ->assertSessionHasErrors('nombre')
        ->assertSessionHasInput('nombre', 'A');

    expect(Marca::count())->toBe(0);
});

test('un nombre de solo espacios se rechaza en vez de guardarse vacio', function () {
    $this->actingAs(usuarioCon('marca.crear'))
        ->post(route('marcas.store'), ['nombre' => '    ', 'activo' => 1])
        ->assertSessionHasErrors(['nombre' => 'La marca necesita un nombre.']);

    expect(Marca::count())->toBe(0);
});

test('un nombre repetido se rechaza con un mensaje que lo explica', function () {
    Marca::factory()->create(['nombre' => 'ASUS', 'slug' => 'asus']);

    $this->actingAs(usuarioCon('marca.crear'))
        ->post(route('marcas.store'), ['nombre' => 'ASUS', 'activo' => 1])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una marca con ese nombre.']);
});

test('un logo svg se rechaza', function () {
    // Un SVG puede contener <script>: servido desde el mismo origen sería
    // XSS almacenado sobre la sesión de todos los usuarios.
    $this->actingAs(usuarioCon('marca.crear'))
        ->post(route('marcas.store'), [
            'nombre' => 'Corsair',
            'activo' => 1,
            'logo'   => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('logo');

    expect(Marca::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta genera el slug y guarda el logo', function () {
    $this->actingAs(usuarioCon('marca.crear'))
        ->post(route('marcas.store'), [
            'nombre' => 'Western Digital',
            'activo' => 1,
            'logo'   => UploadedFile::fake()->image('logo.png', 120, 60),
        ])
        ->assertRedirect(route('marcas.index'))
        ->assertSessionHas('exito');

    $marca = Marca::firstWhere('nombre', 'Western Digital');

    expect($marca->slug)->toBe('western-digital');
    Storage::disk('public')->assertExists($marca->logo);
});

test('reemplazar el logo borra el archivo anterior', function () {
    $anterior = UploadedFile::fake()->image('viejo.png')->store('marcas', 'public');
    $marca    = Marca::factory()->create(['logo' => $anterior]);

    $this->actingAs(usuarioCon('marca.editar'))
        ->put(route('marcas.update', $marca), [
            'nombre' => $marca->nombre,
            'activo' => 1,
            'logo'   => UploadedFile::fake()->image('nuevo.png'),
        ]);

    Storage::disk('public')->assertMissing($anterior);
    Storage::disk('public')->assertExists($marca->fresh()->logo);
});

test('el checkbox desmarcado desactiva la marca', function () {
    $marca = Marca::factory()->create();

    // El checkbox sin marcar no viaja en el POST. Sin la normalización del
    // Form Request, desactivar una marca sería imposible desde el formulario.
    $this->actingAs(usuarioCon('marca.editar'))
        ->put(route('marcas.update', $marca), ['nombre' => $marca->nombre]);

    expect($marca->fresh()->activo)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Baja física y baja lógica
|--------------------------------------------------------------------------
|
| M-16. El original borraba con un DELETE plano y el ON DELETE CASCADE se
| llevaba el historial. Lo que está referenciado no se borra.
|
*/

test('una marca sin productos se elimina', function () {
    $marca = Marca::factory()->create();

    $this->actingAs(usuarioCon('marca.eliminar'))
        ->delete(route('marcas.destroy', $marca))
        ->assertRedirect(route('marcas.index'))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'eliminada'));

    $this->assertModelMissing($marca);
});

test('una marca con productos se desactiva y el mensaje lo dice', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->create(['marca_id' => $marca->id]);

    $this->actingAs(usuarioCon('marca.eliminar'))
        ->delete(route('marcas.destroy', $marca))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

    $this->assertModelExists($marca);
    expect($marca->fresh()->activo)->toBeFalse();
});

test('desactivar una marca no desactiva sus productos, y el mensaje lo dice', function () {
    $marca    = Marca::factory()->create();
    $producto = Producto::factory()->create(['marca_id' => $marca->id]);

    $this->actingAs(usuarioCon('marca.editar'))
        ->put(route('marcas.update', $marca), ['nombre' => $marca->nombre])
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'siguen activos'));

    expect($producto->fresh()->activo)->toBeTrue();
});

test('con la opcion, desactivar la marca desactiva sus productos', function () {
    $marca    = Marca::factory()->create();
    $producto = Producto::factory()->create(['marca_id' => $marca->id]);
    $ajeno    = Producto::factory()->create();

    $this->actingAs(usuarioCon('marca.editar'))
        ->put(route('marcas.update', $marca), [
            'nombre' => $marca->nombre,
            'desactivar_productos' => 1,
        ])
        ->assertSessionHas('exito');

    expect($producto->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue();
});