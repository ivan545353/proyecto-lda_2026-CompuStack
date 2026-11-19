<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\CredencialService;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// La frontera entre la tienda y la gestión (HL-15): una cuenta de la tienda no entra al panel,
// ni con sesión web ni con su credencial. Las rutas se leen del router: una ruta nueva de
// gestión queda cubierta sin tocar este archivo.

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaCliente = fn (): User => app(UsuarioService::class)->crear(datosDeClienteDeTienda());

    // Cada ruta con el middleware `gestion`, con su verbo y la URL con parámetros de relleno.
    $this->tiendaRutasDeGestion = fn (): array => collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($ruta) => in_array('gestion', $ruta->gatherMiddleware(), true))
        ->map(fn ($ruta) => [
            'metodo' => strtolower(collect($ruta->methods())->reject(fn ($m) => $m === 'HEAD')->first()),
            'url'    => '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $ruta->uri()), '/'),
        ])
        ->values()
        ->all();
});

test('hay rutas de gestión para recorrer', function () {
    expect(count(($this->tiendaRutasDeGestion)()))->toBeGreaterThan(50);
});

test('con sesión web de un cliente, ninguna ruta de gestión da 200 y todas terminan en el login', function () {
    $cliente  = ($this->tiendaCliente)();
    $fallidas = [];

    foreach (($this->tiendaRutasDeGestion)() as ['metodo' => $metodo, 'url' => $url]) {
        app('auth')->forgetGuards();
        $this->actingAs($cliente, 'web');

        $respuesta = $this->{$metodo}($url);

        if (! $respuesta->isRedirect(route('login'))) {
            $fallidas[] = strtoupper($metodo)." {$url} → {$respuesta->getStatusCode()}";
        }
    }

    expect($fallidas)->toBe([]);
});

test('con la credencial Bearer de un cliente, ninguna ruta de gestión da 200 y todas terminan en el login', function () {
    $encabezado = ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir(($this->tiendaCliente)())['credencial']];
    $fallidas   = [];

    foreach (($this->tiendaRutasDeGestion)() as ['metodo' => $metodo, 'url' => $url]) {
        app('auth')->forgetGuards();

        $respuesta = $metodo === 'get'
            ? $this->get($url, $encabezado)
            : $this->{$metodo}($url, [], $encabezado);

        if (! $respuesta->isRedirect(route('login'))) {
            $fallidas[] = strtoupper($metodo)." {$url} → {$respuesta->getStatusCode()}";
        }
    }

    expect($fallidas)->toBe([]);
});

// --- Los permisos de la tienda, en las dos direcciones ------------------------

test('un rol de tienda con una clave la tiene, y no las otras dos', function (string $clave) {
    $rol = Rol::create(['nombre' => 'Sólo '.$clave, 'ambito' => 'tienda', 'es_sistema' => false]);
    $rol->permisos()->sync(\App\Models\Permiso::where('clave', $clave)->pluck('id'));
    $cuenta = app(UsuarioService::class)->crear(datosDeClienteDeTienda(['rol_id' => $rol->id]));

    $claves = ['tienda.ver_precios', 'tienda.comprar', 'tienda.ver_pedidos'];
    foreach ($claves as $otra) {
        expect($cuenta->fresh()->can($otra))->toBe($otra === $clave);
    }
})->with(['tienda.ver_precios', 'tienda.comprar', 'tienda.ver_pedidos']);

test('el Cliente tiene las tres claves y un rol de tienda sin permisos ninguna', function (string $clave) {
    $cliente = ($this->tiendaCliente)();
    $rol     = Rol::create(['nombre' => 'Tienda vacía', 'ambito' => 'tienda', 'es_sistema' => false]);
    $sin     = User::factory()->create(['rol_id' => $rol->id]);

    expect($cliente->can($clave))->toBeTrue()
        ->and($sin->can($clave))->toBeFalse();
})->with(['tienda.ver_precios', 'tienda.comprar', 'tienda.ver_pedidos']);
