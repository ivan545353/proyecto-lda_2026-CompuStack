<?php

use App\Http\Middleware\AutenticarConCredencial;
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

    // Desde el tramo C entra por la API, con la credencial en la cookie (D-30).
    ingresoAdministracion($this, $usuario->email, 'password')->assertOk();

    $this->get(route('inicio'))->assertRedirect(route('panel'));
    $this->assertAuthenticatedAs($usuario);
});

test('rechaza credenciales incorrectas sin revelar cual fallo', function () {
    $usuario = User::factory()->create();

    // El mismo mensaje que para un correo que no existe.
    ingresoAdministracion($this, $usuario->email, 'incorrecta')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'El correo o la contraseña no son correctos.'], 'errores');
    ingresoAdministracion($this, 'nadie@sistema.local', 'incorrecta')
        ->assertJsonValidationErrors(['email' => 'El correo o la contraseña no son correctos.'], 'errores');

    $this->assertGuest();
});

test('un usuario inactivo no puede iniciar sesion', function () {
    $usuario = User::factory()->inactivo()->create();

    ingresoAdministracion($this, $usuario->email, 'password')->assertJsonValidationErrors('email', 'errores');

    $this->assertGuest();
});

test('un usuario de ambito tienda no entra al sistema de gestion', function () {
    // El ámbito se lee de la tabla roles. Comparar el nombre del rol sería
    // repetir el `perfil === 'Administrador'` del frontend original (M-33).
    $cliente = User::factory()->conRol('Cliente')->create();

    // La sesión es común (HL-98): la API le emite su credencial, pero con ella no se entra a la
    // administración. Lo decide el servidor en cada pantalla (AutenticarConCredencial).
    ingresoAdministracion($this, $cliente->email, 'password')->assertOk();

    $this->get(route('inicio'))->assertRedirect(route('login'));
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
        ingresoAdministracion($this, $usuario->email, 'incorrecta');
    }

    ingresoAdministracion($this, $usuario->email, 'incorrecta')->assertStatus(429);
});

test('cada ingreso emite una credencial nueva, y una elegida por el cliente no sirve', function () {
    // Era «el identificador de sesión cambia al autenticarse», contra la fijación de sesión.
    // Desde el tramo C no se entra con la sesión (D-30): la defensa pasa a la credencial, que la
    // genera el servidor en cada ingreso y nunca se acepta una que traiga el cliente.
    $usuario = User::factory()->conRol('Vendedor')->create();

    $primera = ingresoAdministracion($this, $usuario->email, 'password')->json('datos.credencial');
    $segunda = ingresoAdministracion($this, $usuario->email, 'password')->json('datos.credencial');

    expect($segunda)->not->toBe($primera);

    app('auth')->forgetGuards();
    $this->withUnencryptedCookie(AutenticarConCredencial::COOKIE, '1|elegida-por-el-cliente')
        ->get(route('panel'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

test('un invitado es redirigido al login', function () {
    $this->get(route('panel'))->assertRedirect(route('login'));
});