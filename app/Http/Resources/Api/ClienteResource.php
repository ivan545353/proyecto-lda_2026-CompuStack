<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un cliente, para el buscador de la venta, el listado, la ficha y el alta. Las cuatro primeras claves
 * y su orden son las de `Cliente::resultadoDeBusqueda()` (tramo D): el alta rápida devuelve la misma
 * fila que el listado, y la venta no muestra dos formatos para el mismo cliente. Lo del tramo E va al
 * final (regla 40). `cantidad_direcciones` viaja si se contó; `cuenta_email`, si se cargó la cuenta;
 * `direcciones` (la lista, predeterminada primero), sólo en la ficha.
 */
class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'texto'                 => $this->razonConDocumento(),
            'razon_social'          => $this->razon_social,
            'documento'             => $this->documento(),
            'tipo_doc'              => $this->tipo_doc,
            'nro_doc'               => $this->nro_doc,
            'condicion_iva'         => $this->condicion_iva,
            'condicion_iva_texto'   => \App\Models\Cliente::CONDICIONES_IVA[$this->condicion_iva] ?? $this->condicion_iva,
            'tipo_comprobante'      => $this->tipoComprobante(),
            'tipo_comprobante_texto' => $this->tipoComprobante() === 'factura_a' ? 'Factura A' : 'Factura B',
            'email'                 => $this->email,
            'telefono'              => $this->telefono,
            'con_cuenta'            => ! $this->esDeMostrador(),
            'cuenta_email'          => $this->whenLoaded('usuario', fn () => $this->usuario?->email),
            'cantidad_direcciones'  => $this->whenCounted('direcciones'),
            'direcciones'           => DireccionResource::collection($this->whenLoaded('direcciones')),
        ];
    }
}
