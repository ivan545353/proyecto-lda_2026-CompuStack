<?php

namespace App\Http\Resources\Api;

use App\Support\Api\Imagenes;
use App\Support\Api\Precios;
use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un producto para la administración (tramo D), `GET /productos?forma=gestion`. Lleva lo que la
 * pantalla de siempre le muestra a quien tiene producto.ver —precios, disponible, mínimo, el aviso
 * de reposición—, y lo demás por permiso, con la clave ausente si no lo hay (regla 36):
 * `costo_promedio` y `margen` con precio.ver_costo; `stock` y `stock_reservado` con stock.ver.
 * La de la tienda es ProductoListadoResource: no cambia.
 *
 * Pide cargadas `categoria.padre.padre`, `marca` y `proveedores`, para no consultar por fila.
 */
class ProductoGestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usuario = $request->user();
        $margen  = $this->margen();

        return [
            'id'                  => $this->id,
            'codigo'              => $this->codigo,
            'nombre'              => $this->nombre,
            // Cómo se nombra al elegirlo («TEC-001 — Teclado»): el mismo rótulo en todo el sistema.
            'texto'               => $this->rotulo(),
            'imagen'              => empty($this->imagenes) ? null : Imagenes::url($this->imagenes[0]),
            'categoria'           => [
                'id'     => $this->categoria->id,
                'nombre' => $this->categoria->nombre,
                'ruta'   => $this->categoria->ruta,
                'activo' => (bool) $this->categoria->activo,
            ],
            'marca'               => $this->marca === null ? null : [
                'id'     => $this->marca->id,
                'nombre' => $this->marca->nombre,
                'activo' => (bool) $this->marca->activo,
            ],
            'activo'              => (bool) $this->activo,
            'destacado'           => (bool) $this->destacado,
            'precio_manual'       => (bool) $this->precio_manual,
            'precios'             => Precios::de($this->resource),
            'disponible'          => $this->stock_disponible,
            'stock_minimo'        => (int) $this->stock_minimo,
            'cantidad_reposicion' => (int) $this->cantidad_reposicion,
            'sin_proveedor'       => $this->noSePuedeReponer(),
            'costo_promedio'      => $this->when($usuario?->can('precio.ver_costo') === true, fn () => Importe::json($this->costo_promedio)),
            'margen'              => $this->when($usuario?->can('precio.ver_costo') === true, fn () => $margen === null ? null : Importe::json($margen)),
            'stock'               => $this->when($usuario?->can('stock.ver') === true, fn () => (int) $this->stock),
            'stock_reservado'     => $this->when($usuario?->can('stock.ver') === true, fn () => (int) $this->stock_reservado),
        ];
    }
}
