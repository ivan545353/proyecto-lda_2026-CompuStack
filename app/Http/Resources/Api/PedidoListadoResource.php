<?php

namespace App\Http\Resources\Api;

use App\Models\Venta;
use App\Support\Api\TextosTienda;
use App\Support\Importe;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón de «Mis pedidos». Lee `unidades` y `ultimoIntentoPago` ya traídos por
 * `Venta::paginaDelCliente()`: el listado no consulta por pedido. Sin `tienda.ver_precios`,
 * `importes` no viaja (regla 21).
 *
 * @property Venta $resource
 */
class PedidoListadoResource extends JsonResource
{
    public function toArray($request): array
    {
        $conPrecios = $request->user()?->can('tienda.ver_precios') === true;
        $venta      = $this->resource;

        return [
            'id'                => $venta->id,
            'numero'            => $venta->numeroFormateado(),
            'creado'            => $venta->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'estado'            => $venta->estado,
            'estado_texto'      => TextosTienda::pedido($venta->estado),
            'estado_pago'       => $venta->estadoDelPago(),
            'estado_pago_texto' => TextosTienda::situacionDelPago($venta->situacionDelPago()),
            'unidades'          => (int) $venta->unidades,
            ...($conPrecios ? ['importes' => ['total' => Importe::json($venta->total)]] : []),
        ];
    }
}
