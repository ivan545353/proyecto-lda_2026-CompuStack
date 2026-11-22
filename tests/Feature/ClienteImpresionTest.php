<?php

use App\Models\Cliente;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// La impresión se pide a la API y se abre por enlace firmado (D-22, tramo E de la Etapa 3).
beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('imprimir los clientes pide cliente.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('cliente'), ['cliente.ver']));

    $this->getJson('/api/v1/clientes/impresion', bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    app('auth')->forgetGuards();

    $url = $this->getJson('/api/v1/clientes/impresion', bearer(usuarioCon('cliente.ver')))
        ->assertOk()->json('datos.url');

    $respuesta = $this->get($url)->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('clientes-');
});

test('la planilla de clientes trae lo que muestra la pantalla, con lo que filtra', function () {
    Cliente::factory()->create(['razon_social' => 'Ana Sosa', 'nro_doc' => null]);
    Cliente::factory()->conCuenta()->create(['razon_social' => 'Bruno Paz', 'nro_doc' => '30111222']);
    Cliente::factory()->responsableInscripto()->create();

    $url = $this->getJson('/api/v1/clientes/impresion?formato=csv&condicion_iva=consumidor_final', bearer(usuarioCon('cliente.ver')))
        ->assertOk()->json('datos.url');

    $filas = ($this->filasDe)($this->get($url)->assertOk()->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Condición frente al IVA: Consumidor final')
        ->and($filas[4])->toBe(['Cliente', 'Documento', 'Condición frente al IVA', 'Cuenta'])
        ->and($filas[5])->toBe(['Ana Sosa', '', 'Consumidor final', 'De mostrador'])
        ->and($filas[6])->toBe(['Bruno Paz', 'DNI 30111222', 'Consumidor final', 'Con cuenta'])
        ->and(end($filas)[0])->toBe('Total: 2 clientes');
});

test('el correo y el telefono no se imprimen, ni pidiendolos', function () {
    $cliente = Cliente::factory()->create();

    $encabezado = bearer(usuarioCon('cliente.ver'));

    $url = $this->getJson('/api/v1/clientes/impresion?formato=csv', $encabezado)->assertOk()->json('datos.url');
    $csv = $this->get($url)->streamedContent();

    expect($csv)->not->toContain($cliente->email)
        ->not->toContain($cliente->telefono);

    // La web volvía al listado con el aviso; la API no da enlace y lo dice en el 422.
    $rechazo = $this->getJson('/api/v1/clientes/impresion?columnas[]=cliente&columnas[]=email', $encabezado)
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');

    expect($rechazo->getContent())->toContain('columna que este listado no tiene');
});

test('el enlace de imprimir de clientes lleva los filtros puestos y no la pagina', function () {
    $url = $this->getJson('/api/v1/clientes/impresion?cuenta=con_cuenta&pagina=2', bearer(usuarioCon('cliente.ver')))
        ->assertOk()->json('datos.url');

    expect($url)->toContain('cuenta=con_cuenta')->not->toContain('pagina=');
});

test('un filtro inexistente de clientes es 422 y no da enlace', function () {
    $this->getJson('/api/v1/clientes/impresion?condicion_iva=cualquiera', bearer(usuarioCon('cliente.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');
});
