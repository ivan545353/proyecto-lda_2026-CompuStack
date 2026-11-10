<?php

namespace App\Http\Resources\Api;

use App\Support\Api\Imagenes;

/**
 * La ficha: todo lo del listado y además la descripción completa, la galería y la marca.
 *
 * La galería nunca viene vacía: sin fotos trae la imagen por omisión, igual que `imagen`, para
 * que la app no tenga un caso especial de «no hay imágenes». Va en el orden en que el producto
 * las guarda, y la primera es la principal.
 */
class ProductoFichaResource extends ProductoListadoResource
{
    public function toArray($request): array
    {
        $rutas = array_values(array_filter((array) $this->imagenes));

        return [
            ...parent::toArray($request),
            'descripcion' => (string) $this->descripcion,
            'galeria'     => array_map(fn (?string $ruta) => Imagenes::url($ruta), $rutas ?: [null]),
            'marca'       => $this->marca === null ? null : ['id' => $this->marca->id, 'nombre' => $this->marca->nombre],
        ];
    }
}
