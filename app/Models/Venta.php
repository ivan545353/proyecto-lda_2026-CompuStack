<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Venta: el documento comercial, del presupuesto a la entrega.
 *
 * Es el módulo que más se reescribe respecto del original, donde `SaleDao` aceptaba
 * cualquier transición de estado (C-9), validaba el monto del pago fuera de la
 * transacción (C-10) y borraba la venta con un `DELETE` plano sobre una tabla con
 * `ON DELETE CASCADE` (M-16).
 *
 * **El número de venta es el `id`**, formateado `V-00042`. No hay columna `numero`,
 * y es el cierre de A-18: el original tenía `ventas.numero` sin índice único y
 * `venta_numeracion`, una tabla de una sola fila sin clave primaria, para generar
 * un número que tampoco garantizaba único. La clave primaria ya lo garantiza por
 * construcción, sin contador, sin tabla auxiliar y sin condición de carrera. La
 * numeración fiscal —correlativa, sin huecos, por punto de venta y tipo de
 * comprobante— la gobierna AFIP y vive en `comprobantes`, en la Etapa 2. Es el
 * mismo criterio que `OrdenCompra`.
 *
 * **Una venta no se borra.** El evento `deleting` lo impide, igual que en
 * `MovimientoStock`: es el núcleo de M-16. La guarda tiene un límite que conviene
 * saber —un borrado masivo por el query builder no dispara eventos—, así que lo que
 * garantiza de verdad es que ninguna ruta, ningún controlador y ningún servicio
 * ofrecen borrar; la guarda ataja el `$venta->delete()`, que es la única forma en
 * que alguien lo escribiría por accidente.
 *
 * **Qué queda fuera de `$fillable` y por qué.** `estado` lo mueve sólo la máquina de
 * estados, así que no puede llegar en una petición. `subtotal`, `descuento` y
 * `total` son derivados: se recalculan desde las líneas al guardar, y el descuento
 * se calcula en el servidor a partir del porcentaje con su tope por rol (A-12) —el
 * original dejaba que el cliente fijara `descuentoPorcentaje` y validaba sólo que
 * estuviera entre 0 y 100, y en el dump hay una venta al 50 %—. `canal` y
 * `modo_entrega` están fijos en la Etapa 1: mostrador y retiro, porque la tienda y
 * la tabla `envios` son de la Etapa 2 y una venta con envío no tendría dónde
 * guardar la dirección ni con qué cotizar el costo. Los cinco van en `$attributes`
 * con el mismo valor que el `DEFAULT` de la base, para que el objeto y la fila digan
 * lo mismo desde antes de guardar.
 *
 * **El saldo se calcula en un solo lugar, y eso es parte de C-10.** `pagado()` suma
 * los pagos en la base y `saldo()` resta contra el total. Los leen el servicio del
 * cobro, el Form Request y la pantalla, y si cada uno hiciera su propia cuenta se
 * desincronizarían — el mismo argumento por el que `VentaService::topeDeDescuento()`
 * es público.
 *
 * Los dos son lecturas y se pueden llamar desde cualquier parte. Lo que **no** es
 * indiferente es *cuándo*: `PagoService::cobrar()` bloquea la fila de la venta y
 * recién después pregunta el saldo. Leerlo antes del lock es exactamente el hallazgo:
 * `SaleService::cobrar()` comparaba el monto contra el saldo fuera de la transacción
 * y abría el `FOR UPDATE` recién al insertar, así que dos peticiones concurrentes
 * pasaban las dos la validación. La fila de `ventas` es el candado de todo lo que es
 * plata de esta venta, y no las filas de `pagos`: un pago que todavía no existe no se
 * puede bloquear. Es el mismo razonamiento que `DireccionService`, que bloquea la fila
 * del cliente y no las de las direcciones para que el alta de la primera también se
 * serialice.
 *
 * Relaciones:
 *   cliente()      BelongsTo   a quién se le vende; null = consumidor final
 *   usuario()      BelongsTo   el vendedor; null en las ventas online de la Etapa 2
 *   lineas()       HasMany     qué se vendió, con el precio congelado
 *   pagos()        HasMany     con qué se cobró; el cajero vive en la fila del pago
 *   movimientos()  MorphMany   lo que salió del depósito por esta venta
 *
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * VentaFiltroRequest):
 *   ?q=            → buscar($texto)        número de venta o razón social del cliente
 *   ?estado=       → conEstado($estado)    lista cerrada de ESTADOS
 *   ?cliente_id=   → deCliente($id)
 *   ?vendedor_id=  → deVendedor($id)       quién vendió, no quién cobró
 *   ?desde=        → desde($fecha)         por fecha de emisión, inclusive
 *   ?hasta=        → hasta($fecha)
 *
 * Métodos:
 *   numeroDe()             el formato del número, para quien tiene el id y no el modelo
 *   numeroFormateado()     V-00042
 *   estadoTexto()          el texto de pantalla del estado
 *   estadoClase()          la clase del badge; el texto viaja siempre con él
 *   esPresupuesto()        el único estado en el que la venta se edita
 *   descuentoPorcentaje()  el porcentaje, derivado del monto guardado
 *   pagado()               cuánta plata quedó de esta venta, sumada en la base
 *   saldo()                lo que falta cobrar; se lee DESPUÉS del lock
 *   tienePendientesDeDevolver()  si queda algo sin devolver; de acá sale el estado
 *   clienteTexto()         «Consumidor final» cuando no hay cliente

 */
