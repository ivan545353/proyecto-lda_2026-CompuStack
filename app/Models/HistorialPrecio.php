<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de precio de un producto: el anterior, el nuevo, el motivo y quién.
 *
 * Sin `updated_at`: una fila de historial se escribe una vez y no se edita.
 */
class HistorialPrecio extends Model
{
    public const MOTIVOS = [
        'alta'          => 'Alta del producto',
        'recalculo'     => 'Recálculo desde los factores',
        'manual'        => 'Precio cargado a mano',
        'ajuste_masivo' => 'Ajuste masivo de precios',
        'deshacer'      => 'Ajuste deshecho',
    ];

    public const UPDATED_AT = null;

    protected $table = 'historial_precios';

    protected $fillable = [
        'producto_id', 'usuario_id', 'lote_id', 'motivo',
        'precio_contado_anterior', 'precio_contado_nuevo',
        'precio_lista_anterior', 'precio_lista_nuevo',
        'costo_anterior', 'costo_nuevo',
        'ganancia_anterior', 'ganancia_nuevo',
    ];

    protected function casts(): array
    {
        return [
            'precio_contado_anterior' => 'decimal:2',
            'precio_contado_nuevo'    => 'decimal:2',
            'precio_lista_anterior'   => 'decimal:2',
            'precio_lista_nuevo'      => 'decimal:2',
            'costo_anterior'          => 'decimal:2',
            'costo_nuevo'             => 'decimal:2',
            'ganancia_anterior'       => 'decimal:3',
            'ganancia_nuevo'          => 'decimal:3',
            'created_at'              => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** El ajuste masivo del que vino este cambio, si vino de uno. */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(LoteAjuste::class, 'lote_id');
    }

    /** Del más nuevo al más viejo, que es como se lee un historial. */
    public function scopeRecientes(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Cuánto se movió el precio de contado, en porcentaje. */
    public function variacionContado(): float
    {
        $anterior = (float) $this->precio_contado_anterior;

        return $anterior > 0
            ? round((((float) $this->precio_contado_nuevo - $anterior) / $anterior) * 100, 2)
            : 0.0;
    }
}