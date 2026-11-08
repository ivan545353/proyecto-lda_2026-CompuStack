<?php

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/**
 * Una cuenta con una contraseña conocida.
 *
 * La factory usa 'password' para todas; acá hace falta una distinta para poder
 * afirmar que la vieja dejó de servir.
 */
function cuentaCon(string $password, array $sobreescribir = []): User
{
    return User::factory()->conRol('Vendedor')->create(
        array_merge(['password' => $password], $sobreescribir)
    );
}

/*
|--------------------------------------------------------------------------
| M-21: siempre hay un camino para cambiar la contraseña
|--------------------------------------------------------------------------
|
| `resetPass` bloqueaba el login y no existía ninguna pantalla para
| restablecer la clave: la cuenta quedaba en un estado del que no se salía.
|
*/

test('un usuario sin ningun permiso puede cambiar su contrasena', function () {
    // El test central de M-21. Si esta ruta llevara un `can:`, un rol al que se
    // le olvidara ese permiso dejaría a su gente sin salida, que es el hallazgo
    // otra vez. usuarioCon() sin argumentos crea un rol sin ningún permiso.
    $usuario = usuarioCon();
    $usuario->update(['password' => 'Vieja12345']);

    $this->actingAs($usuario)
        ->get(route('cuenta.password.edit'))
        ->assertOk();

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Nueva67890',
        ])
        ->assertRedirect(route('panel'))
        ->assertSessionHas('exito');

    expect(Hash::check('Nueva67890', $usuario->fresh()->password))->toBeTrue();
});

test('un usuario de ambito tienda tambien puede', function () {
    // La ruta está fuera del grupo 'gestion' a propósito: el día que exista la
    // tienda, un cliente tiene que poder cambiar su clave sin entrar al sistema
    // de gestión.
    $cliente = User::factory()->conRol('Cliente')->create(['password' => 'Vieja12345']);

    $this->actingAs($cliente)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Nueva67890',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Nueva67890', $cliente->fresh()->password))->toBeTrue();
});

test('la contrasena nueva sirve para entrar y la vieja no', function () {
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)->put(route('cuenta.password.update'), [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    $this->post(route('logout'));

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'Vieja12345',
    ])->assertSessionHasErrors();

    $this->assertGuest();

    $this->post(route('login.attempt'), [
        'email'    => $usuario->email,
        'password' => 'Nueva67890',
    ]);

    $this->assertAuthenticatedAs($usuario);
});

test('cambiar la contrasena no cierra la sesion propia', function () {
    // Expulsar a alguien por hacer lo correcto sería un castigo, y encima lo
    // desalentaría de cambiarla.
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)->put(route('cuenta.password.update'), [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    $this->assertAuthenticatedAs($usuario);
});

/*
|--------------------------------------------------------------------------
| Quién puede llegar
|--------------------------------------------------------------------------
*/

test('sin sesion no se llega a la pantalla', function () {
    $this->get(route('cuenta.password.edit'))->assertRedirect(route('login'));
});

test('una cuenta desactivada no cambia su contrasena', function () {
    // VerificarUsuarioActivo corre sobre todo el grupo web y se revalida en cada
    // request, no sólo en el login (A-5). Si le quitaron el acceso, no hace nada.
    $usuario = cuentaCon('Vieja12345', ['activo' => false]);

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Nueva67890',
        ])
        ->assertRedirect();

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

test('hace falta la contrasena actual, y tiene que ser la correcta', function () {
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Nueva67890',
        ])
        ->assertSessionHasErrors('password_actual');

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'NoEsLaMia123',
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Nueva67890',
        ])
        ->assertSessionHasErrors(['password_actual' => 'Esa no es tu contraseña actual.']);

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

test('las dos contrasenas nuevas tienen que coincidir', function () {
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'Nueva67890',
            'password_confirmation' => 'Otra456789',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

test('una contrasena debil se rechaza', function () {
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'corta',
            'password_confirmation' => 'corta',
        ])
        ->assertSessionHasErrors('password');
});

test('la contrasena nueva no puede ser igual a la actual', function () {
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'Vieja12345',
            'password_confirmation' => 'Vieja12345',
        ])
        ->assertSessionHasErrors(['password' => 'La contraseña nueva tiene que ser distinta de la actual.']);
});

/*
|--------------------------------------------------------------------------
| C-1 y limpieza
|--------------------------------------------------------------------------
*/

test('la pantalla no trae la contrasena escrita en el formulario', function () {
    // Única excepción a la regla de old() del proyecto: repintar una contraseña
    // la deja en el historial del navegador y en cualquier caché intermedia.
    $usuario = cuentaCon('Vieja12345');

    $this->actingAs($usuario)
        ->from(route('cuenta.password.edit'))
        ->put(route('cuenta.password.update'), [
            'password_actual'       => 'Vieja12345',
            'password'              => 'corta',
            'password_confirmation' => 'corta',
        ]);

    $this->actingAs($usuario)
        ->get(route('cuenta.password.edit'))
        ->assertOk()
        ->assertDontSee('Vieja12345')
        ->assertDontSee('value="corta"', false)
        ->assertDontSee($usuario->password, false);
});

test('el cambio borra los tokens de restablecimiento pendientes', function () {
    $usuario = cuentaCon('Vieja12345');

    DB::table('password_reset_tokens')->insert([
        'email'      => $usuario->email,
        'token'      => 'un-token-pendiente',
        'created_at' => now(),
    ]);

    $this->actingAs($usuario)->put(route('cuenta.password.update'), [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    // Un token vivo después del cambio sigue sirviendo para cambiarla otra vez
    // hasta que expire.
    expect(DB::table('password_reset_tokens')->where('email', $usuario->email)->exists())
        ->toBeFalse();
});