class Venta extends Model
{
    use HasFactory;

    /**
     * Estados, con su texto para la pantalla. La clave es el valor del ENUM.
     *
     * Están los once aunque la Etapa 1 use cuatro —presupuesto, pagada, entregada y
     * cancelada—. Los de la tienda online y las devoluciones ya están declarados en
     * la base porque modificar un ENUM reescribe la tabla; tener acá su texto es lo
     * que permite que `MaquinaEstadosVenta` escriba sus mensajes en lenguaje del
     * negocio en vez de nombrar el valor crudo de la columna.
     */
    public const ESTADOS = [
        'presupuesto'       => 'Presupuesto',
        'pendiente_pago'    => 'Pendiente de pago',
        'pagada'            => 'Pagada',
        'en_preparacion'    => 'En preparación',
        'despachada'        => 'Despachada',
        'lista_retiro'      => 'Lista para retirar',
        'entregada_parcial' => 'Entregada parcial',
        'entregada'         => 'Entregada',
        'cancelada'         => 'Cancelada',
        'devuelta_parcial'  => 'Devuelta parcial',
        'devuelta'          => 'Devuelta',
    ];

    public const ESTADOS_EN_USO = [
        'presupuesto', 'pagada', 'entregada', 'cancelada', 'devuelta_parcial', 'devuelta',
    ];

    // Los estados en los que el cobro ya ocurrió: de acá sale todo lo que el panel
    // cuenta como vendido (A-26). Incluye los de la Etapa 2 para que el día que se
    // alcancen el panel no se quede corto en silencio.
    public const ESTADOS_VENDIDOS = [
        'pagada', 'en_preparacion', 'despachada', 'lista_retiro',
        'entregada_parcial', 'entregada', 'devuelta_parcial', 'devuelta',
    ];

    // Los otros tres: la plata no entró. PanelServiceTest afirma que los dos juegos
    // parten ESTADOS sin superponerse, así que agregar un valor al ENUM rompe el test
    // en vez de contarse solo.
    public const ESTADOS_SIN_COBRO = ['presupuesto', 'pendiente_pago', 'cancelada'];

    protected $table = 'ventas';

    protected $fillable = ['cliente_id', 'usuario_id', 'observaciones'];

    protected $attributes = [
        'canal'        => 'mostrador',
        'estado'       => 'presupuesto',
        'modo_entrega' => 'retiro',
        'subtotal'     => 0,
        'descuento'    => 0,
        'costo_envio'  => 0,
        'total'        => 0,
    ];

    protected function casts(): array
    {
        return [
            'subtotal'    => 'decimal:2',
            'descuento'   => 'decimal:2',
            'costo_envio' => 'decimal:2',
            'total'       => 'decimal:2',
        ];
    }

