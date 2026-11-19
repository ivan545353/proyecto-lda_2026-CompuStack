<?php

use App\Models\IntentoPago;
use App\Models\Venta;
use App\Pagos\PasarelaMercadoPago;
use App\Pagos\PasarelaNoDisponible;
use App\Pagos\Pasarelas;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago contra `Http::fake()` (tramo G, HL-71 y HL-72). Sin base y sin red: ningún test
 * le pega al proveedor real.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.mercadopago.access_token' => 'APP_USR-de-prueba']);
    $this->travelTo(now()->setDateTime(2026, 11, 13, 10, 0, 0));

    $this->intento = function (): IntentoPago {
        $venta = (new Venta)->forceFill(['id' => 42]);

        return (new IntentoPago)->forceFill([
            'estado'     => 'pendiente',
            'importe'    => '15000.50',
            'referencia' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'vence_at'   => now()->addMinutes(20),
        ])->setRelation('venta', $venta);
    };

    $this->busqueda = fn (array $pagos) => Http::fake([
        'api.mercadopago.com/v1/payments/search*' => Http::response(['paging' => ['total' => count($pagos)], 'results' => $pagos]),
    ]);

    $this->pago = fn (string $status, int $id = 111, array $mas = []) => [
        'id' => $id, 'status' => $status, 'status_detail' => "detalle_{$status}",
        'transaction_amount' => 15000.5, 'currency_id' => 'ARS', ...$mas,
    ];

    $this->mp = fn () => app(PasarelaMercadoPago::class);

    // Las fallas del proveedor, por nombre: un dataset de closures no llega a registrar el falso.
    $this->falla = fn (string $caso) => Http::fake(['*' => match ($caso) {
        'sin red'            => fn () => throw new ConnectionException('timeout'),
        'error del proveedor' => Http::response(['message' => 'invalid'], 400),
        'error del servidor' => Http::response('caído', 500),
        'sin init_point'     => Http::response(['id' => 'pref-1'], 201),
        'respuesta rara'     => Http::response(['algo' => 'otro']),
    }]);
});

test('pasarelas resuelve mercado pago por el contenedor', function () {
    expect(app(Pasarelas::class)->para('mercadopago'))->toBeInstanceOf(PasarelaMercadoPago::class);
});

test('la preferencia lleva el pedido, la referencia y vence con el intento', function () {
    Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response([
        'id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1',
    ], 201)]);

    $inicio = ($this->mp)()->iniciar(($this->intento)());

    expect($inicio->url)->toBe('https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1')
        ->and($inicio->referencia)->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');

    Http::assertSent(function (Request $r) {
        $item = $r['items'][0];

        return $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer APP_USR-de-prueba')
            && $r->hasHeader('X-Idempotency-Key', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            && $item['title'] === 'Pedido V-00042'
            && $item['quantity'] === 1
            && $item['unit_price'] === 15000.5
            && $item['currency_id'] === 'ARS'
            && $r['external_reference'] === '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'
            && $r['expires'] === true
            && str_starts_with($r['expiration_date_to'], now()->addMinutes(20)->format('Y-m-d\TH:i:s'));
    });
});

test('si la preferencia no se crea, la pasarela no esta disponible', function (string $caso) {
    ($this->falla)($caso);

    ($this->mp)()->iniciar(($this->intento)());
})->with(['error del proveedor', 'sin init_point', 'sin red'])->throws(PasarelaNoDisponible::class);

test('sin credencial no se inicia ni se consulta', function () {
    config(['services.mercadopago.access_token' => '']);

    expect(($this->mp)()->consultar(($this->intento)())->estado)->toBe('pendiente');
    Http::assertNothingSent();

    ($this->mp)()->iniciar(($this->intento)());
})->throws(PasarelaNoDisponible::class);

test('la consulta busca por la referencia, los mas nuevos primero', function () {
    ($this->busqueda)([]);

    ($this->mp)()->consultar(($this->intento)());

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_contains($r->url(), '/v1/payments/search')
        && $r['external_reference'] === '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'
        && $r['sort'] === 'date_created' && $r['criteria'] === 'desc');
});

test('la consulta traduce cada estado del proveedor', function (string $status, string $estado) {
    ($this->busqueda)([($this->pago)($status)]);

    $consulta = ($this->mp)()->consultar(($this->intento)());

    expect($consulta->estado)->toBe($estado)
        ->and($consulta->paymentId)->toBe('111')
        ->and($consulta->detalle)->toBe("detalle_{$status}");
})->with([
    ['approved', 'aprobado'],
    ['rejected', 'rechazado'],
    ['cancelled', 'rechazado'],
    ['pending', 'pendiente'],
    ['in_process', 'pendiente'],
    ['authorized', 'pendiente'],
]);

test('un aprobado trae el importe que cobro el proveedor', function () {
    ($this->busqueda)([($this->pago)('approved')]);

    expect(($this->mp)()->consultar(($this->intento)())->importe)->toBe('15000.50');
});

test('con varios pagos manda el aprobado aunque haya rechazos despues', function () {
    ($this->busqueda)([($this->pago)('rejected', 3), ($this->pago)('approved', 2), ($this->pago)('rejected', 1)]);

    $consulta = ($this->mp)()->consultar(($this->intento)());

    expect($consulta->estado)->toBe('aprobado')->and($consulta->paymentId)->toBe('2');
});

test('un rechazo seguido de un pago en curso queda pendiente', function () {
    ($this->busqueda)([($this->pago)('in_process', 2), ($this->pago)('rejected', 1)]);

    expect(($this->mp)()->consultar(($this->intento)())->estado)->toBe('pendiente');
});

test('lo que no es un rechazo no se afirma como rechazo', function (array $estados) {
    ($this->busqueda)(array_map(fn ($s) => ($this->pago)($s), $estados));

    $consulta = ($this->mp)()->consultar(($this->intento)());

    expect($consulta->estado)->toBe('pendiente')->and($consulta->respondida)->toBeTrue();
})->with([
    'devuelto'     => [['refunded']],
    'contracargo'  => [['charged_back', 'rejected']],
    'estado nuevo' => [['un_estado_nuevo']],
    'sin pagos'    => [[]],
]);

test('un pago en otra moneda no cuenta', function () {
    ($this->busqueda)([($this->pago)('approved', 1, ['currency_id' => 'USD'])]);

    expect(($this->mp)()->consultar(($this->intento)())->estado)->toBe('pendiente');
});

test('sin respuesta del proveedor el intento sigue pendiente', function (string $caso) {
    ($this->falla)($caso);

    $consulta = ($this->mp)()->consultar(($this->intento)());

    expect($consulta->estado)->toBe('pendiente')
        ->and($consulta->paymentId)->toBeNull()
        ->and($consulta->respondida)->toBeFalse();
})->with(['sin red', 'error del servidor', 'respuesta rara']);
