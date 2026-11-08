<?php

use App\Models\Cliente;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('imprimir los clientes pide cliente.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('cliente'), ['cliente.ver']));

    $this->actingAs(usuarioCon(...$otros))->get(route('clientes.imprimir'))->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('cliente.ver'))->get(route('clientes.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('clientes-');
});

test('la planilla de clientes trae lo que muestra la pantalla, con lo que filtra', function () {
    Cliente::factory()->create(['razon_social' => 'Ana Sosa', 'nro_doc' => null]);
    Cliente::factory()->conCuenta()->create(['razon_social' => 'Bruno Paz', 'nro_doc' => '30111222']);
    Cliente::factory()->responsableInscripto()->create();

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.imprimir', ['formato' => 'csv', 'condicion_iva' => 'consumidor_final']))
        ->assertOk()
        ->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Condición frente al IVA: Consumidor final')
        ->and($filas[4])->toBe(['Cliente', 'Documento', 'Condición frente al IVA', 'Cuenta'])
        ->and($filas[5])->toBe(['Ana Sosa', '', 'Consumidor final', 'De mostrador'])
        ->and($filas[6])->toBe(['Bruno Paz', 'DNI 30111222', 'Consumidor final', 'Con cuenta'])
        ->and(end($filas)[0])->toBe('Total: 2 clientes');
});

test('el correo y el telefono no se imprimen, ni pidiendolos', function () {
    $cliente = Cliente::factory()->create();

    $csv = $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.imprimir', ['formato' => 'csv']))
        ->streamedContent();

    expect($csv)->not->toContain($cliente->email)
        ->not->toContain($cliente->telefono);

    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.imprimir', ['columnas' => ['cliente', 'email']]))
        ->assertRedirect(route('clientes.index'))
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'columna que este listado no tiene'));
});

test('el boton de imprimir de clientes lleva los filtros puestos y no la pagina', function () {
    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['cuenta' => 'con_cuenta', 'page' => 2]))
        ->assertOk()
        ->assertSee(route('clientes.imprimir', ['cuenta' => 'con_cuenta']))
        ->assertDontSee(route('clientes.imprimir', ['cuenta' => 'con_cuenta', 'page' => 2]));
});