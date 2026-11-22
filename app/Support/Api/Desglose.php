<?php

namespace App\Support\Api;

use App\Support\Importe;

/**
 * El desglose del motor de precios en el contrato, con los importes como cadena (regla 13). Lo comparten
 * la ficha de gestión del producto (tramo D) y el simulador (tramo F): una sola forma para la misma cuenta.
 *
 * Métodos:
 *   de()  el array de MotorPrecios::calcular() → el JSON
 */
final class Desglose
{
    public static function de(array $calculo): array
    {
        $etapa = fn (array $parte) => [
            'neto'      => Importe::json($parte['neto']),
            'final'     => Importe::json($parte['final']),
            'impuestos' => array_map(fn (array $impuesto) => [
                'nombre'      => $impuesto['nombre'],
                'alicuota'    => Importe::json($impuesto['alicuota']),
                'comprobante' => $impuesto['comprobante'],
                'importe'     => Importe::json($impuesto['importe']),
            ], $parte['impuestos']),
        ];

        return [
            'costo_neto' => Importe::json($calculo['costo_neto']),
            'ganancia'   => number_format((float) $calculo['ganancia'], 3, '.', ''),
            'margen'     => Importe::json($calculo['margen']),
            'factores'   => array_map(fn (array $factor) => [
                'nombre'     => $factor['nombre'],
                'modo'       => $factor['modo'],
                'porcentaje' => Importe::json($factor['porcentaje']),
                'importe'    => Importe::json($factor['importe']),
            ], $calculo['factores']),
            'contado'    => $etapa($calculo['contado']),
            'lista'      => $etapa($calculo['lista']),
        ];
    }
}
