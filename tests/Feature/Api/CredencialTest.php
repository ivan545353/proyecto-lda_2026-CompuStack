<?php

use App\Models\User;
use App\Services\CredencialService;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->credenciales = app(CredencialService::class);
    $this->usuarios     = app(UsuarioService::class);
    $this->tiendaCuenta = fn () => $this->usuarios->crear(datosDeClienteDeTienda());
});

/*
|--------------------------------------------------------------------------
| CredencialService
|--------------------------------------------------------------------------
*/

test('emitir devuelve la forma propia de docs/api.md y no la de Sanctum', function () {
    $cuenta = ($this->tiendaCuenta)();

    $emitida = $this->credenciales->emitir($cuenta);

    expect(array_keys($emitida))->toBe(['credencial', 'tipo', 'vence', 'usuario'])
        ->and($emitida['tipo'])->toBe('Bearer')
        ->and($emitida['credencial'])->toBeString()->not->toBeEmpty()
        ->and($emitida['usuario'])->toBe([
            'nombre'   => 'Camila',
            'apellido' => 'Herrera',
            'email'    => 'camila@sistema.local',
        ]);
});

test('la credencial vence a los siete días, en ISO 8601 UTC con Z', function () {
    $this->freezeTime();
    $cuenta = ($this->tiendaCuenta)();

    $emitida = $this->credenciales->emitir($cuenta);

    expect($emitida['vence'])->toBe(now()->addDays(7)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($emitida['vence'])->toEndWith('Z');
});

test('el vencimiento sale de SANCTUM_EXPIRATION', function () {
    $this->freezeTime();
    config(['sanctum.expiration' => 60]);

    $emitida = $this->credenciales->emitir(($this->tiendaCuenta)());

    expect($emitida['vence'])->toBe(now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'));
});

test('cada emisión es una fila nueva, y la credencial en claro no se guarda', function () {
    $cuenta = ($this->tiendaCuenta)();

    $primera = $this->credenciales->emitir($cuenta);
    $segunda = $this->credenciales->emitir($cuenta);

    expect($primera['credencial'])->not->toBe($segunda['credencial'])
        ->and($cuenta->tokens()->count())->toBe(2)
        ->and(DB::table('personal_access_tokens')->pluck('token')->all())
            ->not->toContain($primera['credencial'])
            ->not->toContain($segunda['credencial']);
});

test('revocarTodas borra las credenciales de esa persona y no las de otra', function () {
    $camila = ($this->tiendaCuenta)();
    $otra   = $this->usuarios->crear(datosDeClienteDeTienda([
        'email'   => 'otra@sistema.local',
        'cliente' => ['razon_social' => 'Otra', 'tipo_doc' => 'dni', 'nro_doc' => '30111222',
                      'condicion_iva' => 'consumidor_final', 'email' => 'otra@sistema.local', 'telefono' => '297-5000000'],
    ]));

    $this->credenciales->emitir($camila);
    $this->credenciales->emitir($camila);
    $this->credenciales->emitir($otra);

    $this->credenciales->revocarTodas($camila);

    expect($camila->tokens()->count())->toBe(0)
        ->and($otra->tokens()->count())->toBe(1);
});

test('revocarActual cierra sólo la sesión de este dispositivo', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);
    $actual = $cuenta->createToken('este-dispositivo');

    $cuenta->withAccessToken($actual->accessToken);
    $this->credenciales->revocarActual($cuenta);

    expect($cuenta->tokens()->count())->toBe(1)
        ->and(PersonalAccessToken::find($actual->accessToken->id))->toBeNull();
});

test('revocarActual sin credencial en el pedido no rompe ni borra nada', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);

    $this->credenciales->revocarActual($cuenta);   // sin withAccessToken()

    expect($cuenta->tokens()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Desactivar o dar de baja una cuenta corta su acceso por la API (A-5)
|--------------------------------------------------------------------------
*/

test('desactivar una cuenta revoca sus credenciales', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);
    $this->credenciales->emitir($cuenta);

    $this->usuarios->actualizar($cuenta, datosDeEdicionDe($cuenta, ['activo' => false]));

    expect($cuenta->fresh()->activo)->toBeFalse()
        ->and($cuenta->tokens()->count())->toBe(0);
});

test('editar una cuenta que sigue activa no toca sus credenciales', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);

    $this->usuarios->actualizar($cuenta, datosDeEdicionDe($cuenta, ['nombre' => 'Camila Inés']));

    expect($cuenta->tokens()->count())->toBe(1);
});

test('borrar una cuenta sin historial revoca sus credenciales', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);

    expect($this->usuarios->eliminar($cuenta))->toBeTrue();

    // personal_access_tokens no tiene FK: sin revocar, la fila quedaría huérfana.
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $cuenta->id)->count())->toBe(0);
});

