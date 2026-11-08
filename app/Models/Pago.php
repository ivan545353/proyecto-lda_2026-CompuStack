<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Pago: un movimiento de plata contra una venta.
 *
 * Es el documento que el sistema original tenía como tabla y operaba mal:
 * `SaleService::cobrar()` validaba el monto **fuera** de la transacción y recién
 * después abría el `FOR UPDATE`, así que dos peticiones concurrentes leían el mismo
 * saldo, las dos pasaban la validación y las dos insertaban (C-10). Esa corrección
 * no vive acá sino en `PagoService`; lo que vive acá es qué es un pago y qué no se
 * le puede hacer.
 *
 * **Un pago no se borra.** El evento `deleting` tira `LogicException`, igual que
 * `Venta` y `MovimientoStock`. Es `LogicException` y no una excepción de negocio
 * porque ninguna pantalla ofrece borrar un pago: si eso se dispara, lo que hay es un
 * error de programación. Y tiene el mismo límite que la guarda de `Venta` —un
 * borrado masivo por el query builder no dispara eventos de modelo—, así que lo que
 * garantiza de verdad es que nada lo ofrece; la guarda ataja el `$pago->delete()`,
 * que es la única forma en que alguien lo escribiría por accidente.
 *
 * `pagos.venta_id` tiene `cascadeOnDelete` declarado en el esquema y **no se alcanza
 * nunca**, porque no se puede borrar el padre. No hay que quitarlo: hay que saber que
 * está.
 *
 * **Lo que se deshace es con otro pago, no editando éste.** Una devolución escribe un
 * pago de monto **negativo** —el contra-asiento— en lugar de tocar la fila original.
 * Es el mismo criterio que `movimientos_stock.cantidad`, que guarda la cantidad con
 * signo: un asiento por hecho, y `SUM(monto)` sigue respondiendo cuánta plata quedó
 * de esta venta sin tener que filtrar nada. Esto es lo que cierra A-11 del lado del
 * dinero, y es de esta mitad de la fase.
 *
 * **El medio del contra-asiento lo elige quien devuelve, y no se copia del cobro.**
 * Es la única decisión de la devolución que no se deriva, y tiene su motivo: una
 * venta cobrada por transferencia se puede devolver en efectivo de la caja, y
 * escribir un negativo en transferencia afirmaría un hecho que no ocurrió. Cómo
 * volvió la plata es un dato del mundo que el operador conoce y el sistema no puede
 * deducir — el mismo argumento con el que la Fase 5 decidió no marcar una orden como
 * «enviada» sin un SMTP que lo respalde. La consecuencia es que el neto de un medio
 * puede quedar negativo en un período, y eso es correcto: es lo que un libro de caja
 * tiene que poder decir.
 *
 * **`$fillable` tiene dos campos, y uno de ellos es un importe. No es una
 * contradicción con `VentaLinea`.** El precio de una línea **no** es asignable porque
 * el servidor lo puede leer del catálogo, y confiar en el que manda el cliente es
 * manipulación de precios (M-14). El monto de un pago **sí** lo pone el usuario,
 * porque es un hecho del mundo que el sistema no tiene de dónde deducir: cuánta plata
 * entró lo sabe el cajero. Lo que el sistema hace con ese número no es reemplazarlo
 * sino **validarlo contra el saldo, después de bloquear la venta**, y eso es C-10.
 *
 * Todo lo demás lo escribe el servidor por asignación directa:
 *
 *   - `venta_id` lo pone la relación (`$venta->pagos()->create(...)`), no el
 *     formulario.
 *   - `usuario_id` es el cajero, y sale de la sesión. Es nullable porque un pago de
 *     Mercado Pago lo acredita un webhook y no una persona; en la Etapa 1 siempre
 *     está.
 *   - `fecha` es `NOT NULL` **y no tiene `DEFAULT`**, así que todo camino que escriba
 *     un pago tiene que asignarla. Existe al lado de `created_at` porque son dos
 *     cosas distintas: `fecha` es cuándo entró la plata y `created_at` cuándo se
 *     escribió la fila. En el mostrador coinciden; un webhook de Mercado Pago que
 *     llega con diez minutos de atraso, no. El documento imprime la fecha del hecho,
 *     nunca la de la fila.
 *   - `comision`, `neto_acreditado`, `cuotas`, `mp_payment_id` y `mp_status` son de
 *     la Etapa 2 y quedan nulas. `mp_payment_id` tiene índice único desde la Fase 1 y
 *     es el mecanismo de idempotencia frente a los reintentos de webhook: sin él, un
 *     reintento acredita el mismo pago dos veces.
 *
 * Por eso un `create()` con cualquiera de esos campos **los descarta en silencio**, y
 * hay un test que lo afirma. El `prohibited` que los rechaza y lo explica va en el
 * Form Request del paso 2, que es la capa que puede informar; acá abajo lo único que
 * corresponde es que no se puedan escribir.
 *
 * **No hay `PagoFactory`**, por el mismo motivo por el que no hay `VentaLineaFactory`:
 * un pago suelto, sin el descuento de stock y sin el cambio de estado de su venta, es
 * una fila que el sistema nunca produce. Lo que se prueba sobre el cobro se arma
 * llamando al servicio. Los pagos que escriben los tests de este paso son a mano y a
 * propósito, para probar justamente qué pasa cuando alguien escribe lo que no debe.
 *
 * **No hay scopes**, y es la única pieza del proyecto donde la ausencia necesita
 * explicación: `patron-modulo.md` pide un scope por filtro del listado, y los pagos
 * no tienen listado propio. Se ven en la ficha de la venta, que es el único lugar
 * donde la pregunta «qué pagos tiene esto» tiene sentido. El día que el panel pida
 * una caja diaria, ese listado traerá sus filtros y sus dos niveles de test.
 *
 * Relaciones:
 *   venta()    BelongsTo   el documento que se está cobrando
 *   usuario()  BelongsTo   el cajero que cobró; null si lo acreditó un webhook
 *
 * Métodos:
 *   metodoTexto()  el texto de pantalla del método
 */
