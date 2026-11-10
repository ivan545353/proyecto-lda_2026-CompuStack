<?php

namespace App\Http\Requests\Api;

use App\Models\Producto;
use Illuminate\Validation\Rule;

/**
 * Los parámetros del listado de productos (docs/api.md, `GET /productos`).
 *
 * El orden admitido sale de `Producto::ORDENES_TIENDA`: una sola lista para validar y para
 * ordenar. `precio_asc` y `precio_desc` entran con el tramo D, porque exigen permiso.
 *
 * Métodos:
 *   criterios()   lo que el servidor aplica, normalizado; vuelve en `criterios` de la respuesta
 */
class CatalogoRequest extends PaginadoRequest
{
    public const SECCIONES = ['novedades', 'destacados'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'            => ['nullable', 'string', 'max:100'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
            'orden'        => ['nullable', 'string', Rule::in(Producto::ORDENES_TIENDA)],
            'seccion'      => ['nullable', 'string', Rule::in(self::SECCIONES)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.string'             => 'La búsqueda tiene que ser un texto.',
            'q.max'                => 'La búsqueda admite hasta :max caracteres.',
            'categoria_id.integer' => 'La categoría no es válida.',
            'categoria_id.min'     => 'La categoría no es válida.',
            'orden.in'             => 'Ese orden no existe. Podés usar: '.implode(', ', Producto::ORDENES_TIENDA).'.',
            'seccion.in'           => 'Esa sección no existe. Podés usar: '.implode(', ', self::SECCIONES).'.',
        ];
    }

    /**
     * Sin `orden`, las novedades van de la más nueva a la más vieja y el resto por nombre:
     * una sección «novedades» en orden alfabético no se leería como novedades.
     *
     * @return array{categoria_id: ?int, q: ?string, orden: string, seccion: ?string}
     */
    public function criterios(): array
    {
        $seccion = $this->filled('seccion') ? (string) $this->input('seccion') : null;

        return [
            'categoria_id' => $this->filled('categoria_id') ? $this->integer('categoria_id') : null,
            'q'            => $this->filled('q') ? (string) $this->input('q') : null,
            'orden'        => $this->filled('orden')
                ? (string) $this->input('orden')
                : ($seccion === 'novedades' ? 'recientes' : 'nombre'),
            'seccion'      => $seccion,
        ];
    }
}
