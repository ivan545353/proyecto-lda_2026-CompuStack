<?php

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('una accion sin permiso definido se deniega', function () {
    // El corazón del hallazgo C-2. El middleware original hacía
    // `MAPA_PERMISOS[$action] ?? "can_update"`, así que toda acción no mapeada
    // heredaba el permiso de actualizar. Acá lo que no está asignado, no se da.
    $admin = User::factory()->conRol('Administrador')->create();

    expect(Gate::forUser($admin)->allows('modulo.inexistente'))->toBeFalse();
});

test('el administrador puede todo lo que existe', function () {
    $admin = User::factory()->conRol('Administrador')->create();

    expect(Gate::forUser($admin)->allows('venta.anular'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('rol.editar'))->toBeTrue();
});

test('un vendedor no puede cobrar ni anular', function () {
    $vendedor = User::factory()->conRol('Vendedor')->create();

    expect(Gate::forUser($vendedor)->allows('venta.crear'))->toBeTrue();
    expect(Gate::forUser($vendedor)->allows('venta.cobrar'))->toBeFalse();
    expect(Gate::forUser($vendedor)->allows('venta.anular'))->toBeFalse();
});

test('un usuario sin permiso recibe 403', function () {
    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('roles.index'))
        ->assertForbidden();
});

test('un usuario con el permiso accede', function () {
    $this->actingAs(usuarioCon('rol.ver'))
        ->get(route('roles.index'))
        ->assertOk();
});

test('ver no alcanza para editar', function () {
    $this->actingAs(usuarioCon('rol.ver'))
        ->get(route('roles.create'))
        ->assertForbidden();
});

test('la cache de permisos se invalida al cambiar el rol', function () {
    $usuario = usuarioCon('rol.ver', 'rol.editar');
    $rol     = $usuario->rol;

    expect($usuario->tienePermiso('rol.editar'))->toBeTrue();

    // Simula lo que hace RolService al guardar
    $rol->permisos()->sync(
        \App\Models\Permiso::where('clave', 'rol.ver')->pluck('id')
    );
    \Illuminate\Support\Facades\Cache::forget(User::claveCache($rol->id));

    expect($usuario->fresh()->tienePermiso('rol.editar'))->toBeFalse();
});