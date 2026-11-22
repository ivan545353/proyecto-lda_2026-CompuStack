<?php

namespace App\Http\Requests\Api;

use App\Models\Proveedor;
use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de proveedores: búsqueda, estado (tramo D, selectores del catálogo) y
 * canal de pedido (tramo E, la pantalla). Un filtro inválido es 422.
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
            'canal'  => ['nullable', Rule::in(array_keys(Proveedor::CANALES))],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'     => 'La búsqueda puede tener hasta :max caracteres.',
            'estado.in' => 'Ese estado no existe: activos o inactivos.',
            'canal.in'  => 'Ese canal de pedido no existe.',
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
            'canal'  => $this->filled('canal') ? (string) $this->input('canal') : null,
        ];
    }
}
