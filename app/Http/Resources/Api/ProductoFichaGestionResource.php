<?php

namespace App\Http\Resources\Api;

use App\Models\HistorialPrecio;
use App\Services\PrecioService;
use App\Support\Api\Imagenes;
use App\Support\Importe;
use Illuminate\Http\Request;

/**
 * La ficha de un producto para la administración (tramo D), `GET /productos/{p}?forma=gestion`:
 * lo del listado y además lo que el formulario edita. Por permiso, con la clave ausente si no lo hay:
 *   ganancia, ganancia_aplicada, historial   producto.editar (lo que ve hoy quien abre la edición)
 *   calculo                                  precio.ver_costo (el desglose parte del costo)
 *
 * `imagenes` lleva la ruta guardada: es la referencia que el orden devuelve (`actual:<ruta>`).
 */
class ProductoFichaGestionResource extends ProductoGestionResource
{
    public function toArray(Request $request): array
    {
        $usuario = $request->user();
        $edita   = $usuario?->can('producto.editar') === true;
        $precios = app(PrecioService::class);

        return [
            ...parent::toArray($request),
            'descripcion'       => (string) $this->descripcion,
            'alicuota_iva'      => $this->alicuota_iva,
            'imagenes'          => array_map(
                fn (string $ruta) => ['ruta' => $ruta, 'url' => Imagenes::url($ruta)],
                array_values(array_filter((array) $this->imagenes)),
            ),
            // La propia, o null si hereda; la aplicada es la que hereda de la categoría o la global.
            'ganancia'          => $this->when($edita, fn () => $this->ganancia === null ? null : number_format((float) $this->ganancia, 3, '.', '')),
            'ganancia_aplicada' => $this->when($edita, fn () => number_format((float) $precios->gananciaDe($this->resource), 3, '.', '')),
            'calculo'           => $this->when($usuario?->can('precio.ver_costo') === true, fn () => self::calculo($precios->calcular($this->resource))),
            'historial'         => $this->when($edita, fn () => $this->historialPrecios()->with('usuario')->recientes()->limit(5)->get()
                ->map(fn (HistorialPrecio $cambio) => [
                    'fecha'            => $cambio->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'contado_anterior' => $cambio->precio_contado_anterior === null ? null : Importe::json($cambio->precio_contado_anterior),
                    'contado_nuevo'    => Importe::json($cambio->precio_contado_nuevo),
                    'motivo'           => $cambio->motivo,
                    'motivo_texto'     => HistorialPrecio::MOTIVOS[$cambio->motivo] ?? $cambio->motivo,
                    'usuario'          => $cambio->usuario?->nombre,
                ])->all()),
        ];
    }

    /** El desglose del motor, con los importes como cadena (regla 13). */
    private static function calculo(array $calculo): array
    {
        $etapa = fn (array $parte) => [
            'neto'      => Importe::json($parte['neto']),
            'final'     => Importe::json($parte['final']),
            'impuestos' => array_map(fn (array $impuesto) => [
                'nombre'      => $impuesto['nombre'],
                'alicuota'    => Importe::json($impuesto['alicuota']),
                'comprobante' => $impuesto['comprobante'],
                'importe'     => Importe::json($impuesto['importe']),
            ], $parte['impuestos']),
        ];

        return [
            'costo_neto' => Importe::json($calculo['costo_neto']),
            'ganancia'   => number_format((float) $calculo['ganancia'], 3, '.', ''),
            'margen'     => Importe::json($calculo['margen']),
            'factores'   => array_map(fn (array $factor) => [
                'nombre'     => $factor['nombre'],
                'modo'       => $factor['modo'],
                'porcentaje' => Importe::json($factor['porcentaje']),
                'importe'    => Importe::json($factor['importe']),
            ], $calculo['factores']),
            'contado'    => $etapa($calculo['contado']),
            'lista'      => $etapa($calculo['lista']),
        ];
    }
}
