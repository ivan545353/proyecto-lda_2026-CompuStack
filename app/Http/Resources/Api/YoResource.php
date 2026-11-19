<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quién tiene la sesión: su cuenta, su rol y sus permisos. Los dos clientes pintan la interfaz con
 * esto; el servidor igual vuelve a preguntar el permiso en cada endpoint (regla 11).
 */
class YoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'nombre'   => $this->nombre,
            'apellido' => $this->apellido,
            'email'    => $this->email,
            'rol'      => [
                'nombre' => $this->rol->nombre,
                'ambito' => $this->rol->ambito,
            ],
            // Ordenados: la misma cuenta da siempre la misma lista.
            'permisos' => $this->clavesDePermiso()->sort()->values()->all(),
        ];
    }
}
