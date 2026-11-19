<?php

use App\Models\IntentoPago;
use App\Pagos\Consulta;
use App\Pagos\PasarelaSimulador;
use App\Pagos\Pasarelas;

/**
 * El contrato de las pasarelas y el simulador (tramo G). Sin base: el simulador no escribe nada.
 */

beforeEach(function () {
    $this->intento = fn (string $estado) => (new IntentoPago)->forceFill([
        'estado'     => $estado,
        'importe'    => '15000.50',
        'referencia' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
    ]);
});

test('el simulador inicia sin url y con la referencia del intento', function () {
    $inicio = (new PasarelaSimulador)->iniciar(($this->intento)('pendiente'));

    expect($inicio->url)->toBeNull()
        ->and($inicio->referencia)->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');
});

test('el simulador consulta lo que ya esta guardado', function (string $estado) {
    $consulta = (new PasarelaSimulador)->consultar(($this->intento)($estado));

    expect($consulta->estado)->toBe($estado)
        ->and($consulta->importe)->toBe('15000.50')
        ->and($consulta->paymentId)->toBeNull();
})->with(['pendiente', 'aprobado', 'rechazado']);

test('un intento vencido o con error no tiene nada nuevo que decir', function (string $estado) {
    expect((new PasarelaSimulador)->consultar(($this->intento)($estado))->estado)->toBe('pendiente');
})->with(['vencido', 'error']);

test('una consulta no puede afirmar un estado que no es del proveedor', function () {
    new Consulta('vencido');
})->throws(InvalidArgumentException::class);

test('pasarelas resuelve el simulador por el contenedor y rechaza una clave desconocida', function () {
    $pasarelas = app(Pasarelas::class);

    expect($pasarelas->para('simulador'))->toBeInstanceOf(PasarelaSimulador::class);

    $pasarelas->para('efectivo');
})->throws(InvalidArgumentException::class);

test('el simulador esta habilitado segun su llave', function (bool $llave) {
    config(['tienda.pagos.simulador' => $llave]);

    expect(app(Pasarelas::class)->habilitada('simulador'))->toBe($llave);
})->with([true, false]);

test('mercado pago esta habilitado solo con credencial', function (?string $token, bool $habilitada) {
    config(['services.mercadopago.access_token' => $token]);

    expect(app(Pasarelas::class)->habilitada('mercadopago'))->toBe($habilitada);
})->with([
    'con token'   => ['APP_USR-de-prueba', true],
    'vacío'       => ['', false],
    'sin definir' => [null, false],
]);

test('las habilitadas van con mercado pago primero', function () {
    config(['tienda.pagos.simulador' => true, 'services.mercadopago.access_token' => 'APP_USR-de-prueba']);

    expect(app(Pasarelas::class)->habilitadas())->toBe(['mercadopago', 'simulador']);

    config(['services.mercadopago.access_token' => null]);

    expect(app(Pasarelas::class)->habilitadas())->toBe(['simulador'])
        ->and(app(Pasarelas::class)->habilitada('qr'))->toBeFalse();
});
