<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de categorías. Mismo criterio que
 * MarcaFiltroRequest: el contrato de filtros escrito, y un valor inválido
 * lleva al listado limpio con aviso en lugar de rebotar.
 *
 * Contrato:
 *   ?q=          texto, nombre contiene
 *   ?parent_id=  'raiz' o un id: hijas directas
 *   ?estado=     activas | inactivas
 *
 * En el original, CategoryController mandaba `estado`, que no existía ni como
 * columna, y el DAO esperaba `nombre` (hallazgo A-24).
 */
class CategoriaFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige categoria.ver
    }

    public function rules(): array
    {
        return [
            'q'         => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'regex:/^(raiz|\d+)$/'],
            'estado'    => ['nullable', Rule::in(['activas', 'inactivas'])],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('categorias.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todas las categorias.'
            )
        );
    }
}