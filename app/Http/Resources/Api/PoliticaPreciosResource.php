<?php

namespace App\Http\Resources\Api;

use App\Models\PoliticaPrecios;
use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La política de precios del comercio (tramo F). Los multiplicadores con tres decimales, como los guarda la
 * base; `margen_global` es el margen sobre la venta que equivale a la ganancia global, para la pantalla.
 */
class PoliticaPreciosResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ganancia_global'     => $this->ganancia_global,
            'margen_global'       => Importe::json($this->margenGlobal()),
            'recargo_lista'       => $this->recargo_lista,
            'redondeo_multiplo'   => $this->redondeo_multiplo,
            'redondeo_modo'       => $this->redondeo_modo,
            'redondeo_modo_texto' => PoliticaPrecios::MODOS_REDONDEO[$this->redondeo_modo] ?? $this->redondeo_modo,
        ];
    }
}
