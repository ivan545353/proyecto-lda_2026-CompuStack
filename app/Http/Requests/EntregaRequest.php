<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de una entrega.
 *
 * Sin `cantidades` es «entregar todo lo pendiente», el botón de un clic de la ficha;
 * con `cantidades` es la entrega parcial, con el mismo contrato que la devolución.
 * El tope de cada renglón y que el renglón sea de esta venta los valida el servicio,
 * con la venta bloqueada. Quién entregó y el estado los decide el servidor (M-31).
 */
class EntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige venta.entregar
    }

    public function rules(): array
    {
        return [
            'cantidades'   => ['sometimes', 'array', 'min:1'],
            'cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'usuario_id' => ['prohibited'],
            'estado'     => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidades.array'     => 'Las cantidades a entregar no llegaron bien. Volvé a cargar la pantalla.',
            'cantidades.min'       => 'No llegó ninguna cantidad para entregar.',
            'cantidades.*.integer' => 'Las unidades que se entregan se escriben en números enteros.',
            'cantidades.*.min'     => 'Las unidades que se entregan no pueden ser negativas. Si de ese producto no sale nada, dejalo en cero.',
            'cantidades.*.max'     => 'Esa cantidad parece un error de tipeo.',
            'usuario_id.prohibited' => 'Quién entregó lo registra el sistema con tu sesión.',
            'estado.prohibited'     => 'Si la entrega es parcial o total lo decide el sistema según lo que sale.',
        ];
    }
}