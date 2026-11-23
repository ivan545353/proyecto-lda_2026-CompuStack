<?php

namespace App\Http\Resources\Api;

use App\Models\OrdenCompraLinea;
use App\Support\Importe;
use App\Support\MaquinaEstadosCompra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La ficha de una orden de compra (tramo G): lo del listado (regla 40), y además el proveedor con cómo
 * se le pide, las líneas, lo que entró por la orden y `acciones`.
 *
 * `acciones` dice qué permite **el estado** —la tabla de transiciones y lo que falta recibir—, no qué
 * permite el permiso: eso lo decide el `@can` de cada botón en la pantalla (regla 12) y la ruta en cada
 * petición. La pantalla no calcula ninguna de las dos cosas (regla 9).
 */
class OrdenCompraFichaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $proveedor  = $this->proveedor;
        $recibidas  = (int) $this->lineas->sum('cantidad_recibida');
        $pendientes = (int) $this->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        return [
            ...(new OrdenCompraResource($this->resource))->toArray($request),
            'proveedor'        => [
                'id'                 => $proveedor->id,
                'razon_social'       => $proveedor->razon_social,
                'activo'             => (bool) $proveedor->activo,
                'cuit_formateado'    => $proveedor->cuit ? $proveedor->cuitFormateado() : null,
                'contacto'           => $proveedor->contacto,
                'email'              => $proveedor->email,
                'telefono'           => $proveedor->telefono,
                'canal_pedido'       => $proveedor->canal_pedido,
                'canal_pedido_texto' => $proveedor->canalPedidoTexto(),
                'portal_url'         => $proveedor->portal_url,
                'plazo_entrega_dias' => (int) $proveedor->plazo_entrega_dias,
            ],
            'observaciones'    => $this->observaciones,
            'creada_por'       => $this->usuarioCreo ? "{$this->usuarioCreo->nombre} {$this->usuarioCreo->apellido}" : null,
            'fecha_aprobacion' => $this->fecha_aprobacion?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'aprobada_por'     => $this->usuarioAprobo ? "{$this->usuarioAprobo->nombre} {$this->usuarioAprobo->apellido}" : null,
            'fecha_envio'      => $this->fecha_envio?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'unidades_recibidas'  => $recibidas,
            'unidades_pendientes' => $pendientes,
            'lineas'           => $this->lineas->map(fn (OrdenCompraLinea $linea) => [
                'id'                => $linea->id,
                'producto'          => [
                    'id'     => $linea->producto->id,
                    'codigo' => $linea->producto->codigo,
                    'nombre' => $linea->producto->nombre,
                    // La edición del borrador lo avisa: «(dado de baja)».
                    'activo' => (bool) $linea->producto->activo,
                ],
                'codigo_proveedor'  => $linea->codigo_proveedor,
                'cantidad_pedida'   => $linea->cantidad_pedida,
                'cantidad_recibida' => $linea->cantidad_recibida,
                'cantidad_pendiente' => $linea->cantidadPendiente(),
                'costo_unitario'    => Importe::json($linea->costo_unitario),
                'subtotal'          => Importe::json($linea->subtotalPedido()),
            ])->values()->all(),
            'recepciones'      => MovimientoStockResource::collection(
                $this->movimientos->sortBy([['created_at', 'desc'], ['id', 'desc']])->values(),
            )->resolve($request),
            'acciones'         => [
                'editar'   => $this->esEditable(),
                'aprobar'  => MaquinaEstadosCompra::puede($this->estado, 'aprobada'),
                'enviar'   => MaquinaEstadosCompra::puede($this->estado, 'enviada'),
                'recibir'  => MaquinaEstadosCompra::puedeRecibir($this->estado) && $pendientes > 0,
                // Cerrar incompleta sólo tiene sentido si entró algo y falta algo.
                'cerrar'   => MaquinaEstadosCompra::puede($this->estado, 'recibida') && $recibidas > 0 && $pendientes > 0,
                'cancelar' => MaquinaEstadosCompra::puede($this->estado, 'cancelada'),
                'imprimir' => $this->sePuedeImprimir(),
            ],
        ];
    }
}
