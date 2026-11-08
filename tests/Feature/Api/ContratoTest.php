<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\TransicionInvalidaException;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El contrato de errores de api/* (docs/api.md §6.3)
|--------------------------------------------------------------------------
|
| Las rutas de prueba se definen dentro de cada test y llevan el grupo `api`,
| como las de verdad: lo que se ejerce es la misma pila de middlewares.
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    app()->setLocale('es');

    $this->rutaApi = fn (string $metodo, string $uri, Closure $accion, string|array $grupo = 'api') =>
        Route::middleware($grupo)->{$metodo}('api/v1/_prueba/'.$uri, $accion);
});

test('una ruta inexistente de api devuelve 404 con la forma de error, en JSON y sin enviar Accept', function () {
    // $this->get() no manda Accept: es el caso del cliente que no sabe pedirlo.
    $respuesta = $this->get('/api/v1/no-existe');

    $respuesta->assertStatus(404)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'mensaje' => 'No encontramos lo que buscás.',
            'codigo'  => 'no_encontrado',
            'errores' => [],
        ]);
});

test('errores es un objeto vacío en JSON y no una lista', function () {
    expect($this->get('/api/v1/no-existe')->getContent())->toContain('"errores":{}');
});

test('una ruta de api existente tampoco devuelve HTML aunque pidan HTML', function () {
    ($this->rutaApi)('get', 'html', fn () => throw new ReglaDeNegocioException('No.'));

    $this->get('/api/v1/_prueba/html', ['Accept' => 'text/html'])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/json');
});

test('un 422 trae mensaje, codigo validacion y errores por campo, en español', function () {
    ($this->rutaApi)('post', 'validar', function (Request $request) {
        $request->validate(['email' => 'required|email', 'cantidad' => 'required|integer']);
    });

    $respuesta = $this->post('/api/v1/_prueba/validar', ['cantidad' => 'mucho']);

    $respuesta->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonStructure(['mensaje', 'codigo', 'errores' => ['email', 'cantidad']]);

    expect($respuesta->json('errores.email.0'))->toContain('obligatorio')
        ->and($respuesta->json('mensaje'))->not->toBeEmpty();
});

test('las reglas de negocio dan 409, cada una con su codigo', function (string $clase, string $codigo) {
    ($this->rutaApi)('get', 'regla', fn () => throw new $clase('Algo que el cliente puede leer.'));

    $this->get('/api/v1/_prueba/regla')
        ->assertStatus(409)
        ->assertExactJson([
            'mensaje' => 'Algo que el cliente puede leer.',
            'codigo'  => $codigo,
            'errores' => [],
        ]);
})->with([
    'stock insuficiente'  => [StockInsuficienteException::class,  'stock_insuficiente'],
    'transicion invalida' => [TransicionInvalidaException::class, 'transicion_invalida'],
    'regla de negocio'    => [ReglaDeNegocioException::class,     'regla_de_negocio'],
]);

test('una excepción cualquiera da 500 sin traza, aunque APP_DEBUG sea true', function () {
    config(['app.debug' => true]);
    ($this->rutaApi)('get', 'rota', fn () => throw new RuntimeException('secreto: SELECT * FROM users en C:\\proyecto'));

    $respuesta = $this->get('/api/v1/_prueba/rota');

    $respuesta->assertStatus(500)
        ->assertJsonPath('codigo', 'error_interno')
        ->assertJsonPath('errores', []);

    expect($respuesta->getContent())
        ->not->toContain('secreto')
        ->not->toContain('SELECT')
        ->not->toContain('trace')
        ->not->toContain('RuntimeException')
        ->not->toContain('proyecto');
});

test('el 403, el 404 de un modelo y el 405 salen con su codigo', function () {
    ($this->rutaApi)('get', 'prohibido', fn () => abort(403));
    ($this->rutaApi)('get', 'modelo', fn () => User::findOrFail(0));
    ($this->rutaApi)('get', 'solo-get', fn () => 'ok');

    $this->get('/api/v1/_prueba/prohibido')->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');

    // El mensaje no filtra el nombre del modelo.
    $modelo = $this->get('/api/v1/_prueba/modelo');
    $modelo->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
    expect($modelo->getContent())->not->toContain('User');

    $this->post('/api/v1/_prueba/solo-get')->assertStatus(405)
        ->assertJsonPath('codigo', 'metodo_no_permitido')
        ->assertHeader('Allow');
});

test('una autorización denegada por un Gate es 403 sin_permiso', function () {
    ($this->rutaApi)('get', 'gate', function () {
        Gate::authorize('permiso-que-nadie-tiene');
    });

    $this->get('/api/v1/_prueba/gate')->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
});

/*
|--------------------------------------------------------------------------
| El límite de intentos
|--------------------------------------------------------------------------
*/

