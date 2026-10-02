<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    /** Un ingreso suma, una salida resta. Lo usa la vista para el signo y el color. */
    public function esIngreso(): bool
    {
        return $this->cantidad > 0;
    }
}