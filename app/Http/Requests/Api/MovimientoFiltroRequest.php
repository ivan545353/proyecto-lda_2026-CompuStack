<?php

namespace App\Http\Requests\Api;

use App\Models\MovimientoStock;
use App\Models\Venta;
use Illuminate\Validation\Rule;

/**
 * Los filtros del kardex (tramo F). Los mismos que la web, con el nombre de su scope; un filtro
 * inválido —también un rango invertido— es 422 en lugar de volver al kardex limpio (M-31, HL-120).
 *
 * Métodos:
 *   criterios()  lo que se aplicó, normalizado
 */
class MovimientoFiltroRequest extends PaginadoRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'tipo'        => ['nullable', Rule::in(array_keys(MovimientoStock::TIPOS))],
            'usuario_id'  => ['nullable', 'integer', 'exists:users,id'],
            'canal'       => ['nullable', Rule::in(array_keys(Venta::CANALES))],
            'desde'       => ['nullable', 'date'],
            'hasta'       => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'producto_id.integer'  => 'El producto no es válido.',
            'producto_id.exists'   => 'Ese producto no existe.',
            'tipo.in'              => 'Ese tipo de movimiento no existe.',
            'usuario_id.integer'   => 'El usuario no es válido.',
            'usuario_id.exists'    => 'Ese usuario no existe.',
            'canal.in'             => 'Ese canal no existe: mostrador u online.',
            'desde.date'           => 'La fecha «desde» no es una fecha.',
            'hasta.date'           => 'La fecha «hasta» no es una fecha.',
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde»: no devolvería nada.',
        ];
    }

    public function criterios(): array
    {
        return [
            'producto_id' => $this->filled('producto_id') ? $this->integer('producto_id') : null,
            'tipo'        => $this->filled('tipo') ? (string) $this->input('tipo') : null,
            'usuario_id'  => $this->filled('usuario_id') ? $this->integer('usuario_id') : null,
            'canal'       => $this->filled('canal') ? (string) $this->input('canal') : null,
            'desde'       => $this->filled('desde') ? (string) $this->input('desde') : null,
            'hasta'       => $this->filled('hasta') ? (string) $this->input('hasta') : null,
        ];
    }
}
