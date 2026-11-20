<?php

use App\Models\Categoria;
use App\Models\User;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| La frontera del catálogo compartido (tramo D de la Etapa 3, HL-108). `GET /productos` lo piden
| los dos clientes con su credencial; `GET /categorias` respondía sin leerla, y una credencial que
| no sirve no puede pasar a ser 401 ahí: la app la manda igual (regla 40).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tienda  = app(UsuarioService::class)->crear(datosDeClienteDeTienda());
    $this->gestion = User::factory()->conRol('Vendedor')->create();

    Categoria::factory()->create(['nombre' => 'Almacenamiento', 'activo' => true]);

    $this->rutaDe = function (string $grupo) {
        Route::middleware($grupo)->get('api/v1/_prueba/yo', fn (Request $request) => response()->json([
            'datos' => ['usuario_id' => $request->user()?->id],
        ]));
    };
});

test('GET /productos: la cuenta de gestión entra con su credencial, ya no es 401 (HL-108)', function () {
    $this->getJson('/api/v1/productos', bearer($this->gestion))->assertOk();
});

test('GET /productos: la cuenta de tienda y el visitante siguen entrando', function () {
    $this->getJson('/api/v1/productos', bearer($this->tienda))->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/productos')->assertOk();
});

test('sesión opcional compartida: identifica a las dos cuentas', function (string $cual) {
    ($this->rutaDe)('api.sesion_opcional');

    $this->getJson('/api/v1/_prueba/yo', bearer($this->{$cual}))
        ->assertOk()->assertJsonPath('datos.usuario_id', $this->{$cual}->id);
})->with(['tienda', 'gestion']);

test('sesión opcional compartida: la credencial que no sirve sigue siendo 401', function () {
    ($this->rutaDe)('api.sesion_opcional');

    $this->getJson('/api/v1/_prueba/yo', ['Authorization' => 'Bearer basura'])
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('sesión opcional compartida: una cuenta de gestión desactivada es 401', function () {
    ($this->rutaDe)('api.sesion_opcional');
    $encabezado = bearer($this->gestion);
    $this->gestion->forceFill(['activo' => false])->save();

    $this->getJson('/api/v1/_prueba/yo', $encabezado)
        ->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');
});

test('credencial si sirve: identifica a las dos cuentas', function (string $cual) {
    ($this->rutaDe)('api.credencial_si_sirve');

    $this->getJson('/api/v1/_prueba/yo', bearer($this->{$cual}))
        ->assertOk()->assertJsonPath('datos.usuario_id', $this->{$cual}->id);
})->with(['tienda', 'gestion']);

test('credencial si sirve: sin credencial, o con una que no sirve, es un visitante y no 401', function (string $caso) {
    ($this->rutaDe)('api.credencial_si_sirve');

    $encabezado = match ($caso) {
        'ninguna'    => [],
        'basura'     => ['Authorization' => 'Bearer basura'],
        'no Bearer'  => ['Authorization' => 'Basic dXN1YXJpbzpjbGF2ZQ=='],
        'vencida'    => bearer($this->tienda),
        'desactivada' => bearer($this->gestion),
    };
    if ($caso === 'vencida') {
        $this->travel(8)->days();
    }
    if ($caso === 'desactivada') {
        $this->gestion->forceFill(['activo' => false])->save();
    }

    $this->getJson('/api/v1/_prueba/yo', $encabezado)
        ->assertOk()->assertJsonPath('datos.usuario_id', null);
})->with(['ninguna', 'basura', 'no Bearer', 'vencida', 'desactivada']);

test('GET /categorias: el árbol es el mismo con credencial, sin ella o con una que no sirve', function () {
    $visitante = $this->getJson('/api/v1/categorias')->assertOk()->json();

    foreach ([bearer($this->tienda), bearer($this->gestion), ['Authorization' => 'Bearer basura']] as $encabezado) {
        app('auth')->forgetGuards();
        expect($this->getJson('/api/v1/categorias', $encabezado)->assertOk()->json())->toBe($visitante);
    }

    expect($visitante['datos'][0]['nombre'])->toBe('Almacenamiento');
});
