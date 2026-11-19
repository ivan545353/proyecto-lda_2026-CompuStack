<?php

use App\Http\Middleware\AutenticarConCredencial;
use App\Models\Permiso;
use App\Models\User;
use App\Services\CredencialService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/*
| La administración entra con la credencial de la API, en cookie, y no con la sesión (D-30, HL-109).
| El servidor vuelve a decidir en cada pedido: lo que cambió en la cuenta se nota en el siguiente.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->vendedor   = User::factory()->conRol('Vendedor')->create();
    $this->credencial = fn (User $u) => app(CredencialService::class)->emitir($u)['credencial'];
    $this->entrar     = function (string $credencial, string $ruta) {
        app('auth')->forgetGuards();

        return $this->withUnencryptedCookie(AutenticarConCredencial::COOKIE, $credencial)->get(route($ruta));
    };
});

test('con la credencial en la cookie se entra a la administración, sin guardar nada en la sesión', function () {
    ($this->entrar)(($this->credencial)($this->vendedor), 'panel')->assertOk();

    $this->assertAuthenticatedAs($this->vendedor);
    expect(session()->has(Auth::guard('web')->getName()))->toBeFalse();
});

test('sin credencial se pide el ingreso', function () {
    $this->get(route('panel'))->assertRedirect(route('login'));
});

test('una credencial vencida pide el ingreso y lo dice', function () {
    $credencial = ($this->credencial)($this->vendedor);

    $this->travel(8 * 60 + 1)->minutes();

    ($this->entrar)($credencial, 'panel')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Tu sesión venció. Ingresá de nuevo.'])
        ->assertCookieExpired(AutenticarConCredencial::COOKIE);
    $this->assertGuest();
});

test('un componente que pide JSON con la credencial vencida recibe 401, no la página del ingreso', function () {
    $credencial = ($this->credencial)($this->vendedor);

    $this->travel(8 * 60 + 1)->minutes();
    app('auth')->forgetGuards();

    // `withCredentials`: un pedido JSON de prueba no manda cookies si no se le pide; el navegador sí.
    $this->withCredentials()
        ->withUnencryptedCookie(AutenticarConCredencial::COOKIE, $credencial)
        ->getJson(route('panel'))
        ->assertUnauthorized()
        ->assertJsonPath('codigo', 'sesion_expirada')
        ->assertCookieExpired(AutenticarConCredencial::COOKIE);
});

test('una credencial revocada, inventada, de una cuenta inactiva o de la tienda no entra', function (string $caso) {
    $usuario = $caso === 'tienda' ? User::factory()->conRol('Cliente')->create() : $this->vendedor;
    $credencial = $caso === 'inventada' ? '999|inventada' : ($this->credencial)($usuario);

    match ($caso) {
        'revocada' => $usuario->tokens()->delete(),
        'inactiva' => $usuario->update(['activo' => false]),
        default    => null,
    };

    ($this->entrar)($credencial, 'panel')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
})->with(['revocada', 'inventada', 'inactiva', 'tienda']);

test('sin el permiso de la acción, la pantalla responde 403', function () {
    ($this->entrar)(($this->credencial)($this->vendedor), 'panel.general')->assertForbidden();
});

test('quitado un permiso, el pedido siguiente lo refleja sin volver a ingresar', function () {
    $credencial = ($this->credencial)($this->vendedor);

    ($this->entrar)($credencial, 'panel')->assertOk();

    $rol = $this->vendedor->rol;
    $rol->permisos()->detach(Permiso::where('clave', 'panel.ver_propio')->value('id'));
    Cache::forget(User::claveCache($rol->id));

    ($this->entrar)($credencial, 'panel')->assertForbidden();
});

test('la API no acepta la cookie: sólo el Bearer', function () {
    app('auth')->forgetGuards();

    $this->withCredentials()
        ->withUnencryptedCookie(AutenticarConCredencial::COOKIE, ($this->credencial)($this->vendedor))
        ->getJson('/api/v1/yo')
        ->assertUnauthorized();
});
