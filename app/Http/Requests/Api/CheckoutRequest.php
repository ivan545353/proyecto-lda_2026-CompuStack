<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Iniciar el checkout (`POST /carrito/checkout`). No lleva datos: el carrito es el del usuario.
 * Sólo rechaza un importe enviado, como el resto del carrito (M-31).
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(CarritoCantidadRequest::IMPORTES, ['prohibited']);
    }

    public function messages(): array
    {
        return ['*.prohibited' => 'El precio lo calcula la tienda: no se envía.'];
    }
}
