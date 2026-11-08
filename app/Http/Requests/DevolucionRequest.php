<?php

namespace App\Http\Requests;

use App\Models\Pago;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de una devolución.
 *
 * `cantidades` llega con el id de cada línea como clave y cuántas unidades vuelven
 * como valor. El formulario muestra todas las líneas de la venta, así que las que no
 * vuelven viajan en cero: eso no es un error y el servicio las descarta. Es el mismo
 * contrato que `RecepcionCompraRequest`.
 *
 * **Que el tope de cada línea se respete lo valida el servicio y no esta clase**, y
 * es la misma distinción que en el cobro: `cantidad_devuelta` es un saldo que otra
 * petición puede estar moviendo en este mismo instante, y un Form Request no puede
 * bloquear una fila. Validarlo acá sería una lectura sin lock seguida de una
 * decisión, que es la forma exacta del hallazgo C-10 aplicada a otra columna.
 *
 * **Que cada id de línea pertenezca a ESTA venta también lo resuelve el servicio**,
 * buscándolas a través de la relación del padre. Un `exists` sobre `venta_lineas` no
 * alcanzaría: la línea existiría igual, sólo que en la venta de otro cliente.
 *
 * **Lo que el sistema decide está declarado `prohibited`, no omitido** (M-31), y acá
 * son los dos campos que definen el hallazgo que la devolución cierra:
 *
 *   - **`monto`**: cuánta plata vuelve lo calcula el servidor a partir de lo que se
 *     devuelve, con su parte proporcional del descuento. Si lo escribiera el
 *     operador, podría devolver más de lo que entró — y A-11 es justamente que el
 *     dinero y la venta queden diciendo cosas distintas.
 *   - **`estado`**: que la devolución sea parcial o total no lo elige nadie, se
 *     deriva de comparar `cantidad_devuelta` contra `cantidad` en cada línea.
 *
 * **`metodo` sí lo elige el operador, y es la única decisión de la devolución que no
 * se deriva.** Una venta cobrada por transferencia se puede devolver en efectivo de
 * la caja: cómo volvió la plata es un dato del mundo que el sistema no puede deducir,
 * y escribir un negativo en el medio equivocado afirmaría un hecho que no ocurrió. La
 * pantalla propone el medio del cobro, que es el caso habitual, y deja cambiarlo.
 *
 * Métodos:
 *   rules()      las cantidades, el medio y el motivo
 *   messages() / attributes()
 */
class DevolucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige venta.anular
    }

    public function rules(): array
    {
        return [
            'cantidades'   => ['required', 'array', 'min:1'],
            'cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'metodo' => ['required', 'string', Rule::in(Pago::METODOS_EN_USO)],

            // Opcional, y es lo único que el sistema no puede reconstruir después:
            // por qué el cliente lo trajo de vuelta. Va a cada movimiento de
            // reingreso del kardex, cuya columna `motivo` es varchar(255).
            'motivo' => ['nullable', 'string', 'max:255'],

            // --- Lo que el servidor decide y nadie manda ---------------------
            'monto'  => ['prohibited'],
            'estado' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidades.required' => 'No llegó ninguna cantidad para devolver.',
            'cantidades.*.integer' => 'Las unidades que vuelven se escriben en números enteros.',
            'cantidades.*.min'     => 'Las unidades que vuelven no pueden ser negativas. Si de ese producto no vuelve nada, dejalo en cero.',
            'cantidades.*.max'     => 'Esa cantidad parece un error de tipeo.',

            'metodo.required' => 'Indicá por qué medio vuelve la plata.',
            'metodo.in'       => 'Ese medio de pago no está disponible en esta etapa.',

            'motivo.max' => 'El motivo no puede pasar de 255 caracteres.',

            'monto.prohibited'  => 'Cuánta plata vuelve lo calcula el sistema a partir de lo que se devuelve.',
            'estado.prohibited' => 'Que la devolución sea parcial o total lo decide cuánto se devolvió, no el operador.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cantidades' => 'unidades que se devuelven',
            'metodo'     => 'medio por el que vuelve la plata',
            'motivo'     => 'motivo',
        ];
    }
}