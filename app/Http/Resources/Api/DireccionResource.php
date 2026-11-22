<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una dirección de un cliente (tramo E). `resumen` es su forma de pantalla, en una línea
 * («Av. Eva Perón 1450 — Caleta Olivia, Santa Cruz (9011)»).
 */
class DireccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'calle'             => $this->calle,
            'numero'            => $this->numero,
            'piso_depto'        => $this->piso_depto,
            'codigo_postal'     => $this->codigo_postal,
            'localidad'         => $this->localidad,
            'provincia'         => $this->provincia,
            'es_predeterminada' => (bool) $this->es_predeterminada,
            'resumen'           => $this->resumen(),
        ];
    }
}
