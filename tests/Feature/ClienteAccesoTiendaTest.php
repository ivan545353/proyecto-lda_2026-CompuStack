<?php

use App\Models\Cliente;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * La cuenta de la tienda para un cliente del mostrador (HL-89, D-26): la da el operador, sobre la misma
 * ficha, y la persona elige la contraseña con el enlace.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaFicha = fn (array $cambios = []) => Cliente::factory()->create(array_merge([
        'razon_social' => 'Panadería Los Tilos',
        'email'        => 'tilos@ejemplo.com',
        'user_id'      => null,
    ], $cambios));

    // Desde el tramo E es `POST /api/v1/clientes/{cliente}/acceso-tienda`, con la credencial de quien lo da.
    $this->tiendaDarAcceso = fn (Cliente $cliente, ?User $quien = null) => $this->postJson(
        "/api/v1/clientes/{$cliente->id}/acceso-tienda",
        ['nombre' => 'Rosa', 'apellido' => 'Tilos'],
        bearer($quien ?? usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda')),
    );
});

test('dar acceso crea la cuenta de la tienda sobre la misma ficha y devuelve el enlace', function () {
    $cliente = ($this->tiendaFicha)();

    $respuesta = ($this->tiendaDarAcceso)($cliente)
        ->assertCreated()
        ->assertJsonPath('datos.cliente_id', $cliente->id)
        // El mismo «Apellido, Nombre» con que la pantalla titulaba el enlace (`nombre_completo`).
        ->assertJsonPath('datos.nombre', 'Tilos, Rosa')
        ->assertJsonPath('mensaje', 'Se creó la cuenta de la tienda de «Rosa Tilos».');

    // El enlace es un secreto de un solo uso: viaja en esta respuesta, que no se guarda en caché.
    $enlace = $respuesta->json('datos.enlace_restablecimiento');

    // Es el enlace de la pantalla de restablecimiento, con el correo de la ficha.
    expect($enlace)->toBe(route('password.reset', ['token' => tokenDelEnlace($enlace), 'email' => 'tilos@ejemplo.com']))
        ->and(tokenDelEnlace($enlace))->not->toBe('')
        ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store');

    $cuenta = User::where('email', 'tilos@ejemplo.com')->sole();

    expect($cliente->fresh()->user_id)->toBe($cuenta->id)
        ->and(Cliente::count())->toBe(1)
        ->and($cuenta->rol->ambito)->toBe('tienda')
        ->and($cuenta->esDeGestion())->toBeFalse()
        ->and("{$cuenta->nombre} {$cuenta->apellido}")->toBe('Rosa Tilos');
});

test('con el enlace elige la contraseña y entra a la app; sus compras del mostrador no son pedidos', function () {
    $cliente = ($this->tiendaFicha)();
    DB::table('ventas')->where('id', ventaCobrada(productoConPrecios(), 1)->id)->update(['cliente_id' => $cliente->id]);

    $enlace = ($this->tiendaDarAcceso)($cliente)->json('datos.enlace_restablecimiento');
    app('auth')->forgetGuards();

    $this->post(route('password.store'), [
        'token'                 => tokenDelEnlace($enlace),
        'email'                 => 'tilos@ejemplo.com',
        'password'              => 'Panaderia123',
        'password_confirmation' => 'Panaderia123',
    ])->assertSessionHasNoErrors();
    app('auth')->forgetGuards();

    $credencial = $this->postJson('/api/v1/sesion', ['email' => 'tilos@ejemplo.com', 'password' => 'Panaderia123'])
        ->assertOk()
        ->json('datos.credencial');
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/pedidos', ['Authorization' => "Bearer {$credencial}"])
        ->assertOk()
        ->assertJsonCount(0, 'datos');
});

test('antes de usar el enlace la cuenta no entra con ninguna contraseña', function () {
    ($this->tiendaDarAcceso)(($this->tiendaFicha)());
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/sesion', ['email' => 'tilos@ejemplo.com', 'password' => ''])->assertStatus(422);
    $this->postJson('/api/v1/sesion', ['email' => 'tilos@ejemplo.com', 'password' => 'cualquiera'])->assertStatus(422);
});

