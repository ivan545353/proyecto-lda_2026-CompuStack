<?php

use App\Exceptions\TransicionInvalidaException;
use App\Support\MaquinaEstadosCompra;

/*
|--------------------------------------------------------------------------
| Transiciones de una orden de compra
|--------------------------------------------------------------------------
|
| Va en tests/Unit porque no toca la base: es una tabla y dos funciones. La forma
| de C-9 aplicada a compras — lo que no está declarado, no se puede.
|
*/

test('las transiciones declaradas se permiten', function (string $desde, string $hacia) {
    expect(MaquinaEstadosCompra::puede($desde, $hacia))->toBeTrue();
})->with([
    ['borrador', 'aprobada'],
    ['borrador', 'cancelada'],
    ['aprobada', 'enviada'],
    ['aprobada', 'cancelada'],
    // La mercadería ya está en la puerta: no se bloquea por un clic que faltó.
    ['aprobada', 'recibida_parcial'],
    ['aprobada', 'recibida'],
    ['enviada', 'recibida_parcial'],
    ['enviada', 'recibida'],
    ['enviada', 'cancelada'],
    // Una segunda entrega parcial deja la orden en el mismo estado.
    ['recibida_parcial', 'recibida_parcial'],
    ['recibida_parcial', 'recibida'],
]);

test('las transiciones que no estan declaradas se rechazan', function (string $desde, string $hacia) {
    expect(MaquinaEstadosCompra::puede($desde, $hacia))->toBeFalse();
})->with([
    // Un borrador no se envía sin aprobar: sería el automatismo sin supervisión.
    ['borrador', 'enviada'],
    ['borrador', 'recibida'],
    ['borrador', 'recibida_parcial'],
    // Cancelar una orden con mercadería adentro sería un estado que miente.
    ['recibida_parcial', 'cancelada'],
    // Y no se vuelve atrás.
    ['aprobada', 'borrador'],
    ['recibida', 'enviada'],
    ['cancelada', 'aprobada'],
]);

test('los estados finales no tienen salida', function () {
    expect(MaquinaEstadosCompra::destinosDesde('recibida'))->toBe([])
        ->and(MaquinaEstadosCompra::destinosDesde('cancelada'))->toBe([]);
});

test('validar lanza una excepcion que nombra los dos estados en palabras', function () {
    expect(fn () => MaquinaEstadosCompra::validar('borrador', 'enviada'))
        ->toThrow(TransicionInvalidaException::class, 'Una orden «Borrador» no puede pasar a «Enviada».');
});

test('un estado desconocido no habilita nada', function () {
    // El `?? []` del lookup: un estado que no está en la tabla no concede por
    // descarte. Es la forma de C-2 en otro lugar del sistema.
    expect(MaquinaEstadosCompra::puede('inventado', 'recibida'))->toBeFalse()
        ->and(MaquinaEstadosCompra::puedeRecibir('inventado'))->toBeFalse();
});

test('solo tres estados pueden recibir mercaderia', function () {
    expect(MaquinaEstadosCompra::puedeRecibir('aprobada'))->toBeTrue()
        ->and(MaquinaEstadosCompra::puedeRecibir('enviada'))->toBeTrue()
        ->and(MaquinaEstadosCompra::puedeRecibir('recibida_parcial'))->toBeTrue()
        ->and(MaquinaEstadosCompra::puedeRecibir('borrador'))->toBeFalse()
        ->and(MaquinaEstadosCompra::puedeRecibir('recibida'))->toBeFalse()
        ->and(MaquinaEstadosCompra::puedeRecibir('cancelada'))->toBeFalse();
});