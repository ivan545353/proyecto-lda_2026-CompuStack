<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un proveedor. Mínimo en el tramo D: lo que necesita un selector. El tramo E le suma lo de su ficha.
 */
class ProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'razon_social' => $this->razon_social,
            'activo'       => (bool) $this->activo,
        ];
    }
}