test('dar acceso pide cliente.dar_acceso_tienda y ningún otro permiso del módulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('cliente'), ['cliente.dar_acceso_tienda']));

    ($this->tiendaDarAcceso)(($this->tiendaFicha)(), usuarioCon(...$otros))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');

    expect(User::where('email', 'tilos@ejemplo.com')->exists())->toBeFalse();
});

test('el enlace no se vuelve a mostrar: ni la ficha ni el listado lo traen', function () {
    $cliente = ($this->tiendaFicha)();
    $lector  = bearer(usuarioCon('cliente.ver'));

    ($this->tiendaDarAcceso)($cliente)->assertCreated();

    $this->getJson("/api/v1/clientes/{$cliente->id}", $lector)
        ->assertOk()
        ->assertJsonPath('datos.con_cuenta', true)
        ->assertJsonPath('datos.cuenta_email', 'tilos@ejemplo.com')
        ->assertJsonMissingPath('datos.enlace_restablecimiento');

    expect($this->getJson('/api/v1/clientes', $lector)->getContent())->not->toContain('reset-password');
});

test('la ficha ofrece el acceso sólo con el permiso, y sin correo pide cargarlo', function () {
    $conCorreo = ($this->tiendaFicha)();
    $sinCorreo = ($this->tiendaFicha)(['email' => null, 'razon_social' => 'Kiosco Sur']);
    $operador  = usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda');

    // El shell trae el bloque sólo para quien tiene el permiso; si el cliente tiene correo o no, y si ya
    // tiene cuenta, lo decide la pantalla con la ficha de la API (que es lo que se prueba acá).
    $this->actingAs($operador)->get(route('clientes.edit', $conCorreo))
        ->assertOk()->assertSee('data-acceso-tienda', false)->assertSee('data-formulario-acceso', false)
        ->assertSee('cargale un correo');
    app('auth')->forgetGuards();
    $this->actingAs(usuarioCon('cliente.ver', 'cliente.editar'))->get(route('clientes.edit', $conCorreo))
        ->assertOk()->assertDontSee('data-acceso-tienda', false)->assertDontSee('data-formulario-acceso', false);
    app('auth')->forgetGuards();

    $lector = bearer(usuarioCon('cliente.ver'));
    $this->getJson("/api/v1/clientes/{$conCorreo->id}", $lector)
        ->assertJsonPath('datos.con_cuenta', false)->assertJsonPath('datos.email', $conCorreo->email);
    $this->getJson("/api/v1/clientes/{$sinCorreo->id}", $lector)
        ->assertJsonPath('datos.con_cuenta', false)->assertJsonPath('datos.email', null);
});

test('no se da acceso dos veces, ni sin correo, ni con un correo que ya es de otra cuenta', function () {
    $operador = usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda');

    $conCuenta = ($this->tiendaFicha)();
    // La ReglaDeNegocioException es un 409 `regla_de_negocio` en la API, como un mensaje en la web.
    ($this->tiendaDarAcceso)($conCuenta, $operador)->assertCreated();
    ($this->tiendaDarAcceso)($conCuenta, $operador)->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');

    $sinCorreo = ($this->tiendaFicha)(['email' => null]);
    ($this->tiendaDarAcceso)($sinCorreo, $operador)->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');

    $repetido = ($this->tiendaFicha)(['email' => $operador->email]);
    ($this->tiendaDarAcceso)($repetido, $operador)->assertStatus(409)->assertJsonPath('codigo', 'regla_de_negocio');

    expect(User::count())->toBe(2)   // el operador y la única cuenta que se dio
        ->and($sinCorreo->fresh()->user_id)->toBeNull()
        ->and($repetido->fresh()->user_id)->toBeNull();
});

test('el nombre y el apellido son obligatorios', function () {
    $cliente = ($this->tiendaFicha)();

    $this->postJson("/api/v1/clientes/{$cliente->id}/acceso-tienda", ['nombre' => '', 'apellido' => ''], bearer(usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'apellido'], 'errores');

    expect($cliente->fresh()->user_id)->toBeNull();
});
