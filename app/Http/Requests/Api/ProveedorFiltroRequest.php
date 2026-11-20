<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de proveedores. Mínimo en el tramo D (para los selectores del catálogo):
 * búsqueda y estado. El tramo E le suma lo de su pantalla. Un filtro inválido es 422.
 *
 * Métodos:
 *   porPagina()  de a 20 por omisión
 *   criterios()  lo que se aplicó, normalizado
 */
class ProveedorFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 20;

    public const ESTADOS = ['activos', 'inactivos'];

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
            'estado.in' => 'Ese estado no existe: activos o inactivos.',
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
