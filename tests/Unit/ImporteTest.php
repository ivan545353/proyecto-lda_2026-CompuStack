<?php

use App\Support\Importe;

dataset('importes con una sola lectura', [
    'entero'                    => ['1500',          '1500'],
    'coma con un decimal'       => ['1500,5',        '1500.5'],
    'coma con dos decimales'    => ['1500,50',       '1500.50'],
    'punto de miles'            => ['1.500',         '1500'],
    'miles y coma decimal'      => ['1.500,50',      '1500.50'],
    'varios grupos de miles'    => ['12.345.678,90', '12345678.90'],
    'punto decimal'             => ['1500.50',       '1500.50'],
    'con signo pesos y espacio' => ['$ 1.500,50',    '1500.50'],
    'cero'                      => ['0',             '0'],
]);

test('interpreta las formas de escribir un precio', function (string $escrito, string $esperado) {
    expect(Importe::normalizar($escrito))->toBe($esperado);
})->with('importes con una sola lectura');

dataset('importes ambiguos o invalidos', [
    'formato estadounidense'  => ['1,500.50'],
    'punto con tres decimales' => ['1234.567'],
    'tres decimales'          => ['1500.505'],
    'texto'                   => ['mil quinientos'],
    'negativo'                => ['-5'],
    'puntos mal agrupados'    => ['1.5.0'],
]);

test('devuelve sin tocar lo que no puede interpretar', function (string $escrito) {
    // No adivina: la regla de validación lo va a rechazar con su mensaje.
    expect(Importe::normalizar($escrito))->toBe($escrito);
})->with('importes ambiguos o invalidos');

test('lo que no es texto pasa sin cambios', function () {
    expect(Importe::normalizar(null))->toBeNull()
        ->and(Importe::normalizar(1500.5))->toBe(1500.5);
});

test('formatea para mostrar en un campo', function () {
    expect(Importe::paraFormulario('1500.50'))->toBe('1.500,50')
        ->and(Importe::paraFormulario('12345678.9'))->toBe('12.345.678,90')
        ->and(Importe::paraFormulario(null))->toBe('')
        ->and(Importe::paraFormulario('mil'))->toBe('mil');
});