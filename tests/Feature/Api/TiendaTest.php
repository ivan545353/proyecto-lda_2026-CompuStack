<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// GET /api/v1/tienda: lo que la tienda ofrece, para el bloque de confianza de Inicio (tramo J, HL-81).

test('sin envío habilitado, la tienda ofrece sólo el retiro', function () {
    config(['tienda.envio.habilitado' => false]);

    $this->getJson('/api/v1/tienda')
        ->assertOk()
        ->assertExactJson(['datos' => ['entrega' => [
            ['modo' => 'retiro', 'texto' => 'Retiro en el local'],
        ]]]);
});

test('con envío habilitado, ofrece retiro y envío, en ese orden', function () {
    config(['tienda.envio.habilitado' => true, 'tienda.envio.costo' => '8000.00']);

    $this->getJson('/api/v1/tienda')
        ->assertOk()
        ->assertJsonPath('datos.entrega.0.modo', 'retiro')
        ->assertJsonPath('datos.entrega.1.modo', 'envio')
        ->assertJsonPath('datos.entrega.1.texto', 'Envío a domicilio');
});

test('el costo del envío no viaja, ni para el visitante ni con sesión', function () {
    config(['tienda.envio.habilitado' => true, 'tienda.envio.costo' => '8000.00']);

    $visitante = $this->getJson('/api/v1/tienda')->assertOk();
    expect($visitante->getContent())->not->toContain('costo')->not->toContain('8000');

    app('auth')->forgetGuards();
    Sanctum::actingAs(User::factory()->create());

    $conSesion = $this->getJson('/api/v1/tienda')->assertOk();
    expect($conSesion->getContent())->toBe($visitante->getContent());
});

test('es pública: una credencial inválida no la frena', function () {
    $this->withToken('no-es-una-credencial')
        ->getJson('/api/v1/tienda')
        ->assertOk();
});
