<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * `GET /categorias` (tramo D). Sin `forma`, el árbol de la tienda de siempre: no lee ningún otro
 * filtro, y nada cambia para la app (regla 40). Con `forma=lista`, el listado de la administración,
 * que muestra también las inactivas: exige `categoria.ver`, y sin él es 403 antes de validar.
 *
 * Métodos:
 *   esLista()    si se pidió el listado de la administración
 *   porPagina()  de a 15 en la lista, como la pantalla de siempre
 *   criterios()  lo que se aplicó, normalizado
 */
class CategoriaFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA_LISTA = 15;

    public const ESTADOS = ['activas', 'inactivas'];

    public function authorize(): bool
    {
        return ! $this->esLista() || $this->user()?->can('categoria.ver') === true;
    }

    public function rules(): array
    {
        if (! $this->esLista()) {
            return parent::rules();
        }

        return [
            ...parent::rules(),
            'forma'     => ['nullable', Rule::in(['lista'])],
            'q'         => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'regex:/^(raiz|\d+)$/'],
            'estado'    => ['nullable', Rule::in(self::ESTADOS)],
            'con_hijas' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'           => 'La búsqueda puede tener hasta :max caracteres.',
            'parent_id.regex' => 'Para ver un grupo, elegí una categoría o «raiz» para las principales.',
            'estado.in'       => 'Ese estado no existe: activas o inactivas.',
            'con_hijas.boolean' => 'Esa opción es sí o no.',
        ];
    }

    // La impresión es siempre del listado de la administración: no hay árbol que imprimir.
    public function esLista(): bool
    {
        return $this->query('forma') === 'lista' || $this->route()?->getActionMethod() === 'impresion';
    }

    public function porPagina(): int
    {
        return $this->esLista() ? $this->integer('por_pagina', self::POR_PAGINA_LISTA) : parent::porPagina();
    }

    public function criterios(): array
    {
        return [
            'forma'     => 'lista',
            'q'         => $this->filled('q') ? (string) $this->input('q') : null,
            'parent_id' => $this->filled('parent_id') ? (string) $this->input('parent_id') : null,
            'estado'    => $this->filled('estado') ? (string) $this->input('estado') : null,
            'con_hijas' => $this->boolean('con_hijas'),
        ];
    }
}
