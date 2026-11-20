<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una categoría para la administración (tramo D): plana, con su lugar en el árbol. La ruta se arma
 * con las relaciones cargadas (`padre.padre.padre`), sin una consulta por fila. Los conteos viajan
 * si se contaron. La de la tienda es CategoriaResource, el árbol: no cambia.
 */
class CategoriaGestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'nombre'        => $this->nombre,
            'slug'          => $this->slug,
            'parent_id'     => $this->parent_id,
            'ruta'          => $this->ruta,
            'dentro_de'     => $this->padre?->ruta,
            'nivel'         => $this->nivelCargado(),
            'orden'         => (int) $this->orden,
            'activo'        => (bool) $this->activo,
            'subcategorias' => $this->whenCounted('hijas'),
            'productos'     => $this->whenCounted('productos'),
        ];
    }
}
