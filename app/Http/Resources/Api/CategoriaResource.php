<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

/** Una categoría de la tienda con sus hijas anidadas; sin slug ni datos de gestión. */
class CategoriaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'     => $this->id,
            'nombre' => $this->nombre,
            // Las hojas no cargan la relación: siempre `[]`, nunca la clave ausente.
            'hijas'  => $this->relationLoaded('hijas') ? self::collection($this->hijas)->resolve() : [],
        ];
    }
}
