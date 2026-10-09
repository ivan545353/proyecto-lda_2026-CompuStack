<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Pago;
use App\Models\User;
use App\Models\Venta;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Support\Facades\DB;

/**
 * El cobro de una venta. Es el cierre del núcleo de C-10.
 *
 * **Qué hacía mal el original, textual.** `SaleService::cobrar()` comparaba el monto
 * contra el saldo **fuera** de la transacción y abría el `FOR UPDATE` recién al
 * insertar:
 *
 *     if ($monto > (float) $venta["saldo"] + 0.001) throw ...
 *     $dao->registrarPago(...);   // recién acá abre transacción y hace FOR UPDATE
 *
 * Dos peticiones concurrentes leían el mismo saldo, las dos pasaban la validación y
 * las dos insertaban. Con Mercado Pago reintentando webhooks eso deja de ser
 * teórico, y la auditoría agregaba una segunda mitad: faltaba una **clave de
 * idempotencia** en `pagos`, así que no había forma de distinguir un reintento de un
 * pago nuevo.
 *
 * Las dos mitades se cierran acá, y cada una con su mecanismo:
 *
 *   1. **El saldo se lee DESPUÉS de bloquear la venta**, dentro de la transacción. El
 *      lock de la fila de `ventas` es el candado de todo lo que es plata de esa
 *      venta: mientras una petición lo tiene, ninguna otra puede leer el saldo ni
 *      escribir un pago, porque las dos empiezan por el mismo lock. Que el candado
 *      sea la fila de `ventas` y no las de `pagos` no es un detalle: un pago que
 *      todavía no existe no se puede bloquear. Es el mismo razonamiento que
 *      `DireccionService`, que bloquea la fila del **cliente** para que el alta de la
 *      primera dirección también se serialice.
 *
 *      De eso se desprende una invariante que hay que respetar en todo lo que se
 *      agregue acá: **el `lockForUpdate()` tiene que ser la primera lectura de la
 *      venta dentro de la transacción.** Si antes hubiera una lectura sin lock, la
 *      transacción se quedaría con esa foto y el saldo leído después podría ser el de
 *      antes de que la otra petición insertara su pago. El test de los dos cobros
 *      concurrentes del paso 3 está justamente para que esto no sea una afirmación de
 *      docblock sino algo que se verifica.
 *
 *   2. **La idempotencia tiene dos niveles**, y cubren dos cosas distintas que
 *      conviene no confundir:
 *
 *      - **El mismo cobro dos veces** —doble clic, dos pestañas, el botón «atrás» y
 *        volver a enviar— lo ataja el estado: `pagada → pagada` no está declarado en
 *        `MaquinaEstadosVenta`, así que el segundo intento no llega a escribir nada.
 *        Es el cuarto agujero de C-9 haciendo el trabajo de C-10, y es el nivel que
 *        opera en la Etapa 1.
 *      - **El mismo pago externo dos veces** —el webhook de Mercado Paga que
 *        reintenta porque no recibió el 200— lo ataja `pagos.mp_payment_id`, que
 *        tiene índice único desde la Fase 1. `cobrar()` lo busca **dentro del lock**:
 *        si ese pago ya está acreditado, no escribe nada y devuelve la venta como
 *        está, que es la definición de idempotente —el mismo mensaje dos veces
 *        produce el mismo estado—. Sin eso, el índice único convertiría el reintento
 *        en un error 500 en lugar de en un no-op, y Mercado Pago seguiría
 *        reintentando.
 *
 *      **Por qué existe el parámetro de la clave si en la Etapa 1 nadie lo manda.**
 *      Es la única excepción que me permito a la regla de que un parámetro sin uso es
 *      decoración —la que hace que `cancelar()` y `marcarEnviada()` no reciban el
 *      usuario—, y la diferencia es que este parámetro **cambia el comportamiento del
 *      método** y cierra una mitad de un hallazgo nombrado. La columna existe desde
 *      la Fase 1 justamente para no pagar una migración después; tener la columna y
 *      ningún código que la lea dejaría el cierre de C-10 diciendo «el índice
 *      existe», que es lo que ya era verdad antes de esta fase. En la Etapa 1 el
 *      único que lo manda es el test, y el formulario lo tiene declarado
 *      `prohibited`: la pantalla del mostrador no es el webhook.
 *
 * **El cobro es por el total exacto.** Los pagos parciales están fuera del alcance, y
 * no es una simplificación arbitraria: la máquina de estados no tiene ningún estado
 * para «un presupuesto con plata adentro». `pendiente_pago` no sirve —no está
 * declarado desde `presupuesto` y significa otra cosa: que el checkout online cerró y
 * Mercado Pago no confirmó— así que una venta parcialmente pagada se quedaría en
 * `presupuesto`, que es el estado en el que la venta **se edita**: alguien podría
 * cambiarle las líneas, mover el total y dejar el saldo inconsistente, o recotizar
 * para abajo y quedar con saldo negativo. Admitirlo pide un valor más en el ENUM —que
 * reescribe la tabla— más bloquear la edición cuando hay pagos. Queda anotado en los
 * pendientes.
 *
 * Lo que **sí** se conserva es lo que la auditoría pedía conservar: `pagos` es una
 * tabla con **varias filas por venta**, porque un cobro se reparte entre medios de
 * pago —la venta 25 del dump original tenía transferencia más Mercado Pago—. Un cobro
 * de un solo monto exacto repartido en tres filas no es un pago parcial: es un pago,
 * con tres medios. Las tres filas se escriben en la misma transacción y la venta pasa
 * a `pagada` en esa misma transacción o en ninguna.
 *
 * **La dependencia va en una sola dirección, y tiene que seguir yendo en una sola
 * dirección.** Este servicio recibe `VentaService` porque el cobro termina en
 * `marcarPagada()`, que es donde se descuenta el stock: el descuento necesita las
 * líneas de la venta y el `origen` del movimiento de kardex **es** la venta, así que
 * no corresponde acá. Lo que no puede pasar es lo inverso: si `VentaService`
 * recibiera `PagoService`, el contenedor entraría en recursión infinita construyendo
 * los dos. Por eso la devolución —que cambia el estado de la venta y por lo tanto
 * vive en `VentaService`— va a escribir sus contra-asientos con el modelo `Pago`
 * directo, igual que `VentaService` ya escribe `VentaLinea` directo, y no pidiéndole
 * nada a este servicio.
 *
 * **Lo que este servicio no valida, a propósito: nada del medio de pago contra la
 * venta.** `pagos.metodo` es informativo —dice cómo entró la plata, no determina el
 * precio—, porque en el mostrador el precio es uno solo: `precio_contado`, con el
 * recargo de tarjeta aplicado por el posnet o arreglado informalmente. El sistema no
 * calcula lo que no controla, así que no hay nada que cruzar entre el método y el
 * total.
 *
 * Métodos:
 *   cobrar()  registra los medios de pago por el total exacto y pasa la venta a pagada
 */
