<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rol (tramo I), para el listado y la ficha. Los conteos viajan si se contaron (los cuenta la base,
 * A-26). `se_puede_eliminar` se deriva de los datos (regla 9) con las mismas dos condiciones que el
 * servicio: no es de sistema y nadie lo tiene; la pantalla lo usa para no ofrecer lo que se va a
 * rechazar, y quien decide igual es el servicio (409). `permisos` (los ids), sólo en la ficha.
 */
class RolResource extends JsonResource
{
    public const AMBITOS = ['gestion' => 'Gestión', 'tienda' => 'Tienda'];

    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'nombre'            => $this->nombre,
            'descripcion'       => $this->descripcion,
            'ambito'            => $this->ambito,
            'ambito_texto'      => self::AMBITOS[$this->ambito] ?? $this->ambito,
            'es_sistema'        => (bool) $this->es_sistema,
            'cantidad_permisos' => $this->whenCounted('permisos'),
            'cantidad_usuarios' => $this->whenCounted('usuarios'),
            'se_puede_eliminar' => $this->whenCounted('usuarios', fn ($cantidad) => ! $this->es_sistema && (int) $cantidad === 0),
            'permisos'          => $this->whenLoaded('permisos', fn () => $this->permisos->pluck('id')->sort()->values()->all()),
        ];
    }
}
