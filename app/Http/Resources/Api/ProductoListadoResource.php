<?php

namespace App\Http\Resources\Api;

use App\Support\Api\Imagenes;
use App\Support\Api\TextosTienda;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un producto en el listado. Las claves se enumeran una por una: lo que no está acá no viaja
 * (costo, ganancia, stock, precios). Los precios entran con el tramo D, bajo permiso.
 */
class ProductoListadoResource extends JsonResource
{
    public function toArray($request): array
    {
        $disponibilidad = $this->disponibilidad();

        return [
            'id'                   => $this->id,
            'codigo'               => $this->codigo,
            'nombre'               => $this->nombre,
            'descripcion_breve'    => $this->descripcionBreve(),
            'imagen'               => Imagenes::url($this->imagenes[0] ?? null),
            'categoria'            => ['id' => $this->categoria->id, 'nombre' => $this->categoria->nombre],
            'es_novedad'           => $this->esNovedadMarcada(),
            'es_destacado'         => (bool) $this->destacado,
            'disponibilidad'       => $disponibilidad,
            'disponibilidad_texto' => TextosTienda::disponibilidad($disponibilidad),
        ];
    }

    /** La marca que dejó `Producto::paginaEnTienda` (una consulta por pedido); sola, la calcula. */
    protected function esNovedadMarcada(): bool
    {
        return (bool) ($this->resource->getAttribute('es_novedad') ?? $this->esNovedad());
    }
}
