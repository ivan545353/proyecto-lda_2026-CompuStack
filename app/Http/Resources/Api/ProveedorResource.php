<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un proveedor. Las tres primeras claves son las del tramo D (selectores); lo de su ficha va al final
 * (regla 40). `productos` y `ordenes_compra` viajan si se contaron; `productos_activos` sólo en la ficha:
 * es el número que se ve antes de pedir la cascada de desactivación.
 */
class ProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'razon_social'       => $this->razon_social,
            'activo'             => (bool) $this->activo,
            'cuit'               => $this->cuit,
            'cuit_formateado'    => $this->cuitFormateado(),
            'email'              => $this->email,
            'telefono'           => $this->telefono,
            'contacto'           => $this->contacto,
            'canal_pedido'       => $this->canal_pedido,
            'canal_pedido_texto' => $this->canalPedidoTexto(),
            'portal_url'         => $this->portal_url,
            'plazo_entrega_dias' => (int) $this->plazo_entrega_dias,
            'productos'          => $this->whenCounted('productos'),
            'ordenes_compra'     => $this->whenCounted('ordenesCompra'),
            'productos_activos'  => $this->whenHas('productos_activos'),
        ];
    }
}
