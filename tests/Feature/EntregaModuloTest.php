<?php

use App\Models\Entrega;
use App\Models\Venta;
use App\Services\PagoService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| La entrega, de la ruta a la pantalla y al comprobante
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(VentaService::class);

    $mouse  = productoConPrecios(contado: 1000);
    $fuente = productoConPrecios(contado: 5000);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($mouse, 2), lineaDeVenta($fuente, 3)]), admin());

    app(PagoService::class)->cobrar($venta, ['pagos' => [
        ['metodo' => 'efectivo', 'monto' => $venta->total],
    ]], admin());

    $this->venta  = $venta->fresh('lineas');
    $this->mouse  = $this->venta->lineas->firstWhere('producto_id', $mouse->id);
    $this->fuente = $this->venta->lineas->firstWhere('producto_id', $fuente->id);
});

/*
|--------------------------------------------------------------------------
| Permisos, en las dos direcciones
|--------------------------------------------------------------------------
*/

dataset('rutas de la entrega', [
    'pantalla de entrega parcial' => ['ventas.entrega', 'venta.entregar'],
]);

// Tramo H: el comprobante de entrega se pide a la API, que da el enlace firmado (D-22, HL-130).
test('ningun otro permiso de ventas habilita el enlace del comprobante de entrega', function () {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();
    $otros   = array_values(array_diff(permisosDelModulo('venta'), ['venta.ver']));

    $this->getJson("/api/v1/ventas/{$this->venta->id}/entregas/{$entrega->id}/pdf", bearer(usuarioCon(...$otros)))
        ->assertForbidden();
});

test('venta.ver alcanza para el enlace del comprobante de entrega', function () {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();

    $this->getJson("/api/v1/ventas/{$this->venta->id}/entregas/{$entrega->id}/pdf", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonStructure(['datos' => ['url', 'vence']]);
});

test('ningun otro permiso de ventas habilita las rutas de la entrega', function (string $ruta, string $permiso) {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();
    $otros   = array_values(array_diff(permisosDelModulo('venta'), [$permiso]));

    $this->actingAs(usuarioCon(...$otros))
        ->get(route($ruta, ['venta' => $this->venta, 'entrega' => $entrega]))
        ->assertForbidden();
})->with('rutas de la entrega');

test('el permiso de la ruta alcanza para las rutas de la entrega', function (string $ruta, string $permiso) {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();

    $this->actingAs(usuarioCon($permiso))
        ->get(route($ruta, ['venta' => $this->venta, 'entrega' => $entrega]))
        ->assertOk();
})->with('rutas de la entrega');

/*
|--------------------------------------------------------------------------
| La entrega en dos veces, por las rutas
|--------------------------------------------------------------------------
*/

test('la pantalla ofrece un campo por renglon pendiente', function () {
    // Tramo H: lo pendiente de cada renglón, de la API; el JS arma una fila por renglón con su campo
    // `cantidades.{renglón}`, y un renglón completo lleva la insignia en lugar del campo.
    $lineas = collect($this->getJson("/api/v1/ventas/{$this->venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->json('datos.lineas'))->keyBy('id');

    expect($lineas[$this->mouse->id]['pendiente_de_entregar'])->toBeGreaterThan(0)
        ->and($lineas[$this->fuente->id]['pendiente_de_entregar'])->toBeGreaterThan(0);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.entregar'))
        ->get(route('ventas.entrega', $this->venta))
        ->assertOk()
        ->assertSee('data-plantilla-linea', false)
        ->assertSee('data-cantidad', false);
});

test('un presupuesto no tiene pantalla de entrega', function () {
    $presupuesto = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    $this->actingAs(usuarioCon('venta.entregar'))
        ->get(route('ventas.entrega', $presupuesto))
        ->assertRedirect(route('ventas.show', $presupuesto))
        ->assertSessionHas('error');
});

test('una venta se entrega en dos veces por la API', function () {
    $usuario    = usuarioCon('venta.entregar');
    $credencial = bearer($usuario);

    $this->postJson("/api/v1/ventas/{$this->venta->id}/entregas", ['cantidades' => [
            $this->mouse->id  => 2,
            $this->fuente->id => 1,
        ]], $credencial)
        ->assertCreated()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'Entrega parcial'));

    expect($this->venta->fresh()->estado)->toBe('entregada_parcial')
        ->and(Entrega::sole()->usuario_id)->toBe($usuario->id);

    // El botón de un clic de la ficha cierra lo que falta.
    $this->postJson("/api/v1/ventas/{$this->venta->id}/entregas", [], $credencial)
        ->assertCreated()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'entregada por completo'));

    expect($this->venta->fresh()->estado)->toBe('entregada')
        ->and(Entrega::count())->toBe(2);
});

