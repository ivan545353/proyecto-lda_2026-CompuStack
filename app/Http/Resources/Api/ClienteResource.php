<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un cliente en un listado (tramo D, mínimo para el buscador de la venta). `documento` es su forma
 * de pantalla («DNI 30111222»), como `numero` en una venta; el tramo E le suma lo de su ficha.
 */
class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Las claves y su orden son las de Cliente::resultadoDeBusqueda(): el alta rápida devuelve la
            // misma fila, y la venta no muestra dos formatos para el mismo cliente.
            'id'           => $this->id,
            'texto'        => $this->razonConDocumento(),
            'razon_social' => $this->razon_social,
            'documento'    => $this->documento(),
        ];
    }
}
