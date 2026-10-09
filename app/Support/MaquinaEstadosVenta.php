<?php

namespace App\Support;

use App\Exceptions\TransicionInvalidaException;
use App\Models\Venta;

/**
 * Transiciones permitidas de una venta. **Lo que no está acá, no se puede.**
 *
 * Es el cierre de C-9, que es el hallazgo más grave del módulo que «funcionaba
 * perfectamente». `SaleDao::updateEstado()` aceptaba cualquier transición: la única
 * lógica era una cadena de dos `if` —presupuesto a confirmada descontaba stock,
 * confirmada o cobrada a anulada reponía— y **cualquier otro caso actualizaba la
 * columna y nada más**. Los cuatro agujeros que la auditoría nombra uno por uno se
 * cierran todos con la misma cosa: una tabla sin huecos, en lugar de una cadena de
 * casos donde el que falta es el peligroso.
 *
 * Decisiones que están en esta tabla y en ningún otro lado:
 *
 *   - **`cancelada` sólo antes de cobrar**, desde `presupuesto` o
 *     `pendiente_pago`: antes de que exista comprobante y antes de que se haya
 *     movido stock o entrado plata. Una venta cobrada no se cancela, **se
 *     devuelve**, y la devolución es la que revierte los pagos y repone el stock.
 *     Acá es donde cambia de forma A-11: el original tenía `cobrada → anulada`,
 *     reponía stock y dejaba las filas de `pagos` intactas —plata cobrada sobre una
 *     venta inexistente—. La salida de una venta cobrada es `devuelta`, no
 *     `cancelada`, y eso es lo que obliga a que el camino pase por donde se revierte
 *     el dinero. Dos estados que significaran «deshecha» con mecánicas distintas
 *     serían peor que uno.
 *
 *   - **`pagada → pagada` NO está declarado.** Es el cuarto agujero de C-9:
 *     `confirmada → confirmada` volvía a descontar stock, porque el `if` no
 *     comparaba el origen. La idempotencia no se consigue con una bandera ni con un
 *     chequeo extra en el servicio: se consigue con que el destino no esté en la
 *     lista del origen.
 *
 *   - **`presupuesto` no llega a nada que no sea `pagada` o `cancelada`.** El
 *     `presupuesto → cobrada` del original marcaba la venta como cobrada **sin
 *     descontar stock y sin un solo pago registrado**, simplemente porque la cadena
 *     de `if` no contemplaba ese caso. Acá `presupuesto → entregada` y
 *     `presupuesto → en_preparacion` se rechazan: para entregar algo hay que haberlo
 *     cobrado, y cobrar es lo que descuenta el stock.
 *
 *   - **`cancelada` y `devuelta` no tienen salida.** `anulada → confirmada` revivía
 *     la venta sin volver a descontar stock, y `cobrada → confirmada` dejaba pagos
 *     registrados sobre una venta no cobrada. Los dos son listas vacías.
 *
 *   - **`presupuesto → pendiente_pago` no se declara**, aunque suene razonable. Un
 *     presupuesto que espera que el cliente vuelva a pagar sigue siendo un
 *     presupuesto; `pendiente_pago` significa algo más preciso: que el checkout
 *     online ya se cerró y Mercado Pago todavía no confirmó. Son dos situaciones
 *     distintas y la segunda es de la Etapa 2. Declararlas iguales dejaría una venta
 *     de mostrador esperando un webhook que nunca va a llegar.
 *
 *   - **`devuelta_parcial → devuelta_parcial` está permitido**, porque una segunda
 *     devolución parcial deja la venta en el mismo estado. Es el caso de quien
 *     devuelve el mouse en enero y la fuente en marzo. Mismo criterio que
 *     `recibida_parcial` en compras.
 *
 *   - **Que una devolución sea parcial o total no lo elige el operador**: se deriva
 *     de cuánto se devolvió, comparando `cantidad_devuelta` contra `cantidad` en cada
 *     línea. La tabla sólo declara que los dos destinos existen; cuál de los dos
 *     corresponde lo decide el servicio, en la segunda mitad de la fase.
 *
 *   - **`entregada_parcial` no figura en la tabla**, y no es un olvido. El valor está
 *     declarado en el ENUM de la base porque es la única pieza de la venta con
 *     faltante que no sería barata de agregar después, pero la venta con faltante
 *     está fuera del alcance de la Etapa 1: ninguna transición lo alcanza y, por el
 *     `?? []` del lookup, tampoco sale de ahí. Un estado declarado e inalcanzable es
 *     más honesto que una transición que ninguna pantalla puede continuar.
 *
 *   - **El flujo online está declarado completo** —`en_preparacion`, `despachada`,
 *     `lista_retiro`— aunque la Etapa 1 no lo opere. La tabla es un dato, no un
 *     camino: declarar una transición no construye quién la dispare, y ninguna
 *     pantalla de la Etapa 1 ofrece esas acciones. Escribir la tabla entera de una
 *     vez es lo que evita volver a abrirla con media decisión tomada.
 *
 *   - **Los dos destinos de la devolución se preguntan juntos**, con
 *     `puedeDevolver()`. Hace falta porque el servicio tiene que validar que la venta
 *     admite una devolución **antes** de reponer una sola unidad, y en ese momento
 *     todavía no sabe cuál de los dos destinos le va a tocar: eso se deriva de las
 *     cantidades recién cuando están aplicadas. Es el equivalente de
 *     `MaquinaEstadosCompra::puedeRecibir()`, con una diferencia a favor: allá los
 *     estados que reciben están en una constante aparte, que es una segunda fuente
 *     para la misma pregunta y puede desincronizarse de la tabla; acá se le pregunta
 *     a la tabla.
 * 
 * 
 * El `?? []` del lookup es la forma de C-2 en este rincón del sistema: un estado que
 * no está en la tabla no concede por descarte. Si mañana alguien agrega un valor al
 * ENUM y se olvida de la tabla, ese estado no habilita nada en lugar de habilitar
 * todo.
 */
