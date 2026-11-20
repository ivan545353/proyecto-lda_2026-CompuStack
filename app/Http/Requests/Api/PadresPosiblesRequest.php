<?php

namespace App\Http\Requests\Api;

/**
 * Las categorías que se pueden elegir como padre (tramo D): de una existente (`categoria`) o de
 * una nueva (sin `categoria`). Es comodidad del formulario; el control es CategoriaRequest.
 */
class PadresPosiblesRequest extends PaginadoRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'categoria' => ['nullable', 'integer', 'exists:categorias,id'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'categoria.integer' => 'La categoría pedida no es válida.',
            'categoria.exists'  => 'La categoría pedida ya no existe.',
        ];
    }
}
