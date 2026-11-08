<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Support\MotorPrecios;

/** El catálogo mínimo: un solo impuesto, IVA 21 %. */
function soloIva21(): array
{
    return [['nombre' => 'IVA 21 %', 'alicuota' => '21.00', 'comprobante' => 'iva']];
}

test('el criterio del tramo: 20.000 con ganancia 2,5 e IVA 21 % da 60.500', function () {
    $contado = (new MotorPrecios([], soloIva21()))->calcular('20000.00', '2.500')['contado'];

    expect($contado['final'])->toBe(60500.0)
        ->and($contado['neto'])->toBe(50000.0)
        ->and($contado['impuestos'][0]['importe'])->toBe(10500.0)
        ->and($contado['neto'] + $contado['impuestos'][0]['importe'])->toBe($contado['final']);
});

test('el desglose suma exacto aunque el precio no sea redondo', function () {
    // El caso que importa: si el neto se redondeara primero y el IVA se calculara
    // encima, neto + IVA no daría el total, y el total es lo que se cobra.
    $contado = (new MotorPrecios([], soloIva21()))->calcular('1333.33', '2.370')['contado'];

    expect($contado['final'])->toBe(3823.59)
        ->and($contado['neto'])->toBe(3159.99)
        ->and($contado['impuestos'][0]['importe'])->toBe(663.60)
        ->and(round($contado['neto'] + $contado['impuestos'][0]['importe'], 2))->toBe($contado['final']);
});

test('con dos impuestos cada uno declara su importe y los dos suman la diferencia', function () {
    $impuestos = [
        ['nombre' => 'IVA 21 %',            'alicuota' => '21.00', 'comprobante' => 'iva'],
        ['nombre' => 'Impuestos internos',  'alicuota' => '10.00', 'comprobante' => 'otros_nacionales'],
    ];

    $contado = (new MotorPrecios([], $impuestos))->calcular(1000, 1)['contado'];

    expect($contado['final'])->toBe(1331.0)
        ->and($contado['neto'])->toBe(1000.0)
        ->and($contado['impuestos'][0]['importe'])->toBe(210.0)
        // El segundo se calcula sobre 1.210, no sobre 1.000: es lo que significa
        // que los impuestos se apilan.
        ->and($contado['impuestos'][1]['importe'])->toBe(121.0)
        ->and($contado['neto'] + 210.0 + 121.0)->toBe($contado['final']);
});

test('un factor de recupero divide, no multiplica', function () {
    // Con 3 %: dividir por 0,97 recupera el 3 %; multiplicar por 1,03 recupera 2,91 %.
    $factores = [['nombre' => 'IIBB', 'modo' => 'recupero', 'porcentaje' => '3.000']];

    $contado = (new MotorPrecios($factores, soloIva21()))->calcular(1000, 2)['contado'];

    expect($contado['final'])->toBe(2494.85)
        ->and($contado['final'])->not->toBe(2492.60);
});

test('un factor de recargo multiplica y declara lo que sumó', function () {
    $factores = [['nombre' => 'Flete', 'modo' => 'recargo', 'porcentaje' => '10.000']];

    $calculo = (new MotorPrecios($factores, soloIva21()))->calcular(1000, 2);

    expect($calculo['contado']['final'])->toBe(2662.0)
        ->and($calculo['factores'][0]['importe'])->toBe(200.0);
});

test('el precio de lista lleva el recargo adentro del neto y paga IVA sobre él', function () {
    // El recargo financiero forma parte de la base imponible: vender en cuotas
    // más caro no es cobrar el mismo neto con otro IVA.
    $calculo = (new MotorPrecios([], soloIva21(), '15.000'))->calcular(1000, 2);

    expect($calculo['contado']['final'])->toBe(2420.0)
        ->and($calculo['lista']['final'])->toBe(2783.0)
        ->and($calculo['lista']['neto'])->toBe(2300.0)
        ->and($calculo['lista']['impuestos'][0]['importe'])->toBe(483.0);
});

dataset('modos de redondeo', [
    'al más cercano' => ['cercano', 3000.0],
    'hacia arriba'   => ['arriba',  3100.0],
    'hacia abajo'    => ['abajo',   3000.0],
]);

test('se redondea el precio final y el desglose se deriva del redondeado', function (string $modo, float $esperado) {
    // Sin redondeo daría 3.025. Pase lo que pase con el múltiplo, neto + IVA
    // tiene que dar el precio que se cobra.
    $contado = (new MotorPrecios([], soloIva21(), 0, '100.00', $modo))->calcular(1000, 2.5)['contado'];

    expect($contado['final'])->toBe($esperado)
        ->and(round($contado['neto'] + $contado['impuestos'][0]['importe'], 2))->toBe($contado['final']);
})->with('modos de redondeo');

test('redondear hacia arriba no mueve un precio que ya cae en el multiplo', function () {
    // 60500 / 0,01 no da 6.050.000 exacto en punto flotante: sin el round previo,
    // el ceil() devolvería 60.500,01.
    $contado = (new MotorPrecios([], soloIva21(), 0, '0.01', 'arriba'))->calcular('20000.00', '2.500')['contado'];

    expect($contado['final'])->toBe(60500.0);
});

test('el margen equivalente se calcula sobre la venta', function () {
    // Un multiplicador de 2,5 es markup del 150 % y margen del 60 %. El campo que
    // se carga es el multiplicador; el margen es el número que se lee al lado.
    expect((new MotorPrecios([], soloIva21()))->calcular('20000.00', '2.500')['margen'])->toBe(60.0);
});

test('sin impuestos el precio final es el neto', function () {
    $contado = (new MotorPrecios())->calcular(1000, 2)['contado'];

    expect($contado['final'])->toBe(2000.0)
        ->and($contado['neto'])->toBe(2000.0)
        ->and($contado['impuestos'])->toBeEmpty();
});

test('un producto exento muestra su IVA en cero y no desaparece del desglose', function () {
    // Un comprobante a consumidor final declara el IVA contenido aunque sea cero:
    // la línea tiene que estar.
    $exento  = [['nombre' => 'Exento', 'alicuota' => '0.00', 'comprobante' => 'iva']];
    $contado = (new MotorPrecios([], $exento))->calcular(1000, 2)['contado'];

    expect($contado['final'])->toBe(2000.0)
        ->and($contado['impuestos'])->toHaveCount(1)
        ->and($contado['impuestos'][0]['importe'])->toBe(0.0);
});

test('un costo en cero no rompe el calculo', function () {
    $calculo = (new MotorPrecios([], soloIva21()))->calcular(0, 2.5);

    expect($calculo['contado']['final'])->toBe(0.0)
        ->and($calculo['margen'])->toBe(0.0);
});

test('un recupero del 100 % o mas se rechaza', function () {
    // No hay precio que cubra un impuesto que se lleva todo. Rechazar es lo
    // correcto; devolver infinito o un negativo sería afirmar un precio falso.
    $factores = [['nombre' => 'Imposible', 'modo' => 'recupero', 'porcentaje' => '100.000']];

    expect(fn () => (new MotorPrecios($factores, soloIva21()))->calcular(1000, 2))
        ->toThrow(ReglaDeNegocioException::class);
});