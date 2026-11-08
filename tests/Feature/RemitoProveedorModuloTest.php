<?php

use App\Models\MovimientoStock;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Services\CompraService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| El remito del proveedor, de la pantalla a la base y de vuelta
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $this->orden    = OrdenCompra::factory()->enviada()->conLinea($this->producto, 10, 100)->create();
    $this->linea    = $this->orden->lineas->first();
});

test('la pantalla de recepcion ofrece el numero y la fecha del remito', function () {
    $this->actingAs(usuarioCon('compra.recibir'))
        ->get(route('compras.recepcion', $this->orden))
        ->assertOk()
        ->assertSee('name="remito_numero"', false)
        ->assertSee('name="remito_fecha"', false);
});

test('recibir por la ruta con remito lo deja en el asiento y el mensaje lo nombra', function () {
    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $this->orden), [
            'cantidades'    => [$this->linea->id => 4],
            'remito_numero' => '0001-00001234',
            'remito_fecha'  => '2026-11-05',
        ])
        ->assertRedirect(route('compras.show', $this->orden))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'con el remito 0001-00001234'));

    $asiento = MovimientoStock::sole();

    expect($asiento->remito_numero)->toBe('0001-00001234')
        ->and($asiento->remito_fecha->toDateString())->toBe('2026-11-05');
});

test('recibir sin remito sigue funcionando y no lo inventa', function () {
    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $this->orden), ['cantidades' => [$this->linea->id => 4]])
        ->assertSessionHas('exito', fn ($mensaje) => ! str_contains($mensaje, 'remito'));

    expect(MovimientoStock::sole()->remito_numero)->toBeNull();
});

test('un remito incompleto o imposible se rechaza y no entra nada', function (array $remito, string $campo) {
    $this->actingAs(usuarioCon('compra.recibir'))
        ->from(route('compras.recepcion', $this->orden))
        ->post(route('compras.recibir', $this->orden), ['cantidades' => [$this->linea->id => 4]] + $remito)
        ->assertRedirect(route('compras.recepcion', $this->orden))
        ->assertSessionHasErrors($campo);

    expect(MovimientoStock::count())->toBe(0)
        ->and($this->producto->fresh()->stock)->toBe(0);
})->with([
    'numero sin fecha'  => [['remito_numero' => '0001-00001234'], 'remito_fecha'],
    'fecha sin numero'  => [['remito_fecha' => '2026-11-05'], 'remito_numero'],
    'fecha futura'      => [['remito_numero' => 'A-1', 'remito_fecha' => '2099-01-01'], 'remito_fecha'],
    'fecha ilegible'    => [['remito_numero' => 'A-1', 'remito_fecha' => '05/11/2026'], 'remito_fecha'],
    'numero muy largo'  => [['remito_numero' => str_repeat('9', 31), 'remito_fecha' => '2026-11-05'], 'remito_numero'],
]);

test('la ficha de la orden muestra el remito de cada recepcion', function () {
    $servicio = app(CompraService::class);

    $servicio->recibir($this->orden, [$this->linea->id => 4], admin(), ['numero' => 'A-0001', 'fecha' => '2026-11-02']);
    $servicio->recibir($this->orden->fresh(), [$this->linea->id => 6], admin());

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.show', $this->orden))
        ->assertOk()
        ->assertSee('A-0001')
        ->assertSee('del 02/11/2026')
        ->assertSee('No consta');
});

test('el kardex muestra el remito en el asiento de la recepcion', function () {
    app(CompraService::class)->recibir(
        $this->orden, [$this->linea->id => 4], admin(), ['numero' => 'A-0001', 'fecha' => '2026-11-02'],
    );

    $this->actingAs(usuarioCon('stock.ver', 'producto.ver'))
        ->get(route('stock.index'))
        ->assertOk()
        ->assertSee('Remito A-0001 del 02/11/2026');
});