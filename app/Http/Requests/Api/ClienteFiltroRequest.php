<?php

namespace App\Http\Requests\Api;

use App\Models\Cliente;
use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de clientes: búsqueda por razón social, documento, correo o teléfono
 * (tramo D, el buscador de la venta), condición frente al IVA y cuenta de tienda (tramo E, la
 * pantalla). Un filtro inválido es 422.
 *
 * Métodos:
 *   porPagina()  de a 20 por omisión
 *   criterios()  lo que se aplicó, normalizado
 */
class ClienteFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 20;

    public const CUENTAS = ['con_cuenta', 'sin_cuenta'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'             => ['nullable', 'string', 'max:100'],
            'condicion_iva' => ['nullable', Rule::in(array_keys(Cliente::CONDICIONES_IVA))],
            'cuenta'        => ['nullable', Rule::in(self::CUENTAS)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'             => 'La búsqueda puede tener hasta :max caracteres.',
            'condicion_iva.in'  => 'Esa condición frente al IVA no existe.',
            'cuenta.in'         => 'Esa cuenta no existe: con_cuenta o sin_cuenta.',
        ];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', self::POR_PAGINA);
    }

    public function criterios(): array
    {
        return [
            'q'             => $this->filled('q') ? (string) $this->input('q') : null,
            'condicion_iva' => $this->filled('condicion_iva') ? (string) $this->input('condicion_iva') : null,
            'cuenta'        => $this->filled('cuenta') ? (string) $this->input('cuenta') : null,
        ];
    }
}
