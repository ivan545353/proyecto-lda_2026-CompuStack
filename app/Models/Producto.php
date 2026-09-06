<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'categoria_id', 'marca_id', 'proveedor_id', 'codigo', 'nombre',
        'descripcion', 'imagenes', 'precio_lista', 'precio_contado',
        'alicuota_iva', 'stock_minimo', 'cantidad_reposicion',
        'peso_gramos', 'destacado', 'activo',
    ];

    // stock, stock_reservado y costo_promedio quedan fuera de la asignación
    // masiva a propósito: sólo los modifica el servicio de stock (Fase 5), y
    // siempre dejando un movimiento en el kardex.
    protected $guarded = ['stock', 'stock_reservado', 'costo_promedio'];

    protected function casts(): array
    {
        return [
            'imagenes'       => 'array',
            'precio_lista'   => 'decimal:2',
            'precio_contado' => 'decimal:2',
            'alicuota_iva'   => 'decimal:2',
            'costo_promedio' => 'decimal:2',
            'destacado'      => 'boolean',
            'activo'         => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class, 'marca_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function getStockDisponibleAttribute(): int
    {
        return $this->stock - $this->stock_reservado;
    }
}
