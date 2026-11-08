<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Entrega;
use App\Models\MovimientoStock;
use App\Models\Pago;
use App\Models\Venta;
use App\Services\PagoService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La entrega en dos veces
|--------------------------------------------------------------------------
|
| El criterio de terminado del tramo H: una venta se entrega en dos veces, queda en
| `entregada_parcial` y después en `entregada`, y cada vez deja su entrega. Lo que se
| afirma tanto como el estado es lo que NO pasa: el stock salió al cobrar.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(VentaService::class);
    $this->quien   = admin();

    $this->mouse  = productoConPrecios(contado: 1000);
    $this->fuente = productoConPrecios(contado: 5000);

    $venta = $this->service->crear(datosDeVenta([
        lineaDeVenta($this->mouse, 2),
        lineaDeVenta($this->fuente, 3),
    ]), admin());

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $this->venta = $venta->fresh('lineas');
    [$this->lineaMouse, $this->lineaFuente] = [
        $this->venta->lineas->firstWhere('producto_id', $this->mouse->id),
        $this->venta->lineas->firstWhere('producto_id', $this->fuente->id),
    ];
});

test('una venta se entrega en dos veces y cada vez deja su entrega', function () {
    $stockMouse = $this->mouse->fresh()->stock;
    $asientos   = MovimientoStock::count();

    $venta = $this->service->entregar($this->venta, $this->quien, [
        $this->lineaMouse->id  => 2,
        $this->lineaFuente->id => 1,
    ]);

    expect($venta->estado)->toBe('entregada_parcial')
        ->and($this->lineaMouse->fresh()->cantidad_entregada)->toBe(2)
        ->and($this->lineaFuente->fresh()->cantidad_entregada)->toBe(1);

    $venta = $this->service->entregar($venta, $this->quien, [$this->lineaFuente->id => 2]);

    expect($venta->estado)->toBe('entregada')
        ->and($this->lineaFuente->fresh()->cantidad_entregada)->toBe(3);

    // Dos entregas, y la segunda no reescribe la primera: cada comprobante se regenera igual.
    [$primera, $segunda] = Entrega::with('lineas')->orderBy('id')->get()->all();

    expect($primera->lineas->pluck('cantidad', 'venta_linea_id')->all())
        ->toBe([$this->lineaMouse->id => 2, $this->lineaFuente->id => 1])
        ->and($segunda->lineas->pluck('cantidad', 'venta_linea_id')->all())
        ->toBe([$this->lineaFuente->id => 2])
        ->and($primera->usuario_id)->toBe($this->quien->id);

    expect($this->mouse->fresh()->stock)->toBe($stockMouse)
        ->and(MovimientoStock::count())->toBe($asientos)
        ->and(Pago::count())->toBe(1);
});

test('sin cantidades entrega todo lo pendiente, tambien despues de una parcial', function () {
    $this->service->entregar($this->venta, $this->quien, [$this->lineaMouse->id => 1]);

    $venta = $this->service->entregar($this->venta, $this->quien);

    expect($venta->estado)->toBe('entregada')
        ->and(Entrega::latest('id')->first()->lineas->pluck('cantidad', 'venta_linea_id')->all())
        ->toBe([$this->lineaMouse->id => 1, $this->lineaFuente->id => 3]);
});

test('entregar mas de lo pendiente se rechaza y no deja nada', function () {
    expect(fn () => $this->service->entregar($this->venta, $this->quien, [$this->lineaMouse->id => 3]))
        ->toThrow(ReglaDeNegocioException::class);

    expect($this->venta->fresh()->estado)->toBe('pagada')
        ->and($this->lineaMouse->fresh()->cantidad_entregada)->toBe(0)
        ->and(Entrega::count())->toBe(0);
});

test('una entrega con todo en cero se rechaza', function () {
    expect(fn () => $this->service->entregar($this->venta, $this->quien, [$this->lineaMouse->id => 0]))
        ->toThrow(ReglaDeNegocioException::class);

    expect(Entrega::count())->toBe(0);
});

test('un renglon de otra venta no se entrega en esta', function () {
    $ajena = ventaCobrada(productoConPrecios(), 1);

    expect(fn () => $this->service->entregar($this->venta, $this->quien, [$ajena->lineas->first()->id => 1]))
        ->toThrow(ReglaDeNegocioException::class);

    expect(Entrega::count())->toBe(0)
        ->and($ajena->fresh()->estado)->toBe('pagada');
});

test('un renglon anterior a las entregas, sin dato, se entrega como pendiente', function () {
    // Así quedaron las 25.025 líneas que existían antes de la columna.
    DB::table('venta_lineas')->where('venta_id', $this->venta->id)->update(['cantidad_entregada' => null]);

    $venta = $this->service->entregar($this->venta, $this->quien);

    expect($venta->estado)->toBe('entregada')
        ->and($this->lineaMouse->fresh()->cantidad_entregada)->toBe(2)
        ->and($this->lineaFuente->fresh()->cantidad_entregada)->toBe(3);
});

test('una venta entregada en parte se puede devolver', function () {
    $this->service->entregar($this->venta, $this->quien, [$this->lineaMouse->id => 2]);

    $venta = $this->service->devolver($this->venta, [
        'cantidades' => [$this->lineaMouse->id => 1],
        'metodo'     => 'efectivo',
    ], $this->quien);

    expect($venta->estado)->toBe('devuelta_parcial');
});

test('quien entrega lo decide el servidor y sin usuario queda registrada por el sistema', function () {
    $this->service->entregar($this->venta);

    expect(Entrega::sole()->usuario_id)->toBeNull()
        ->and(Entrega::sole()->quienTexto())->toBe('Registrada por el sistema');
});