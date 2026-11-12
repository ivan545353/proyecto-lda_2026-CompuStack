<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Venta: el documento comercial, del presupuesto a la entrega.
 * 
 * Relaciones:
 *   cliente()      BelongsTo   a quién se le vende; null = consumidor final
 *   usuario()      BelongsTo   el vendedor; null en las ventas online de la Etapa 2
 *   lineas()       HasMany     qué se vendió, con el precio congelado
 *   pagos()        HasMany     con qué se cobró; el cajero vive en la fila del pago
 *   movimientos()  MorphMany   lo que salió del depósito por esta venta
 *   carrito()      BelongsTo   el carrito de la tienda del que nació; null en el mostrador
 *   intentosPago() HasMany     los intentos de pago por pasarela (tramo G)
 *
 * Pertenencia (API): delCliente($cliente)   los pedidos de la tienda de ese cliente; siempre filtra
 *
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * VentaFiltroRequest):
 *   ?q=            → buscar($texto)        número de venta o razón social del cliente
 *   ?estado=       → conEstado($estado)    lista cerrada de ESTADOS
 *   ?cliente_id=   → deCliente($id)
 *   ?vendedor_id=  → deVendedor($id)       quién vendió, no quién cobró
 *   ?canal=        → deCanal($canal)       mostrador u online
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
 *   situacionDelPago()     la situación del pago, derivada; elige el texto para el cliente
 *   estadoDelPago()        aprobado, pendiente, en_revision, rechazado o sin_cobrar (tramo G)
 *   tienePendientesDeDevolver()  si queda algo sin devolver; de acá sale el estado
 *   clienteTexto()         «Sin identificar» cuando no hay cliente

 */
class Venta extends Model
{
    use HasFactory;

