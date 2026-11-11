<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El carrito de la tienda, guardado en el servidor. No se borra: se convierte en pedido (tramo F).
 *
 * Relaciones:
 *   usuario()  BelongsTo   la cuenta de tienda dueña
 *   lineas()   HasMany     un renglón por producto
 *   (venta() entra en el F, con `ventas.carrito_id`: HL-57)
 *
 * Scopes:
 *   vigente()  `activo` o `en_checkout`: el que el usuario está usando
 */
class Carrito extends Model
{
    public const ESTADOS = [
        'activo'      => 'Activo',
        'en_checkout' => 'En checkout',
        'convertido'  => 'Convertido en pedido',
        'abandonado'  => 'Abandonado',
    ];

    public const ESTADOS_VIGENTES = ['activo', 'en_checkout'];

    protected $attributes = ['estado' => 'activo'];

    protected function casts(): array
    {
        return [
            'reservado_hasta' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CarritoLinea::class, 'carrito_id');
    }

    public function scopeVigente(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_VIGENTES);
    }

    /** En checkout y con la reserva todavía en plazo. */
    public function enCheckoutVigente(): bool
    {
        return $this->estado === 'en_checkout'
            && $this->reservado_hasta !== null
            && $this->reservado_hasta->isFuture();
    }
}
