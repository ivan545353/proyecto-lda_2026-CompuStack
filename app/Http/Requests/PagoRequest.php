<?php

namespace App\Http\Requests;

use App\Models\Pago;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de cobro.
 *
 * **Lo que este Form Request NO valida es el centro del hallazgo, así que vale
 * escribirlo acá y no sólo en el servicio: el saldo.** La doctrina del proyecto es
 * que cada regla que el usuario puede violar vive en el servicio **además** del Form
 * Request, y el tope de descuento está en los dos a propósito. Acá no, y la
 * diferencia es exactamente C-10:
 *
 *   - El tope de descuento depende de lo que llegó y del rol de quien lo manda.
 *     Ninguna de las dos cosas la puede cambiar otra petición mientras tanto, así que
 *     validarlo dos veces en dos capas da siempre la misma respuesta.
 *   - El saldo depende de los pagos ya registrados, que otra petición puede estar
 *     escribiendo en este mismo instante. Un Form Request no puede bloquear una fila,
 *     así que cualquier comprobación del saldo que se escribiera acá sería una
 *     lectura sin lock seguida de una decisión — que es, línea por línea, lo que
 *     hacía `SaleService::cobrar()`.
 *
 * No es que fuera redundante: es que **tener una copia del hallazgo en el código
 * sería peor que no tener el mensaje en el campo**. Alguien que lo leyera después
 * podría concluir razonablemente que la comprobación del servicio es la duplicada y
 * sacarla. El precio que se paga es que, cuando el monto no coincide, el error no cae
 * en el campo sino arriba de la pantalla, como aviso, con los valores conservados por
 * `withInput()`. Es un precio chico y es el correcto.
 *
 * Lo que sí valida es la **forma** del cobro, que no depende del estado de nada: que
 * el medio esté entre los que la Etapa 1 ofrece, que el monto sea un importe
 * positivo con dos decimales, y que no venga el mismo medio dos veces.
 *
 * **Lo que no se manda está declarado `prohibited`, no omitido** (M-31). El cajero
 * sale de la sesión, la fecha la pone el servidor, y las cinco columnas de Mercado
 * Pago las escribe el webhook de la Etapa 2 —`mp_payment_id` incluida: el servicio la
 * acepta como parámetro porque es la clave de idempotencia, pero **la pantalla del
 * mostrador no es el webhook**, y cuando exista va a ser otro controlador que arma el
 * arreglo él mismo—. Todos esos campos ya tienen dos barreras silenciosas —el modelo
 * no los puede escribir y el servicio no los mira— pero omitirlos acá los haría
 * desaparecer sin dejar rastro: alguien que manda `pagos[0][neto_acreditado]=1` vería
 * un cobro correcto sin saber que su dato se tiró.
 *
 * **El formulario no necesita JavaScript.** Manda una fila por cada medio de pago
 * ofrecido, con el `metodo` fijo y el `monto` vacío, y las filas sin monto se
 * descartan en `prepareForValidation()`. Así `distinct` se cumple por construcción, no
 * hay armador de filas que mantener, y sin JS la pantalla funciona igual — que es la
 * convención de mejora progresiva del proyecto.
 *
 * Métodos:
 *   prepareForValidation()  descarta filas vacías y normaliza los montos
 *   rules()                 la forma del cobro, nunca el saldo
 *   messages() / attributes()
 */
class PagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige venta.cobrar
    }

        protected function prepareForValidation(): void
    {
        $pagos = $this->input('pagos');

        if (! is_array($pagos)) {
            return;
        }

        // **Acá no se descarta ninguna fila, y es un cambio deliberado.** La primera
        // versión descartaba las filas donde ningún campo tuviera contenido, copiando
        // la heurística de `VentaRequest`. Allá funciona porque los dos campos de una
        // línea vienen de inputs visibles y están los dos vacíos cuando el renglón no
        // se usó. Acá `metodo` es un `hidden` con un valor fijo, así que **ninguna
        // fila es nunca «completamente vacía»**: las tres sobrevivían y los dos medios
        // que el operador dejaba en blanco fallaban con «escribí cuánto se cobró por
        // este medio». La heurística hacía la pregunta equivocada para este
        // formulario.
        //
        // La pregunta correcta la contesta el servicio, que descarta las filas sin
        // monto. Y no descartar nada acá tiene un beneficio propio: una fila sin monto
        // pero con un campo `prohibited` armado a mano se sigue rechazando con su
        // mensaje, en lugar de desaparecer en silencio (M-31).
        $this->merge([
            'pagos' => array_map(
                function ($pago) {
                    if (! is_array($pago)) {
                        return $pago;
                    }

                    // Un monto en blanco significa «por este medio no entró nada», y
                    // se normaliza a null **explícitamente**, para no depender de que
                    // el middleware global de Laravel convierta las cadenas vacías.
                    //
                    // Si trae algo: "1.500,50" → "1500.50". La regla `numeric` espera
                    // el punto y en Argentina se escribe con coma. Lo ambiguo llega
                    // sin tocar y la regla lo rechaza: nunca se adivina, porque leer
                    // "1.500" como 1,50 registraría un cobro de mil quinientos pesos
                    // a uno con cincuenta sin reportar ningún error.
                    $pago['monto'] = filled($pago['monto'] ?? null)
                        ? Importe::normalizar($pago['monto'])
                        : null;

                    return $pago;
                },
                $pagos,
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            // El tope sale de cuántos medios se ofrecen: con uno por fila y `distinct`,
            // más filas que medios es imposible. El `max` está igual, porque acota el
            // trabajo antes de que `distinct` mire una petición armada a mano con mil
            // filas. Y se deriva en lugar de escribirse, así que el día que la Etapa 2
            // sume Mercado Pago a los ofrecidos, esto ya dice 4.
            'pagos'   => ['required', 'array', 'min:1', 'max:'.count(Pago::METODOS_EN_USO)],
            'pagos.*' => ['array'],

            'pagos.*.metodo' => [
                'required', 'string',
                // Contra los ofrecidos y no contra los cuatro del ENUM: `mercadopago`
                // está declarado en la base y no se opera en esta etapa, y un cobro
                // registrado por un canal que el sistema no integra afirmaría una
                // acreditación que no conoce.
                Rule::in(Pago::METODOS_EN_USO),
                'distinct',
            ],

            'pagos.*.monto' => [
                'nullable', 'numeric',
                'decimal:0,2',
                'min:0.01', 'max:9999999999',
            ],

            // --- Lo que el servidor decide o acredita el webhook ---------------
            'pagos.*.venta_id'        => ['prohibited'],
            'pagos.*.usuario_id'      => ['prohibited'],
            'pagos.*.fecha'           => ['prohibited'],
            'pagos.*.mp_payment_id'   => ['prohibited'],
            'pagos.*.mp_status'       => ['prohibited'],
            'pagos.*.comision'        => ['prohibited'],
            'pagos.*.neto_acreditado' => ['prohibited'],
            'pagos.*.cuotas'          => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'pagos.required' => 'Cargá el monto de al menos un medio de pago.',
            'pagos.min'      => 'Cargá el monto de al menos un medio de pago.',
            'pagos.max'      => 'Hay más medios de pago cargados que medios disponibles.',

            'pagos.*.metodo.required' => 'Falta el medio de pago de esta línea.',
            'pagos.*.metodo.in'       => 'Ese medio de pago no está disponible en esta etapa.',
            'pagos.*.metodo.distinct' => 'Ese medio de pago ya está cargado en otra línea. Dejá una sola, con el monto total.',

            'pagos.*.monto.numeric' => 'El monto no se entiende. Escribilo como 1500,50.',
            'pagos.*.monto.decimal' => 'El monto va con dos decimales como máximo.',
            'pagos.*.monto.min'     => 'El monto tiene que ser mayor que cero. Si por este medio no se cobró nada, dejá el campo vacío.',

            'pagos.*.venta_id.prohibited'        => 'La venta que se cobra la determina la pantalla, no el formulario.',
            'pagos.*.usuario_id.prohibited'      => 'El cajero es quien está usando el sistema, y no se elige.',
            'pagos.*.fecha.prohibited'           => 'La fecha del cobro la pone el sistema.',
            'pagos.*.mp_payment_id.prohibited'   => 'El identificador de Mercado Pago lo acredita el webhook, no el mostrador.',
            'pagos.*.mp_status.prohibited'       => 'El estado de Mercado Pago lo informa Mercado Pago.',
            'pagos.*.comision.prohibited'        => 'La comisión la informa el medio de pago, no el cajero.',
            'pagos.*.neto_acreditado.prohibited' => 'El neto acreditado lo informa el medio de pago, no el cajero.',
            'pagos.*.cuotas.prohibited'          => 'Las cuotas las informa el medio de pago, no el cajero.',
        ];
    }

    public function attributes(): array
    {
        return [
            'pagos'          => 'medios de pago',
            'pagos.*.metodo' => 'medio de pago',
            'pagos.*.monto'  => 'monto',
        ];
    }
}