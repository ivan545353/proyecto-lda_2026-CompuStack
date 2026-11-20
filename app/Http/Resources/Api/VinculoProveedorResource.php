<?php

namespace App\Http\Resources\Api;

use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un vínculo producto-proveedor del comparador (tramo D). El recurso es el proveedor con su pivote;
 * `mas_barato`, `diferencia` y `pedido_en_curso` los calcula el controlador para toda la tabla de una
 * vez (una consulta, no una por fila) y llegan con with(). Los costos viajan con producto.editar,
 * que es lo que pide la ruta: es la pantalla donde se cargan.
 */
class VinculoProveedorResource extends JsonResource
{
    /** @var array{mas_barato?: bool, diferencia?: ?int, pedido?: ?OrdenCompra} */
    public array $contexto = [];

    public function conContexto(array $contexto): static
    {
        $this->contexto = $contexto;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Proveedor $proveedor */
        $proveedor = $this->resource;
        $pedido    = $this->contexto['pedido'] ?? null;

        return [
            'proveedor'        => [
                'id'                 => $proveedor->id,
                'razon_social'       => $proveedor->razon_social,
                'activo'             => (bool) $proveedor->activo,
                'plazo_entrega_dias' => $proveedor->plazo_entrega_dias,
            ],
            'costo_ultimo'     => $proveedor->pivot->costo_ultimo === null ? null : Importe::json($proveedor->pivot->costo_ultimo),
            'codigo_proveedor' => $proveedor->pivot->codigo_proveedor,
            'es_preferido'     => (bool) $proveedor->pivot->es_preferido,
            'mas_barato'       => $this->contexto['mas_barato'] ?? false,
            'diferencia'       => $this->contexto['diferencia'] ?? null,
            // Si hay un pedido vivo, el vínculo no se puede quitar: la pantalla dice por qué.
            'pedido_en_curso'  => $pedido === null ? null : [
                'id'           => $pedido->id,
                'numero'       => $pedido->numeroFormateado(),
                'estado_texto' => $pedido->estadoTexto(),
            ],
        ];
    }
}
