<?php

namespace App\Http\Resources\Api;

use App\Support\Api\Imagenes;
use App\Support\Api\TextosTienda;
use App\Support\Importe;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El carrito: recibe `CarritoService::resumen()`. Un documento con sus renglones, como una
 * venta, y no un listado: paginarlo partiría los totales.
 *
 * Precios, totales y avisos de precio, sólo con `tienda.ver_precios` (regla 21): sin el
 * permiso, las claves no están.
 */
class CarritoResource extends JsonResource
{
    public function toArray($request): array
    {
        ['carrito' => $carrito, 'renglones' => $renglones, 'totales' => $totales, 'avisos' => $avisos] = $this->resource;
        $conPrecios = $request->user()?->can('tienda.ver_precios') === true;

        return [
            'estado'          => $carrito->estado,
            'estado_texto'    => TextosTienda::carrito($carrito->estado),
            'reservado_hasta' => $carrito->estado === 'en_checkout'
                ? $carrito->reservado_hasta?->copy()->utc()->format('Y-m-d\TH:i:s\Z')
                : null,
            // Para la insignia de la barra: unidades, no renglones.
            'unidades'        => array_sum(array_map(fn ($r) => $r['linea']->cantidad, $renglones)),
            'renglones'       => array_map(fn (array $r) => $this->renglon($r, $conPrecios), $renglones),
            'totales'         => $this->when($conPrecios, fn () => [
                'contado' => ['importe' => Importe::json($totales['contado']), 'texto' => TextosTienda::precio('contado')],
                'lista'   => ['importe' => Importe::json($totales['lista']), 'texto' => TextosTienda::precio('lista')],
            ]),
            'avisos'          => array_values(array_filter(
                $avisos,
                fn (array $aviso) => $conPrecios || $aviso['tipo'] !== 'precio',
            )),
        ];
    }

    private function renglon(array $r, bool $conPrecios): array
    {
        $producto = $r['producto'];
        $nivel    = $producto->disponibilidad();

        $fila = [
            'id'       => $r['linea']->id,
            'cantidad' => $r['linea']->cantidad,
            'producto' => [
                'id'                   => $producto->id,
                'codigo'               => $producto->codigo,
                'nombre'               => $producto->nombre,
                'imagen'               => Imagenes::url($producto->imagenes[0] ?? null),
                'en_tienda'            => $r['en_tienda'],
                'disponibilidad'       => $nivel,
                'disponibilidad_texto' => TextosTienda::disponibilidad($nivel),
            ],
        ];

        if ($conPrecios) {
            foreach (['contado', 'lista'] as $lista) {
                $fila['precios'][$lista] = [
                    'unitario' => Importe::json($r['unitario'][$lista]),
                    'subtotal' => Importe::json($r['subtotal'][$lista]),
                    'texto'    => TextosTienda::precio($lista),
                ];
            }
        }

        return $fila;
    }
}
