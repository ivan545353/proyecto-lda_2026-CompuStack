<?php

use App\Models\IntentoPago;
use App\Models\Pago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * El esquema de los intentos de pago (tramo G), con `Schema::getColumns()` para correr solo.
 */

beforeEach(function () {
    $this->columna = function (string $tabla, string $columna): array {
        $fila = collect(Schema::getColumns($tabla))->firstWhere('name', $columna);
        expect($fila)->not->toBeNull("No existe {$tabla}.{$columna}");

        return $fila;
    };

    $this->valores = function (string $tabla, string $columna): array {
        preg_match_all("/'([^']*)'/", ($this->columna)($tabla, $columna)['type'], $valores);

        return $valores[1];
    };
});

test('los enum del intento coinciden con el modelo', function () {
    expect(($this->valores)('intentos_pago', 'pasarela'))->toBe(IntentoPago::PASARELAS)
        ->and(($this->valores)('intentos_pago', 'estado'))->toBe(IntentoPago::ESTADOS);
});

test('el importe es decimal y la referencia es unica', function () {
    expect(($this->columna)('intentos_pago', 'importe')['type'])->toBe('decimal(12,2)');

    $indices = collect(Schema::getIndexes('intentos_pago'));

    expect($indices->first(fn ($i) => $i['unique'] && $i['columns'] === ['referencia']))->not->toBeNull()
        ->and($indices->pluck('columns')->all())->toContain(['venta_id', 'estado']);
});

test('lo que todavia no se sabe es nulo', function () {
    foreach (['url_pago', 'payment_id', 'detalle', 'resuelto_at', 'acreditado_at'] as $columna) {
        expect(($this->columna)('intentos_pago', $columna)['nullable'])->toBeTrue("{$columna} debería ser null");
    }

    expect(($this->columna)('intentos_pago', 'vence_at')['nullable'])->toBeFalse();
});

test('actualizar un intento no le pisa el vencimiento', function () {
    // HL-73: un TIMESTAMP NOT NULL sin DEFAULT recibía ON UPDATE CURRENT_TIMESTAMP en MariaDB.
    $intento = new IntentoPago;
    $intento->venta_id   = \App\Models\Venta::factory()->create()->id;
    $intento->pasarela   = 'mercadopago';
    $intento->importe    = '100.00';
    $intento->referencia = (string) \Illuminate\Support\Str::uuid();
    $intento->vence_at   = now()->addMinutes(20)->startOfSecond();
    $intento->save();
    $this->travel(5)->minutes();

    $intento->url_pago = 'https://www.mercadopago.com.ar/checkout';
    $intento->save();

    expect($intento->fresh()->vence_at->equalTo($intento->vence_at))->toBeTrue();
});

test('actualizar un pago no le pisa la fecha del hecho', function () {
    // HL-73, la misma trampa en `pagos.fecha` (Etapa 1): la fecha impresa es la del cobro.
    $pago = (\App\Models\Venta::factory()->create())->pagos()->make(['metodo' => 'efectivo', 'monto' => '100.00']);
    $pago->fecha = now()->subDay()->startOfSecond();
    $pago->save();
    $this->travel(5)->minutes();

    $pago->mp_status = 'approved';   // cualquier UPDATE de la fila
    $pago->save();

    expect($pago->fresh()->fecha->equalTo($pago->fecha))->toBeTrue();
});

test('un intento acredita como mucho un pago', function () {
    expect(($this->columna)('pagos', 'intento_pago_id')['nullable'])->toBeTrue();

    $unico = collect(Schema::getIndexes('pagos'))
        ->first(fn ($i) => $i['unique'] && $i['columns'] === ['intento_pago_id']);

    expect($unico)->not->toBeNull();
});

test('el metodo simulador va al final del enum y tiene su texto', function () {
    expect(($this->valores)('pagos', 'metodo'))->toBe(['efectivo', 'transferencia', 'qr', 'mercadopago', 'simulador'])
        ->and(Pago::METODOS)->toHaveKey('simulador')
        ->and(Pago::METODOS_EN_USO)->not->toContain('simulador');
});
