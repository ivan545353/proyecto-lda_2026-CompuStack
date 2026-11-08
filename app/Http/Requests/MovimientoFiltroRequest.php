<?php

namespace App\Http\Requests;

use App\Models\MovimientoStock;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del kardex.
 *
 * Declara el contrato: los filtros que existen son estos y no otros, con el mismo
 * nombre que el scope del modelo. Es la contracara de A-24.
 *
 * Un rango invertido —«hasta» antes que «desde»— también es un filtro inválido:
 * no devuelve nada y no es lo que el usuario quiso pedir, así que se rechaza con
 * un aviso en lugar de mostrar un listado vacío que parecería decir que no pasó
 * nada en ese período.
 */
class MovimientoFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige stock.ver
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'tipo'        => ['nullable', Rule::in(array_keys(MovimientoStock::TIPOS))],
            'usuario_id'  => ['nullable', 'integer', 'exists:users,id'],
            'desde'       => ['nullable', 'date'],
            'hasta'       => ['nullable', 'date', 'after_or_equal:desde'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('stock.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todos los movimientos.'
            )
        );
    }
}