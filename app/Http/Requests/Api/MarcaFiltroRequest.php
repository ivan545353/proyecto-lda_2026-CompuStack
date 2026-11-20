<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de marcas (tramo D). Un filtro inválido es 422: el listado no muestra
 * otra cosa que la pedida.
 *
 * Métodos:
 *   porPagina()  de a 15 por omisión, como la pantalla de siempre
 *   criterios()  lo que se aplicó, normalizado
 */
class MarcaFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 15;

    public const ESTADOS = ['activas', 'inactivas'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'      => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(self::ESTADOS)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'     => 'La búsqueda puede tener hasta :max caracteres.',
            'estado.in' => 'Ese estado no existe: activas o inactivas.',
        ];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', self::POR_PAGINA);
    }

    public function criterios(): array
    {
        return [
            'q'      => $this->filled('q') ? (string) $this->input('q') : null,
            'estado' => $this->filled('estado') ? (string) $this->input('estado') : null,
        ];
    }
}
