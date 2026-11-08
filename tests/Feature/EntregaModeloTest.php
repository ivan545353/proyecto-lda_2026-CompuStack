<?php

use App\Models\Entrega;
use App\Models\EntregaLinea;
use App\Models\Venta;
use App\Models\VentaLinea;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La entrega, en el modelo
|--------------------------------------------------------------------------
|
| La entrega no tiene listado propio, así que no hay scopes nuevos que filtrar: el
| listado de ventas ya filtra por `entregada_parcial` con `conEstado()`. Lo que se
| fija acá es el saldo del renglón, el estado derivado y que la entrega es un hecho.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

test('cantidad_entregada admite null para las lineas viejas y nace en cero', function () {
    $columna = DB::selectOne(
        'SELECT IS_NULLABLE AS nula, COLUMN_DEFAULT AS omision
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB::getDatabaseName(), 'venta_lineas', 'cantidad_entregada'],
    );

    expect($columna->nula)->toBe('YES')
        ->and($columna->omision)->toBe('0')
        ->and(tipoDeColumna('venta_lineas', 'cantidad_entregada'))->toBe('int(10) unsigned');
});

test('un renglon nuevo nace con cero entregadas, no sin dato', function () {
    $venta = ventaCobrada(productoConPrecios(), 3);

    expect($venta->lineas->first()->cantidad_entregada)->toBe(0)
        ->and($venta->lineas->first()->cantidadPendienteDeEntregar())->toBe(3);
});

test('lo pendiente de entregar cuenta el null de una linea vieja como nada entregado', function () {
    $linea = new VentaLinea(['cantidad' => 5]);

    $linea->cantidad_entregada = null;
    expect($linea->cantidadPendienteDeEntregar())->toBe(5);

    $linea->cantidad_entregada = 2;
    expect($linea->cantidadPendienteDeEntregar())->toBe(3);

    $linea->cantidad_entregada = 5;
    expect($linea->cantidadPendienteDeEntregar())->toBe(0);
});

test('el estado de entrega se deriva de los renglones', function () {
    $venta = Venta::factory()->pagada()
        ->conLinea(productoConPrecios(), 2)
        ->conLinea(productoConPrecios(), 3)
        ->create();

    [$uno, $otro] = $venta->lineas->all();

    $uno->cantidad_entregada = 2;
    $otro->cantidad_entregada = 1;
    expect($venta->estadoDeEntrega())->toBe('entregada_parcial');

    $otro->cantidad_entregada = 3;
    expect($venta->estadoDeEntrega())->toBe('entregada');
});

test('el numero de la entrega sale del id', function () {
    expect(Entrega::numeroDe(42))->toBe('E-00042');
});

test('una entrega y sus renglones no se modifican ni se borran', function () {
    $venta   = ventaCobrada(productoConPrecios(), 2);
    $entrega = Entrega::create(['venta_id' => $venta->id, 'usuario_id' => null]);
    $linea   = $entrega->lineas()->create(['venta_linea_id' => $venta->lineas->first()->id, 'cantidad' => 2]);

    expect($entrega->quienTexto())->toBe('Registrada por el sistema')
        ->and(fn () => $entrega->update(['usuario_id' => admin()->id]))->toThrow(LogicException::class)
        ->and(fn () => $entrega->delete())->toThrow(LogicException::class)
        ->and(fn () => $linea->update(['cantidad' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $linea->delete())->toThrow(LogicException::class);

    expect(EntregaLinea::sole()->cantidad)->toBe(2);
});

test('el listado ya filtra por entregada_parcial', function () {
    Venta::factory()->count(2)->pagada()->create();
    $parcial = Venta::factory()->pagada()->create();

    // Escrita a mano: es un test del filtro, no de cómo se llega a ese estado.
    $parcial->estado = 'entregada_parcial';
    $parcial->save();

    expect(Venta::conEstado('entregada_parcial')->pluck('id')->all())->toBe([$parcial->id]);
});