test('la entrega rechaza lo que el servidor decide y las cantidades imposibles', function (array $datos, string $campo) {
    $datos = array_merge(['cantidades' => [$this->mouse->id => 1]], $datos);

    $this->postJson("/api/v1/ventas/{$this->venta->id}/entregas", $datos, bearer(usuarioCon('venta.entregar')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor($campo, 'errores');

    expect(Entrega::count())->toBe(0);
})->with([
    'el estado'          => [['estado' => 'entregada'], 'estado'],
    'quien entrego'      => [['usuario_id' => 1], 'usuario_id'],
]);

test('una cantidad negativa se rechaza en su campo', function () {
    $this->postJson("/api/v1/ventas/{$this->venta->id}/entregas", ['cantidades' => [$this->mouse->id => -1]], bearer(usuarioCon('venta.entregar')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor("cantidades.{$this->mouse->id}", 'errores');

    expect(Entrega::count())->toBe(0);
});

test('entregar mas de lo pendiente vuelve con el error y no entrega nada', function () {
    $this->postJson("/api/v1/ventas/{$this->venta->id}/entregas", ['cantidades' => [$this->mouse->id => 5]], bearer(usuarioCon('venta.entregar')))
        ->assertStatus(409)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'quedan 2'));

    expect(Entrega::count())->toBe(0)
        ->and($this->venta->fresh()->estado)->toBe('pagada');
});

/*
|--------------------------------------------------------------------------
| La ficha y el comprobante
|--------------------------------------------------------------------------
*/

test('la ficha lista las entregas con su comprobante', function () {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 2])->entregas->last();

    // Tramo H: las entregas y lo que el estado admite, de la API; los botones, del shell con su @can.
    $this->getJson("/api/v1/ventas/{$this->venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.entregas.0.numero', $entrega->numeroFormateado())
        ->assertJsonPath('datos.acciones.entregar', true)
        ->assertJsonPath('datos.acciones.entregar_parte', true)
        ->assertJsonPath('datos.entregada_sin_comprobante', false);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.ver', 'venta.entregar'))
        ->get(route('ventas.show', $this->venta))
        ->assertOk()
        ->assertSee('data-descargar-entrega', false)
        ->assertSee('data-accion="entregar"', false)
        ->assertSee('data-accion="entregar_parte"', false);
});

test('una venta entregada antes de las entregas dice que no tiene comprobante', function () {
    $vieja = Venta::factory()->entregada()->conLinea(productoConPrecios(), 2)->create();

    $this->getJson("/api/v1/ventas/{$vieja->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.entregada_sin_comprobante', true)
        ->assertJsonPath('datos.entregas', []);
});

test('con una sola unidad pendiente no se ofrece entregar una parte', function () {
    $this->service->entregar($this->venta, admin(), [$this->mouse->id => 2, $this->fuente->id => 2]);

    $this->getJson("/api/v1/ventas/{$this->venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.unidades_pendientes_de_entregar', 1)
        ->assertJsonPath('datos.acciones.entregar', true)
        ->assertJsonPath('datos.acciones.entregar_parte', false);
});

test('el comprobante de otra venta no se descarga por esta', function () {
    $ajena   = ventaCobrada(productoConPrecios(), 1);
    $entrega = $this->service->entregar($ajena, admin())->entregas->last();

    $this->getJson("/api/v1/ventas/{$this->venta->id}/entregas/{$entrega->id}/pdf", bearer(usuarioCon('venta.ver')))
        ->assertNotFound();
});

test('el comprobante se descarga, es una lectura y declara lo que no es', function () {
    $entrega = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();

    $enlace = $this->getJson("/api/v1/ventas/{$this->venta->id}/entregas/{$entrega->id}/pdf", bearer(usuarioCon('venta.ver')))
        ->json('datos.url');

    app('auth')->forgetGuards();

    $respuesta = $this->get($enlace);

    $respuesta->assertOk();

    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain($entrega->numeroFormateado())
        ->and(Entrega::count())->toBe(1);

    // El PDF viaja comprimido: el pie se verifica sobre la vista que dompdf recibe.
    $html = view('ventas.entrega-pdf', [
        'venta'     => $this->venta,
        'entrega'   => $entrega->load('lineas.ventaLinea.producto'),
        'acumulado' => $entrega->acumuladoPorRenglon(),
    ])->render();

    expect($html)->toContain('RG 1415')
        ->and($html)->toContain('no lleva CAI')
        ->and($html)->toContain('no es un comprobante fiscal');
});

test('el comprobante de la primera entrega no cambia cuando llega la segunda', function () {
    $primera = $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1])->entregas->last();

    expect($primera->acumuladoPorRenglon())->toBe([$this->mouse->id => 1]);

    $this->service->entregar($this->venta, admin(), [$this->mouse->id => 1]);

    expect($primera->fresh()->acumuladoPorRenglon())->toBe([$this->mouse->id => 1])
        ->and(Entrega::latest('id')->first()->acumuladoPorRenglon())->toBe([$this->mouse->id => 2]);
});