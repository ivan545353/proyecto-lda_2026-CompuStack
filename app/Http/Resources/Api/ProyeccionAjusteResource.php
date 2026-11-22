<?php

namespace App\Http\Resources\Api;

use App\Services\PrecioService;
use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una fila de la vista previa del ajuste masivo (tramo F): el producto, su precio de hoy y lo que le
 * pasaría. Recibe `['producto' => Producto, 'proyeccion' => AjusteMasivoService::proyectar()]`.
 *
 * El costo es de `precio.ver_costo`: sin el permiso, las claves `costo` y `costo_nuevo` no están, ni en
 * null (reglas 21 y 36). `costo_nuevo` es null cuando la operación no lo toca.
 */
class ProyeccionAjusteResource extends JsonResource
{
    /** El texto de cada resultado: el mismo de la pantalla web. */
    private const RESULTADOS = [
        PrecioService::APLICADO   => 'Cambia precio',
        PrecioService::SIN_COSTO  => 'Sin costo',
        PrecioService::SIN_CAMBIO => 'Queda igual',
        PrecioService::MANUAL     => 'Precio manual',
    ];

    public function toArray(Request $request): array
    {
        $producto   = $this->resource['producto'];
        $proyeccion = $this->resource['proyeccion'];
        $actual     = (float) $producto->precio_contado;
        $verCosto   = $request->user()?->can('precio.ver_costo') === true;

        return [
            'id'              => $producto->id,
            'codigo'          => $producto->codigo,
            'nombre'          => $producto->nombre,
            'precio_contado'  => Importe::json($producto->precio_contado),
            'precio_lista'    => Importe::json($producto->precio_lista),
            'nuevo_contado'   => Importe::json($proyeccion['contado']),
            'nuevo_lista'     => Importe::json($proyeccion['lista']),
            // Cuánto se mueve el precio de contado; null si hoy no tiene precio del que partir.
            'variacion'       => $actual > 0 ? Importe::json((($proyeccion['contado'] - $actual) / $actual) * 100) : null,
            'resultado'       => $proyeccion['resultado'],
            'resultado_texto' => self::RESULTADOS[$proyeccion['resultado']] ?? $proyeccion['resultado'],
            'bajo_costo'      => (bool) $proyeccion['bajo_costo'],
            'costo'           => $this->when($verCosto, fn () => Importe::json($producto->costo_promedio)),
            'costo_nuevo'     => $this->when($verCosto, fn () => $proyeccion['costo'] === null ? null : Importe::json($proyeccion['costo'])),
        ];
    }
}
