<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Orden de compra. Módulo nuevo: el sistema original no tenía compras.
 *
 * Relaciones:
 *   proveedor()      BelongsTo   a quién se le compra
 *   lineas()         HasMany     qué se le pidió
 *   usuarioCreo()    BelongsTo   quién la armó; null = la generó el sistema
 *   usuarioAprobo()  BelongsTo   quién autorizó el gasto
 *   movimientos()    MorphMany   lo que entró al depósito por esta orden
 *
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * OrdenCompraFiltroRequest):
 *   ?q=             → buscar($texto)        número de orden o razón social
 *   ?estado=        → conEstado($estado)    lista cerrada de ESTADOS, o «abiertas»
 *   ?origen=        → deOrigen($origen)     reposicion | manual
 *   ?proveedor_id=  → deProveedor($id)
 *   ?desde=         → desde($fecha)         por fecha de creación, inclusive
 *   ?hasta=         → hasta($fecha)
 *
 * Fuera del listado:
 *   abiertas()  la reusan la reposición automática y el panel de la Fase 7
 */
class OrdenCompra extends Model
{
    use HasFactory;

    /** Estados, con su texto para la pantalla. La clave es el valor del ENUM. */
    public const ESTADOS = [
        'borrador'         => 'Borrador',
        'aprobada'         => 'Aprobada',
        'enviada'          => 'Enviada',
        'recibida_parcial' => 'Recibida parcial',
        'recibida'         => 'Recibida',
        'cancelada'        => 'Cancelada',
    ];

    /**
     * Estados en los que la orden sigue viva.
     *
     */
    public const ESTADOS_ABIERTOS = ['borrador', 'aprobada', 'enviada', 'recibida_parcial'];

    /** El valor de ?estado= que agrupa los cuatro abiertos: es el destino del panel. */
    public const ABIERTAS = 'abiertas';

    
    /** Lo que ofrece el selector de estado del listado: el grupo de las abiertas, y cada estado. */
    public const ESTADOS_DEL_FILTRO = [self::ABIERTAS => 'Abiertas (sin recibir ni cancelar)'] + self::ESTADOS;

    /** Quién la originó, para ?origen=. Sin usuario que la creara, la generó la reposición. */
    public const ORIGENES = [
        'reposicion' => 'Generada por la reposición',
        'manual'     => 'Cargada por una persona',
    ];

    protected $table = 'ordenes_compra';

    protected $fillable = ['proveedor_id', 'observaciones', 'usuario_creo_id'];

    /**
     * `estado`, `total_estimado`, `usuario_aprobo_id`, `fecha_aprobacion` y
     * `fecha_envio` quedan fuera de $fillable: los mueve sólo CompraService. El
     * estado pasa por la máquina de estados y el total se recalcula desde las
     * líneas, así que ninguno de los dos puede llegar desde un formulario.
     *
     * Van en $attributes con el mismo valor que el DEFAULT de la base, para que el
     * objeto y la fila digan lo mismo desde el primer momento.
     */
    protected $attributes = [
        'estado'         => 'borrador',
        'total_estimado' => 0,
    ];

    protected function casts(): array
    {
        return [
            'total_estimado'   => 'decimal:2',
            'fecha_aprobacion' => 'datetime',
            'fecha_envio'      => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relaciones
    // ------------------------------------------------------------------

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class, 'orden_compra_id');
    }

    public function usuarioCreo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creo_id');
    }

    public function usuarioAprobo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_aprobo_id');
    }

    /** Lo que entró al depósito por esta orden, con su fecha y su usuario. */
    public function movimientos(): MorphMany
    {
        return $this->morphMany(MovimientoStock::class, 'origen');
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /** El número con el que el proveedor va a referirse al pedido. */
    public function numeroFormateado(): string
    {
        return 'OC-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function estadoTexto(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /**
     * Clase del badge de estado.
     */
    public function estadoClase(): string
    {
        return match ($this->estado) {
            'borrador'         => 'text-bg-light border',
            'aprobada', 'enviada' => 'text-bg-dark',
            'recibida_parcial' => 'text-bg-warning',
            'recibida'         => 'text-bg-success',
            'cancelada'        => 'text-bg-secondary',
            default            => 'text-bg-light border',
        };
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado, self::ESTADOS_ABIERTOS, true);
    }

    /** Sólo un borrador se edita: una vez aprobada, el monto está comprometido. */
    public function esEditable(): bool
    {
        return $this->estado === 'borrador';
    }

    public function sePuedeImprimir(): bool
    {
        return $this->fecha_aprobacion !== null;
    }

    /** La generó la tarea de reposición, no una persona. */
    public function fueGeneradaPorElSistema(): bool
    {
        return $this->usuario_creo_id === null;
    }

    /** Hay algo pendiente de recibir en alguna línea. */
    public function tienePendientes(): bool
    {
        return $this->lineas->contains(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente() > 0);
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Busca por número de orden o por razón social del proveedor.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $digitos = preg_replace('/\D/', '', (string) $texto);

            return $query->where(function (Builder $query) use ($texto, $digitos) {
                $query->whereHas('proveedor', fn (Builder $proveedor) => $proveedor
                    ->where('razon_social', 'like', Like::contiene($texto)));

                if ($digitos !== '') {
                    $query->orWhere('id', (int) $digitos);
                }
            });
        });
    }

    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        if ($estado === self::ABIERTAS) {
            return $query->abiertas();
        }

        return $query->when(
            array_key_exists((string) $estado, self::ESTADOS),
            fn (Builder $query) => $query->where('estado', $estado),
        );
    }

    // Un valor fuera de ORIGENES no filtra, igual que un estado inexistente.
    public function scopeDeOrigen(Builder $query, ?string $origen): Builder
    {
        return match ($origen) {
            'reposicion' => $query->whereNull('usuario_creo_id'),
            'manual'     => $query->whereNotNull('usuario_creo_id'),
            default      => $query,
        };
    }

    public function scopeDeProveedor(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('proveedor_id', (int) $id),
        );
    }

    /** Ver el comentario de MovimientoStock::scopeDesde(): los dos extremos incluyen el día completo. */
    public function scopeDesde(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('created_at', '>=', $fecha),
        );
    }

    public function scopeHasta(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('created_at', '<=', $fecha),
        );
    }

    /** Las que todavía esperan algo. En la URL llega como ?estado=abiertas. */
    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ABIERTOS);
    }
}