<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un comprobante de transferencia que cargó el comprador de un pedido (D-27). Es un hecho: no se
 * modifica ni se borra; uno nuevo se suma al lado (regla 1).
 */
class ComprobanteTransferencia extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comprobantes_transferencia';

    protected function casts(): array
    {
        return [
            'tamano'     => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'Un comprobante cargado no se modifica: si hace falta otro, se carga otro.'
        ));
        static::deleting(fn () => throw new LogicException(
            'Un comprobante cargado no se borra: es lo que el comprador dijo que pagó.'
        ));
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }
}
