<?php

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

function idsDe(string ...$claves): array
{
    return Permiso::whereIn('clave', $claves)->pluck('id')->all();
}

// Desde el tramo I, el alta, la edición y la baja van por la API; las pantallas siguen en la web.

test('crea un rol con sus permisos', function () {
    $this->postJson('/api/v1/roles', [
        'nombre'   => 'Depósito',
        'ambito'   => 'gestion',
        'permisos' => idsDe('producto.ver', 'stock.ajustar'),
    ], bearer(admin()))
        ->assertCreated()
        ->assertJsonPath('mensaje', 'Rol «Depósito» creado.');

    $rol = Rol::where('nombre', 'Depósito')->firstOrFail();

    expect($rol->es_sistema)->toBeFalse();
    expect($rol->permisos()->pluck('clave'))->toContain('stock.ajustar');
});

test('cualquier accion implica poder ver el modulo', function () {
    // La regla vive en el servicio, no sólo en el formulario: una petición
    // armada a mano saltearía el JavaScript.
    $this->postJson('/api/v1/roles', [
        'nombre'   => 'Alta productos',
        'ambito'   => 'gestion',
        'permisos' => idsDe('producto.crear'),   // sin producto.ver
    ], bearer(admin()))->assertCreated();

    $claves = Rol::where('nombre', 'Alta productos')->firstOrFail()
        ->permisos()->pluck('clave');

    expect($claves)->toContain('producto.ver');
    expect($claves)->toContain('producto.crear');
});

test('rechaza un rol de gestion sin permisos', function () {
    $this->postJson('/api/v1/roles', [
        'nombre'   => 'Vacío',
        'ambito'   => 'gestion',
        'permisos' => [],
    ], bearer(admin()))
        ->assertJsonValidationErrors(['permisos' => 'Un rol de gestión necesita al menos un permiso.'], 'errores');

    expect(Rol::where('nombre', 'Vacío')->exists())->toBeFalse();
});

test('acepta un rol de tienda sin permisos', function () {
    // El rol Cliente es exactamente este caso: su ámbito es tienda y en la
    // Etapa 1 no hay tienda.
    $this->postJson('/api/v1/roles', [
        'nombre'   => 'Visitante',
        'ambito'   => 'tienda',
        'permisos' => [],
    ], bearer(admin()))
        ->assertCreated();

    expect(Rol::where('nombre', 'Visitante')->exists())->toBeTrue();
});

test('no se pueden modificar los permisos del rol propio', function () {
    // Sin esto, un administrador que se quita 'rol.editar' deja el sistema sin
    // nadie que pueda devolvérselo, y sólo se sale tocando la base a mano.
    $admin = admin();

    $this->patchJson("/api/v1/roles/{$admin->rol_id}", [
        'descripcion' => 'Cambio',
        'permisos'    => idsDe('rol.ver'),
    ], bearer($admin))
        ->assertConflict()
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect($admin->rol->permisos()->count())->toBe(Permiso::count());
});

test('un rol de sistema conserva su nombre y su ambito', function () {
    $vendedor = Rol::where('nombre', 'Vendedor')->firstOrFail();

    $this->patchJson("/api/v1/roles/{$vendedor->id}", [
        'nombre'      => 'Otro nombre',
        'ambito'      => 'tienda',
        'descripcion' => 'Descripción nueva',
        'permisos'    => idsDe('venta.ver'),
    ], bearer(admin()))->assertOk();

    $vendedor->refresh();

    expect($vendedor->nombre)->toBe('Vendedor');
    expect($vendedor->ambito)->toBe('gestion');
    expect($vendedor->descripcion)->toBe('Descripción nueva');
});

test('no se puede eliminar un rol de sistema', function () {
    $cajero = Rol::where('nombre', 'Cajero')->firstOrFail();

    $this->deleteJson("/api/v1/roles/{$cajero->id}", [], bearer(admin()))
        ->assertConflict()
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect(Rol::find($cajero->id))->not->toBeNull();
});

test('no se puede eliminar un rol con usuarios asignados', function () {
    $rol = Rol::create(['nombre' => 'Temporal', 'ambito' => 'gestion', 'es_sistema' => false]);
    User::factory()->create(['rol_id' => $rol->id]);

    $this->deleteJson("/api/v1/roles/{$rol->id}", [], bearer(admin()))
        ->assertConflict()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, '1 usuario(s) asignado(s)'));

    expect(Rol::find($rol->id))->not->toBeNull();
});

