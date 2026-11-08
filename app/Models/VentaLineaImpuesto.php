<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un impuesto tal como se cobró en un renglón: nombre, alícuota e importe,
 * copiados al momento de la venta.
 *
 * La clasificación de la Ley 27.743 también se copia, porque es la que decide
 * en qué línea del comprobante va el importe, y la ley puede cambiar.
 */
class VentaLineaImpuesto extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'venta_linea_impuestos';

    protected $fillable = ['nombre', 'alicuota', 'importe', 'comprobante', 'orden'];

    protected function casts(): array
    {
        return [
            'alicuota'   => 'decimal:2',
            'importe'    => 'decimal:2',
            'orden'      => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(VentaLinea::class, 'venta_linea_id');
    }

    /** El orden que tenía el desglose cuando se emitió. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }
}