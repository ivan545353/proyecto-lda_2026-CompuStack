<?php

use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as BrokerDePassword;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/** Genera el enlace como lo haría el administrador y devuelve la URL. */
function enlaceParaRestablecer(User $objetivo): string
{
    return route('password.reset', [
        'token' => app(\App\Services\UsuarioService::class)->crearTokenDeRestablecimiento($objetivo),
        'email' => $objetivo->email,
    ]);
}

/*
|--------------------------------------------------------------------------
| Quién genera el enlace
|--------------------------------------------------------------------------
*/

test('quien puede editar usuarios no puede por eso restablecer contrasenas', function () {
    // Son responsabilidades de distinto peso: corregirle el teléfono a alguien
    // y darle la capacidad de fijar su credencial. Bajo el mismo permiso, lo
    // segundo llegaría como efecto lateral de lo primero (C-2).
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.ver', 'usuario.editar'))
        ->post(route('personal.restablecer', $objetivo))
        ->assertForbidden();
});

test('con el permiso propio se genera el enlace', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.resetear_password'))
        ->post(route('personal.restablecer', $objetivo))
        ->assertRedirect(route('personal.edit', $objetivo))
        ->assertSessionHas('enlace_restablecimiento');

    expect(DB::table('password_reset_tokens')->where('email', $objetivo->email)->exists())
        ->toBeTrue();
});

test('el administrador recibe un enlace, nunca una contrasena', function () {
    // La decisión central del paso. Si el administrador conociera la clave,
    // podría operar el sistema con la identidad de otra persona y arruinar la
    // atribución de ventas y movimientos de stock (A-13).
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);

    $respuesta = $this->actingAs(usuarioCon('usuario.resetear_password'))
        ->post(route('personal.restablecer', $objetivo));

    $enlace = $respuesta->getSession()->get('enlace_restablecimiento');

    expect($enlace)->toStartWith(url('/restablecer/'))
        // La contraseña del objetivo no cambió: sigue siendo la que él conoce.
        ->and(Hash::check('Vieja12345', $objetivo->fresh()->password))->toBeTrue();

    // Y el token guardado está hasheado: ni siquiera en la base queda el que
    // viaja en el enlace.
    $guardado = DB::table('password_reset_tokens')->where('email', $objetivo->email)->value('token');

    expect($enlace)->not->toContain($guardado);
});

test('no se genera enlace para una cuenta sin acceso', function () {
    // Fijar una contraseña no le devolvería el acceso: son dos cosas distintas y
    // el orden importa.
    $objetivo = User::factory()->conRol('Vendedor')->inactivo()->create();

    $this->actingAs(usuarioCon('usuario.resetear_password'))
        ->post(route('personal.restablecer', $objetivo))
        ->assertSessionHas('error')
        ->assertSessionMissing('enlace_restablecimiento');

    expect(DB::table('password_reset_tokens')->where('email', $objetivo->email)->exists())
        ->toBeFalse();
});

test('generar el enlace no invalida la contrasena actual', function () {
    // Invalidarla dejaría a la persona afuera si el enlace se pierde, que es
    // M-21 otra vez. La actual sigue sirviendo hasta que la cambie.
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);

    enlaceParaRestablecer($objetivo);

    $this->post(route('login.attempt'), [
        'email'    => $objetivo->email,
        'password' => 'Vieja12345',
    ]);

    $this->assertAuthenticatedAs($objetivo);
});

/*
|--------------------------------------------------------------------------
| M-21: el enlace es la salida del estado sin salida
|--------------------------------------------------------------------------
*/

test('la persona fija su contrasena con el enlace y entra con ella', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);
    $enlace   = enlaceParaRestablecer($objetivo);

    // La pantalla es pública: quien la usa, por definición, no puede entrar.
    $this->get($enlace)->assertOk();

    $this->post(route('password.store'), [
        'token'                 => tokenDelEnlace($enlace),
        'email'                 => $objetivo->email,
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('exito');

    expect(Hash::check('Nueva67890', $objetivo->fresh()->password))->toBeTrue();

    $this->post(route('login.attempt'), [
        'email'    => $objetivo->email,
        'password' => 'Nueva67890',
    ]);

    $this->assertAuthenticatedAs($objetivo);
});