test('elimina un rol sin usuarios', function () {
    $rol = Rol::create(['nombre' => 'Descartable', 'ambito' => 'gestion', 'es_sistema' => false]);

    $this->deleteJson("/api/v1/roles/{$rol->id}", [], bearer(admin()))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true);

    expect(Rol::find($rol->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Los endpoints de roles (tramo I)
|--------------------------------------------------------------------------
|
| Leer con `rol.ver`, escribir con `rol.editar`, como en la web (C-2). Las dos
| mitades: sin el permiso del endpoint se deniega, y con él solo alcanza.
|
*/

dataset('endpoints de roles', [
    'listado'  => ['get',    '/api/v1/roles',       'rol.ver'],
    'ficha'    => ['get',    '/api/v1/roles/{rol}', 'rol.ver'],
    'permisos' => ['get',    '/api/v1/permisos',    'rol.ver'],
    'alta'     => ['post',   '/api/v1/roles',       'rol.editar'],
    'edicion'  => ['patch',  '/api/v1/roles/{rol}', 'rol.editar'],
    'baja'     => ['delete', '/api/v1/roles/{rol}', 'rol.editar'],
]);

test('en la API, el otro permiso del modulo no habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $rol  = Rol::create(['nombre' => 'De prueba', 'ambito' => 'gestion', 'es_sistema' => false]);
    $otro = $permiso === 'rol.ver' ? 'rol.editar' : 'rol.ver';

    $this->json($metodo, str_replace('{rol}', (string) $rol->id, $url), ['nombre' => 'Nuevo', 'ambito' => 'tienda', 'permisos' => []], bearer(usuarioCon($otro)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de roles');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $rol = Rol::create(['nombre' => 'De prueba', 'ambito' => 'gestion', 'es_sistema' => false]);

    $respuesta = $this->json($metodo, str_replace('{rol}', (string) $rol->id, $url), ['nombre' => 'Nuevo', 'ambito' => 'tienda', 'permisos' => []], bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de roles');

test('el listado trae los conteos y si se puede eliminar, derivado de los datos', function () {
    $libre   = Rol::create(['nombre' => 'Aaa libre', 'ambito' => 'gestion', 'es_sistema' => false]);
    $ocupado = Rol::create(['nombre' => 'Aaa ocupado', 'ambito' => 'gestion', 'es_sistema' => false]);
    User::factory()->create(['rol_id' => $ocupado->id]);

    $datos = collect($this->getJson('/api/v1/roles?por_pagina=50', bearer(usuarioCon('rol.ver')))->assertOk()->json('datos'));

    expect($datos->firstWhere('id', $libre->id)['se_puede_eliminar'])->toBeTrue()
        ->and($datos->firstWhere('id', $ocupado->id)['se_puede_eliminar'])->toBeFalse()
        ->and($datos->firstWhere('id', $ocupado->id)['cantidad_usuarios'])->toBe(1)
        ->and($datos->firstWhere('nombre', 'Cajero')['se_puede_eliminar'])->toBeFalse()
        // Los de sistema primero, como la pantalla de siempre.
        ->and($datos->first()['es_sistema'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Las rutas web retiradas y los shells (tramo I)
|--------------------------------------------------------------------------
*/

dataset('rutas web retiradas de roles', [
    'alta'            => ['post',   '/roles'],
    'guardar edicion' => ['put',    '/roles/{rol}'],
    'baja'            => ['delete', '/roles/{rol}'],
]);

test('la ruta web retirada de roles ya no existe', function (string $metodo, string $url) {
    $rol   = Rol::create(['nombre' => 'Intacto', 'ambito' => 'gestion', 'es_sistema' => false]);
    $quien = usuarioCon('rol.ver', 'rol.editar');
    $antes = Rol::count();

    $respuesta = pedirRuta(
        $this->actingAs($quien),
        $metodo,
        str_replace('{rol}', (string) $rol->id, $url),
        ['nombre' => 'Cambiado', 'ambito' => 'gestion', 'permisos' => idsDe('venta.ver')],
    );

    expect($respuesta->status())->toBeIn([404, 405])
        ->and($rol->fresh()?->nombre)->toBe('Intacto')
        ->and($rol->permisos()->count())->toBe(0)
        ->and(Rol::count())->toBe($antes);
})->with('rutas web retiradas de roles');

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de roles no traen datos de negocio', function () {
    $rol = Rol::create(['nombre' => 'Rol Reservado', 'descripcion' => 'Descripción reservada', 'ambito' => 'gestion', 'es_sistema' => false]);
    $rol->permisos()->sync(idsDe('venta.ver'));
    $usuario = usuarioCon('rol.ver', 'rol.editar');

    $this->actingAs($usuario)->get(route('roles.index'))
        ->assertOk()
        ->assertDontSee('Rol Reservado')
        ->assertDontSee('Cajero')
        ->assertSee('data-pantalla="roles"', false);

    $this->actingAs($usuario)->get(route('roles.edit', $rol))
        ->assertOk()
        ->assertDontSee('Rol Reservado')
        ->assertDontSee('Descripción reservada')
        // Ni el catálogo de permisos: las casillas las arma el JS.
        ->assertDontSee('venta.ver')
        ->assertSee('data-rol="'.$rol->id.'"', false);
});

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $this->actingAs(usuarioCon('rol.ver'))
        ->get(route('roles.index'))
        ->assertOk()
        ->assertDontSee(route('roles.create'))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('rol.ver', 'rol.editar'))
        ->get(route('roles.index'))
        ->assertSee(route('roles.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});
