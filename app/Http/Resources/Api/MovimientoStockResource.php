<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón del kardex (tramo F): lo que había, lo que se movió y lo que quedó, con su origen en
 * palabras del negocio. Nunca el nombre de una clase: `origen_texto` lo escribe el modelo.
 */
class MovimientoStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'fecha'            => $this->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'producto'         => [
                'id'     => $this->producto->id,
                'codigo' => $this->producto->codigo,
                'nombre' => $this->producto->nombre,
            ],
            'tipo'             => $this->tipo,
            'tipo_texto'       => $this->tipoTexto(),
            'cantidad'         => (int) $this->cantidad,
            'stock_anterior'   => (int) $this->stock_anterior,
            'stock_resultante' => (int) $this->stock_resultante,
            'motivo'           => $this->motivo,
            'origen_texto'     => $this->origenTexto(),
            'remito_texto'     => $this->remitoTexto(),
            'registrado_por'   => $this->registradoPorTexto(),
        ];
    }
}
