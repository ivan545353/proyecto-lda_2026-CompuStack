<?php

use App\Models\HistorialPrecio;
use App\Models\Impuesto;
use App\Models\Venta;
use App\Models\VentaLineaImpuesto;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(VentaService::class);
});

/** Un impuesto que alcanza a todo el catálogo. */
function impuestoGeneral(string $alicuota = '10.00'): Impuesto
{
    return Impuesto::create([
        'nombre'      => 'Impuestos internos',
        'alicuota'    => $alicuota,
        'alcance'     => 'general',
        'comprobante' => 'otros_nacionales',
        'orden'       => 50,
        'activo'      => true,
    ]);
}

test('cada renglon congela su desglose y la suma da el total', function () {
    $producto = productoConPrecios(contado: 1210, lista: 1210, costo: 500);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());
    $linea = $venta->lineas()->sole();

    $impuesto = $linea->impuestos()->sole();

    expect($linea->total)->toBe('2420.00')
        ->and($linea->neto)->toBe('2000.00')
        ->and($impuesto->nombre)->toBe('IVA 21 %')
        ->and($impuesto->importe)->toBe('420.00')
        ->and((float) $linea->neto + (float) $impuesto->importe)->toBe((float) $linea->total);
});

test('con un impuesto general el neto no se lo come', function () {
    // Es el defecto que este paso cierra. Dividir 1.331 sólo por 1,21 daría un
    // neto de 1.100: cien pesos de impuesto interno contados como ganancia.
    impuestoGeneral();

    $producto = productoConPrecios(contado: 1331, lista: 1331, costo: 500);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());
    $linea = $venta->lineas()->sole();

    expect($linea->neto)->toBe('1000.00')
        ->and($linea->impuestos()->count())->toBe(2);
});

test('el iva de la linea sigue siendo solo el iva', function () {
    // Es el importe que se declara: sumarle los internos lo falsearía.
    impuestoGeneral();

    $producto = productoConPrecios(contado: 1331, lista: 1331, costo: 500);

    $linea = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin())->lineas()->sole();

    $internos = $linea->impuestos()->where('comprobante', 'otros_nacionales')->sole();

    expect($linea->iva)->toBe('210.00')
        ->and($internos->importe)->toBe('121.00')
        ->and((float) $linea->neto + 210.0 + 121.0)->toBe((float) $linea->total);
});

test('el desglose congelado no cambia si despues se borra el impuesto', function () {
    // El corazón del tramo: el comprobante reimpreso dentro de un año dice lo
    // que se cobró entonces.
    $impuesto = impuestoGeneral();
    $producto = productoConPrecios(contado: 1331, lista: 1331, costo: 500);

    $linea = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin())->lineas()->sole();

    $impuesto->delete();

    $congelado = $linea->impuestos()->where('nombre', 'Impuestos internos')->sole();

    expect($congelado->importe)->toBe('121.00')
        ->and($congelado->alicuota)->toBe('10.00');
});

test('cambiar la cantidad reescribe el desglose entero', function () {
    // Mezclar el desglose de dos cotizaciones no sumaría el total.
    $producto = productoConPrecios(contado: 1210, lista: 1210, costo: 500);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($producto, 3)]), admin());

    $linea = $venta->fresh()->lineas()->sole();

    expect($linea->impuestos()->count())->toBe(1)
        ->and($linea->impuestos()->sole()->importe)->toBe('630.00')
        ->and((float) $linea->neto + 630.0)->toBe((float) $linea->total);
});

test('quitar un renglon se lleva su desglose', function () {
    $uno = productoConPrecios(contado: 1210, lista: 1210, costo: 500);
    $dos = productoConPrecios(contado: 2420, lista: 2420, costo: 900);

    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($uno, 1), lineaDeVenta($dos, 1)]),
        admin(),
    );

    expect(VentaLineaImpuesto::count())->toBe(2);

    $this->service->actualizar($venta, datosDeVenta([lineaDeVenta($uno, 1)]), admin());

    expect(VentaLineaImpuesto::count())->toBe(1);
});