<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto del catálogo.
 *
 * Respecto del original (nombre, código, descripción, categoría, precio, stock)
 * suma dos precios, alícuota de IVA, costo promedio, stock mínimo, cantidad de
 * reposición, imágenes y marca. Todo importe es decimal: el original tenía
 * productos.precio en float(12,2) y acumulaba error de redondeo (A-17).
 *
 * stock, stock_reservado y costo_promedio no son asignables en masa: sólo los
 * mueve el StockService (Fase 5), dejando un movimiento en el kardex.
 *
 * Scopes (uno por filtro del listado; la correspondencia con el parámetro de
 * la URL está declarada acá y en ProductoFiltroRequest):
 *   ?q=             → buscar($texto)          código o nombre contiene
 *   ?categoria_id=  → deCategoria($id)        categoría exacta, sin subcategorías
 *   ?marca_id=      → deMarca($id)            marca exacta
 *   ?estado=        → conEstado($estado)      activos | inactivos
 *   ?stock=         → conStock($stock)        disponible | agotado | critico
 *   ?categoria_id=  → deCategoria($id)        la categoría y todas sus subcategorías
 * 
 * Fuera del listado:
 *   stockCritico()  lo reusan la reposición automática (Fase 5) y el panel (Fase 7)
 *
 * Todo lo relativo a stock se calcula sobre el DISPONIBLE (stock menos lo
 * reservado), que es la misma definición que usa la reposición automática. Si
 * el filtro y el job usaran definiciones distintas, dirían cosas distintas
 * sobre el mismo producto.
 * 
 */
class Producto extends Model
{
    use HasFactory;

    private const DISPONIBLE = '(productos.stock - productos.stock_reservado)';

    /**
     * Alícuotas de IVA admitidas, con su texto para la pantalla.
     *
     * La clave está escrita como la devuelve la base con el cast decimal:2
     * ('21.00', no 21 ni '21'). La usan el Request para validar y la vista
     * para armar el selector, así que las dos comparan exactamente lo mismo.
     * Con Rule::in([0, 10.5, 21]), '21.00' no coincidiría con '21' y ningún
     * producto existente se podría volver a guardar.
     *
     */
    public const ALICUOTAS_IVA = [
        '21.00' => '21 %',
        '10.50' => '10,5 %',
        '0.00'  => '0 % (exento)',
    ];

    public const MAX_IMAGENES = 5;

    protected $table = 'productos';

    protected $fillable = [
        'categoria_id', 'marca_id', 'proveedor_id', 'codigo', 'nombre',
        'descripcion', 'imagenes', 'precio_lista', 'precio_contado',
        'alicuota_iva', 'stock_minimo', 'cantidad_reposicion',
        'peso_gramos', 'destacado', 'activo',
    ];

    protected $attributes = [
        'stock'           => 0,
        'stock_reservado' => 0,
        'costo_promedio'  => 0,
    ];

    protected function casts(): array
    {
        return [
            'imagenes'            => 'array',
            'precio_lista'        => 'decimal:2',
            'precio_contado'      => 'decimal:2',
            'alicuota_iva'        => 'decimal:2',
            'costo_promedio'      => 'decimal:2',
            'stock'               => 'integer',
            'stock_reservado'     => 'integer',
            'stock_minimo'        => 'integer',
            'cantidad_reposicion' => 'integer',
            'destacado'           => 'boolean',
            'activo'              => 'boolean',
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

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $patron = Like::contiene($texto);

            return $query->where(fn (Builder $query) => $query
                ->where('nombre', 'like', $patron)
                ->orWhere('codigo', 'like', $patron));
        });
    }

    /**
     * La categoría y todas sus subcategorías.
     *
     * Los productos viven en las hojas del árbol (Discos SSD, no
     * Almacenamiento). Filtrar sólo la categoría exacta devolvía vacío
     * justamente en las categorías que más se usan para buscar.
     *
     * No es el mismo criterio que el filtro ?parent_id= del listado de
     * categorías, que muestra sólo las hijas directas: aquel es navegación
     * (se baja de a un nivel), este es búsqueda ("¿qué tengo de
     * almacenamiento?").
     *
     * Cuesta como mucho cuatro consultas, porque el árbol no pasa de tres
     * niveles (Categoria::PROFUNDIDAD_MAXIMA).
     */
    public function scopeDeCategoria(Builder $query, int|string|null $id): Builder
    {
        if (! ctype_digit((string) $id)) {
            return $query;
        }

        $categoria = Categoria::find((int) $id);

        // Una categoría inexistente filtra y no devuelve nada. Ignorarla
        // mostraría el catálogo entero, como si el usuario hubiera elegido
        // "Todas".
        if ($categoria === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('categoria_id', [$categoria->id, ...$categoria->idsDescendientes()]);
    }

    public function scopeDeMarca(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('marca_id', (int) $id),
        );
    }

    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            'activos'   => $query->where('activo', true),
            'inactivos' => $query->where('activo', false),
            default     => $query,
        };
    }

    public function scopeConStock(Builder $query, ?string $stock): Builder
    {
        return match ($stock) {
            'disponible' => $query->whereRaw(self::DISPONIBLE.' > 0'),
            'agotado'    => $query->whereRaw(self::DISPONIBLE.' <= 0'),
            'critico'    => $query->stockCritico(),
            default      => $query,
        };
    }


    public function scopeStockCritico(Builder $query): Builder
    {
        return $query->whereRaw(self::DISPONIBLE.' <= productos.stock_minimo');
    }
}