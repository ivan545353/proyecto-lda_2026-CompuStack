<?php

namespace App\Http\Resources\Api;

use App\Models\Cliente;
use App\Models\ComprobanteTransferencia;
use App\Models\Entrega;
use App\Models\Pago;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Models\VentaLineaImpuesto;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La ficha de una venta (tramo H): lo del listado (regla 40), el cliente, los renglones congelados, las
 * entregas, los comprobantes de transferencia, los movimientos de dinero y `acciones`.
 *
 * `acciones` dice qué permite **el estado** (la tabla de transiciones y lo pendiente), no el permiso: eso
 * lo decide el `@can` de cada botón y la ruta en cada petición (regla 12). `cobro` y `devolucion` traen lo
 * que esas pantallas necesitan para armarse (HL-128, HL-131): sólo cuando el estado las admite.
 *
 * El disponible de un renglón viaja sólo en un presupuesto (en `faltante` si lo supera, y en `cotizacion_hoy`
 * para editarlo): es lo que la web ya le mostraba en la ficha y en el formulario, no el stock del producto.
 *
 * @property Venta $resource
 */
class VentaFichaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $venta      = $this->resource;
        $faltantes  = $venta->esPresupuesto() ? $venta->faltantesDeStock()->keyBy('id') : collect();
        $pendientes = (int) $venta->lineas->sum(fn (VentaLinea $linea) => $linea->cantidadPendienteDeEntregar());
        $acciones   = $this->acciones($venta, $pendientes);

        return [
            ...(new VentaResource($venta))->toArray($request),
            'cliente'              => $venta->cliente ? $this->cliente($venta->cliente) : null,
            'es_online'            => $venta->esOnline(),
            'medio_pago'           => $venta->medio_pago,
            'modificada'           => $venta->updated_at->ne($venta->created_at) ? VentaResource::fecha($venta->updated_at) : null,
            'observaciones'        => $venta->observaciones,
            'sin_stock'            => $faltantes->isNotEmpty(),
            'productos'            => $venta->lineas->count(),
            'importes'             => [
                'subtotal'             => Importe::json($venta->subtotal),
                'descuento'            => Importe::json($venta->descuento),
                'descuento_porcentaje' => Importe::json($venta->descuentoPorcentaje()),
                'total'                => Importe::json($venta->total),
                'pagado'               => Importe::json($venta->pagado()),
                'saldo'                => Importe::json($venta->saldo()),
            ],
            'unidades_pendientes_de_entregar' => $pendientes,
            'lineas'               => $venta->lineas->map(fn (VentaLinea $linea) => [
                'id'                    => $linea->id,
                'producto'              => [
                    'id'     => $linea->producto_id,
                    'codigo' => $linea->producto?->codigo,
                    'rotulo' => $linea->producto?->rotulo(),
                    'activo' => (bool) $linea->producto?->activo,
                ],
                // Lo de hoy del producto, para editar el presupuesto: el precio al que se cotizaría un
                // renglón nuevo y el disponible que avisa (no impide). Cobrado, ya no se edita.
                'cotizacion_hoy'        => $venta->esPresupuesto() && $linea->producto ? [
                    'precio'     => Importe::json($linea->producto->precio_contado),
                    'disponible' => $linea->producto->stock_disponible,
                ] : null,
                // La descripción se copió al cotizar: sigue diciendo qué se vendió aunque el producto cambie.
                'descripcion'           => $linea->descripcion,
                'alicuota_iva'          => Importe::json($linea->alicuota_iva),
                'cantidad'              => $linea->cantidad,
                'cantidad_entregada'    => (int) ($linea->cantidad_entregada ?? 0),
                'cantidad_devuelta'     => (int) $linea->cantidad_devuelta,
                'pendiente_de_entregar' => $linea->cantidadPendienteDeEntregar(),
                'pendiente_de_devolver' => $linea->cantidadPendienteDeDevolver(),
                'precio_unitario'       => Importe::json($linea->precio_unitario),
                'neto'                  => Importe::json($linea->neto),
                'iva'                   => Importe::json($linea->iva),
                'impuestos'             => $linea->impuestos->map(fn (VentaLineaImpuesto $impuesto) => [
                    'nombre'  => $impuesto->nombre,
                    'importe' => Importe::json($impuesto->importe),
                ])->values()->all(),
                'total'                 => Importe::json($linea->total),
                'faltante'              => $faltantes->has($linea->id)
                    ? ['disponible' => $linea->producto->stock_disponible]
                    : null,
            ])->values()->all(),
            'comprobantes_transferencia' => $venta->comprobantesTransferencia
                ->map(fn (ComprobanteTransferencia $comprobante) => [
                    'id'              => $comprobante->id,
                    'nombre_original' => $comprobante->nombre_original,
                    'fecha'           => VentaResource::fecha($comprobante->created_at),
                ])->values()->all(),
            'entregas'             => $venta->entregas->map(fn (Entrega $entrega) => [
                'id'       => $entrega->id,
                'numero'   => $entrega->numeroFormateado(),
                'fecha'    => VentaResource::fecha($entrega->created_at),
                'quien'    => $entrega->quienTexto(),
                'unidades' => (int) $entrega->unidades,
            ])->values()->all(),
            // Entregada antes de que el sistema registrara cada entrega: no tiene comprobante.
            'entregada_sin_comprobante' => $venta->estado === 'entregada' && $venta->entregas->isEmpty(),
            'pagos'                => $venta->pagos->sortBy('id')->map(fn (Pago $pago) => [
                'id'            => $pago->id,
                // La fecha del hecho, no la de la fila: un webhook con atraso no coincide.
                'fecha'         => VentaResource::fecha($pago->fecha),
                'metodo'        => $pago->metodo,
                'metodo_texto'  => $pago->metodoTexto(),
                'monto'         => Importe::json($pago->monto),
                'es_devolucion' => (float) $pago->monto < 0,
                'registro'      => $pago->usuario?->nombre_completo,
            ])->values()->all(),
            'medios'               => collect(Pago::METODOS_EN_USO)
                ->map(fn (string $metodo) => ['metodo' => $metodo, 'texto' => Pago::METODOS[$metodo]])
                ->all(),
            'cobro'                => $acciones['cobrar'] ? [
                // Por transferencia, el saldo se propone en transferencia: es lo que el pedido dijo (D-27).
                'principal' => $venta->medio_pago === 'transferencia' ? 'transferencia' : 'efectivo',
                'problemas' => $this->problemasDelCobro($venta),
            ] : null,
            'devolucion'           => $acciones['devolver'] ? ['sugerido' => $this->medioSugerido($venta)] : null,
            'acciones'             => $acciones,
        ];
    }

    /** @return array<string, bool> */
    private function acciones(Venta $venta, int $pendientes): array
    {
        $estado = $venta->estado;

        return [
            // Editar y recotizar no son transiciones: operan un documento que todavía no tuvo efecto.
            'editar'         => $venta->esPresupuesto(),
            'recotizar'      => $venta->esPresupuesto(),
            'cobrar'         => MaquinaEstadosVenta::puede($estado, 'pagada'),
            'entregar'       => MaquinaEstadosVenta::puede($estado, 'entregada'),
            // Con una sola unidad pendiente no hay parte que elegir.
            'entregar_parte' => MaquinaEstadosVenta::puede($estado, 'entregada_parcial') && $pendientes > 1,
            'devolver'       => MaquinaEstadosVenta::puedeDevolver($estado),
            'cancelar'       => MaquinaEstadosVenta::puede($estado, 'cancelada'),
            'imprimir'       => $venta->sePuedeImprimir(),
        ];
    }

    private function cliente(Cliente $cliente): array
    {
        return [
            'id'                  => $cliente->id,
            'razon_social'        => $cliente->razon_social,
            // Lo que el buscador de clientes muestra al tenerlo elegido.
            'texto'               => $cliente->razonConDocumento(),
            'tipo_doc_texto'      => $cliente->nro_doc ? (Cliente::TIPOS_DOC[$cliente->tipo_doc] ?? 'Documento') : null,
            'nro_doc'             => $cliente->nro_doc,
            'condicion_iva_texto' => Cliente::CONDICIONES_IVA[$cliente->condicion_iva] ?? $cliente->condicion_iva,
            'telefono'            => $cliente->telefono,
            'email'               => $cliente->email,
        ];
    }

    /**
     * Los renglones que van a hacer fallar el cobro, en palabras: un renglón, un mensaje, y la baja del
     * producto antes que el faltante (los dos se arreglan en pantallas distintas).
     *
     * @return array<int, string>
     */
    private function problemasDelCobro(Venta $venta): array
    {
        $faltantes = $venta->faltantesDeStock()->keyBy('id');

        return $venta->lineas
            ->map(function (VentaLinea $linea) use ($faltantes) {
                if (! $linea->producto->activo) {
                    return "«{$linea->descripcion}» está dado de baja en el catálogo. Hay que volver a "
                        .'activarlo, o quitar el renglón del presupuesto.';
                }

                if ($faltantes->has($linea->id)) {
                    return "«{$linea->descripcion}»: se venden {$linea->cantidad} unidad(es) y hay "
                        ."{$linea->producto->stock_disponible} disponible(s).";
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** El medio que la devolución propone: el del cobro más grande, si sigue en uso. */
    private function medioSugerido(Venta $venta): string
    {
        $delCobro = $venta->pagos
            ->filter(fn (Pago $pago) => (float) $pago->monto > 0)
            ->sortByDesc(fn (Pago $pago) => (float) $pago->monto)
            ->first()?->metodo;

        return in_array($delCobro, Pago::METODOS_EN_USO, true) ? $delCobro : Pago::METODOS_EN_USO[0];
    }
}
