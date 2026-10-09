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
 * En el sistema original el stock era una columna que se pisaba con
 * `UPDATE productos SET stock = stock - :cant`, y no quedaba rastro de nada: no
 * se podía auditar quién movió qué, ni reconstruir el stock a una fecha, ni
 * distinguir una venta de un ajuste o de una recepción de mercadería.
 *
 * Cada fila responde las cuatro preguntas:
 *   qué y cuánto → producto_id, tipo, cantidad (con signo)
 *   cuándo       → created_at
 *   quién        → usuario_id
 *   por qué      → el documento de origen (venta, orden de compra) y, cuando no
 *                  hay documento porque es un ajuste manual, el motivo
 *
 * `stock_resultante` guarda el saldo que quedó después del movimiento, así que el
 * stock de una fecha se lee del kardex sin recalcular nada.
 *
 * **El kardex es de sólo agregado.** No hay pantalla que edite ni borre un
 * movimiento, y los eventos de abajo lo impiden también por código: corregir un
 * asiento en lugar de compensarlo con uno nuevo destruye justamente lo que A-13
 * pedía. Un error se arregla con un ajuste que queda registrado.
  * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * MovimientoFiltroRequest):
 *   ?producto_id=  → deProducto($id)    el kardex de un producto
 *   ?tipo=         → deTipo($tipo)      lista cerrada de TIPOS
 *   ?usuario_id=   → deUsuario($id)     quién lo registró
 *   ?desde=        → desde($fecha)      desde esa fecha, inclusive
 *   ?hasta=        → hasta($fecha)      hasta esa fecha, inclusive
 *
 * No hay factory a propósito: un movimiento sin su cambio de stock sería una fila
 * que afirma algo que no pasó. Los tests los crean llamando al StockService.
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
    ];

    protected function casts(): array
    {
        return [
            'cantidad'         => 'integer',
            'stock_resultante' => 'integer',
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
     *
     * `origen_type` guarda un nombre de clase de PHP, y eso no se le muestra a
     * nadie: la regla del proyecto es lenguaje del negocio, nunca del programador.
     * Un ajuste no tiene origen y devuelve null, porque su `motivo` ocupa ese
     * lugar en la pantalla.
     */
    public function origenTexto(): ?string
    {
        if ($this->origen_type === null) {
            return null;
        }

        return match (class_basename($this->origen_type)) {
            'OrdenCompra' => 'Orden de compra OC-'.str_pad((string) $this->origen_id, 5, '0', STR_PAD_LEFT),
            // El número lo arma el modelo que lo define, no esta pantalla: así el
            // kardex y la ficha de la venta dicen «V-00042» igual, y cambiar el
            // formato se hace en un solo lugar. La orden de compra todavía lo arma
            // acá a mano; no lo muevo en esta fase para no mezclar un retoque de la
            // Fase 5 con el módulo nuevo.
            'Venta'       => 'Venta '.Venta::numeroDe($this->origen_id),
            // Cualquier otro documento: se nombra sin filtrar la clase.
            default       => 'Documento interno',
        };
    }

    /** Un ingreso suma, una salida resta. Lo usa la vista para el signo y el color. */
    public function esIngreso(): bool
    {
        return $this->cantidad > 0;
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
     *
     * Se compara contra las claves de TIPOS y no con filled(): un tipo
     * inexistente no filtra nada, en lugar de devolver un listado vacío que
     * parecería estar diciendo «no hubo movimientos de ese tipo».
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
     *
     * Los dos extremos usan `whereDate()`, que compara sólo la parte de fecha.
     * En el extremo de abajo `created_at >= '2026-03-15'` funcionaría igual, pero
     * en el de arriba `created_at <= '2026-03-15'` dejaría afuera todo lo del día
     * 15 después de medianoche —que es casi todo el día—. Usar el mismo criterio
     * en los dos hace que el rango signifique lo que el usuario eligió en el
     * calendario: días completos.
     *
     * Una fecha ilegible no filtra: la rechaza el Form Request de filtros, que
     * redirige al listado limpio con un aviso.
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