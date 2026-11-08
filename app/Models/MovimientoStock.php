<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;

/**
 * Kardex: el libro de movimientos de stock.
 *
 * MovimientoFiltroRequest):
 *   ?producto_id=  → deProducto($id)    el kardex de un producto
 *   ?tipo=         → deTipo($tipo)      lista cerrada de TIPOS
 *   ?usuario_id=   → deUsuario($id)     quién lo registró
 *   ?desde=        → desde($fecha)      desde esa fecha, inclusive
 *   ?hasta=        → hasta($fecha)      hasta esa fecha, inclusive
 *
 */
class MovimientoStock extends Model
{
    /** Tipos de movimiento, con su texto para la pantalla. */
    public const TIPOS = [
        'compra'           => 'Recepción de compra',
        'venta'            => 'Venta',
        'devolucion'       => 'Devolución',
        'ajuste'           => 'Ajuste de inventario',
        'reserva_liberada' => 'Reserva liberada',
    ];

    protected $table = 'movimientos_stock';

    protected $fillable = [
        'producto_id', 'tipo', 'cantidad', 'stock_resultante',
        'origen_type', 'origen_id', 'usuario_id', 'motivo',
        'remito_numero', 'remito_fecha',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'         => 'integer',
            'stock_resultante' => 'integer',
            'remito_fecha'     => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException(
                'Un movimiento de stock no se modifica: el error se corrige con un ajuste nuevo, que queda registrado.'
            );
        });

        static::deleting(function () {
            throw new LogicException(
                'Un movimiento de stock no se borra: el kardex es el historial, y un historial con huecos no sirve.'
            );
        });
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Quién lo registró. Nullable porque la reposición automática no tiene sesión. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** El documento que lo originó: una venta, una orden de compra, una nota de crédito. */
    public function origen(): MorphTo
    {
        return $this->morphTo('origen');
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /**
     * De dónde salió el movimiento, en palabras del negocio.
     */
    public function origenTexto(): ?string
    {
        if ($this->origen_type === null) {
            return null;
        }

        return match (class_basename($this->origen_type)) {
            'OrdenCompra' => 'Orden de compra OC-'.str_pad((string) $this->origen_id, 5, '0', STR_PAD_LEFT),
            'Venta'       => 'Venta '.Venta::numeroDe($this->origen_id),
            // Cualquier otro documento: se nombra sin filtrar la clase.
            default       => 'Documento interno',
        };
    }

    /** El remito del proveedor con el que entró la mercadería; null si no consta. */
    public function remitoTexto(): ?string
    {
        if ($this->remito_numero === null) {
            return null;
        }

        return "Remito {$this->remito_numero} del {$this->remito_fecha->format('d/m/Y')}";
    }

    /** Un ingreso suma, una salida resta. Lo usa la vista para el signo y el color. */
    public function esIngreso(): bool
    {
        return $this->cantidad > 0;
    }

    /**
     * El stock que había antes del movimiento.
     */
    public function getStockAnteriorAttribute(): int
    {
        return $this->stock_resultante - $this->cantidad;
    }

        // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    public function scopeDeProducto(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('producto_id', (int) $id),
        );
    }

    /**
     * Filtro por tipo.
     */
    public function scopeDeTipo(Builder $query, ?string $tipo): Builder
    {
        return $query->when(
            array_key_exists((string) $tipo, self::TIPOS),
            fn (Builder $query) => $query->where('tipo', $tipo),
        );
    }

    /** Quién registró el movimiento. Es la pregunta literal de A-13. */
    public function scopeDeUsuario(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('usuario_id', (int) $id),
        );
    }

    /**
     * Desde la fecha, inclusive.
     */
    public function scopeDesde(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('created_at', '>=', $fecha),
        );
    }

    /** Hasta la fecha, inclusive. Ver el comentario de scopeDesde(). */
    public function scopeHasta(Builder $query, ?string $fecha): Builder
    {
        return $query->when(
            filled($fecha) && strtotime($fecha) !== false,
            fn (Builder $query) => $query->whereDate('created_at', '<=', $fecha),
        );
    }
}