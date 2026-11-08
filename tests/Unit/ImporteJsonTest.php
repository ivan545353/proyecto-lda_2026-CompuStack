<?php

use App\Support\Importe;

dataset('importes para la api', [
    'entero'                  => [1500,       '1500.00'],
    'cero'                    => [0,          '0.00'],
    'un decimal'              => [1500.5,     '1500.50'],
    'dos decimales'           => [1500.55,    '1500.55'],
    'cadena de la base'       => ['60500.00', '60500.00'],
    'cadena con un decimal'   => ['54450.5',  '54450.50'],
    'sin separador de miles'  => [1234567.89, '1234567.89'],
    'redondea hacia arriba'   => [10.005,     '10.01'],
    'redondea hacia abajo'    => ['10.004',   '10.00'],
    'negativo'                => ['-250.5',   '-250.50'],
]);

test('un importe viaja como cadena con dos decimales y punto', function (int|float|string $valor, string $esperado) {
    expect(Importe::json($valor))->toBe($esperado);
})->with('importes para la api');

test('un cero negativo no sale con signo', function () {
    expect(Importe::json(-0.001))->toBe('0.00');
});

test('json() no es la forma de la pantalla', function () {
    expect(Importe::json('1500.5'))->not->toBe(Importe::paraFormulario('1500.5'));
});
