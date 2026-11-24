<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de personal (tramo I). Los mismos cuatro de la pantalla web, que eran la
 * corrección de A-24: el listado es sólo del personal, siempre, y no hay filtro de ámbito. `estado`
 * (acceso, `users.activo`) y `situacion` (vínculo laboral, `empleados.fecha_baja`) son dos datos.
 * Un filtro inválido es 422 en su campo (HL-120), no una redirección con aviso.
 *
 * Métodos:
 *   porPagina()  de a 15 por omisión, como la pantalla web
 *   criterios()  lo que se aplicó, normalizado
 */
class PersonalFiltroRequest extends PaginadoRequest
{
    public const POR_PAGINA = 15;

    public const ESTADOS = ['activos', 'inactivos'];

    public const SITUACIONES = ['en_actividad', 'dados_de_baja'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'         => ['nullable', 'string', 'max:100'],
            // Sólo roles de gestión: un rol de tienda no tiene a nadie que listar acá.
            'rol_id'    => ['nullable', 'integer', Rule::exists('roles', 'id')->where('ambito', 'gestion')],
            'estado'    => ['nullable', Rule::in(self::ESTADOS)],
            'situacion' => ['nullable', Rule::in(self::SITUACIONES)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'          => 'La búsqueda puede tener hasta :max caracteres.',
            'rol_id.integer' => 'Ese rol no existe o no es un rol del personal.',
            'rol_id.exists'  => 'Ese rol no existe o no es un rol del personal.',
            'estado.in'      => 'Ese acceso no existe: activos o inactivos.',
            'situacion.in'   => 'Esa situación no existe: en_actividad o dados_de_baja.',
        ];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', self::POR_PAGINA);
    }

    public function criterios(): array
    {
        return [
            'q'         => $this->filled('q') ? (string) $this->input('q') : null,
            'rol_id'    => $this->filled('rol_id') ? $this->integer('rol_id') : null,
            'estado'    => $this->filled('estado') ? (string) $this->input('estado') : null,
            'situacion' => $this->filled('situacion') ? (string) $this->input('situacion') : null,
        ];
    }
}
