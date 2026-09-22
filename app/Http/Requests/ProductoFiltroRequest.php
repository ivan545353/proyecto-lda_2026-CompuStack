<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de productos.
 *
 * Contrato:
 *   ?q=             texto, código o nombre contiene
 *   ?categoria_id=  id, la categoría y todas sus subcategorías
 *   ?marca_id=      id
 *   ?estado=        activos | inactivos
 *   ?stock=         disponible | agotado | critico
 *
 * Es el caso original  ItemController mandaba `categoriaId` y
 * `limit`, e ItemDao esperaba `codigo`, `nombre`, `categoria`, `stock`,
 * `limit` y `offset`. Ningún filtro coincidía.
 */
class ProductoFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige producto.ver
    }

    public function rules(): array
    {
        return [
            'q'            => ['nullable', 'string', 'max:100'],
            'categoria_id' => ['nullable', 'integer', 'min:1'],
            'marca_id'     => ['nullable', 'integer', 'min:1'],
            'estado'       => ['nullable', Rule::in(['activos', 'inactivos'])],
            'stock'        => ['nullable', Rule::in(['disponible', 'agotado', 'critico'])],
            'page'         => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('productos.index')
                ->with('error', 'Ese filtro no es válido. Se muestran todos los productos.')
        );
    }
}