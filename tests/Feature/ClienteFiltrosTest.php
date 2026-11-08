<?php

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de clientes
|--------------------------------------------------------------------------
|
| Módulo nuevo, así que no hay un bug heredado que cerrar. El test existe por
| la lección de A-24: tres módulos del sistema original tenían filtros que no
| filtraban, y el bug sobrevivió a la versión final porque no existía este
| archivo.
|
*/

test('buscar encuentra por razon social', function () {
    Cliente::factory()->create(['razon_social' => 'Estudio Austral S.R.L.']);
    Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    expect(Cliente::buscar('austral')->count())->toBe(1);
});

test('buscar encuentra por correo y por telefono', function () {
    Cliente::factory()->create(['email' => 'compras@austral.com.ar', 'telefono' => '297-4551122']);
    Cliente::factory()->create(['email' => 'otro@ejemplo.com', 'telefono' => '11-40001111']);

    expect(Cliente::buscar('compras@')->count())->toBe(1)
        ->and(Cliente::buscar('4551122')->count())->toBe(1);
});

test('buscar encuentra un cuit escrito con guiones', function () {
    // Se lee de una factura como 30-71555888-1 y se guarda sin separadores.
    // Pegarlo tal cual no puede devolver cero resultados.
    Cliente::factory()->responsableInscripto()->create(['nro_doc' => '30715558881']);
    Cliente::factory()->create(['nro_doc' => '41556778']);

    expect(Cliente::buscar('30-71555888-1')->count())->toBe(1)
        ->and(Cliente::buscar('30.715.558.881')->count())->toBe(1)
        ->and(Cliente::buscar('30715558881')->count())->toBe(1);
});

test('un texto con pocos digitos no se interpreta como documento', function () {
    // Sin el piso de 6 dígitos, buscar "Austral 2" traería a todos los clientes
    // que tengan un 2 en el documento.
    Cliente::factory()->create(['razon_social' => 'Austral 2', 'nro_doc' => '30715558881']);
    Cliente::factory()->create(['razon_social' => 'Otra cosa', 'nro_doc' => '12345672']);

    $resultado = Cliente::buscar('Austral 2')->pluck('razon_social')->all();

    expect($resultado)->toBe(['Austral 2']);
});

test('buscar sin texto no filtra nada', function () {
    Cliente::factory()->count(3)->create();

    expect(Cliente::buscar(null)->count())->toBe(3)
        ->and(Cliente::buscar('')->count())->toBe(3)
        ->and(Cliente::buscar('   ')->count())->toBe(3);
});

test('buscar trata el comodin de LIKE como texto literal', function () {
    Cliente::factory()->create(['razon_social' => 'Insumos 100%']);
    Cliente::factory()->create(['razon_social' => 'Otra empresa']);

    expect(Cliente::buscar('100%')->count())->toBe(1);
});

test('conCondicionIva filtra por la condicion exacta', function () {
    Cliente::factory()->count(2)->create(['condicion_iva' => 'consumidor_final']);
    Cliente::factory()->responsableInscripto()->create();

    expect(Cliente::conCondicionIva('consumidor_final')->count())->toBe(2)
        ->and(Cliente::conCondicionIva('responsable_inscripto')->count())->toBe(1)
        ->and(Cliente::conCondicionIva('monotributo')->count())->toBe(0);
});

test('conCondicionIva sin valor o con un valor desconocido no filtra', function () {
    Cliente::factory()->count(2)->create();
    Cliente::factory()->responsableInscripto()->create();

    expect(Cliente::conCondicionIva(null)->count())->toBe(3)
        ->and(Cliente::conCondicionIva('')->count())->toBe(3)
        ->and(Cliente::conCondicionIva('inscripto')->count())->toBe(3);
});

test('conCuenta separa al cliente de mostrador del que tiene cuenta', function () {
    Cliente::factory()->count(2)->create();            // mostrador: user_id null
    Cliente::factory()->conCuenta()->create();

    expect(Cliente::conCuenta('sin_cuenta')->count())->toBe(2)
        ->and(Cliente::conCuenta('con_cuenta')->count())->toBe(1);
});

test('conCuenta sin valor o con un valor desconocido no filtra', function () {
    Cliente::factory()->count(2)->create();
    Cliente::factory()->conCuenta()->create();

    expect(Cliente::conCuenta(null)->count())->toBe(3)
        ->and(Cliente::conCuenta('')->count())->toBe(3)
        ->and(Cliente::conCuenta('registrados')->count())->toBe(3);
});

test('los filtros se combinan entre si', function () {
    Cliente::factory()->responsableInscripto()->create(['razon_social' => 'Austral S.R.L.']);
    Cliente::factory()->create(['razon_social' => 'Austral Hogar', 'condicion_iva' => 'consumidor_final']);
    Cliente::factory()->responsableInscripto()->create(['razon_social' => 'Patagonia S.A.']);

    $resultado = Cliente::buscar('austral')
        ->conCondicionIva('responsable_inscripto')
        ->pluck('razon_social')
        ->all();

    expect($resultado)->toBe(['Austral S.R.L.']);
});

/*
|--------------------------------------------------------------------------
| Reglas del modelo
|--------------------------------------------------------------------------
*/

test('solo el responsable inscripto lleva factura A', function () {
    // Es la regla que va a gobernar la emisión en la Etapa 2, y es lo único que
    // condicion_iva decide hoy.
    expect(Cliente::factory()->responsableInscripto()->create()->tipoComprobante())->toBe('factura_a');

    foreach (['consumidor_final', 'monotributo', 'exento'] as $condicion) {
        expect(Cliente::factory()->create(['condicion_iva' => $condicion])->tipoComprobante())
            ->toBe('factura_b');
    }
});

test('el cliente sin cuenta es de mostrador', function () {
    expect(Cliente::factory()->create()->esDeMostrador())->toBeTrue()
        ->and(Cliente::factory()->conCuenta()->create()->esDeMostrador())->toBeFalse();
});