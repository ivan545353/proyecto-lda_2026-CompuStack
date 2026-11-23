<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Con qué sugeridos entra el armador (tramo G, HL-124): `productos[]`, hasta el tope del documento, o nada
 * si se abre a mano. Una lista inválida es 422 en lugar de abrir el armador vacío (M-31, HL-120).
 *
 * Métodos:
 *   elegidos()  los ids pedidos, como enteros
 */
class ProductosElegidosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige compra.crear
    }

    public function rules(): array
    {
        return [
            'productos'   => ['nullable', 'array', 'max:'.PedidoCompraRequest::MAXIMO_LINEAS],
            'productos.*' => ['integer', 'distinct', Rule::exists('productos', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'productos.array'     => 'La lista de productos no es válida.',
            'productos.max'       => 'Un pedido no puede tener más de :max líneas.',
            'productos.*.integer' => 'Ese producto no es válido.',
            'productos.*.distinct' => 'Ese producto está elegido dos veces.',
            'productos.*.exists'  => 'Ese producto no existe.',
        ];
    }

    /** @return array<int, int> */
    public function elegidos(): array
    {
        return array_map('intval', (array) ($this->validated('productos') ?? []));
    }
}
