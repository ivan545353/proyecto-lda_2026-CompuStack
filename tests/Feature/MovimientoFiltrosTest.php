<?php

use App\Models\Marca;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(StockService::class);
});

/*
|--------------------------------------------------------------------------
| Filtros del kardex
|--------------------------------------------------------------------------
|
| Primer nivel de los dos: acá se prueba que los scopes filtran. Que el parámetro
| de la URL llegue al scope se prueba en StockModuloTest.
|
| Los movimientos se crean llamando al StockService, porque no hay factory de
| MovimientoStock: una fila sin su cambio de stock sería un asiento que afirma
| algo que no pasó. Y las fechas se consiguen viajando en el tiempo, no editando
| la fila, porque el kardex no se deja modificar ni por un test.
|
*/

test('deProducto devuelve solo los movimientos de ese producto', function () {
    $uno  = Producto::factory()->conStock(50)->create();
    $otro = Producto::factory()->conStock(50)->create();
    $usuario = admin();

    $this->service->ajustar($uno, 45, 'Inventario', $usuario);
    $this->service->ajustar($uno->fresh(), 40, 'Inventario', $usuario);
    $this->service->ajustar($otro, 48, 'Inventario', $usuario);

    expect(MovimientoStock::deProducto($uno->id)->count())->toBe(2)
        ->and(MovimientoStock::deProducto($otro->id)->count())->toBe(1);
});

test('deProducto sin valor o con un valor no numerico no filtra', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $this->service->ajustar($producto, 45, 'Inventario', admin());

    expect(MovimientoStock::deProducto(null)->count())->toBe(1)
        ->and(MovimientoStock::deProducto('')->count())->toBe(1)
        ->and(MovimientoStock::deProducto('abc')->count())->toBe(1);
});

test('deTipo filtra por tipo de movimiento', function () {
    $producto  = Producto::factory()->conStockYCosto(50, 100)->create();
    $documento = Marca::factory()->create();
    $usuario   = admin();

    $this->service->ajustar($producto->fresh(), 45, 'Inventario', $usuario);
    $this->service->descontar($producto->fresh(), 5, $documento, $usuario);
    $this->service->reponer($producto->fresh(), 2, $documento, $usuario);
    $this->service->recibirCompra($producto->fresh(), 10, 120, $documento, $usuario);

    expect(MovimientoStock::deTipo('ajuste')->count())->toBe(1)
        ->and(MovimientoStock::deTipo('venta')->count())->toBe(1)
        ->and(MovimientoStock::deTipo('devolucion')->count())->toBe(1)
        ->and(MovimientoStock::deTipo('compra')->count())->toBe(1);
});

test('deTipo sin valor o con un tipo inexistente no filtra', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $this->service->ajustar($producto, 45, 'Inventario', admin());

    expect(MovimientoStock::deTipo(null)->count())->toBe(1)
        ->and(MovimientoStock::deTipo('')->count())->toBe(1)
        ->and(MovimientoStock::deTipo('telepatia')->count())->toBe(1);
});

test('deUsuario filtra por quien registro el movimiento', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $una  = admin();
    $otra = admin();

    $this->service->ajustar($producto->fresh(), 40, 'Inventario de la manana', $una);
    $this->service->ajustar($producto->fresh(), 30, 'Inventario de la tarde', $otra);

    // Es la pregunta literal de A-13: ¿quién ajustó?
    expect(MovimientoStock::deUsuario($una->id)->count())->toBe(1)
        ->and(MovimientoStock::deUsuario($otra->id)->pluck('motivo')->all())
        ->toBe(['Inventario de la tarde']);
});

test('desde y hasta recortan por fecha e incluyen el dia completo', function () {
    $producto = Producto::factory()->conStock(100)->create();
    $usuario  = admin();

    $this->travelTo(Carbon::parse('2026-03-01 09:00'));
    $this->service->ajustar($producto->fresh(), 90, 'Primero de marzo', $usuario);

    $this->travelTo(Carbon::parse('2026-03-15 23:30'));
    $this->service->ajustar($producto->fresh(), 80, 'Quince de marzo a la noche', $usuario);

    $this->travelTo(Carbon::parse('2026-04-02 10:00'));
    $this->service->ajustar($producto->fresh(), 70, 'Dos de abril', $usuario);

    $this->travelBack();

    expect(MovimientoStock::desde('2026-03-01')->hasta('2026-03-31')->count())->toBe(2)
        // Comparar `created_at <= '2026-03-15'` dejaría afuera el movimiento de
        // las 23:30, que es casi todo ese día. whereDate() compara sólo la fecha.
        ->and(MovimientoStock::hasta('2026-03-15')->count())->toBe(2)
        ->and(MovimientoStock::desde('2026-04-01')->count())->toBe(1);
});

test('desde y hasta con una fecha ilegible no filtran', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $this->service->ajustar($producto, 45, 'Inventario', admin());

    expect(MovimientoStock::desde(null)->count())->toBe(1)
        ->and(MovimientoStock::desde('')->count())->toBe(1)
        ->and(MovimientoStock::desde('el martes pasado')->count())->toBe(1)
        ->and(MovimientoStock::hasta('2026-13-45')->count())->toBe(1);
});

test('los filtros se combinan entre si', function () {
    $uno  = Producto::factory()->conStock(50)->create();
    $otro = Producto::factory()->conStock(50)->create();
    $documento = Marca::factory()->create();
    $usuario   = admin();

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    $this->service->ajustar($uno->fresh(), 45, 'Ajuste de marzo', $usuario);
    $this->service->descontar($uno->fresh(), 5, $documento, $usuario);
    $this->service->ajustar($otro->fresh(), 40, 'Ajuste del otro producto', $usuario);

    $this->travelTo(Carbon::parse('2026-05-10 10:00'));
    $this->service->ajustar($uno->fresh(), 30, 'Ajuste de mayo', $usuario);

    $this->travelBack();

    $resultado = MovimientoStock::deProducto($uno->id)
        ->deTipo('ajuste')
        ->desde('2026-03-01')
        ->hasta('2026-03-31')
        ->pluck('motivo');

    expect($resultado->all())->toBe(['Ajuste de marzo']);
});