test('el sexto intento por correo e IP da 429 con reintentar_en', function () {
    ($this->rutaApi)('post', 'acceso', fn () => response()->json(['datos' => 'ok']), ['api', 'throttle:tienda-acceso']);

    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local'])->assertOk();
    }

    $respuesta = $this->post('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local']);

    $respuesta->assertStatus(429)
        ->assertJsonPath('codigo', 'demasiados_intentos')
        ->assertHeader('Retry-After');

    expect($respuesta->json('reintentar_en'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

test('un correo bloqueado no bloquea a otro correo desde la misma IP', function () {
    ($this->rutaApi)('post', 'acceso', fn () => response()->json(['datos' => 'ok']), ['api', 'throttle:tienda-acceso']);

    foreach (range(1, 6) as $intento) {
        $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local']);
    }

    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local'])->assertStatus(429);
    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'otra@sistema.local'])->assertOk();
});

test('el correo cuenta igual en mayúsculas y con espacios', function () {
    ($this->rutaApi)('post', 'acceso', fn () => response()->json(['datos' => 'ok']), ['api', 'throttle:tienda-acceso']);

    foreach (['camila@sistema.local', 'CAMILA@sistema.local', ' camila@sistema.local ', 'Camila@Sistema.Local', 'camila@sistema.local'] as $correo) {
        $this->postJson('/api/v1/_prueba/acceso', ['email' => $correo])->assertOk();
    }

    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'CAMILA@SISTEMA.LOCAL'])->assertStatus(429);
});

test('recorrer correos distintos desde una misma IP también se frena', function () {
    ($this->rutaApi)('post', 'acceso', fn () => response()->json(['datos' => 'ok']), ['api', 'throttle:tienda-acceso']);

    foreach (range(1, 30) as $n) {
        $this->postJson('/api/v1/_prueba/acceso', ['email' => "persona{$n}@sistema.local"])->assertOk();
    }

    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'persona31@sistema.local'])
        ->assertStatus(429)
        ->assertJsonPath('codigo', 'demasiados_intentos');
});

test('pasado el minuto se puede volver a intentar', function () {
    ($this->rutaApi)('post', 'acceso', fn () => response()->json(['datos' => 'ok']), ['api', 'throttle:tienda-acceso']);

    foreach (range(1, 6) as $intento) {
        $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local']);
    }
    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local'])->assertStatus(429);

    $this->travel(61)->seconds();

    $this->postJson('/api/v1/_prueba/acceso', ['email' => 'camila@sistema.local'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| La web no cambió
|--------------------------------------------------------------------------
*/

test('una ReglaDeNegocioException en una ruta web sigue redirigiendo con error', function () {
    Route::middleware('web')->get('_prueba-web', fn () => throw new ReglaDeNegocioException('No se puede.'));

    $this->from('/atras')->get('/_prueba-web')
        ->assertRedirect('/atras')
        ->assertSessionHas('error', 'No se puede.');
});

test('y una ruta web que no existe sigue siendo una página, no JSON', function () {
    $this->get('/esta-ruta-no-existe')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});
