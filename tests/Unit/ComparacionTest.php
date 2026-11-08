<?php

use App\Support\Comparacion;

beforeEach(function () {
    $this->periodo = 'entre el 01/02 y el 28/02';
});

test('una suba dice cuanto mas y contra que', function () {
    expect(Comparacion::entre(1120, 1000)->texto($this->periodo, '$ 1.000,00'))
        ->toBe('12 % más que entre el 01/02 y el 28/02 ($ 1.000,00)');
});

test('una baja dice menos con palabras y no solo con el icono', function () {
    $comparacion = Comparacion::entre(880, 1000);

    expect($comparacion->texto($this->periodo, '$ 1.000,00'))
        ->toBe('12 % menos que entre el 01/02 y el 28/02 ($ 1.000,00)')
        ->and($comparacion->icono())->toBe('bi-arrow-down-right');
});

test('sin nada en el periodo anterior no inventa un porcentaje', function () {
    // Contra cero, cualquier número sería «+∞ %» o un «+100 %» que nadie midió.
    $comparacion = Comparacion::entre(5000, 0);

    expect($comparacion->texto($this->periodo, '$ 0,00'))->toBe('Sin datos del período anterior')
        ->and($comparacion->porcentaje)->toBeNull()
        ->and($comparacion->icono())->toBeNull();
});

test('contra una base negativa da el sentido sin porcentaje', function () {
    $comparacion = Comparacion::entre(500, -1000);

    expect($comparacion->porcentaje)->toBeNull()
        ->and($comparacion->texto($this->periodo, '$ -1.000,00'))
        ->toBe('Más que entre el 01/02 y el 28/02 ($ -1.000,00)');
});

test('una diferencia que redondea a cero no se presenta como igual', function () {
    expect(Comparacion::entre(1002, 1000)->texto($this->periodo, '1000'))
        ->toBe('Apenas más que entre el 01/02 y el 28/02 (1000)')
        ->and(Comparacion::entre(1000, 1000)->texto($this->periodo, '1000'))
        ->toBe('Igual que entre el 01/02 y el 28/02 (1000)');
});

test('caer a cero es cien por ciento menos', function () {
    expect(Comparacion::entre(0, 3)->texto($this->periodo, '3'))
        ->toBe('100 % menos que entre el 01/02 y el 28/02 (3)');
});