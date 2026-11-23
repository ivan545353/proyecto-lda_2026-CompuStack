<?php

namespace App\Http\Resources\Api;

use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Una fila de los productos más vendidos (tramo H): unidades netas de lo devuelto, agrupadas por la base. */
class InformeProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'producto_id' => (int) $this->producto_id,
            'codigo'      => $this->codigo,
            'nombre'      => $this->nombre,
            'unidades'    => (int) $this->unidades,
            'importe'     => Importe::json($this->importe),
        ];
    }
}
