<?php

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('un usuario de gestion puede iniciar sesion', function () {
    $usuario = User::factory()->conRol('Vendedor')->create();

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'password',
    ])->assertRedirect(route('panel'));

    $this->assertAuthenticatedAs($usuario);
});

test('rechaza credenciales incorrectas sin revelar cual fallo', function () {
    $usuario = User::factory()->create();

    $this->from(route('login'))
        ->post(route('login.attempt'), [
            'email'    => $usuario->email,
            'password' => 'incorrecta',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('un usuario inactivo no puede iniciar sesion', function () {
    $usuario = User::factory()->inactivo()->create();

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('un usuario de ambito tienda no entra al sistema de gestion', function () {
    // El ámbito se lee de la tabla roles. Comparar el nombre del rol sería
    // repetir el `perfil === 'Administrador'` del frontend original (M-33).
    $cliente = User::factory()->conRol('Cliente')->create();

    $this->post(route('login.attempt'), [
        'email'    => $cliente->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('deshabilitar un usuario lo desconecta en el proximo request', function () {
    // Regresión del hallazgo A-5. En el sistema original el perfil viajaba
    // dentro del JWT y no se revalidaba: deshabilitar a alguien lo dejaba
    // operando hasta una hora.
    $usuario = User::factory()->conRol('Vendedor')->create();

    $this->actingAs($usuario)->get(route('panel'))->assertOk();

    $usuario->update(['activo' => false]);

    $this->get(route('panel'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('el login limita los intentos', function () {
    // Cierra A-6: el sistema original no tenía ningún límite.
    $usuario = User::factory()->create();

    foreach (range(1, 5) as $intento) {
        $this->post(route('login.attempt'), [
            'email'    => $usuario->email,
            'password' => 'incorrecta',
        ]);
    }

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'incorrecta',
    ])->assertStatus(429);
});

test('el identificador de sesion cambia al autenticarse', function () {
    $usuario = User::factory()->conRol('Vendedor')->create();

    $this->get(route('login'));
    $anterior = session()->getId();

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'password',
    ]);

    expect(session()->getId())->not->toBe($anterior);
});

test('un invitado es redirigido al login', function () {
    $this->get(route('panel'))->assertRedirect(route('login'));
});