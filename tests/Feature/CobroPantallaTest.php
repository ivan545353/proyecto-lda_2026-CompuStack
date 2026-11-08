<?php

use App\Models\Venta;
use App\Services\VentaService;
use App\Support\Importe;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| La pantalla de cobro del tramo I
|--------------------------------------------------------------------------
|
| Lo que se fija acá es lo que hace el servidor: la propuesta del efectivo va en el
| value que rinde Blade, se retira cuando la pantalla vuelve de un rechazo, y nada
| de lo que agrega el JavaScript —«Total», billetes, vuelto— viaja con el cobro.
|
*/

/** Un presupuesto de dos unidades, cobrable: el producto tiene stock de sobra. */
function presupuestoParaCobrar(int $cantidad = 2): Venta
{
    return app(VentaService::class)->crear(
        datosDeVenta([lineaDeVenta(productoConPrecios(contado: 1210), $cantidad)]),
        admin(),
    );
}

/** Los tres medios en el orden que los rinde la pantalla. */
function pagosDelCobro(string $efectivo = '', string $transferencia = '', string $qr = ''): array
{
    return ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $efectivo],
        ['metodo' => 'transferencia', 'monto' => $transferencia],
        ['metodo' => 'qr', 'monto' => $qr],
    ]];
}

test('el efectivo llega con el saldo en el value que rinde el servidor', function () {
    $venta     = presupuestoParaCobrar();
    $propuesta = Importe::paraFormulario($venta->saldo());

    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('name="pagos[0][monto]" value="'.$propuesta.'"', false)
        ->assertSee('name="pagos[1][monto]" value=""', false)
        ->assertSee('name="pagos[2][monto]" value=""', false)
        ->assertSee('data-cobro-propuesto', false)
        ->assertSee('no se registra');
});

test('cobrar al total es enviar el formulario tal como llega', function () {
    $venta = presupuestoParaCobrar();

    // Lo que manda el navegador si el cajero aprieta Enter sin tocar nada.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), pagosDelCobro(efectivo: Importe::paraFormulario($venta->saldo())))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('exito');

    expect($venta->fresh()->estado)->toBe('pagada');
});

test('al volver de un rechazo muestra lo que se mando y no propone el saldo otra vez', function () {
    $venta = presupuestoParaCobrar();

    // El cajero vació el efectivo y cargó una transferencia que no alcanza: si la
    // pantalla re-propusiera el saldo, el próximo Enter cobraría de más.
    $html = $this->actingAs(usuarioCon('venta.cobrar'))
        ->from(route('pagos.create', $venta))
        ->followingRedirects()
        ->post(route('pagos.store', $venta), pagosDelCobro(transferencia: '1,00'))
        ->assertOk()
        ->assertSee('name="pagos[0][monto]" value=""', false)
        ->assertSee('name="pagos[1][monto]" value="1', false)
        ->assertDontSee('data-cobro-propuesto', false)
        ->getContent();

    // Un importe ya cargado no se pliega: «dividir el pago» nace abierto.
    expect($html)->toMatch('/<details[^>]*\bopen\b/');
    expect($venta->fresh()->estado)->not->toBe('pagada');
});

test('dividir el pago nace plegado cuando no hay nada cargado en los otros medios', function () {
    $html = $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', presupuestoParaCobrar()))
        ->assertOk()
        ->assertSee('Dividir el pago')
        ->getContent();

    expect($html)->not->toMatch('/<details[^>]*\bopen\b/');
});

test('con un aviso arriba el efectivo se propone igual pero no roba el foco', function () {
    $venta = presupuestoParaCobrar(cantidad: 60);   // el producto tiene 50

    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('va a ser rechazado')
        ->assertSee('value="'.Importe::paraFormulario($venta->saldo()).'"', false)
        ->assertDontSee('autofocus', false);
});

test('los billetes salen de la configuracion y no viajan con el cobro', function () {
    config(['venta.cobro.billetes' => [500, 1000]]);

    $html = $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', presupuestoParaCobrar()))
        ->assertOk()
        ->assertSee('data-billete="500"', false)
        ->assertSee('data-billete="1000"', false)
        ->assertDontSee('data-billete="20000"', false)
        ->getContent();

    // Un botón sin name no viaja: el vuelto se muestra y no se guarda.
    expect($html)->not->toMatch('/<button(?=[^>]*data-billete)(?=[^>]*\bname=)[^>]*>/');
});

test('sin javascript los controles que no funcionarian nacen ocultos', function () {
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', presupuestoParaCobrar()))
        ->assertOk()
        ->assertSee('data-cobro-total hidden', false)
        ->assertSee('data-cobro-billetes hidden', false)
        ->assertSee('data-cobro-suma aria-live="polite" hidden', false);
});