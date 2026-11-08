<?php

use App\Models\OrdenCompra;
use App\Services\PanelService;
use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(PanelService::class);

    $this->desde = Carbon::parse('2026-03-01')->startOfDay();
    $this->hasta = Carbon::parse('2026-03-31')->endOfDay();
});

/*
|--------------------------------------------------------------------------
| El período anterior
|--------------------------------------------------------------------------
|
| Del mismo largo que el elegido y pegado a él. Sus números los cuenta la base con
| las fechas corridas: no salen de restar nada que ya esté cargado (A-26).
|
*/

test('el periodo anterior tiene el mismo largo y termina el dia antes', function () {
    [$desde, $hasta] = $this->service->periodoAnterior($this->desde, $this->hasta);

    // Marzo tiene 31 días: los 31 anteriores empiezan el 29 de enero.
    expect($desde->toDateTimeString())->toBe('2026-01-29 00:00:00')
        ->and($hasta->toDateTimeString())->toBe('2026-02-28 23:59:59');

    [$desde, $hasta] = $this->service->periodoAnterior(
        Carbon::parse('2026-03-10')->startOfDay(),
        Carbon::parse('2026-03-10')->endOfDay(),
    );

    expect($desde->toDateString())->toBe('2026-03-09')
        ->and($hasta->toDateString())->toBe('2026-03-09');
});

test('correr el periodo no modifica las fechas que recibe', function () {
    // Las fechas del Form Request son Carbon mutables: un subDays() sin copia movería
    // también el período que la pantalla muestra arriba.
    $this->service->periodoAnterior($this->desde, $this->hasta);

    expect($this->desde->toDateTimeString())->toBe('2026-03-01 00:00:00')
        ->and($this->hasta->toDateTimeString())->toBe('2026-03-31 23:59:59');
});

test('el panel general cuenta el periodo anterior con las fechas corridas', function () {
    $producto = productoConPrecios(contado: 1000, costo: 600);

    $this->travelTo(Carbon::parse('2026-01-28 10:00'));
    ventaCobrada($producto, 9);                       // antes del período anterior
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-02-20 10:00'));
    ventaCobrada($producto, 2);                       // período anterior
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($producto, 3);                       // período elegido
    $this->travelBack();

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    expect($panel['facturado'])->toBe(3000.0)
        ->and($panel['anterior']['facturado'])->toBe(2000.0)
        ->and($panel['anterior']['cobrado'])->toBe(2000.0)
        ->and($panel['anterior']['margenBruto'])->toBe(800.0)
        ->and($panel['anterior']['desde']->toDateString())->toBe('2026-01-29');
});

test('el panel propio compara solo lo de quien pregunta', function () {
    $producto = productoConPrecios(contado: 1000);
    $mio      = usuarioCon('panel.ver_propio');
    $otro     = usuarioCon('panel.ver_propio');

    $this->travelTo(Carbon::parse('2026-02-20 10:00'));
    ventaCobrada($producto, 2, vendedor: $mio, cajero: $mio);
    ventaCobrada($producto, 5, vendedor: $otro, cajero: $otro);
    $this->travelBack();

    $panel = $this->service->paraVendedor($mio, $this->desde, $this->hasta);

    expect($panel['vendi']['cantidad'])->toBe(0)
        ->and($panel['anterior']['vendi']['cantidad'])->toBe(1)
        ->and($panel['anterior']['vendi']['facturado'])->toBe(2000.0)
        ->and($panel['anterior']['cobre']['neto'])->toBe(2000.0);
});

test('las instantaneas no tienen periodo anterior', function () {
    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // El sistema no guarda cuántos productos había para reponer el mes pasado: una
    // comparación ahí afirmaría algo que no puede verificar.
    expect($panel['anterior'])->not->toHaveKey('stockCritico')
        ->and($panel['anterior'])->not->toHaveKey('comprasAbiertas');
});

/*
|--------------------------------------------------------------------------
| Los conteos que llevan enlace
|--------------------------------------------------------------------------
*/

test('de las ordenes abiertas se cuentan aparte los borradores de la reposicion', function () {
    OrdenCompra::factory()->create(['usuario_creo_id' => null]);              // espera aprobación
    OrdenCompra::factory()->create();                                         // la cargó una persona
    OrdenCompra::factory()->aprobada()->create(['usuario_creo_id' => null]);  // ya la aprobó alguien
    OrdenCompra::factory()->recibida()->create(['usuario_creo_id' => null]);  // cerrada

    $panel = $this->service->paraAdministrativo($this->desde, $this->hasta);

    // El pendiente #36: lo que la reposición dejó en borrador y nadie miró todavía.
    expect($panel['comprasAbiertas'])->toBe(3)
        ->and($panel['borradoresDeReposicion'])->toBe(1);
});