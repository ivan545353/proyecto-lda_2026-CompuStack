<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de una recepción de mercadería.
 *
 * `cantidades` llega con el id de cada línea como clave y cuántas unidades
 * llegaron como valor. El formulario muestra todas las líneas, así que las que no
 * llegaron viajan en cero: eso no es un error, y el servicio las descarta.
 *
 * Que llegue al menos una unidad lo valida el servicio y no esta clase, porque es
 * una regla sobre el conjunto y no sobre un campo: se traduce a un aviso general,
 * que es lo que corresponde cuando el problema no es de ningún campo en particular.
 *
 * Que cada id de línea pertenezca a ESTA orden también lo resuelve el servicio,
 * buscándola a través de la relación del padre. Validarlo acá con un `exists`
 * sobre `orden_compra_lineas` no alcanzaría: la línea existiría igual, sólo que en
 * la orden de otro proveedor.
 */
class RecepcionCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige compra.recibir
    }

    public function rules(): array
    {
        return [
            'cantidades'   => ['required', 'array', 'min:1'],
            'cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidades.required' => 'No llegó ninguna cantidad para registrar.',
            'cantidades.*.integer' => 'Las unidades recibidas se escriben en números enteros.',
            'cantidades.*.min'     => 'Las unidades recibidas no pueden ser negativas.',
            'cantidades.*.max'     => 'Esa cantidad parece un error de tipeo.',
        ];
    }
}