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

test('crea un rol con sus permisos', function () {
    $this->actingAs(admin())
        ->post(route('roles.store'), [
            'nombre'   => 'Depósito',
            'ambito'   => 'gestion',
            'permisos' => idsDe('producto.ver', 'stock.ajustar'),
        ])
        ->assertRedirect(route('roles.index'));

    $rol = Rol::where('nombre', 'Depósito')->firstOrFail();

    expect($rol->es_sistema)->toBeFalse();
    expect($rol->permisos()->pluck('clave'))->toContain('stock.ajustar');
});

test('cualquier accion implica poder ver el modulo', function () {
    // La regla vive en el servicio, no sólo en el formulario: una petición
    // armada a mano saltearía el JavaScript.
    $this->actingAs(admin())->post(route('roles.store'), [
        'nombre'   => 'Alta productos',
        'ambito'   => 'gestion',
        'permisos' => idsDe('producto.crear'),   // sin producto.ver
    ]);

    $claves = Rol::where('nombre', 'Alta productos')->firstOrFail()
        ->permisos()->pluck('clave');

    expect($claves)->toContain('producto.ver');
    expect($claves)->toContain('producto.crear');
});

test('rechaza un rol de gestion sin permisos', function () {
    $this->actingAs(admin())
        ->post(route('roles.store'), [
            'nombre'   => 'Vacío',
            'ambito'   => 'gestion',
            'permisos' => [],
        ])
        ->assertSessionHasErrors('permisos');

    expect(Rol::where('nombre', 'Vacío')->exists())->toBeFalse();
});

test('acepta un rol de tienda sin permisos', function () {
    // El rol Cliente es exactamente este caso: su ámbito es tienda y en la
    // Etapa 1 no hay tienda.
    $this->actingAs(admin())
        ->post(route('roles.store'), [
            'nombre'   => 'Visitante',
            'ambito'   => 'tienda',
            'permisos' => [],
        ])
        ->assertSessionHasNoErrors();

    expect(Rol::where('nombre', 'Visitante')->exists())->toBeTrue();
});

test('no se pueden modificar los permisos del rol propio', function () {
    // Sin esto, un administrador que se quita 'rol.editar' deja el sistema sin
    // nadie que pueda devolvérselo, y sólo se sale tocando la base a mano.
    $admin = admin();

    $this->actingAs($admin)
        ->put(route('roles.update', $admin->rol), [
            'descripcion' => 'Cambio',
            'permisos'    => idsDe('rol.ver'),
        ])
        ->assertSessionHas('error');

    expect($admin->rol->permisos()->count())->toBe(Permiso::count());
});

test('un rol de sistema conserva su nombre y su ambito', function () {
    $vendedor = Rol::where('nombre', 'Vendedor')->firstOrFail();

    $this->actingAs(admin())->put(route('roles.update', $vendedor), [
        'nombre'      => 'Otro nombre',
        'ambito'      => 'tienda',
        'descripcion' => 'Descripción nueva',
        'permisos'    => idsDe('venta.ver'),
    ]);

    $vendedor->refresh();

    expect($vendedor->nombre)->toBe('Vendedor');
    expect($vendedor->ambito)->toBe('gestion');
    expect($vendedor->descripcion)->toBe('Descripción nueva');
});

test('no se puede eliminar un rol de sistema', function () {
    $cajero = Rol::where('nombre', 'Cajero')->firstOrFail();

    $this->actingAs(admin())
        ->delete(route('roles.destroy', $cajero))
        ->assertSessionHas('error');

    expect(Rol::find($cajero->id))->not->toBeNull();
});

test('no se puede eliminar un rol con usuarios asignados', function () {
    $rol = Rol::create(['nombre' => 'Temporal', 'ambito' => 'gestion', 'es_sistema' => false]);
    User::factory()->create(['rol_id' => $rol->id]);

    $this->actingAs(admin())
        ->delete(route('roles.destroy', $rol))
        ->assertSessionHas('error');

    expect(Rol::find($rol->id))->not->toBeNull();
});

test('elimina un rol sin usuarios', function () {
    $rol = Rol::create(['nombre' => 'Descartable', 'ambito' => 'gestion', 'es_sistema' => false]);

    $this->actingAs(admin())
        ->delete(route('roles.destroy', $rol))
        ->assertRedirect(route('roles.index'));

    expect(Rol::find($rol->id))->toBeNull();
});