class Pago extends Model
{
    /**
     * Métodos de cobro, con su texto para la pantalla. La clave es el valor del ENUM.
     *
     * Están los cuatro de la base. Igual que con `Venta::ESTADOS`, tener acá el texto
     * es lo que permite que la pantalla hable en lenguaje del negocio en vez de
     * mostrar el valor crudo de la columna.
     */
    public const METODOS = [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        'qr'            => 'QR',
        'mercadopago'   => 'Mercado Pago',
    ];

    /**
     * Los que la Etapa 1 ofrece, que son tres y no cuatro.
     *
     * `mercadopago` queda declarado en el ENUM y **no se ofrece**, por el mismo
     * motivo por el que la Fase 5 decidió no mandarle el correo al proveedor: el
     * sistema no afirma un hecho que no puede verificar. No hay integración con
     * Mercado Pago, así que un pago registrado con ese método tendría `comision` y
     * `neto_acreditado` en null, y «neto acreditado» es una métrica del panel: el
     * sistema estaría diciendo que cobró por un canal que no operó y que le
     * acreditaron un neto que no conoce.
     *
     * `qr` sí se ofrece, y la diferencia es exactamente esa: un cobro por QR es plata
     * que entró y que el cajero vio entrar, sin que el sistema tenga que saber nada
     * de la comisión. Lo que no se puede afirmar es la acreditación, no el cobro.
     *
     * El `METODOS_EN_USO` es el mismo recurso que `Venta::ESTADOS_EN_USO`: la base
     * declara el juego completo para no reescribir la tabla, y la pantalla ofrece lo
     * que hay.
     */
    public const METODOS_EN_USO = ['efectivo', 'transferencia', 'qr'];

    protected $table = 'pagos';

    /** Lo único que manda el formulario: con qué se pagó y cuánto. */
    protected $fillable = ['metodo', 'monto'];

    // No hay $attributes: ninguna columna de `pagos` tiene DEFAULT en la base, así
    // que no hay nada que replicar para que el objeto y la fila digan lo mismo.

    protected function casts(): array
    {
        return [
            'monto'           => 'decimal:2',
            'comision'        => 'decimal:2',
            'neto_acreditado' => 'decimal:2',
            'cuotas'          => 'integer',
            'fecha'           => 'datetime',
        ];
    }

    /**
     * La guarda de M-16 para un documento financiero.
     *
     * Un pago registra que entró plata. Si entró y se devolvió, eso son dos hechos y
     * el libro tiene que tener los dos: el original y su contra-asiento. Borrar el
     * primero sería afirmar que nunca pasó.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException(
                'Un pago no se borra: es un documento financiero. Lo que corresponde es registrar '
                .'la devolución, que escribe el pago en contra y deja los dos hechos a la vista.'
            );
        });
    }

    // ------------------------------------------------------------------
    // Relaciones
    // ------------------------------------------------------------------

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /**
     * El cajero que cobró.
     *
     * Separado del vendedor, que vive en `ventas.usuario_id`. Tener las dos cosas en
     * columnas distintas es lo que permite que el panel distinga quién vendió de
     * quién cobró (M-19): no hay un `created_by` genérico en ninguna tabla, la
     * autoría se guarda donde la pregunta aparece y con el nombre de lo que esa
     * persona hizo.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    public function metodoTexto(): string
    {
        return self::METODOS[$this->metodo] ?? $this->metodo;
    }
}