<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El stock de un producto (tramo F): el panel del kardex y la referencia del ajuste por conteo. La ruta
 * pide `stock.ver`, así que el stock viaja siempre. `reservas` dice quién retiene lo reservado (D-25):
 * un pedido sin cobrar todavía no tiene asiento (regla 5), y así se ve por qué.
 */
class StockProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'codigo'          => $this->codigo,
            'nombre'          => $this->nombre,
            'texto'           => $this->rotulo(),
            'activo'          => (bool) $this->activo,
            'stock'           => (int) $this->stock,
            'stock_reservado' => (int) $this->stock_reservado,
            'disponible'      => $this->stock_disponible,
            'stock_minimo'    => (int) $this->stock_minimo,
            'reservas'        => $this->whenHas('reservas'),
        ];
    }
}
