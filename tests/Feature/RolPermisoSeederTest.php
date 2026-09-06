<?php

use App\Models\Empleado;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Database\Seeders\UsuarioAdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('crea los cinco roles de sistema', function () {
    expect(Rol::count())->toBe(5);
    expect(Rol::where('es_sistema', true)->count())->toBe(5);
});

test('el administrador tiene todos los permisos', function () {
    $admin = Rol::where('nombre', 'Administrador')->first();

    expect($admin->permisos()->count())->toBe(Permiso::count());
});

test('un vendedor no puede cobrar ni anular', function () {
    // Regresión del hallazgo C-2. En el sistema original 'cobrar' y
    // 'updateEstado' caían en el fallback `?? "can_update"`, y el vendedor tenía
    // can_update = 1 sobre el módulo sale: podía anular ventas ya cobradas.
    $claves = Rol::where('nombre', 'Vendedor')->first()->permisos()->pluck('clave');

    expect($claves)->toContain('venta.crear');
    expect($claves)->not->toContain('venta.cobrar');
    expect($claves)->not->toContain('venta.anular');
});

test('el cajero cobra pero no emite ventas', function () {
    $claves = Rol::where('nombre', 'Cajero')->first()->permisos()->pluck('clave');

    expect($claves)->toContain('venta.cobrar');
    expect($claves)->not->toContain('venta.crear');
    expect($claves)->not->toContain('venta.anular');
});

test('solo el administrador puede cambiar roles', function () {
    // Cierra el camino del hallazgo C-3: el cambio de rol es un permiso propio,
    // no una consecuencia de poder editar un usuario.
    $conPermiso = Rol::whereHas('permisos', fn ($q) => $q->where('clave', 'usuario.cambiar_rol'))
        ->pluck('nombre');

    expect($conPermiso->all())->toBe(['Administrador']);
});

test('el rol Cliente es de tienda y no tiene permisos de gestion', function () {
    $cliente = Rol::where('nombre', 'Cliente')->first();

    expect($cliente->ambito)->toBe('tienda');
    expect($cliente->permisos()->count())->toBe(0);
});

test('el vendedor solo ve su propio panel', function () {
    $claves = Rol::where('nombre', 'Vendedor')->first()->permisos()->pluck('clave');

    expect($claves)->toContain('panel.ver_propio');
    expect($claves)->not->toContain('panel.ver_global');
});

test('el seeder es idempotente', function () {
    $permisos = Permiso::count();

    $this->seed(RolPermisoSeeder::class);

    expect(Permiso::count())->toBe($permisos);
    expect(Rol::count())->toBe(5);
});

test('el administrador se crea con su fila de empleado', function () {
    // Invariante del modelo: rol de ámbito gestión ⇒ fila en empleados.
    $this->seed(UsuarioAdministradorSeeder::class);

    $admin = User::whereHas('rol', fn ($q) => $q->where('nombre', 'Administrador'))->first();

    expect($admin)->not->toBeNull();
    expect(Empleado::where('user_id', $admin->id)->exists())->toBeTrue();
});

test('el hash de contrasena queda oculto al serializar el usuario', function () {
    // Hallazgo C-1: el DTO del original devolvía la clave en toArray().
    $this->seed(UsuarioAdministradorSeeder::class);

    $usuario = User::whereHas('rol', fn ($q) => $q->where('nombre', 'Administrador'))->firstOrFail();

    expect($usuario->toArray())->not->toHaveKey('password');
    expect(json_encode($usuario))->not->toContain('$2y$');
});