test('la contrasena vieja deja de servir', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);
    $enlace   = enlaceParaRestablecer($objetivo);

    $this->post(route('password.store'), [
        'token'                 => tokenDelEnlace($enlace),
        'email'                 => $objetivo->email,
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    $this->post(route('login.attempt'), [
        'email'    => $objetivo->email,
        'password' => 'Vieja12345',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

test('el restablecimiento no inicia sesion por su cuenta', function () {
    // Que la persona entre con la contraseña nueva confirma que quedó bien, y
    // evita abrirle sesión a una cuenta que el login podría rechazar por otro
    // motivo, con su propio mensaje.
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $enlace   = enlaceParaRestablecer($objetivo);

    $this->post(route('password.store'), [
        'token'                 => tokenDelEnlace($enlace),
        'email'                 => $objetivo->email,
        'password'              => 'Nueva67890',
        'password_confirmation' => 'Nueva67890',
    ]);

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| El token es una credencial
|--------------------------------------------------------------------------
*/

test('el enlace sirve una sola vez', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $enlace   = enlaceParaRestablecer($objetivo);
    $token    = tokenDelEnlace($enlace);

    $this->post(route('password.store'), [
        'token' => $token, 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ]);

    $this->post(route('password.store'), [
        'token' => $token, 'email' => $objetivo->email,
        'password' => 'Tercera12345', 'password_confirmation' => 'Tercera12345',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Nueva67890', $objetivo->fresh()->password))->toBeTrue();
});

test('un token inventado se rechaza', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);

    $this->post(route('password.store'), [
        'token' => 'esto-no-es-un-token', 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Vieja12345', $objetivo->fresh()->password))->toBeTrue();
});

test('el token de una cuenta no sirve para otra', function () {
    $ajeno    = User::factory()->conRol('Vendedor')->create();
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);

    $tokenAjeno = tokenDelEnlace(enlaceParaRestablecer($ajeno));

    $this->post(route('password.store'), [
        'token' => $tokenAjeno, 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Vieja12345', $objetivo->fresh()->password))->toBeTrue();
});

test('un enlace vencido se rechaza', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);
    $token    = tokenDelEnlace(enlaceParaRestablecer($objetivo));

    // auth.passwords.users.expire son 60 minutos.
    $this->travel(61)->minutes();

    $this->post(route('password.store'), [
        'token' => $token, 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Vieja12345', $objetivo->fresh()->password))->toBeTrue();
});

test('generar un enlace nuevo invalida el anterior', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['password' => 'Vieja12345']);

    $primero = tokenDelEnlace(enlaceParaRestablecer($objetivo));
    enlaceParaRestablecer($objetivo);

    $this->post(route('password.store'), [
        'token' => $primero, 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('Vieja12345', $objetivo->fresh()->password))->toBeTrue();
});

test('una contrasena debil se rechaza y el enlace sigue vivo', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $token    = tokenDelEnlace(enlaceParaRestablecer($objetivo));

    $this->post(route('password.store'), [
        'token' => $token, 'email' => $objetivo->email,
        'password' => 'corta', 'password_confirmation' => 'corta',
    ])->assertSessionHasErrors('password');

    // Un error de validación no puede quemar el enlace: dejaría a la persona
    // afuera por escribir mal una contraseña.
    $this->post(route('password.store'), [
        'token' => $token, 'email' => $objetivo->email,
        'password' => 'Nueva67890', 'password_confirmation' => 'Nueva67890',
    ])->assertSessionHasNoErrors();
});

test('no se genera enlace para la propia cuenta', function () {
    // No es sólo que no tenga sentido teniendo la pantalla de cambio propio: el
    // enlace sería una credencial viva de la cuenta más privilegiada, en el
    // portapapeles y en el historial, durante una hora y sin beneficio alguno.
    $usuario = usuarioCon('usuario.resetear_password');

    $this->actingAs($usuario)
        ->post(route('personal.restablecer', $usuario))
        ->assertSessionHas('error')
        ->assertSessionMissing('enlace_restablecimiento');

    expect(DB::table('password_reset_tokens')->where('email', $usuario->email)->exists())
        ->toBeFalse();
});

test('el nombre de la ruta es el que espera la notificacion de Laravel', function () {
    // La notificación ResetPassword arma la URL con route('password.reset').
    // Si alguien renombra la ruta, el envío por correo de la Etapa 2 rompe con
    // "Route [password.reset] not defined", y este test lo dice antes.
    expect(route('password.reset', ['token' => 'x', 'email' => 'a@b.com']))
        ->toContain('/restablecer/x');
});
