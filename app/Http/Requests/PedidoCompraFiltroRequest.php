<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida con qué entra el armador de pedido.
 *
 * Contrato:
 *   ?productos[]=  ids de sugeridos que entran como renglones; hasta MAXIMO_LINEAS
 *
 *
 * El tope es el del documento, `PedidoCompraRequest::MAXIMO_LINEAS`. La pantalla
 * pagina de a 20, así que de ahí nunca llegan más; el tope está para que una URL
 * escrita a mano no pueda pedir un pedido que no puede existir.
 */
class PedidoCompraFiltroRequest extends FormRequest
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

    /**
     * Los ids que entran como renglones.
     *
     * @return array<int, int>
     */
    public function elegidos(): array
    {
        return array_map('intval', (array) ($this->validated('productos') ?? []));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('compras.pedido')->with(
                'error',
                'Esa lista de productos no es válida. El armador se abrió vacío: volvé a elegirlos.',
            )
        );
    }
}