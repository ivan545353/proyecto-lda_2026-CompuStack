<?php

use App\Impresion\ListadoMovimientos;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\ImpresionService;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );

    // Un ajuste por StockService: un movimiento no se escribe por otra puerta.
    $this->ajuste = fn (Producto $producto, int $contado, $quien) => app(StockService::class)
        ->ajustar($producto, $contado, 'Conteo de inventario', $quien, $producto->fresh()->stock);
});

test('imprimir el kardex pide stock.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('stock'), ['stock.ver']));

    $this->actingAs(usuarioCon(...$otros))->get(route('stock.imprimir'))->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('stock.ver'))->get(route('stock.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('movimientos-');
});

test('el kardex impreso dice lo que habia antes, lo que quedo y quien lo registro', function () {
    $quien    = admin();
    $producto = Producto::factory()->conStock(12)->create(['codigo' => 'SSD-1TB', 'nombre' => 'Disco SSD']);
    ($this->ajuste)($producto, 15, $quien);
    ($this->ajuste)(Producto::factory()->conStock(4)->create(), 1, $quien);

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.imprimir', ['formato' => 'csv', 'producto_id' => $producto->id]))
        ->assertOk()
        ->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Producto: SSD-1TB — Disco SSD')
        ->and($filas[4])->toBe([
            'Fecha', 'Código', 'Producto', 'Tipo', 'Cantidad', 'Stock', 'Motivo u origen', 'Remito del proveedor', 'Lo registró',
        ])
        ->and(array_slice($filas[5], 1))->toBe([
            'SSD-1TB', 'Disco SSD', 'Ajuste de inventario', '3', '12 → 15', 'Conteo de inventario', '',
            "{$quien->nombre} {$quien->apellido}",
        ])
        ->and(end($filas)[0])->toBe('Total: 1 movimiento');
});

test('un movimiento que baja el stock sale con su signo y la planilla lo lee como numero', function () {
    $producto = Producto::factory()->conStock(10)->create();
    ($this->ajuste)($producto, 7, admin());

    $filas = ($this->filasDe)($this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.imprimir', ['formato' => 'csv', 'columnas' => ['cantidad', 'anterior', 'resultante']]))
        ->streamedContent());

    expect($filas[5])->toBe(['-3', '10', '7']);
});

test('las consultas del kardex impreso no crecen con los movimientos', function () {
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoMovimientos();
    $usuario  = usuarioCon('stock.ver');
    $opciones = $servicio->opciones($listado, [], $usuario);
    $quien    = admin();

    $pdf = fn () => view('impresion.listado-pdf',
        $servicio->datosDelPdf($listado, MovimientoStock::query()->orderByDesc('id'), $opciones, $usuario, []))->render();

    ($this->ajuste)(Producto::factory()->conStock(5)->create(), 6, $quien);
    $pdf();
    $pocas = contarConsultas($pdf);

    foreach (range(1, 6) as $vez) {
        ($this->ajuste)(Producto::factory()->conStock(5)->create(), 6 + $vez, $quien);
    }
    $muchas = contarConsultas($pdf);

    expect($muchas)->toBe($pocas);
});

test('el boton de imprimir del kardex lleva los filtros puestos y no la pagina', function () {
    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['tipo' => 'ajuste', 'page' => 2]))
        ->assertOk()
        ->assertSee(route('stock.imprimir', ['tipo' => 'ajuste']))
        ->assertDontSee(route('stock.imprimir', ['tipo' => 'ajuste', 'page' => 2]));
});