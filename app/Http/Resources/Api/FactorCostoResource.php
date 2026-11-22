<?php

namespace App\Http\Resources\Api;

use App\Models\FactorCosto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Un factor de costo (tramo F). El porcentaje es cadena con tres decimales, como lo guarda la base. */
class FactorCostoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'nombre'     => $this->nombre,
            'modo'       => $this->modo,
            'modo_texto' => FactorCosto::MODOS[$this->modo] ?? $this->modo,
            'porcentaje' => $this->porcentaje,
            'orden'      => (int) $this->orden,
            'activo'     => (bool) $this->activo,
        ];
    }
}