    /**
     * El núcleo de M-16, en el modelo.
     *
     * `SaleDao::delete()` era un `DELETE` plano y `detalle_ventas` tenía
     * `ON DELETE CASCADE`: borrar una venta borraba su historial completo. Las
     * cascadas del esquema nuevo siguen declaradas, pero no se alcanzan nunca,
     * porque no hay forma de borrar el padre.
     *
     * `LogicException` y no `ReglaDeNegocioException`: no es un error que el usuario
     * pueda corregir, porque ninguna pantalla ofrece borrar una venta. Si esto se
     * dispara, lo que hay es un error de programación. Mismo criterio que
     * `MovimientoStock`.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException(
                'Una venta no se borra: es un documento comercial. Lo que corresponde es cancelarla '
                .'antes de que exista comprobante, o devolverla después.'
            );
        });
    }

    // ------------------------------------------------------------------
    // Relaciones
    // ------------------------------------------------------------------

    /** Null significa consumidor final: la venta rápida de mostrador no identifica a nadie. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** El vendedor. Separado del cajero que cobra, que vive en `pagos.usuario_id`. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(VentaLinea::class, 'venta_id');
    }
    
    /**
     * Con qué se cobró.
     *
     * Son varias filas por venta aunque los pagos parciales estén fuera del alcance,
     * y por dos motivos que no dependen de eso: un cobro puede repartirse entre
     * métodos —la venta 25 del dump original tenía transferencia más Mercado Pago— y
     * una devolución agrega el pago en contra sin tocar el original. La tabla aparte
     * la justifica además `mp_payment_id`, que es la clave de idempotencia del
     * webhook (C-10), y `comision`/`neto_acreditado`, que son datos del pago y no de
     * la venta: se factura $100.000 y se acreditan $93.000.
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'venta_id');
    }

    /** Lo que salió del depósito por esta venta, con su fecha y su usuario. */
    public function movimientos(): MorphMany
    {
        return $this->morphMany(MovimientoStock::class, 'origen');
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * El formato del número, a partir del id.
     *
     * Es estático porque hay un lugar que tiene el id y no el modelo: el kardex
     * guarda `origen_id` y nombra el documento sin cargarlo, y no vale la pena una
     * consulta para armar un texto. Teniendo el formato en un solo lugar, la ficha y
     * el kardex no se pueden desincronizar.
     */
    public static function numeroDe(int|string $id): string
    {
        return 'V-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function numeroFormateado(): string
    {
        return self::numeroDe($this->id);
    }

    public function estadoTexto(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

        public function esPresupuesto(): bool
    {
        return $this->estado === 'presupuesto';
    }

    // Una venta cancelada no se imprime: es un presupuesto que no se concretó y el
    // papel no va a ir a ningún cliente. Mismo criterio que OrdenCompra, donde la
    // condición es haber tenido aprobación.
    public function sePuedeImprimir(): bool
    {
        return $this->estado !== 'cancelada';
    }

        /**
     * El porcentaje de descuento que se cargó, derivado del monto.
     *
     * `ventas.descuento` guarda un **monto**, que es lo que la columna es y lo que
     * el documento declara. Pero el vendedor lo carga como porcentaje, así que el
     * formulario de edición necesita mostrarle el porcentaje y no el monto, y
     * `recotizar()` necesita conservar el porcentaje —un 10 % sigue siendo 10 %
     * sobre el subtotal nuevo— y no el monto.
     *
     * La recuperación es exacta a dos decimales para cualquier subtotal realista: el
     * error que introduce redondear el monto es como mucho medio centavo, así que al
     * volver a porcentaje queda por debajo de 0,01 punto para cualquier subtotal por
     * encima de unos cincuenta pesos. Y es idempotente: recalcular el monto con el
     * porcentaje recuperado da el mismo monto, así que abrir el formulario y guardar
     * sin tocar nada no cambia la venta.
     *
     * Subtotal cero devuelve cero en lugar de dividir por cero. No puede pasar por
     * el camino normal —una venta sin líneas se rechaza— pero el método no puede
     * depender de eso.
     */
    public function descuentoPorcentaje(): float
    {
        $subtotal = (float) $this->subtotal;

        return $subtotal > 0
            ? round((float) $this->descuento * 100 / $subtotal, 2)
            : 0.0;
    }

    /**
     * Cuánta plata quedó de esta venta.
     *
     * **La suma la hace la base** y no PHP (A-26): no hace falta traer las filas de
     * pagos para sumarlas, igual que `recalcularTotales()` suma las líneas con un
     * `sum()` en lugar de recorrer una colección.
     *
     * Los pagos en contra de una devolución entran en la misma suma con su signo, así
     * que este número no es «lo que se cobró alguna vez» sino **lo que queda**: una
     * venta cobrada y devuelta por completo vuelve a dar cero, y por eso el saldo
     * vuelve a ser el total. Es lo que hace que el contra-asiento no necesite ninguna
     * columna ni ningún filtro: la pregunta se responde sumando.
     *
     * Devuelve `float` y no string, igual que `recalcularTotales()`: el agregado de la
     * base es un número, el redondeo se hace antes de que el valor toque una columna
     * `decimal(12,2)`, y el flotante sólo existe entre la cuenta y el redondeo. La
     * regla de A-17 es sobre cómo se guarda un importe, no sobre cómo se suma.
     */
    public function pagado(): float
    {
        return round((float) $this->pagos()->sum('monto'), 2);
    }

    /**
     * Lo que falta cobrar.
     *
     * **Quien decide sobre este número tiene que haber bloqueado la venta primero.**
     * La pantalla lo muestra sin lock, y está bien: mostrar un saldo que puede quedar
     * viejo un segundo después no hace daño, y el servidor lo vuelve a mirar. Lo que
     * no se puede es validar un monto contra esto fuera de la transacción, que es
     * literalmente C-10.
     *
     * Y dentro de la transacción, el orden importa: el `lockForUpdate()` sobre la
     * venta tiene que ser lo **primero** que se lea de ella. Si antes hubiera una
     * lectura sin lock, la transacción se quedaría con esa foto y el saldo leído
     * después podría ser el de antes de que la otra petición insertara su pago.
     */
    public function saldo(): float
    {
        return round((float) $this->total - $this->pagado(), 2);
    }
    
        /**
     * ¿Queda algo sin devolver?
     *
     * De acá sale el estado de la devolución, y por eso es un método del modelo y no
     * un `if` del servicio: **que una devolución sea parcial o total no lo elige el
     * operador**, se deriva de comparar `cantidad_devuelta` contra `cantidad` en cada
     * línea. Es el mismo criterio y el mismo espejo que
     * `OrdenCompra::tienePendientes()` con la recepción parcial.
     *
     * Lee la colección cargada y no la relación, igual que su equivalente de
     * compras, así que quien la llame después de escribir las líneas tiene que
     * pedirla fresca: `$v->fresh('lineas')->tienePendientesDeDevolver()`.
     */
    public function tienePendientesDeDevolver(): bool
    {
        return $this->lineas->contains(
            fn (VentaLinea $linea) => $linea->cantidadPendienteDeDevolver() > 0
        );
    }
    /**
     * A quién se le vendió, en palabras.
     *
     * `cliente_id` en null **significa** consumidor final: es la venta rápida de
     * mostrador que no identifica a nadie, no un dato que falte. La pantalla lo dice
     * con palabras en lugar de mostrar una celda vacía, igual que
     * `OrdenCompra::fueGeneradaPorElSistema()`.
     */
    public function clienteTexto(): string
    {
        return $this->cliente?->razon_social ?? 'Consumidor final';
    }

    /**
     * Clase del badge de estado.
     *
     * Vive en el modelo y no en la vista porque el listado y la ficha tienen que
     * pintar lo mismo, y dos `match` en dos archivos se desincronizan. El texto viaja
     * siempre junto al badge: el color acompaña, no comunica solo.
     *
     * La convención es la misma que en `OrdenCompra`, que es el pendiente #12 de
     * usabilidad atendido de entrada en lugar de dejarlo para el final: lo que está
     * empezando va en claro, lo que está comprometido en oscuro, lo que volvió para
     * atrás en ámbar, lo que terminó bien en verde y lo que no llegó a pasar en gris.
     */
    public function estadoClase(): string
    {
        return match ($this->estado) {
            'presupuesto'    => 'text-bg-light border',
            'pendiente_pago' => 'text-bg-warning',
            'pagada', 'en_preparacion', 'despachada', 'lista_retiro' => 'text-bg-dark',
            'entregada'      => 'text-bg-success',
            'entregada_parcial', 'devuelta_parcial', 'devuelta' => 'text-bg-warning',
            'cancelada'      => 'text-bg-secondary',
            default          => 'text-bg-light border',
        };
    }

     // ------------------------------------------------------------------
    // Filtros
    //
    // Un scope por parámetro de la URL, con el mismo nombre declarado acá y en
    // VentaFiltroRequest. Es la corrección de fondo de A-24: el original mandaba
    // filtros que el DAO no leía y nunca funcionaron, porque no había ningún lugar
    // donde constara qué filtros acepta el listado.
    //
    // Las columnas van calificadas con `ventas.` aunque hoy no haya ningún join:
    // `buscar()` arma una subconsulta sobre `clientes`, y una columna sin calificar
    // en ese contexto es una ambigüedad esperando a que alguien agregue un join.
    // ------------------------------------------------------------------

    /**
     * Busca por número de venta o por razón social del cliente.
     *
     * El número se escribe de varias formas —«V-00042», «00042», «42»—, así que se
     * buscan los dígitos del texto contra el `id`. Si no hay dígitos, esa rama no se
     * agrega y la búsqueda es sólo por nombre.
     *
     * No busca por documento, a propósito: la búsqueda por documento vive en el
     * listado de clientes, que es donde uno tiene el documento en la mano, y desde
     * la ficha del cliente se llega a su historial. Tenerla también acá agregaría
     * coincidencias raras —un «24» buscado como número de venta encontrando un CUIT
     * que contiene 24— sin resolver ninguna pregunta nueva.
     *
     * **Los dos niveles de agrupamiento son necesarios y por motivos distintos.**
     * El de afuera, porque sin él encadenar `?estado=` después produce
     * `cliente LIKE … OR (id = … AND estado = …)` y la rama del nombre devolvería
     * ventas que no cumplen el filtro de estado. El de adentro del `whereHas`,
     * porque Laravel agrega la condición de la relación a la misma subconsulta: un
     * `orWhere` suelto ahí daría `(clientes.id = ventas.cliente_id AND … ) OR …`, y
     * la subconsulta devolvería filas para cualquier cliente que coincida, así que
     * el `exists` daría verdadero hasta para una venta a consumidor final. Hay un
     * test para cada uno.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $digitos = preg_replace('/\D/', '', (string) $texto);

            return $query->where(function (Builder $query) use ($texto, $digitos) {
                $query->whereHas('cliente', fn (Builder $cliente) => $cliente
                    ->where('razon_social', 'like', Like::contiene($texto)));

                if ($digitos !== '') {
                    $query->orWhere('ventas.id', (int) $digitos);
                }
            });
        });
    }

    /**
     * Filtro por estado.
     *
     * Se compara contra las claves de ESTADOS y no con `filled()`: un estado
     * inexistente no filtra nada, en lugar de devolver un listado vacío que
     * parecería estar diciendo «no hay ventas en ese estado».
     */
    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return $query->when(
            array_key_exists((string) $estado, self::ESTADOS),
            fn (Builder $query) => $query->where('ventas.estado', $estado),
        );
    }

    public function scopeDeCliente(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('ventas.cliente_id', (int) $id),
        );
    }

    /**
     * Quién vendió.
     *
     * El parámetro de la URL es `?vendedor_id=` y no `?usuario_id=` aunque la columna
     * se llame así: en este listado «usuario» es ambiguo —la venta tiene un vendedor
     * y el pago va a tener un cajero, los dos `users`— y el filtro habla del
     * vendedor. Es la regla de lenguaje del negocio aplicada a la URL, que también
     * la lee una persona.
     */
    public function scopeDeVendedor(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('ventas.usuario_id', (int) $id),
        );
    }

    /**
     * Desde la fecha, inclusive.
     *
     * Ver el comentario de `MovimientoStock::scopeDesde()`: los dos extremos usan
     * `whereDate()` para que el rango signifique días completos. Con
     * `created_at <= '2026-03-15'` se perdería todo lo del día 15 después de
     * medianoche, que es casi todo el día.
     */
    public function scopeDesde(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('ventas.created_at', '>=', $fecha),
        );
    }

    /** Hasta la fecha, inclusive. Ver el comentario de scopeDesde(). */
    public function scopeHasta(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('ventas.created_at', '<=', $fecha),
        );
    }
}