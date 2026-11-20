<?php

namespace App\Http\Resources\Api;

use App\Support\Api\Imagenes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una marca, para la administración (tramo D). `logo` es la URL absoluta o null: a diferencia de
 * un producto, una marca sin logo no tiene imagen por omisión. `productos` viaja si se contó.
 */
class MarcaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'nombre'    => $this->nombre,
            'slug'      => $this->slug,
            'logo'      => $this->logo === null ? null : Imagenes::url($this->logo),
            'activo'    => (bool) $this->activo,
            'productos' => $this->whenCounted('productos'),
        ];
    }
}
