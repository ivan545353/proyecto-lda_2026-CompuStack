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

    // Valores iniciales en memoria, iguales a los DEFAULT de la migración.
    //
    // Sin esto, un modelo recién creado tiene stock = null (la columna no es
    // asignable en masa, así que el valor lo pone la base y el objeto no se
    // entera). El accesor stock_disponible restaría dos nulls y devolvería 0:
    // un número inventado. Declarar el default acá mantiene el objeto y la
    // fila diciendo lo mismo desde el primer momento.
    protected $attributes = [
        'stock'           => 0,
        'stock_reservado' => 0,
        'costo_promedio'  => 0,
    ];

    // stock, stock_reservado y costo_promedio NO están en $fillable a
    // propósito: sólo los modifica el StockService (Fase 5), y siempre dejando
    // un movimiento en el kardex. Con $fillable declarado, todo lo que no
    // figura ahí queda fuera de la asignación masiva; un $guarded además sería
    // código muerto, porque Eloquent no lo consulta cuando $fillable no está
    // vacío.
    //
    // Es la misma idea que en el sistema original faltaba: ItemDto tomaba el
    // body completo y el DAO lo escribía entero (hallazgos C-3 y M-31).

    protected function casts(): array
    {
        return [
            'imagenes'        => 'array',
            'precio_lista'    => 'decimal:2',
            'precio_contado'  => 'decimal:2',
            'alicuota_iva'    => 'decimal:2',
            'costo_promedio'  => 'decimal:2',
            'destacado'       => 'boolean',
            'activo'          => 'boolean',
            'stock'           => 'integer',
            'stock_reservado' => 'integer',
            'stock_minimo'    => 'integer',
            'cantidad_reposicion' => 'integer',
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
