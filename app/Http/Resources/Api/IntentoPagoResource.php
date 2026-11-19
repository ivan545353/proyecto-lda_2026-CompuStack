<?php

namespace App\Http\Resources\Api;

use App\Models\IntentoPago;
use App\Support\Api\TextosTienda;
use App\Support\Importe;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un intento de pago, con el estado del pago de su pedido: la app lee el resultado de cualquier
 * respuesta sin un segundo pedido. Sin `tienda.ver_precios`, `importe` no viaja (regla 21). El
 * `payment_id` y el detalle del proveedor no salen: son para quien revisa, no para el cliente.
 *
 * @property IntentoPago $resource
 */
class IntentoPagoResource extends JsonResource
{
    public function toArray($request): array
    {
        $conPrecios = $request->user()?->can('tienda.ver_precios') === true;
        $intento    = $this->resource;
        $pedido     = $intento->venta;
        $situacion  = $pedido->situacionDelPago();

        return [
            'id'             => $intento->id,
            'pasarela'       => $intento->pasarela,
            'pasarela_texto' => TextosTienda::pasarela($intento->pasarela),
            'estado'         => $intento->estado,
            'estado_texto'   => TextosTienda::intento($intento->estado),
            ...($conPrecios ? ['importe' => Importe::json($intento->importe)] : []),
            // Sólo Mercado Pago, y sólo mientras espera: adónde mandar al cliente.
            'url_pago'       => $intento->estado === 'pendiente' ? $intento->url_pago : null,
            'vence'          => self::fecha($intento->vence_at),
            'resuelto'       => self::fecha($intento->resuelto_at),
            'creado'         => self::fecha($intento->created_at),
            'pedido'         => [
                'id'                => $pedido->id,
                'numero'            => $pedido->numeroFormateado(),
                'estado'            => $pedido->estado,
                'estado_texto'      => TextosTienda::pedido($pedido->estado),
                'estado_pago'       => $pedido->estadoDelPago(),
                'estado_pago_texto' => TextosTienda::situacionDelPago($situacion),
            ],
        ];
    }

    private static function fecha($momento): ?string
    {
        return $momento?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
