<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón del carrito: un producto y una cantidad. Los precios se leen del producto vigente;
 * las referencias sólo detectan que cambiaron (D-11).
 *
 * Relaciones:
 *   carrito()   BelongsTo
 *   producto()  BelongsTo
 */
class CarritoLinea extends Model
{
    protected $table = 'carrito_lineas';

    protected function casts(): array
    {
        return [
            'cantidad'           => 'integer',
            'contado_referencia' => 'decimal:2',
            'lista_referencia'   => 'decimal:2',
        ];
    }

    public function carrito(): BelongsTo
    {
        return $this->belongsTo(Carrito::class, 'carrito_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
