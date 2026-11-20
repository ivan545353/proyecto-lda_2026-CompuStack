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

// Las pantallas, que quedan en la web como shells (regla 37).
dataset('rutas de marcas', [
    'listado'         => ['get',    'marcas.index',   'marca.ver',      false],
    'formulario alta' => ['get',    'marcas.create',  'marca.crear',    false],
    'formulario edic' => ['get',    'marcas.edit',    'marca.editar',   true],
]);

// Las operaciones, en la API (tramo D de la Etapa 3). `{marca}` se reemplaza por la marca del test.
dataset('endpoints de marcas', [
    'listado'   => ['get',    '/api/v1/marcas',           'marca.ver'],
    'impresion' => ['get',    '/api/v1/marcas/impresion', 'marca.ver'],
    'ficha'     => ['get',    '/api/v1/marcas/{marca}',   'marca.ver'],
    'alta'      => ['post',   '/api/v1/marcas',           'marca.crear'],
    'edicion'   => ['patch',  '/api/v1/marcas/{marca}',   'marca.editar'],
    'baja'      => ['delete', '/api/v1/marcas/{marca}',   'marca.eliminar'],
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

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $marca = Marca::factory()->create();
    $otros = array_values(array_diff(PERMISOS_MARCA, [$permiso]));

    $this->json($metodo, str_replace('{marca}', $marca->id, $url), ['nombre' => 'Marca de prueba', 'activo' => true], bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de marcas');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $marca = Marca::factory()->create();

    $respuesta = $this->json($metodo, str_replace('{marca}', $marca->id, $url), ['nombre' => 'Marca de prueba', 'activo' => true], bearer(usuarioCon($permiso)));
    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de marcas');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $marca = Marca::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se
    // probó arriba. Esto verifica que la interfaz no ofrezca lo que va a
    // rechazar (hallazgo M-33: el frontend original mostraba pantallas que el
    // backend después rechazaba).
    // Desde el tramo D la fila es una plantilla del shell: lo que se afirma es que la plantilla
    // no trae el botón. Y en la otra dirección, que sí lo trae para quien tiene el permiso.
    $this->actingAs(usuarioCon('marca.ver'))
        ->get(route('marcas.index'))
        ->assertOk()
        ->assertDontSee(route('marcas.create'))
        ->assertDontSee(route('marcas.edit', $marca))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    $this->actingAs(usuarioCon('marca.ver', 'marca.crear', 'marca.editar', 'marca.eliminar'))
        ->get(route('marcas.index'))
        ->assertSee(route('marcas.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de marcas no traen datos de negocio', function () {
    $marca   = Marca::factory()->create(['nombre' => 'Marquita Secreta']);
    $usuario = usuarioCon('marca.ver', 'marca.editar');

    $this->actingAs($usuario)->get(route('marcas.index'))
        ->assertOk()->assertDontSee('Marquita Secreta')->assertSee('data-pantalla="marcas"', false);

    $this->actingAs($usuario)->get(route('marcas.edit', $marca))
        ->assertOk()->assertDontSee('Marquita Secreta')
        ->assertSee('data-marca="'.$marca->id.'"', false);
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

    $this->getJson('/api/v1/marcas?q=king', bearer(usuarioCon('marca.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.nombre', 'Kingston')
        ->assertJsonPath('criterios.q', 'king');
});

test('el parametro estado llega al listado y filtra', function () {
    Marca::factory()->count(2)->create();
    Marca::factory()->inactiva()->create();

    $this->getJson('/api/v1/marcas?estado=inactivas', bearer(usuarioCon('marca.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

// En la API, el aviso es el 422: el listado no muestra otra cosa que la pedida.
test('un filtro inexistente se rechaza con aviso', function () {
    $this->getJson('/api/v1/marcas?estado=cualquiera', bearer(usuarioCon('marca.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('estado', 'errores');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    Marca::factory()->count(20)
        ->sequence(fn ($s) => ['nombre' => "Marca {$s->index}", 'slug' => "marca-{$s->index}"])
        ->create();

    $encabezado = bearer(usuarioCon('marca.ver'));

    // `criterios` es lo que la pantalla repite al pedir la página siguiente: sin él, perdería el filtro.
    $this->getJson('/api/v1/marcas?q=marca', $encabezado)
        ->assertJsonCount(15, 'datos')
        ->assertJsonPath('total', 20)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.q', 'marca');

    $this->getJson('/api/v1/marcas?q=marca&pagina=2', $encabezado)
        ->assertJsonCount(5, 'datos');
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

// Lo escrito lo conserva el formulario: el 422 no recarga la página.
test('un nombre invalido se rechaza y no se guarda', function () {
    $this->postJson('/api/v1/marcas', ['nombre' => 'A', 'activo' => true], bearer(usuarioCon('marca.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('nombre', 'errores');

    expect(Marca::count())->toBe(0);
});

test('un nombre de solo espacios se rechaza en vez de guardarse vacio', function () {
    $this->postJson('/api/v1/marcas', ['nombre' => '    ', 'activo' => true], bearer(usuarioCon('marca.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nombre.0', 'La marca necesita un nombre.');

    expect(Marca::count())->toBe(0);
});

test('un nombre repetido se rechaza con un mensaje que lo explica', function () {
    Marca::factory()->create(['nombre' => 'ASUS', 'slug' => 'asus']);

    $this->postJson('/api/v1/marcas', ['nombre' => 'ASUS', 'activo' => true], bearer(usuarioCon('marca.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nombre.0', 'Ya existe una marca con ese nombre.');
});

test('un logo svg se rechaza', function () {
    // Un SVG puede contener <script>: servido desde el mismo origen sería
    // XSS almacenado sobre la sesión de todos los personal.
    $this->post('/api/v1/marcas', [
        'nombre' => 'Corsair',
        'activo' => 1,
        'logo'   => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
    ], bearer(usuarioCon('marca.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('logo', 'errores');

    expect(Marca::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta genera el slug y guarda el logo', function () {
    $respuesta = $this->post('/api/v1/marcas', [
        'nombre' => 'Western Digital',
        'activo' => 1,
        'logo'   => UploadedFile::fake()->image('logo.png', 120, 60),
    ], bearer(usuarioCon('marca.crear')))
        ->assertCreated()
        ->assertJsonPath('mensaje', 'Marca «Western Digital» creada.')
        ->assertJsonPath('datos.slug', 'western-digital');

    $marca = Marca::firstWhere('nombre', 'Western Digital');

    expect($marca->slug)->toBe('western-digital')
        ->and($respuesta->json('datos.logo'))->toEndWith('storage/'.$marca->logo);
    Storage::disk('public')->assertExists($marca->logo);
});

test('reemplazar el logo borra el archivo anterior', function () {
    $anterior = UploadedFile::fake()->image('viejo.png')->store('marcas', 'public');
    $marca    = Marca::factory()->create(['logo' => $anterior]);

    // Con archivo, `POST` + `_method=PATCH`: PHP no lee los archivos de un PATCH multipart (HL-110).
    $this->post("/api/v1/marcas/{$marca->id}", [
        '_method' => 'PATCH',
        'nombre'  => $marca->nombre,
        'activo'  => 1,
        'logo'    => UploadedFile::fake()->image('nuevo.png'),
    ], bearer(usuarioCon('marca.editar')))->assertOk();

    Storage::disk('public')->assertMissing($anterior);
    Storage::disk('public')->assertExists($marca->fresh()->logo);
});

// La API no adivina un checkbox ausente (M-31): `activo` viaja siempre, y en falso desactiva.
test('activo en falso desactiva la marca, y sin activo se rechaza', function () {
    $marca      = Marca::factory()->create();
    $encabezado = bearer(usuarioCon('marca.editar'));

    $this->patchJson("/api/v1/marcas/{$marca->id}", ['nombre' => $marca->nombre], $encabezado)
        ->assertUnprocessable()->assertJsonValidationErrors('activo', 'errores');
    expect($marca->fresh()->activo)->toBeTrue();

    $this->patchJson("/api/v1/marcas/{$marca->id}", ['nombre' => $marca->nombre, 'activo' => false], $encabezado)
        ->assertOk()->assertJsonPath('datos.activo', false);
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

    $respuesta = $this->deleteJson("/api/v1/marcas/{$marca->id}", [], bearer(usuarioCon('marca.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminada', true);

    expect($respuesta->json('mensaje'))->toContain('eliminada');

    $this->assertModelMissing($marca);
});

test('una marca con productos se desactiva y el mensaje lo dice', function () {
    $marca = Marca::factory()->create();
    Producto::factory()->create(['marca_id' => $marca->id]);

    $respuesta = $this->deleteJson("/api/v1/marcas/{$marca->id}", [], bearer(usuarioCon('marca.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminada', false);

    expect($respuesta->json('mensaje'))->toContain('se desactivó');

    $this->assertModelExists($marca);
    expect($marca->fresh()->activo)->toBeFalse();
});

test('desactivar una marca no desactiva sus productos, y el mensaje lo dice', function () {
    $marca    = Marca::factory()->create();
    $producto = Producto::factory()->create(['marca_id' => $marca->id]);

    $respuesta = $this->patchJson("/api/v1/marcas/{$marca->id}", ['nombre' => $marca->nombre, 'activo' => false], bearer(usuarioCon('marca.editar')))
        ->assertOk();

    expect($respuesta->json('mensaje'))->toContain('siguen activos');

    expect($producto->fresh()->activo)->toBeTrue();
});

test('con la opcion, desactivar la marca desactiva sus productos', function () {
    $marca    = Marca::factory()->create();
    $producto = Producto::factory()->create(['marca_id' => $marca->id]);
    $ajeno    = Producto::factory()->create();

    $this->patchJson("/api/v1/marcas/{$marca->id}", [
        'nombre'               => $marca->nombre,
        'activo'               => false,
        'desactivar_productos' => true,
    ], bearer(usuarioCon('marca.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', "«{$marca->nombre}» se desactivó junto con sus 1 producto(s).");

    expect($producto->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue();
});