    /**
     * Estados, con su texto para la pantalla. La clave es el valor del ENUM.
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

    // `pendiente_pago` entra con la tienda (tramo F, D-2): el cajero tiene que poder filtrar los pedidos.
    public const ESTADOS_EN_USO = [
        'presupuesto', 'pendiente_pago', 'pagada', 'entregada_parcial', 'entregada', 'cancelada', 'devuelta_parcial', 'devuelta',
    ];

    public const ESTADOS_VENDIDOS = [
        'pagada', 'en_preparacion', 'despachada', 'lista_retiro',
        'entregada_parcial', 'entregada', 'devuelta_parcial', 'devuelta',
    ];

    public const ESTADOS_SIN_COBRO = ['presupuesto', 'pendiente_pago', 'cancelada'];

    /** Los valores del ENUM `canal`, con su texto para la pantalla. */
    public const CANALES = [
        'mostrador' => 'Mostrador',
        'online'    => 'Tienda online',
    ];

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
            'reserva_vence' => 'datetime',
        ];
    }

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
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'venta_id');
    }

    /** Cada vez que mercadería de esta venta salió del local, con su comprobante. */
    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class, 'venta_id')->orderBy('id');
    }


    /** El carrito de la tienda del que nació el pedido (D-8). */
    public function carrito(): BelongsTo
    {
        return $this->belongsTo(Carrito::class, 'carrito_id');
    }

    /** Cada intento de pagar el pedido por una pasarela (tramo G). */
    public function intentosPago(): HasMany
    {
        return $this->hasMany(IntentoPago::class, 'venta_id');
    }

    /** El último intento: decide el estado del pago. Relación, para leerlo una vez por pedido. */
    public function ultimoIntentoPago(): HasOne
    {
        return $this->hasOne(IntentoPago::class, 'venta_id')->latestOfMany('id');
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

    public function sePuedeImprimir(): bool
    {
        return $this->estado !== 'cancelada';
    }

    /**
     * El porcentaje de descuento que se cargó, derivado del monto.
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
     */
    public function pagado(): float
    {
        return round((float) $this->pagos()->sum('monto'), 2);
    }

    /**
     * Lo que falta cobrar.
     */
    public function saldo(): float
    {
        return round((float) $this->total - $this->pagado(), 2);
    }
    
    /**
     * En qué situación está el pago del pedido, derivada de los datos (regla 9, plan tramo G).
     * Es más fina que `estadoDelPago()`: las tres formas de «pendiente» se dicen distinto.
     *
     * @return 'pagado'|'contado'|'esperando'|'en_revision'|'rechazado'|'falta_pagar'|'sin_cobrar'
     */
    public function situacionDelPago(): string
    {
        if (in_array($this->estado, self::ESTADOS_VENDIDOS, true)) {
            return 'pagado';
        }

        if ($this->estado === 'cancelada') {
            return 'sin_cobrar';
        }

        if ($this->medio_pago === 'contado') {
            return 'contado';
        }

        $ultimo = $this->ultimoIntentoPago;

        return match (true) {
            $ultimo === null                  => 'falta_pagar',
            $ultimo->estado === 'pendiente'   => 'esperando',
            $ultimo->aprobadoSinAcreditar()   => 'en_revision',
            $ultimo->estado === 'rechazado'   => 'rechazado',
            default                           => 'falta_pagar',   // vencido o con error
        };
    }

    /** @return 'aprobado'|'pendiente'|'en_revision'|'rechazado'|'sin_cobrar' */
    public function estadoDelPago(): string
    {
        return match ($this->situacionDelPago()) {
            'pagado'                            => 'aprobado',
            'contado', 'esperando', 'falta_pagar' => 'pendiente',
            'en_revision'                       => 'en_revision',
            'rechazado'                         => 'rechazado',
            'sin_cobrar'                        => 'sin_cobrar',
        };
    }

    /**
     * ¿Queda algo sin devolver?
     */
    public function tienePendientesDeDevolver(): bool
    {
        return $this->lineas->contains(
            fn (VentaLinea $linea) => $linea->cantidadPendienteDeDevolver() > 0
        );
    }

    /** El único lugar que decide si una venta quedó entregada del todo o en parte (invariante 9). */
    public function estadoDeEntrega(): string
    {
        return $this->lineas->contains(fn (VentaLinea $linea) => $linea->cantidadPendienteDeEntregar() > 0)
            ? 'entregada_parcial'
            : 'entregada';
}

    /**
     * Los renglones que piden más unidades de las que hay disponibles hoy. Un pedido con reserva
     * cuenta las suyas como propias: si no, avisaría faltantes de lo que él mismo retiene (§1.3 C).
     *
     * @return Collection<int, VentaLinea>
     */
    public function faltantesDeStock(): Collection
    {
        $propias = $this->reserva_vence !== null;

        return $this->lineas
            ->filter(fn (VentaLinea $linea) => $linea->producto !== null
                && $linea->cantidad > $linea->producto->stock_disponible + ($propias ? $linea->cantidad : 0))
            ->values();
    }

    public function canalTexto(): string
    {
        return self::CANALES[$this->canal] ?? $this->canal;
    }

    public function esOnline(): bool
    {
        return $this->canal === 'online';
    }

    /**
     * A quién se le vendió, para la pantalla y para el documento.
     */
    public function clienteTexto(): string
    {
        return $this->cliente?->razon_social ?? 'Sin identificar';
    }

    /**
     * Clase del badge de estado.
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
     * Los pedidos de la tienda de este cliente: la pertenencia de la API (HL-70). No es
     * `deCliente()`, que es un filtro del listado y sin valor no filtra; éste siempre filtra.
     */
    public function scopeDelCliente(Builder $query, Cliente $cliente): Builder
    {
        return $query->where('ventas.cliente_id', $cliente->id)->where('ventas.canal', 'online');
    }

    /**
     * Quién vendió.
     */
    public function scopeDeVendedor(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('ventas.usuario_id', (int) $id),
        );
    }

    /** Por canal; un valor fuera de la lista no filtra (la validación ya lo rechazó). */
    public function scopeDeCanal(Builder $query, ?string $canal): Builder
    {
        return $query->when(
            array_key_exists((string) $canal, self::CANALES),
            fn (Builder $query) => $query->where('ventas.canal', $canal),
        );
    }

    /**
     * Desde la fecha, inclusive.
     */
    public function scopeDesde(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('ventas.created_at', '>=', $fecha),
        );
    }

    /** Hasta la fecha, inclusive. Ver el comentario de scopeDesde().
    * 
    */
    public function scopeHasta(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('ventas.created_at', '<=', $fecha),
        );
    }
}