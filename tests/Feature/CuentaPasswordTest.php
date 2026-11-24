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

// Desde el tramo I el cambio va por la API (PUT /api/v1/yo/contrasena); la pantalla sigue en la web.
function cambioDeContrasena($test, User $usuario, array $datos, ?array $credencial = null)
{
    app('auth')->forgetGuards();

    return $test->putJson('/api/v1/yo/contrasena', $datos, $credencial ?? bearer($usuario));
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

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])
        ->assertOk()
        ->assertJsonPath('mensaje', 'Tu contraseña se cambió. La próxima vez que inicies sesión usá la nueva.');

    expect(Hash::check('Nueva67890', $usuario->fresh()->password))->toBeTrue();
});

test('un usuario de ambito tienda tambien puede', function () {
    // La ruta está fuera del grupo 'gestion' a propósito: el día que exista la
    // tienda, un cliente tiene que poder cambiar su clave sin entrar al sistema
    // de gestión.
    $cliente = User::factory()->conRol('Cliente')->create(['password' => 'Vieja12345']);

    cambioDeContrasena($this, $cliente, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertOk();

    expect(Hash::check('Nueva67890', $cliente->fresh()->password))->toBeTrue();
});

test('la contrasena nueva sirve para entrar y la vieja no', function () {
    $usuario = cuentaCon('Vieja12345');

    $nueva = cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertOk()->json('datos.credencial');

    // Salir e ingresar van por la API desde el tramo C (D-30). Se sale con la credencial rotada.
    app('auth')->forgetGuards();
    $this->deleteJson('/api/v1/sesion', [], ['Authorization' => "Bearer {$nueva}"])->assertNoContent();

    ingresoAdministracion($this, $usuario->email, 'Vieja12345')->assertJsonValidationErrors('email', 'errores');

    $this->assertGuest();

    ingresoAdministracion($this, $usuario->email, 'Nueva67890')->assertOk();

    $this->assertAuthenticatedAs($usuario);
});

test('cambiar la contrasena no cierra la sesion propia', function () {
    // Expulsar a alguien por hacer lo correcto sería un castigo, y encima lo
    // desalentaría de cambiarla.
    $usuario    = cuentaCon('Vieja12345');
    $credencial = bearer($usuario);

    $nueva = cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ], $credencial)->assertOk()->json('datos.credencial');

    // Tramo I (HL-132): el servidor revoca la credencial del pedido y devuelve otra, que el módulo
    // central guarda. Era el session()->regenerate(): la sesión sigue, con un identificador nuevo.
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/yo', ['Authorization' => "Bearer {$nueva}"])
        ->assertOk()
        ->assertJsonPath('datos.email', $usuario->email);

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/yo', $credencial)->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Quién puede llegar
|--------------------------------------------------------------------------
*/

test('sin sesion no se llega a la pantalla', function () {
    $this->get(route('cuenta.password.edit'))->assertRedirect(route('login'));

    // Ni al endpoint: sin credencial, 401.
    $this->putJson('/api/v1/yo/contrasena', [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertUnauthorized();
});

test('una cuenta desactivada no cambia su contrasena', function () {
    // La cuenta activa se revalida en cada pedido, no sólo en el ingreso (A-5):
    // en la API, `api.cuenta_activa`. Si le quitaron el acceso, no hace nada.
    $usuario = cuentaCon('Vieja12345', ['activo' => false]);

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertUnauthorized();

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

test('hace falta la contrasena actual, y tiene que ser la correcta', function () {
    $usuario = cuentaCon('Vieja12345');

    cambioDeContrasena($this, $usuario, [
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertJsonValidationErrors('password_actual', 'errores');

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'NoEsLaMia123',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertJsonValidationErrors(['password_actual' => 'Esa no es tu contraseña actual.'], 'errores');

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

test('las dos contrasenas nuevas tienen que coincidir', function () {
    $usuario = cuentaCon('Vieja12345');

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Otra456789',
    ])->assertJsonValidationErrors('password', 'errores');

    expect(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
});

test('una contrasena debil se rechaza', function () {
    $usuario = cuentaCon('Vieja12345');

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'corta',
        'password_confirmation' => 'corta',
    ])->assertJsonValidationErrors('password', 'errores');
});

test('la contrasena nueva no puede ser igual a la actual', function () {
    $usuario = cuentaCon('Vieja12345');

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Vieja12345',
        'password_confirmation' => 'Vieja12345',
    ])->assertJsonValidationErrors(['password' => 'La contraseña nueva tiene que ser distinta de la actual.'], 'errores');
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

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'corta',
        'password_confirmation' => 'corta',
    ])->assertUnprocessable()
        // Ni la respuesta de error la devuelve.
        ->assertDontSee('Vieja12345')
        ->assertDontSee('corta');

    app('auth')->forgetGuards();

    $this->actingAs($usuario)
        ->get(route('cuenta.password.edit'))
        ->assertOk()
        ->assertDontSee('Vieja12345')
        ->assertDontSee('value="corta"', false)
        ->assertDontSee($usuario->password, false)
        // Shell (regla 37): ni el correo de la cuenta llega en la página; lo trae el JS de /yo.
        ->assertDontSee($usuario->email);
});

test('el cambio borra los tokens de restablecimiento pendientes', function () {
    $usuario = cuentaCon('Vieja12345');

    DB::table('password_reset_tokens')->insert([
        'email'      => $usuario->email,
        'token'      => 'un-token-pendiente',
        'created_at' => now(),
    ]);

    cambioDeContrasena($this, $usuario, [
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])->assertOk();

    // Un token vivo después del cambio sigue sirviendo para cambiarla otra vez
    // hasta que expire.
    expect(DB::table('password_reset_tokens')->where('email', $usuario->email)->exists())
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Las rutas web retiradas (tramo I)
|--------------------------------------------------------------------------
*/

dataset('rutas web retiradas de contrasenas', [
    'cambio propio'       => ['put',  '/cuenta/password'],
    'fijar con el enlace' => ['post', '/restablecer'],
]);

test('la ruta web retirada de contrasenas ya no existe', function (string $metodo, string $url) {
    $usuario = cuentaCon('Vieja12345');
    $token   = app(\App\Services\UsuarioService::class)->crearTokenDeRestablecimiento($usuario);

    $respuesta = pedirRuta($this->actingAs($usuario), $metodo, $url, [
        'token'                 => $token,
        'email'                 => $usuario->email,
        'password_actual'       => 'Vieja12345',
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    expect($respuesta->status())->toBeIn([404, 405])
        ->and(Hash::check('Vieja12345', $usuario->fresh()->password))->toBeTrue();
})->with('rutas web retiradas de contrasenas');
