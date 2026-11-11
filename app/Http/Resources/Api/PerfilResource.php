<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La cuenta de la persona con sesión. Sólo lectura y sin datos fiscales: el documento y la
 * condición frente al IVA los carga el local (plan, tramo D). La dirección predeterminada se
 * propone en el envío (tramo F); editarla para un pedido no la cambia acá.
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
            'direccion'    => ($d = $this->cliente?->direccionPredeterminada) === null ? null : [
                'calle'         => $d->calle,
                'numero'        => $d->numero,
                'piso_depto'    => $d->piso_depto,
                'codigo_postal' => $d->codigo_postal,
                'localidad'     => $d->localidad,
                'provincia'     => $d->provincia,
            ],
        ];
    }
}
