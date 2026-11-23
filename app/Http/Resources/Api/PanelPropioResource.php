<?php

namespace App\Http\Resources\Api;

use App\Support\Importe;
use Illuminate\Http\Request;

/**
 * El panel propio (tramo H): lo que vendí y lo que cobré, cada número con su comparación, y los
 * presupuestos abiertos, que son de hoy y no se comparan. No trae ningún número del conjunto.
 */
class PanelPropioResource extends PanelResource
{
    public function toArray(Request $request): array
    {
        $m     = $this->resource;
        $antes = $m['anterior'];

        return [
            'periodo'               => $this->periodo(),
            'vendi'                 => $this->vendi($m['vendi']),
            'cobre'                 => $this->cobre($m['cobre']),
            'presupuestos_abiertos' => $m['presupuestosAbiertos'],
            'anterior'              => $this->anterior([
                'vendi' => $this->vendi($antes['vendi']),
                'cobre' => $this->cobre($antes['cobre']),
            ]),
            'comparaciones'         => [
                'vendi_cantidad'    => $this->comparar($m['vendi']['cantidad'], $antes['vendi']['cantidad'], false),
                'vendi_facturado'   => $this->comparar($m['vendi']['facturado'], $antes['vendi']['facturado'], true),
                'vendi_ticket'      => $this->comparar($m['vendi']['ticket'], $antes['vendi']['ticket'], true),
                'cobre_neto'        => $this->comparar($m['cobre']['neto'], $antes['cobre']['neto'], true),
                'cobre_movimientos' => $this->comparar($m['cobre']['movimientos'], $antes['cobre']['movimientos'], false),
            ],
        ];
    }

    private function vendi(array $vendi): array
    {
        return [
            'cantidad'  => $vendi['cantidad'],
            'facturado' => Importe::json($vendi['facturado']),
            'ticket'    => Importe::json($vendi['ticket']),
        ];
    }

    private function cobre(array $cobre): array
    {
        return [
            'movimientos' => $cobre['movimientos'],
            'neto'        => Importe::json($cobre['neto']),
        ];
    }
}
