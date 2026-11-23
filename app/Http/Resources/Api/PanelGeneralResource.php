<?php

namespace App\Http\Resources\Api;

use App\Models\Pago;
use App\Support\Importe;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * El panel general (tramo H): el conjunto del período con su comparación, lo que requiere atención (de
 * hoy, sin comparar) y las dos series ya agregadas por la base (A-26): facturado por día y cobrado por
 * medio. El gráfico y las tablas dibujan esas filas; no cuentan nada.
 */
class PanelGeneralResource extends PanelResource
{
    public function toArray(Request $request): array
    {
        $m     = $this->resource;
        $antes = $m['anterior'];

        return [
            'periodo'                  => $this->periodo(),
            'cobrado'                  => Importe::json($m['cobrado']),
            'facturado'                => Importe::json($m['facturado']),
            'cantidad'                 => $m['cantidad'],
            'ticket'                   => Importe::json($m['ticket']),
            'descuentos'               => Importe::json($m['descuentos']),
            'margen_bruto'             => Importe::json($m['margenBruto']),
            'por_metodo'               => $m['porMetodo']->map(fn ($fila) => [
                'metodo'       => $fila->metodo,
                'metodo_texto' => Pago::METODOS[$fila->metodo] ?? $fila->metodo,
                'movimientos'  => (int) $fila->movimientos,
                'neto'         => Importe::json($fila->neto),
            ])->values()->all(),
            'por_dia'                  => $m['porDia']->map(fn ($fila) => [
                'dia'       => Carbon::parse($fila->dia)->toDateString(),
                'cantidad'  => (int) $fila->cantidad,
                'facturado' => Importe::json($fila->facturado),
            ])->values()->all(),
            'stock_critico'            => $m['stockCritico'],
            'compras_abiertas'         => $m['comprasAbiertas'],
            'borradores_de_reposicion' => $m['borradoresDeReposicion'],
            'anterior'                 => $this->anterior([
                'cobrado'      => Importe::json($antes['cobrado']),
                'facturado'    => Importe::json($antes['facturado']),
                'margen_bruto' => Importe::json($antes['margenBruto']),
            ]),
            'comparaciones'            => [
                'cobrado'      => $this->comparar($m['cobrado'], $antes['cobrado'], true),
                'facturado'    => $this->comparar($m['facturado'], $antes['facturado'], true),
                'margen_bruto' => $this->comparar($m['margenBruto'], $antes['margenBruto'], true),
            ],
        ];
    }
}
