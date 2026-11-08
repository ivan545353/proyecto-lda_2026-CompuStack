<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea de venta: un producto, una cantidad, y el precio del momento.
 *
 * Relaciones:
 *   venta()     BelongsTo
 *   producto()  BelongsTo
 *
 * Métodos:
 *   cantidadPendienteDeDevolver()  lo que todavía no volvió
 *   estaDevueltaPorCompleto()      si ya volvió todo
 *   superanElDisponible()          scope: los que piden más de lo que hay

 */
class VentaLinea extends Model
{
    protected $table = 'venta_lineas';

    /** Lo único que manda el formulario. Todo lo demás lo escribe el servidor. */
    protected $fillable = ['producto_id', 'cantidad'];

    protected $attributes = ['cantidad_devuelta' => 0, 'cantidad_entregada' => 0];

    protected function casts(): array
    {
        return [
            'cantidad'          => 'integer',
            'cantidad_devuelta' => 'integer',
            'cantidad_entregada' => 'integer',
            'precio_unitario'   => 'decimal:2',
            'alicuota_iva'      => 'decimal:2',
            'costo_unitario'    => 'decimal:2',
            'neto'              => 'decimal:2',
            'iva'               => 'decimal:2',
            'total'             => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /**
     * Los renglones que piden más unidades de las que hay disponibles hoy.
     */
    public function scopeSuperanElDisponible(Builder $query): Builder
    {
        return $query->whereHas(
            'producto',
            fn (Builder $producto) => $producto->whereRaw(Producto::DISPONIBLE.' < venta_lineas.cantidad'),
        );
    }

    /** El desglose de impuestos con el que se cobró este renglón. */
    public function impuestos(): HasMany
    {
        return $this->hasMany(VentaLineaImpuesto::class, 'venta_linea_id')
            ->orderBy('orden')
            ->orderBy('id');
    }

    /**
     * Lo que todavía no se devolvió de esta línea.
     */
    public function cantidadPendienteDeDevolver(): int
    {
        return max(0, $this->cantidad - $this->cantidad_devuelta);
    }

    /** Lo que falta entregar; el null de una línea anterior a las entregas cuenta como nada entregado. */
    public function cantidadPendienteDeEntregar(): int
    {
        return max(0, $this->cantidad - ($this->cantidad_entregada ?? 0));
    }

    public function estaDevueltaPorCompleto(): bool
    {
        return $this->cantidad_devuelta >= $this->cantidad;
    }
}