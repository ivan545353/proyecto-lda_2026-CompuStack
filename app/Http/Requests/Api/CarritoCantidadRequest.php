<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La cantidad de un renglón del carrito (`PATCH /carrito/lineas/{linea}`), y la base del alta.
 * Ningún importe entra por acá: el precio lo pone el servidor, y uno enviado es 422, no se
 * ignora en silencio (M-31).
 */
class CarritoCantidadRequest extends FormRequest
{
    /** Todo lo que se parezca a un precio. */
    public const IMPORTES = [
        'precio', 'precios', 'importe', 'precio_contado', 'precio_lista',
        'contado_referencia', 'lista_referencia', 'subtotal', 'total', 'totales',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'min:1', 'max:'.(int) config('tienda.carrito.cantidad_maxima')],
            ...array_fill_keys(self::IMPORTES, ['prohibited']),
        ];
    }

    public function messages(): array
    {
        return [
            'cantidad.required' => 'Indicá cuántas unidades querés.',
            'cantidad.integer'  => 'La cantidad tiene que ser un número entero.',
            'cantidad.min'      => 'La cantidad tiene que ser al menos :min.',
            'cantidad.max'      => 'Podés llevar hasta :max unidades de cada producto.',
            '*.prohibited'      => 'El precio lo calcula la tienda: no se envía.',
        ];
    }
}
