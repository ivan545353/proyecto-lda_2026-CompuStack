<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\MovimientoStock;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Services\CompraService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| El remito del proveedor
|--------------------------------------------------------------------------
|
| Vive en el asiento de cada recepción y no en la orden: una orden recibida en dos
| veces llega con dos remitos, y ninguno puede pisar al otro.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(CompraService::class);
});

test('el kardex tiene las dos columnas del remito', function () {
    expect(tipoDeColumna('movimientos_stock', 'remito_numero'))->toBe('varchar(30)')
        ->and(tipoDeColumna('movimientos_stock', 'remito_fecha'))->toBe('date');
});

test('recibir con remito lo deja en cada asiento de esa recepcion', function () {
    $uno   = Producto::factory()->conStockYCosto(0, 0)->create();
    $otro  = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden = OrdenCompra::factory()->enviada()->conLinea($uno, 5, 100)->conLinea($otro, 3, 200)->create();

    $this->service->recibir(
        $orden,
        $orden->lineas->mapWithKeys(fn ($linea) => [$linea->id => $linea->cantidad_pedida])->all(),
        admin(),
        ['numero' => '0001-00001234', 'fecha' => '2026-11-05'],
    );

    $asientos = MovimientoStock::orderBy('id')->get();

    expect($asientos)->toHaveCount(2);

    foreach ($asientos as $asiento) {
        expect($asiento->remito_numero)->toBe('0001-00001234')
            ->and($asiento->remito_fecha->toDateString())->toBe('2026-11-05')
            ->and($asiento->remitoTexto())->toBe('Remito 0001-00001234 del 05/11/2026');
    }
});

test('dos recepciones parciales conservan cada una su remito', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 100)->create();
    $linea    = $orden->lineas->first();

    $this->service->recibir($orden, [$linea->id => 4], admin(), ['numero' => 'A-1', 'fecha' => '2026-11-02']);
    $this->service->recibir($orden->fresh(), [$linea->id => 6], admin(), ['numero' => 'A-2', 'fecha' => '2026-11-05']);

    // Es el motivo de que el remito viva en el asiento: el segundo no pisa al primero.
    expect(MovimientoStock::orderBy('id')->pluck('remito_numero')->all())->toBe(['A-1', 'A-2'])
        ->and($orden->fresh()->estado)->toBe('recibida');
});

test('sin remito el asiento queda sin remito y no se inventa uno', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 5, 100)->create();

    $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin());

    $asiento = MovimientoStock::sole();

    expect($asiento->remito_numero)->toBeNull()
        ->and($asiento->remito_fecha)->toBeNull()
        ->and($asiento->remitoTexto())->toBeNull();
});

test('el remito se registra con numero y fecha o no se registra', function (array $remito) {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 5, 100)->create();

    expect(fn () => $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin(), $remito))
        ->toThrow(ReglaDeNegocioException::class);

    expect(MovimientoStock::count())->toBe(0)
        ->and($producto->fresh()->stock)->toBe(0)
        ->and($orden->fresh()->estado)->toBe('enviada');
})->with([
    'sin fecha'  => [['numero' => '0001-00001234']],
    'sin numero' => [['fecha' => '2026-11-05']],
]);