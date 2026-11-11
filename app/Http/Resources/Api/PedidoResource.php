<?php

namespace App\Http\Resources\Api;

use App\Models\Venta;
use App\Models\VentaLinea;
use App\Support\Api\TextosTienda;
use App\Support\Importe;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un pedido de la tienda: la venta online vista por su cliente. Sin vendedor, costos ni stock.
 *
 * Los importes van juntos en `importes` y en cada renglón, y sin `tienda.ver_precios` no viajan
 * (regla 21), como el total por medio del checkout.
 *
 * @property Venta $resource
 */
class PedidoResource extends JsonResource
{
    public function toArray($request): array
    {
        $conPrecios = $request->user()?->can('tienda.ver_precios') === true;
        $venta      = $this->resource;

        return [
            'id'                 => $venta->id,
            'numero'             => $venta->numeroFormateado(),
            'estado'             => $venta->estado,
            'estado_texto'       => TextosTienda::pedido($venta->estado),
            'medio_pago'         => $venta->medio_pago,
            // El medio se dice como se dicen los precios: contado o lista.
            'medio_pago_texto'   => TextosTienda::precio($venta->medio_pago === 'pasarela' ? 'lista' : 'contado'),
            'modo_entrega'       => $venta->modo_entrega,
            'modo_entrega_texto' => TextosTienda::entrega($venta->modo_entrega),
            'direccion'          => $venta->modo_entrega === 'envio' ? [
                'calle'         => $venta->entrega_calle,
                'numero'        => $venta->entrega_numero,
                'piso_depto'    => $venta->entrega_piso_depto,
                'codigo_postal' => $venta->entrega_codigo_postal,
                'localidad'     => $venta->entrega_localidad,
                'provincia'     => $venta->entrega_provincia,
            ] : null,
            // Hasta cuándo se guarda la mercadería; null cuando ya se cobró o se canceló.
            'reserva_vence'      => self::fecha($venta->reserva_vence),
            'creado'             => self::fecha($venta->created_at),
            'lineas'             => $venta->lineas->sortBy('id')->values()->map(fn (VentaLinea $l) => [
                'producto_id' => $l->producto_id,
                'descripcion' => $l->descripcion,
                'cantidad'    => $l->cantidad,
                ...($conPrecios ? [
                    'precio_unitario' => Importe::json($l->precio_unitario),
                    'total'           => Importe::json($l->total),
                ] : []),
            ])->all(),
            ...($conPrecios ? ['importes' => [
                'subtotal'    => Importe::json($venta->subtotal),
                'costo_envio' => Importe::json($venta->costo_envio),
                'total'       => Importe::json($venta->total),
            ]] : []),
        ];
    }

    private static function fecha($momento): ?string
    {
        return $momento?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
