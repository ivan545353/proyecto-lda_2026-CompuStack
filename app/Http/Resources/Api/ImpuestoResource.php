<?php

namespace App\Http\Resources\Api;

use App\Models\Impuesto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un impuesto (tramo F). La alícuota es cadena como un importe (regla 13). `productos` dice cuántos la
 * usan y sólo viaja en una alícuota de IVA contada con `conUso()`: un impuesto general no lo referencia
 * ninguna fila. Con `productos > 0`, eliminarlo lo desactiva.
 */
class ImpuestoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'nombre'            => $this->nombre,
            'alicuota'          => $this->alicuota,
            'alcance'           => $this->alcance,
            'alcance_texto'     => Impuesto::ALCANCES[$this->alcance] ?? $this->alcance,
            'comprobante'       => $this->comprobante,
            'comprobante_texto' => Impuesto::COMPROBANTES[$this->comprobante] ?? $this->comprobante,
            'orden'             => (int) $this->orden,
            'activo'            => (bool) $this->activo,
            'productos'         => $this->when(
                $this->alcance === 'producto' && $this->resource->hasAttribute('productos_con_la_alicuota'),
                fn () => (int) $this->productos_con_la_alicuota,
            ),
        ];
    }
}
