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

    $this->tiendaDarAcceso = fn (Cliente $cliente, ?User $quien = null) => $this
        ->actingAs($quien ?? usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda'))
        ->post(route('clientes.acceso_tienda', $cliente), ['nombre' => 'Rosa', 'apellido' => 'Tilos']);
});

test('dar acceso crea la cuenta de la tienda sobre la misma ficha y muestra el enlace', function () {
    $cliente = ($this->tiendaFicha)();

    ($this->tiendaDarAcceso)($cliente)
        ->assertRedirect(route('clientes.edit', $cliente))
        ->assertSessionHas('enlace_restablecimiento');

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

    $enlace = ($this->tiendaDarAcceso)($cliente)->getSession()->get('enlace_restablecimiento');
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

    ($this->tiendaDarAcceso)(($this->tiendaFicha)(), usuarioCon(...$otros))->assertForbidden();

    expect(User::where('email', 'tilos@ejemplo.com')->exists())->toBeFalse();
});

test('la ficha ofrece el acceso sólo con el permiso, y sin correo pide cargarlo', function () {
    $conCorreo = ($this->tiendaFicha)();
    $sinCorreo = ($this->tiendaFicha)(['email' => null, 'razon_social' => 'Kiosco Sur']);
    $operador  = usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda');

    $this->actingAs($operador)->get(route('clientes.edit', $conCorreo))
        ->assertOk()->assertSee('data-acceso-tienda', false)->assertSee(route('clientes.acceso_tienda', $conCorreo));
    $this->actingAs($operador)->get(route('clientes.edit', $sinCorreo))
        ->assertOk()->assertSee('cargale un correo')->assertDontSee(route('clientes.acceso_tienda', $sinCorreo));
    $this->actingAs(usuarioCon('cliente.ver', 'cliente.editar'))->get(route('clientes.edit', $conCorreo))
        ->assertOk()->assertDontSee('data-acceso-tienda', false);
});

test('no se da acceso dos veces, ni sin correo, ni con un correo que ya es de otra cuenta', function () {
    $operador = usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda');

    $conCuenta = ($this->tiendaFicha)();
    ($this->tiendaDarAcceso)($conCuenta, $operador);
    ($this->tiendaDarAcceso)($conCuenta, $operador)->assertSessionHas('error');

    $sinCorreo = ($this->tiendaFicha)(['email' => null]);
    ($this->tiendaDarAcceso)($sinCorreo, $operador)->assertSessionHas('error');

    $repetido = ($this->tiendaFicha)(['email' => $operador->email]);
    ($this->tiendaDarAcceso)($repetido, $operador)->assertSessionHas('error');

    expect(User::count())->toBe(2)   // el operador y la única cuenta que se dio
        ->and($sinCorreo->fresh()->user_id)->toBeNull()
        ->and($repetido->fresh()->user_id)->toBeNull();
});

test('el nombre y el apellido son obligatorios', function () {
    $cliente = ($this->tiendaFicha)();

    $this->actingAs(usuarioCon('cliente.ver', 'cliente.editar', 'cliente.dar_acceso_tienda'))
        ->post(route('clientes.acceso_tienda', $cliente), ['nombre' => '', 'apellido' => ''])
        ->assertSessionHasErrors(['nombre', 'apellido']);

    expect($cliente->fresh()->user_id)->toBeNull();
});