test('una cuenta con historial se desactiva por eliminar() y también pierde sus credenciales', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->credenciales->emitir($cuenta);

    DB::table('ventas')->insert([
        'usuario_id' => $cuenta->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->usuarios->eliminar($cuenta))->toBeFalse();

    expect($cuenta->fresh()->activo)->toBeFalse()
        ->and($cuenta->tokens()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Los middlewares de credencial, de punta a punta
|--------------------------------------------------------------------------
|
| Las rutas llevan los mismos grupos con nombre que routes/api.php.
|
*/

beforeEach(function () {
    $this->encabezado = fn (array $emitida) => ['Authorization' => 'Bearer '.$emitida['credencial']];

    $this->rutaDe = function (string $grupo) {
        Route::middleware($grupo)->get('api/v1/_prueba/yo', fn (Request $request) => response()->json([
            'datos' => ['usuario_id' => $request->user()?->id],
        ]));
    };
});

test('sesión opcional: sin credencial sigue como visitante', function () {
    ($this->rutaDe)('tienda.sesion_opcional');

    $this->get('/api/v1/_prueba/yo')->assertOk()->assertJsonPath('datos.usuario_id', null);
});

test('sesión opcional: con credencial válida identifica al usuario', function () {
    ($this->rutaDe)('tienda.sesion_opcional');
    $cuenta = ($this->tiendaCuenta)();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($this->credenciales->emitir($cuenta)))
        ->assertOk()
        ->assertJsonPath('datos.usuario_id', $cuenta->id);
});

test('sesión opcional: con una credencial inválida es 401 sesion_invalida y no un visitante', function (string $cabecera) {
    ($this->rutaDe)('tienda.sesion_opcional');

    $this->get('/api/v1/_prueba/yo', ['Authorization' => $cabecera])
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sesion_invalida');
})->with([
    'basura'                 => ['Bearer esto-no-es-una-credencial'],
    'con formato pero falsa' => ['Bearer 1|abcdefghijklmnopqrstuvwxyz0123456789ABCD'],
    'no es Bearer'           => ['Basic dXN1YXJpbzpjbGF2ZQ=='],
]);

test('sesión opcional: una credencial vencida es 401 sesion_invalida', function () {
    ($this->rutaDe)('tienda.sesion_opcional');
    $emitida = $this->credenciales->emitir(($this->tiendaCuenta)());

    $this->travel(8)->days();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('sesión opcional: una credencial revocada es 401 sesion_invalida', function () {
    ($this->rutaDe)('tienda.sesion_opcional');
    $cuenta  = ($this->tiendaCuenta)();
    $emitida = $this->credenciales->emitir($cuenta);

    $this->credenciales->revocarTodas($cuenta);

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('protegida: sin credencial es 401 sin_sesion, en JSON y sin enviar Accept', function () {
    ($this->rutaDe)('tienda.protegida');

    $this->get('/api/v1/_prueba/yo')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('codigo', 'sin_sesion');
});

test('protegida: con una credencial que no sirve es 401 sesion_invalida', function () {
    ($this->rutaDe)('tienda.protegida');

    $this->get('/api/v1/_prueba/yo', ['Authorization' => 'Bearer basura'])
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('protegida: una cuenta activa de tienda entra', function () {
    ($this->rutaDe)('tienda.protegida');
    $cuenta = ($this->tiendaCuenta)();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($this->credenciales->emitir($cuenta)))
        ->assertOk()->assertJsonPath('datos.usuario_id', $cuenta->id);
});

test('protegida: una cuenta desactivada con la credencial todavía vigente es 401', function () {
    ($this->rutaDe)('tienda.protegida');
    $cuenta  = ($this->tiendaCuenta)();
    $emitida = $this->credenciales->emitir($cuenta);

    // Por fuera del servicio, que sí revocaría: es el cinturón de VerificarCuentaTienda.
    $cuenta->forceFill(['activo' => false])->save();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('sesión opcional también revalida la cuenta', function () {
    ($this->rutaDe)('tienda.sesion_opcional');
    $cuenta  = ($this->tiendaCuenta)();
    $emitida = $this->credenciales->emitir($cuenta);

    $cuenta->forceFill(['activo' => false])->save();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('una cuenta de gestión no entra por la API, ni con credencial válida (D-3)', function () {
    ($this->rutaDe)('tienda.protegida');
    $gestion = admin();

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($this->credenciales->emitir($gestion)))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('desactivar una cuenta corta su acceso en el pedido siguiente', function () {
    ($this->rutaDe)('tienda.protegida');
    $cuenta  = ($this->tiendaCuenta)();
    $emitida = $this->credenciales->emitir($cuenta);

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))->assertOk();

    // En producción cada pedido es un proceso nuevo; dentro de un test el guard
    // recordaría al usuario del pedido anterior.
    app('auth')->forgetGuards();

    $this->usuarios->actualizar($cuenta, datosDeEdicionDe($cuenta, ['activo' => false]));

    $this->get('/api/v1/_prueba/yo', ($this->encabezado)($emitida))
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('una sesión web abierta no autentica contra la API (guard de Sanctum vacío)', function () {
    ($this->rutaDe)('tienda.protegida');
    $cuenta = ($this->tiendaCuenta)();

    $this->actingAs($cuenta, 'web')->get('/api/v1/_prueba/yo')
        ->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');
});
