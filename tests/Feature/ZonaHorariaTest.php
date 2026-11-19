<?php

use App\Models\Marca;
use App\Models\MovimientoStock;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolPermisoSeeder::class));

// La hora del sistema de gestión (HL-86, D-24): la de la base es la correcta y PHP la sigue.

it('usa la hora de Argentina, la misma que la base', function () {
    expect(config('app.timezone'))->toBe('America/Argentina/Buenos_Aires');

    $base = Carbon::parse(DB::selectOne('SELECT NOW() AS ahora')->ahora);

    expect(abs(now()->diffInSeconds($base)))->toBeLessThan(60);
});

it('una venta cobrada a las 22:30 queda en el kardex de ese día y la API la da en UTC', function () {
    $this->travelTo(Carbon::parse('2026-11-13 22:30:00', 'America/Argentina/Buenos_Aires'));

    $venta = ventaCobrada(productoConPrecios(), 1);
    $delDia = MovimientoStock::query()
        ->where('origen_id', $venta->id)
        ->desde('2026-11-13')
        ->hasta('2026-11-13');

    expect($delDia->count())->toBe(1)
        ->and(DB::table('movimientos_stock')->where('origen_id', $venta->id)->value('created_at'))
        ->toBe('2026-11-13 22:30:00')
        ->and($delDia->first()->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->toBe('2026-11-14T01:30:00Z');
});

it('la migración corre tres horas para atrás y su vuelta las devuelve, sin tocar los NULL', function () {
    $marca = Marca::factory()->create();
    DB::table('marcas')->where('id', $marca->id)->update([
        'created_at' => '2026-11-14 20:43:00',
        'updated_at' => null,
    ]);
    $migracion = require database_path('migrations/2026_11_14_120000_corregir_zona_horaria_de_fechas.php');
    $fila = fn () => DB::table('marcas')->where('id', $marca->id)->first(['created_at', 'updated_at']);

    $migracion->up();
    expect($fila()->created_at)->toBe('2026-11-14 17:43:00')
        ->and($fila()->updated_at)->toBeNull();

    $migracion->down();
    expect($fila()->created_at)->toBe('2026-11-14 20:43:00');
});
