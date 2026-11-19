<?php

namespace App\Http\Requests\Api;

/**
 * Agregar al carrito (`POST /carrito/lineas`): el producto y la cantidad. Si el producto está en
 * la tienda lo decide el servicio (404), no la validación: `exists` no sabe de categorías ocultas.
 */
class CarritoLineaRequest extends CarritoCantidadRequest
{
    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'min:1'],
            ...parent::rules(),
        ];
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'Indicá qué producto querés agregar.',
            'producto_id.integer'  => 'El producto no es válido.',
            'producto_id.min'      => 'El producto no es válido.',
            ...parent::messages(),
        ];
    }
}
