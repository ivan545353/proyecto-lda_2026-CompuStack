<?php

namespace App\Http\Requests;

use App\Models\OrdenCompra;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de órdenes de compra.
 *
 * Declara el contrato: estos filtros y no otros, con el mismo nombre que el scope
 * del modelo. Es la contracara de A-24.
 */
class OrdenCompraFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige compra.ver
    }

    public function rules(): array
    {
        return [
            'q'            => ['nullable', 'string', 'max:100'],
            'estado'       => ['nullable', Rule::in(array_keys(OrdenCompra::ESTADOS))],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'desde'        => ['nullable', 'date'],
            'hasta'        => ['nullable', 'date', 'after_or_equal:desde'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('compras.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todas las órdenes.'
            )
        );
    }
}