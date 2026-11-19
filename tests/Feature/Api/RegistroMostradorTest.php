<?php

use App\Models\Cliente;
use App\Models\User;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El cliente del mostrador frente al registro de la app y al enlace (HL-89, D-26): el registro rechaza
 * su correo, no lo une; y quien elige la contraseña de una cuenta de la tienda sabe que entra por la app.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaRegistro = fn (string $email) => $this->postJson('/api/v1/registro', [
        'nombre'                => 'Rosa',
        'apellido'              => 'Tilos',
        'email'                 => $email,
        'password'              => 'Panaderia123',
        'password_confirmation' => 'Panaderia123',
    ]);
});

test('el registro rechaza el correo de un cliente del mostrador y no toca su ficha', function () {
    $ficha = Cliente::factory()->create(['email' => 'tilos@ejemplo.com', 'user_id' => null]);

    ($this->tiendaRegistro)('tilos@ejemplo.com')
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonPath('errores.email.0', 'Ese correo ya es de un cliente del local: pedí en el mostrador que te den acceso.');

    expect(User::where('email', 'tilos@ejemplo.com')->exists())->toBeFalse()
        ->and(Cliente::count())->toBe(1)
        ->and($ficha->fresh()->user_id)->toBeNull();
});

test('el rechazo no depende de mayúsculas ni espacios', function () {
    Cliente::factory()->create(['email' => 'tilos@ejemplo.com', 'user_id' => null]);

    ($this->tiendaRegistro)('  Tilos@Ejemplo.COM ')->assertStatus(422)->assertJsonPath('codigo', 'validacion');
});

test('un correo que no es de ninguna ficha del mostrador se registra como siempre', function () {
    Cliente::factory()->create(['email' => 'otro@ejemplo.com', 'user_id' => null]);

    ($this->tiendaRegistro)('rosa@ejemplo.com')->assertCreated();
});

test('quien elige la contraseña de una cuenta de la tienda lee que entra por la app, no por la gestión', function () {
    $usuarios = app(UsuarioService::class);
    $cuenta   = $usuarios->darAccesoATienda(
        Cliente::factory()->create(['email' => 'tilos@ejemplo.com', 'user_id' => null]), 'Rosa', 'Tilos',
    );

    $this->post(route('password.store'), [
        'token'                 => $usuarios->crearTokenDeRestablecimiento($cuenta),
        'email'                 => 'tilos@ejemplo.com',
        'password'              => 'Panaderia123',
        'password_confirmation' => 'Panaderia123',
    ])
        ->assertOk()
        ->assertSee('data-cuenta-tienda-lista', false)
        ->assertSee('desde la app');
});
