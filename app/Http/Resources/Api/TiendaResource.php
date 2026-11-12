<?php

namespace App\Http\Resources\Api;

use App\Support\Api\TextosTienda;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que la tienda ofrece, para que Inicio lo diga sin escribirlo a mano (tramo J, HL-81).
 * El recurso son los modos de entrega vigentes. El costo del envío no viaja: el visitante
 * no ve precios (regla 21), y el checkout lo sigue trayendo.
 */
class TiendaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'entrega' => array_map(fn (string $modo) => [
                'modo'  => $modo,
                'texto' => TextosTienda::entrega($modo),
            ], $this->resource),
        ];
    }
}
