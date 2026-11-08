<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un renglón de una entrega: cuántas unidades de un renglón de la venta salieron esa vez.
 */
class EntregaLinea extends Model
{
    public $timestamps = false;

    protected $table = 'entrega_lineas';

    protected $fillable = ['venta_linea_id', 'cantidad'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    /** Igual que la entrega: lo que salió no se reescribe. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Un renglón de una entrega no se modifica: es un hecho que ya pasó.');
        });

        static::deleting(function () {
            throw new LogicException('Un renglón de una entrega no se borra: su comprobante tiene que poder reimprimirse.');
        });
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(Entrega::class, 'entrega_id');
    }

    public function ventaLinea(): BelongsTo
    {
        return $this->belongsTo(VentaLinea::class, 'venta_linea_id');
    }
}