<?php

namespace App\Http\Resources\Api;

use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Una fila del ranking de vendedores (tramo H): lo que agrupó la base, sin contar nada acá (A-26). */
class InformeVendedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'usuario_id' => (int) $this->usuario_id,
            'vendedor'   => "{$this->apellido}, {$this->nombre}",
            'cantidad'   => (int) $this->cantidad,
            'facturado'  => Importe::json($this->facturado),
            'ticket'     => Importe::json($this->ticket),
        ];
    }
}
