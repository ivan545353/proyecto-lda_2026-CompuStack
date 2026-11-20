<?php

namespace App\Http\Requests\Api;

/**
 * Los filtros del listado de clientes. Mínimo en el tramo D (el buscador de la venta): búsqueda por
 * razón social, documento, correo o teléfono. El tramo E le suma lo de su pantalla.
 *
 * Métodos:
 *   porPagina()  de a 20 por omisión
 *   criterios()  lo que se aplicó, normalizado
 */
class ClienteFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 20;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max' => 'La búsqueda puede tener hasta :max caracteres.',
        ];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', self::POR_PAGINA);
    }

    public function criterios(): array
    {
        return ['q' => $this->filled('q') ? (string) $this->input('q') : null];
    }
}
