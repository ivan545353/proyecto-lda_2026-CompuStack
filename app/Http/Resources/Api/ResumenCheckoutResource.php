<?php

namespace App\Http\Resources\Api;

use App\Support\Api\TextosTienda;
use App\Support\Importe;

/**
 * El carrito reservado, más lo que el checkout necesita para elegir: cuánto sale por cada medio
 * de pago, qué entregas hay y hasta cuándo vale la reserva. Qué se elige lo manda la
 * confirmación (tramo F); acá sólo se ofrece.
 *
 * El total por medio es un precio: sin `tienda.ver_precios` no viaja (regla 21).
 */
class ResumenCheckoutResource extends CarritoResource
{
    public function toArray($request): array
    {
        $conPrecios = $request->user()?->can('tienda.ver_precios') === true;
        $totales    = $this->resource['totales'];

        // Cada medio cobra con uno de los dos precios: contado con el de contado, la pasarela con el de lista.
        $medios = array_map(fn (string $medio, string $lista) => [
            'medio' => $medio,
            'texto' => TextosTienda::precio($lista),
            ...($conPrecios ? ['total' => Importe::json($totales[$lista])] : []),
        ], ['contado', 'pasarela'], ['contado', 'lista']);

        $modos = config('tienda.envio.habilitado') ? ['retiro', 'envio'] : ['retiro'];

        return [
            // El vencimiento es `reservado_hasta`, que ya trae el carrito.
            ...parent::toArray($request),
            'medios'  => $medios,
            'entrega' => array_map(fn (string $modo) => [
                'modo'  => $modo,
                'texto' => TextosTienda::entrega($modo),
                'costo' => Importe::json($modo === 'envio' ? config('tienda.envio.costo') : '0'),
            ], $modos),
        ];
    }
}