class PagoService
{
    public function __construct(private VentaService $ventas)
    {
    }

    /**
     * Cobra una venta por el total exacto de su saldo.
     *
     * El orden de las seis cosas que hace está elegido y cada posición tiene su
     * motivo:
     *
     *   1. **Las comprobaciones sobre lo que llegó, afuera de la transacción.** Que
     *      los montos sean positivos y que no venga el mismo medio dos veces no
     *      depende de nada que otra petición pueda cambiar mientras tanto, así que no
     *      hay motivo para abrir una transacción para preguntarlo. Es la misma
     *      distinción que `VentaService::crear()` tiene escrita sobre el tope de
     *      descuento, y el otro lado de ella es el punto 4: el saldo **sí** depende
     *      del estado, y validarlo afuera es el hallazgo.
     *   2. **El lock, como primera lectura de la venta.**
     *   3. **La idempotencia, antes del estado.** Tiene que ir antes: en un reintento
     *      la venta ya está `pagada`, así que preguntar por el estado primero
     *      devolvería un error en lugar de un no-op, y el webhook seguiría
     *      reintentando para siempre.
     *   4. **El estado, preguntado a la tabla de transiciones.** No se compara contra
     *      `'presupuesto'` escrito a mano: se le pregunta a `MaquinaEstadosVenta` si
     *      desde donde está se puede llegar a `pagada`. Así la respuesta sale de la
     *      única fuente que la tiene, y cuando la Etapa 2 opere `pendiente_pago` este
     *      método no se toca.
     *   5. **El saldo, después del lock.** Esto es C-10.
     *   6. **Los pagos, y después el pase a `pagada`.** El pase descuenta el stock, y
     *      abre su propia transacción: en Laravel una anidada es un savepoint, así
     *      que si un producto no tiene disponible se revierten también los pagos que
     *      se acababan de escribir. Una venta no puede quedar cobrada sin stock
     *      descontado ni con stock descontado sin cobrar.
     *
     * **La comparación de importes se hace en centavos enteros.** Una igualdad de
     * dinero no se pregunta con `===` sobre flotantes, y la tolerancia de `0.001` que
     * tenía el plan original es la solución de alguien que ya sospechaba el problema.
     * El único lugar del sistema donde un flotante decide algo es el promedio
     * ponderado del costo, y ahí es inevitable porque un promedio es una división.
     *
     * @param  array{pagos: array<int, array{metodo: string, monto: float|string|null, mp_payment_id?: string|null}>}  $datos
     *
     * @throws ReglaDeNegocioException
     * @throws \App\Exceptions\TransicionInvalidaException
     * @throws \App\Exceptions\StockInsuficienteException
     */
    public function cobrar(Venta $venta, array $datos, User $usuario): Venta
    {
        $pagos = array_values(array_filter(
            $datos['pagos'] ?? [],
            fn ($pago) => is_array($pago) && filled($pago['monto'] ?? null),
        ));

        if ($pagos === []) {
            throw new ReglaDeNegocioException('Indicá el monto de al menos un medio de pago.');
        }

        // Punto 1: lo que no depende del estado se pregunta afuera.
        $this->exigirMediosDistintos($pagos);
        $this->exigirMontosPositivos($pagos);

        return DB::transaction(function () use ($venta, $pagos, $usuario) {
            // Punto 2. Primera lectura de la venta, y con lock. No agregar ninguna
            // lectura de esta venta por encima de esta línea.
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            // Punto 3. Un reintento del mismo pago externo no escribe nada.
            if ($this->esReintentoYaAcreditado($pagos)) {
                return $v;
            }

            // Punto 4. La pregunta se la hace la tabla, no un `if` con un literal.
            if (! MaquinaEstadosVenta::puede($v->estado, 'pagada')) {
                throw new ReglaDeNegocioException(
                    "La venta {$v->numeroFormateado()} está «{$v->estadoTexto()}» y en ese estado no se "
                    .'cobra. Una venta se cobra una sola vez: si hay que devolver lo que entró, el camino '
                    .'es la devolución.'
                );
            }

            // Punto 5. El saldo, DESPUÉS del lock. Acá está el hallazgo.
            $saldo       = $v->saldo();
            $enCentavos  = $this->centavos($saldo);
            $cobrado     = array_sum(array_map(
                fn (array $pago) => $this->centavos($pago['monto']),
                $pagos,
            ));

            if ($cobrado !== $enCentavos) {
                throw $this->desajusteDeSaldo($v, $saldo, $cobrado / 100);
            }

            // Punto 6. Las filas de pago.
            foreach ($pagos as $datosPago) {
                // De la fila sólo son asignables `metodo` y `monto`, que es lo único
                // que manda el formulario. El cajero, la fecha y la clave del webhook
                // se escriben por asignación directa: un `create()` con ellos adentro
                // los descartaría en silencio.
                $pago = $v->pagos()->make([
                    'metodo' => $datosPago['metodo'],
                    'monto'  => round((float) $datosPago['monto'], 2),
                ]);

                $pago->usuario_id = $usuario->id;

                // `fecha` es NOT NULL y sin DEFAULT, así que todo camino que escriba
                // un pago tiene que asignarla. Es la fecha del hecho y no la de la
                // fila: en el mostrador coinciden con `created_at`, y un webhook que
                // llega con atraso no.
                $pago->fecha = now();

                $pago->mp_payment_id = $datosPago['mp_payment_id'] ?? null;

                $pago->save();
            }

            // El pase a `pagada`, que es el que descuenta el stock exactamente una
            // vez. El usuario que viaja hasta el kardex es el cajero: la mercadería
            // salió por su cobro. Quién vendió sigue en `ventas.usuario_id`.
            return $this->ventas->marcarPagada($v, $usuario);
        });
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /**
     * ¿Es el mismo pago externo que ya se acreditó?
     *
     * Devuelve `true` sólo cuando **todas** las líneas traen clave y **todas** están
     * ya registradas: ése es el reintento del webhook, y lo correcto es no hacer nada.
     *
     * Una mezcla —algunas acreditadas y otras no— no es un reintento sino un mensaje
     * inconsistente, y se rechaza en lugar de escribir la mitad. Si se dejara pasar,
     * el `UNIQUE` de `mp_payment_id` abortaría el insert de la ya acreditada y el
     * reintento se convertiría en un error del servidor; y si se filtraran las ya
     * acreditadas para escribir sólo las nuevas, el total cobrado no coincidiría con
     * el saldo y el rechazo sería por el motivo equivocado.
     *
     * La búsqueda es **global y no por venta**: un `mp_payment_id` identifica un pago
     * en el mundo, no un pago de esta venta, y el índice único es global. Acotarla a
     * la venta dejaría pasar un mensaje que trae el pago de otra.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     *
     * @throws ReglaDeNegocioException
     */
    private function esReintentoYaAcreditado(array $pagos): bool
    {
        $claves = array_values(array_filter(array_map(
            fn (array $pago) => $pago['mp_payment_id'] ?? null,
            $pagos,
        )));

        if ($claves === []) {
            return false;   // cobro de mostrador: no hay clave que mirar
        }

        $acreditadas = Pago::query()
            ->whereIn('mp_payment_id', $claves)
            ->pluck('mp_payment_id')
            ->all();

        if ($acreditadas === []) {
            return false;
        }

        if (count($claves) === count($pagos) && count($acreditadas) === count($claves)) {
            return true;
        }

        throw new ReglaDeNegocioException(
            'El cobro trae medios de pago que ya están acreditados y otros que no. Un reintento '
            .'repite el mensaje completo; esto no es un reintento. No se registró nada.'
        );
    }

    /**
     * Un medio de pago por línea, con su monto total.
     *
     * Mismo criterio y mismo mensaje que `VentaService::exigirProductosDistintos()`:
     * no es un agujero de plata —la suma da lo mismo— pero es un documento con el
     * mismo renglón repetido, y el día que haya que revertirlo habría dos filas
     * candidatas para la misma cosa. El Form Request lo rechaza primero con
     * `distinct`, para que el error caiga en la línea; esto es la barrera que hereda
     * la API de la Etapa 3.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private function exigirMediosDistintos(array $pagos): void
    {
        $metodos = array_column($pagos, 'metodo');

        if (count($metodos) !== count(array_unique($metodos))) {
            throw new ReglaDeNegocioException(
                'El cobro tiene el mismo medio de pago cargado más de una vez. Dejá una sola línea '
                .'por medio, con el monto total.'
            );
        }
    }

    /**
     * Un cobro suma plata. Un monto negativo es otra cosa y tiene otro camino.
     *
     * @param  array<int, array<string, mixed>>  $pagos
     */
    private function exigirMontosPositivos(array $pagos): void
    {
        foreach ($pagos as $pago) {
            if ($this->centavos($pago['monto'] ?? 0) <= 0) {
                throw new ReglaDeNegocioException(
                    'El monto de un medio de pago tiene que ser mayor que cero. Un monto negativo es '
                    .'la reversión de un pago, y eso lo escribe la devolución, no el cobro.'
                );
            }
        }
    }

    /**
     * El mensaje del desajuste, que es distinto según de qué lado falle.
     *
     * Son dos problemas con dos salidas distintas y el usuario tiene que saber cuál
     * tiene. Que falte plata es el pago parcial, que está fuera del alcance; que
     * sobre es el vuelto, que el sistema no registra —se carga lo que vale la venta,
     * no lo que el cliente puso sobre el mostrador—.
     */
    private function desajusteDeSaldo(Venta $venta, float $saldo, float $cobrado): ReglaDeNegocioException
    {
        if ($cobrado < $saldo) {
            return new ReglaDeNegocioException(
                "El cobro tiene que ser por el total: la venta {$venta->numeroFormateado()} tiene un "
                .'saldo de '.Importe::pesos($saldo).' y estás cargando '.Importe::pesos($cobrado)
                .'. Faltan '.Importe::pesos(round($saldo - $cobrado, 2))
                .'. Los cobros en cuotas o en dos veces no están contemplados en esta etapa.'
            );
        }

        return new ReglaDeNegocioException(
            "Estás cargando ".Importe::pesos($cobrado)." y la venta {$venta->numeroFormateado()} tiene "
            .'un saldo de '.Importe::pesos($saldo).'. El sistema no registra el vuelto: cargá lo que '
            .'vale la venta, no lo que el cliente puso sobre el mostrador.'
        );
    }

    /**
     * El importe en centavos enteros, para poder comparar dos importes con `===`.
     *
     * El plan original comparaba con una tolerancia de `0.001`, que es lo que uno
     * escribe cuando ya sospecha que comparar flotantes está mal. En centavos el
     * problema no existe: la igualdad es exacta porque son enteros.
     */
    private function centavos(float|string $importe): int
    {
        return (int) round((float) $importe * 100);
    }
}