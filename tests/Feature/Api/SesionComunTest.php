<?php

use App\Models\User;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

/*
| La sesión común de la Etapa 3 (tramo B): las cuentas de gestión y las de tienda entran por el
| mismo endpoint, y `GET /yo` le dice a cada cliente quién es, con qué rol y qué puede hacer.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tienda  = app(UsuarioService::class)->crear(datosDeClienteDeTienda());
    $this->gestion = User::factory()->conRol('Vendedor')->create(['email' => 'vende@sistema.local', 'password' => 'Secreta123']);

    $this->iniciar = fn (string $email) => $this->postJson('/api/v1/sesion', ['email' => $email, 'password' => 'Secreta123'])
        ->assertOk()->json('datos.credencial');
    $this->con = fn (string $credencial) => ['Authorization' => 'Bearer '.$credencial];
});

test('cada cuenta inicia sesión por el mismo endpoint y /yo le devuelve su rol y sus permisos', function (string $cual, string $rol, string $ambito) {
    $cuenta     = $this->{$cual};
    $credencial = ($this->iniciar)($cuenta->email);
    app('auth')->forgetGuards();

    $respuesta = $this->getJson('/api/v1/yo', ($this->con)($credencial))->assertOk()
        ->assertJsonPath('datos.email', $cuenta->email)
        ->assertJsonPath('datos.rol', ['nombre' => $rol, 'ambito' => $ambito]);

    expect(array_keys($respuesta->json('datos')))->toBe(['nombre', 'apellido', 'email', 'rol', 'permisos'])
        ->and($respuesta->json('datos.permisos'))
        ->toBe($cuenta->rol->permisos()->pluck('clave')->sort()->values()->all())
        ->not->toBeEmpty();
})->with([
    'gestión' => ['gestion', 'Vendedor', 'gestion'],
    'tienda'  => ['tienda', 'Cliente', 'tienda'],
]);

test('los permisos de /yo son los del rol: los de gestión no viajan a la tienda ni al revés', function () {
    $deGestion = $this->getJson('/api/v1/yo', ($this->con)(($this->iniciar)('vende@sistema.local')))->json('datos.permisos');
    app('auth')->forgetGuards();
    $deTienda = $this->getJson('/api/v1/yo', ($this->con)(($this->iniciar)('camila@sistema.local')))->json('datos.permisos');

    expect(array_intersect($deGestion, $deTienda))->toBe([])
        ->and($deTienda)->each->toStartWith('tienda.');
});

test('/yo sin credencial es 401 sin_sesion', function () {
    $this->getJson('/api/v1/yo')->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');
});

test('/yo de una cuenta de gestión desactivada con la credencial vigente es 401 sesion_invalida', function () {
    $credencial = ($this->iniciar)('vende@sistema.local');
    // Sin pasar por el servicio, que revocaría la credencial: se prueba el cinturón.
    User::whereKey($this->gestion->id)->update(['activo' => false]);
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/yo', ($this->con)($credencial))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('una cuenta de gestión cierra su sesión, y esa credencial deja de servir', function () {
    $credencial = ($this->iniciar)('vende@sistema.local');
    $otra       = ($this->iniciar)('vende@sistema.local');
    app('auth')->forgetGuards();

    $this->deleteJson('/api/v1/sesion', [], ($this->con)($credencial))->assertNoContent();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/yo', ($this->con)($credencial))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/yo', ($this->con)($otra))->assertOk();

    expect(PersonalAccessToken::where('tokenable_id', $this->gestion->id)->count())->toBe(1);
});

test('una cuenta de gestión con credencial sigue sin entrar a las rutas propias de la tienda (D-3)', function () {
    $this->getJson('/api/v1/perfil', ($this->con)(($this->iniciar)('vende@sistema.local')))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('bearer() da una credencial real: sirve, y cerrar la sesión la revoca', function () {
    $encabezado = bearer(usuarioCon('venta.crear'));

    $this->getJson('/api/v1/yo', $encabezado)->assertOk()->assertJsonPath('datos.permisos', ['venta.crear']);
    app('auth')->forgetGuards();
    $this->deleteJson('/api/v1/sesion', [], $encabezado)->assertNoContent();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/yo', $encabezado)->assertStatus(401);
});

test('el inicio de sesión de gestión tiene el mismo límite de intentos: al sexto, 429', function () {
    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/sesion', ['email' => 'vende@sistema.local', 'password' => 'Otra12345'])->assertStatus(422);
    }

    $respuesta = $this->postJson('/api/v1/sesion', ['email' => 'vende@sistema.local', 'password' => 'Secreta123'])
        ->assertStatus(429)
        ->assertJsonPath('codigo', 'demasiados_intentos')
        ->assertHeader('Retry-After');

    expect($respuesta->json('reintentar_en'))->toBeInt()->toBeGreaterThan(0)
        ->and(PersonalAccessToken::count())->toBe(0);
});

test('ni la credencial ni la contraseña quedan en el log, aunque el pedido falle con un 500', function () {
    $registrado = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($evento) use (&$registrado) {
        $registrado[] = $evento->message.' '.json_encode(array_map(
            fn ($valor) => $valor instanceof Throwable ? (string) $valor : $valor,
            $evento->context,
        ));
    });
    \Illuminate\Support\Facades\Route::middleware('api.protegida')
        ->post('api/v1/_prueba/falla', fn () => throw new RuntimeException('Algo se rompió.'));

    $encabezado = bearer($this->gestion);
    $credencial = \Illuminate\Support\Str::after($encabezado['Authorization'], 'Bearer ');

    $this->postJson('/api/v1/_prueba/falla', ['password' => 'Secreta123'], $encabezado)
        ->assertStatus(500)->assertJsonPath('codigo', 'error_interno');
    $this->postJson('/api/v1/sesion', ['email' => 'vende@sistema.local', 'password' => 'Equivocada99']);

    $todo = implode("\n", $registrado);
    expect($registrado)->not->toBeEmpty()
        ->and($todo)->not->toContain($credencial)
        ->and($todo)->not->toContain(\Illuminate\Support\Str::after($credencial, '|'))
        ->and($todo)->not->toContain('Secreta123')
        ->and($todo)->not->toContain('Equivocada99');
});
