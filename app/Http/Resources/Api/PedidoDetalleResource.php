<?php

namespace App\Http\Resources\Api;

use App\Models\Venta;
use App\Support\Api\TextosTienda;

/**
 * El detalle de un pedido (tramo H): lo mismo que devuelve `POST /pedidos`, más los dos estados
 * por separado, el último intento y si hay comprobante. Los intentos completos salen de
 * `GET /pedidos/{id}/pagos`, que pagina (regla 15, HL-75).
 *
 * @property Venta $resource
 */
class PedidoDetalleResource extends PedidoResource
{
    public function toArray($request): array
    {
        $venta     = $this->resource;
        $situacion = $venta->situacionDelPago();
        $ultimo    = $venta->ultimoIntentoPago;

        return [
            ...parent::toArray($request),
            'estado_pago'            => $venta->estadoDelPago(),
            'estado_pago_texto'      => TextosTienda::situacionDelPago($situacion),
            'ultimo_pago'            => $ultimo === null ? null : [
                'id'             => $ultimo->id,
                'pasarela'       => $ultimo->pasarela,
                'pasarela_texto' => TextosTienda::pasarela($ultimo->pasarela),
                'estado'         => $ultimo->estado,
                'estado_texto'   => TextosTienda::intento($ultimo->estado),
                'creado'         => $ultimo->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            // Sólo lo cobrado tiene comprobante: lo demás no se vendió todavía (D-22).
            'comprobante_disponible' => $situacion === 'pagado',
        ];
    }
}
