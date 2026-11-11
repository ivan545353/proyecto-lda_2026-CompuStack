<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La cuenta de la persona con sesión. Sólo lectura y sin datos fiscales: el documento y la
 * condición frente al IVA los carga el local (plan, tramo D).
 */
class PerfilResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'nombre'       => $this->nombre,
            'apellido'     => $this->apellido,
            'email'        => $this->email,
            'telefono'     => $this->cliente?->telefono,
            'razon_social' => $this->cliente?->razon_social,
        ];
    }
}
