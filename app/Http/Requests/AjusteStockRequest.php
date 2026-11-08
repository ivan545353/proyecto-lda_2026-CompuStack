<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del ajuste de inventario.
 *
 * Pide el **conteo físico**, no la diferencia: el operario que está en el depósito
 * sabe cuántas unidades hay, no cuántas faltan, y pedirle la diferencia lo obliga
 * a restar contra un número de la pantalla que puede estar viejo.
 *
 * `stock_esperado` es el stock que la pantalla mostró al abrir el formulario, y
 * viaja en un campo oculto. Si al guardar el de la base es otro, el ajuste pisaría
 * un movimiento legítimo de otra persona, así que `StockService` lo rechaza. **No
 * es un control de seguridad**, es de concurrencia: falsificarlo sólo perjudica a
 * quien lo falsifica, porque se queda sin la red que avisa que alguien más movió
 * el stock mientras él contaba.
 *
 * `producto_id` y `tipo` se declaran **prohibited** en vez de omitirse. El producto
 * lo determina la ruta y el tipo lo determina el servicio; omitirlos los ignoraría
 * en silencio y el intento de registrar un ajuste como si fuera una compra
 * desaparecería sin dejar rastro. Prohibirlos lo hace visible.
 */
class AjusteStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige stock.ajustar
    }

    public function rules(): array
    {
        return [
            // El conteo físico. No puede ser negativo: no existen menos de cero
            // unidades en un depósito. El tope corta un error de tipeo —pegar un
            // teléfono en el campo— antes de que llegue a la base.
            'stock_contado' => ['required', 'integer', 'min:0', 'max:1000000'],

            // El único campo que responde el «por qué» de A-13 cuando no hay
            // documento de origen, así que es obligatorio. El mínimo de 3
            // caracteres admite «Robo» y rechaza una letra sola.
            'motivo' => ['required', 'string', 'min:3', 'max:255'],

            'stock_esperado' => ['required', 'integer', 'min:0'],

            'producto_id' => ['prohibited'],
            'tipo'        => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_contado.required' => 'Escribí cuántas unidades contaste.',
            'stock_contado.integer'  => 'El conteo se escribe en unidades enteras.',
            'stock_contado.min'      => 'El conteo no puede ser negativo: no existen menos de cero unidades.',
            'stock_contado.max'      => 'Ese conteo parece un error de tipeo.',

            'motivo.required' => 'Escribí por qué cambió el stock: es lo único que lo explica, porque un ajuste no tiene comprobante.',
            'motivo.min'      => 'El motivo es demasiado corto para que se entienda después.',
            'motivo.max'      => 'El motivo no puede superar los 255 caracteres.',

            'stock_esperado.required' => 'Volvé a abrir el formulario: se perdió el stock de referencia.',

            'producto_id.prohibited' => 'El producto se toma de la pantalla, no del formulario.',
            'tipo.prohibited'        => 'El tipo de movimiento lo determina el sistema.',
        ];
    }

    public function attributes(): array
    {
        return [
            'stock_contado'  => 'conteo físico',
            'motivo'         => 'motivo',
            'stock_esperado' => 'stock de referencia',
        ];
    }
}