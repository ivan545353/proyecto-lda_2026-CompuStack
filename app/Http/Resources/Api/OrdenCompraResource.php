<?php

namespace App\Http\Resources\Api;

use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una orden de compra en el listado (tramo G). `editable` lo decide el modelo, no la pantalla: sólo un
 * borrador se edita. `generada_por_el_sistema` es lo que significa no tener quién la creó.
 */
class OrdenCompraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'numero'                  => $this->numeroFormateado(),
            'fecha'                   => $this->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'proveedor'               => [
                'id'           => $this->proveedor->id,
                'razon_social' => $this->proveedor->razon_social,
            ],
            'estado'                  => $this->estado,
            'estado_texto'            => $this->estadoTexto(),
            'generada_por_el_sistema' => $this->fueGeneradaPorElSistema(),
            'total_estimado'          => Importe::json($this->total_estimado),
            'editable'                => $this->esEditable(),
            'productos'               => $this->whenCounted('lineas'),
        ];
    }
}