final class MaquinaEstadosVenta
{
    /** @var array<string, array<int, string>> */
    private const TRANSICIONES = [
        'presupuesto'      => ['pagada', 'cancelada'],
        'pendiente_pago'   => ['pagada', 'cancelada'],
        'pagada'           => ['en_preparacion', 'entregada', 'devuelta_parcial', 'devuelta'],
        'en_preparacion'   => ['despachada', 'lista_retiro'],
        'despachada'       => ['entregada'],
        'lista_retiro'     => ['entregada'],
        'entregada'        => ['devuelta_parcial', 'devuelta'],
        'devuelta_parcial' => ['devuelta_parcial', 'devuelta'],
        'cancelada'        => [],
        'devuelta'         => [],
    ];

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    /** @throws TransicionInvalidaException */
    public static function validar(string $desde, string $hacia): void
    {
        if (! self::puede($desde, $hacia)) {
            $textoDesde = Venta::ESTADOS[$desde] ?? $desde;
            $textoHacia = Venta::ESTADOS[$hacia] ?? $hacia;

            throw new TransicionInvalidaException(
                "Una venta «{$textoDesde}» no puede pasar a «{$textoHacia}»."
            );
        }
    }

    /**
     * Los destinos posibles desde un estado.
     *
     * La usa la ficha para ofrecer sólo lo que existe, igual que en compras, y la
     * usa el test para verificar que la tabla no nombre ningún estado que el modelo
     * no conozca —un destino mal escrito permitiría una transición hacia un estado
     * que después ninguna pantalla sabe nombrar, y nada más fallaría—.
     *
     * @return array<int, string>
     */
    public static function destinosDesde(string $estado): array
    {
        return self::TRANSICIONES[$estado] ?? [];
    }

        /**
     * ¿Desde este estado se puede registrar una devolución?
     *
     * Devuelve verdadero si alguno de los dos destinos es alcanzable, porque cuál de
     * los dos corresponde **no lo elige el operador**: se deriva de comparar
     * `cantidad_devuelta` contra `cantidad` en cada línea, y eso sólo se sabe después
     * de aplicar las cantidades. El servicio pregunta esto primero para no reponer
     * stock de una venta que después no va a poder cambiar de estado.
     */
    public static function puedeDevolver(string $estado): bool
    {
        return self::puede($estado, 'devuelta_parcial') || self::puede($estado, 'devuelta');
    }
}