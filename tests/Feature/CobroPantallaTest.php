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
| Desde el tramo H la pantalla es un shell: el saldo y el medio principal llegan en la
| ficha de la API (`importes.saldo`, `cobro.principal`) y el JS los pone en el value. Lo
| que el servidor fija es eso, y que el shell no traiga ningún importe propio.
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
    $venta = presupuestoParaCobrar();

    // El saldo y dónde se propone, de la API: el JS lo pone en el value del efectivo.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.importes.saldo', Importe::json($venta->saldo()))
        ->assertJsonPath('datos.cobro.principal', 'efectivo');

    app('auth')->forgetGuards();

    // El shell: los tres medios vacíos, con el `name` de la clave de error de la API.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('name="pagos.0.monto" value=""', false)
        ->assertSee('name="pagos.1.monto" value=""', false)
        ->assertSee('name="pagos.2.monto" value=""', false)
        ->assertDontSee(Importe::paraFormulario($venta->saldo()))
        ->assertSee('no se registra');
});

test('cobrar al total es enviar el formulario tal como llega', function () {
    $venta = presupuestoParaCobrar();

    // Lo que manda la pantalla si el cajero aprieta Enter sin tocar nada (tramo H: a la API).
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", pagosDelCobro(efectivo: Importe::paraFormulario($venta->saldo())), bearer(usuarioCon('venta.cobrar')))
        ->assertCreated()
        ->assertJsonPath('datos.estado', 'pagada');

    expect($venta->fresh()->estado)->toBe('pagada');
});

test('al volver de un rechazo muestra lo que se mando y no propone el saldo otra vez', function () {
    $venta = presupuestoParaCobrar();

    // El cajero vació el efectivo y cargó una transferencia que no alcanza: si la
    // pantalla re-propusiera el saldo, el próximo Enter cobraría de más.
    //
    // Tramo H, la mitad del servidor: el rechazo es un 409 sin errores por campo y la venta
    // no se cobra. La otra mitad ya no la rinde Blade: la pantalla no se recarga, así que
    // queda lo que el cajero cargó y no se vuelve a proponer el saldo, ni se pliega
    // «dividir el pago». Eso lo hace el JS y lo cubre el recorrido manual del tramo.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", pagosDelCobro(transferencia: '1,00'), bearer(usuarioCon('venta.cobrar')))
        ->assertStatus(409)
        ->assertJsonPath('errores', []);

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

    // El aviso y el saldo, de la API: el JS propone el saldo igual y, con un aviso, no le da el foco.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.cobro.problemas.0', fn (string $texto) => str_contains($texto, 'y hay 50 disponible(s)'))
        ->assertJsonPath('datos.importes.saldo', Importe::json($venta->saldo()));

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('va a ser rechazado')
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