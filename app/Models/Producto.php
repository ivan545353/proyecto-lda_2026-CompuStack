<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto del catálogo.
 *
 * Scopes (uno por filtro del listado; la correspondencia con el parámetro de
 * la URL está declarada acá y en ProductoFiltroRequest):
 *   ?q=             → buscar($texto)          código o nombre contiene
 *   ?categoria_id=  → deCategoria($id)        la categoría y todas sus subcategorías
 *   ?marca_id=      → deMarca($id)            marca exacta
 *   ?proveedor_id=  → deProveedor($id)        los que se le compran a ese proveedor
 *   ?estado=        → conEstado($estado)      activos | inactivos
 *   ?stock=         → conStock($stock)        disponible | agotado | critico
 *
 * Los recortes del ajuste masivo. No son filtros del listado del
 * catálogo: su contrato lo declara AjusteMasivoRequest, y por eso no están en
 * ProductoFiltroRequest. Se encadenan con los de arriba, y de ahí salen los
 * recortes cruzados sin una línea más:
 *   ?ids[]=            → conIds($ids)                  la selección con casillas
 *   ?costo_desde=      → conCostoDesde($costo)         inclusive
 *   ?costo_hasta=      → conCostoHasta($costo)         inclusive
 *   ?precio_desde=     → conPrecioDesde($precio)       sobre precio_contado
 *   ?precio_hasta=     → conPrecioHasta($precio)       sobre precio_contado
 *   ?incluir_manuales= → conPrecioManual($manual)      null no filtra
 *   ?excluir[]=        → sinIds($ids)                  lo que ya está cargado
 *
 * 
 *
 * Relaciones:
 *   proveedores()       BelongsToMany  a quiénes se le compra, con el precio de cada uno
 *   ordenCompraLineas() HasMany        en qué órdenes de compra aparece
 *   ventaLineas()       HasMany        en qué ventas aparece
 *   movimientos()       HasMany        el kardex del producto
 *
 */
class Producto extends Model
{
    use HasFactory;

    public const DISPONIBLE = '(productos.stock - productos.stock_reservado)';


    public const MAX_IMAGENES = 5;

    protected $table = 'productos';

    protected $fillable = [
        'categoria_id', 'marca_id', 'codigo', 'nombre',
        'descripcion', 'imagenes', 'precio_lista', 'precio_contado',
        'alicuota_iva', 'stock_minimo', 'cantidad_reposicion',
        'peso_gramos', 'destacado', 'activo',
        'ganancia', 'precio_manual',
    ];

    protected $attributes = [
        'stock'           => 0,
        'stock_reservado' => 0,
        'costo_promedio'  => 0,
        'precio_manual'   => false,
    ];

    protected function casts(): array
    {
        return [
            'imagenes'            => 'array',
            'precio_lista'        => 'decimal:2',
            'precio_contado'      => 'decimal:2',
            'alicuota_iva'        => 'decimal:2',
            'costo_promedio'      => 'decimal:2',
            'ganancia'      => 'decimal:3',
            'precio_manual' => 'boolean',
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

   
    /**
     * Los proveedores a los que se le puede comprar, cada uno con su precio.
     *
     * `withPivot` trae las columnas del vínculo, que es lo que hace útil la
     * relación: sin `costo_ultimo` no se puede comparar nada.
     */
    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(Proveedor::class, 'producto_proveedor', 'producto_id', 'proveedor_id')
            ->withPivot(['costo_ultimo', 'codigo_proveedor', 'es_preferido'])
            ->withTimestamps();
    }

    /**
     * A quién se le pide la reposición de este producto.
     *
     */
    public function proveedorParaReponer(): ?Proveedor
    {
        $this->loadMissing('proveedores');

        $candidatos = $this->proveedores->where('activo', true);

        return $candidatos->firstWhere('pivot.es_preferido', true)
            ?? $candidatos->sortBy(fn (Proveedor $proveedor) => [
                $proveedor->pivot->costo_ultimo === null ? 1 : 0,
                (float) ($proveedor->pivot->costo_ultimo ?? 0),
                $proveedor->razon_social,
            ])->first();
    }

    /** El más barato de los activos, para marcarlo en el comparador. */
    public function proveedorMasBarato(): ?Proveedor
    {
        $this->loadMissing('proveedores');

        return $this->proveedores
            ->where('activo', true)
            ->whereNotNull('pivot.costo_ultimo')
            ->sortBy(fn (Proveedor $proveedor) => (float) $proveedor->pivot->costo_ultimo)
            ->first();
    }

    /**
     * Los proveedores a los que se le puede pedir, en el orden de conveniencia.
     *
     * @return array<int, array{id: int, nombre: string, costo: string|null, preferido: bool}>
     */
    public function proveedoresParaElegir(): array
    {
        $this->loadMissing('proveedores');

        return $this->proveedores
            ->where('activo', true)
            ->map(fn (Proveedor $proveedor) => [
                'id'        => $proveedor->id,
                'nombre'    => $proveedor->razon_social,
                'costo'     => $proveedor->pivot->costo_ultimo,
                'preferido' => (bool) $proveedor->pivot->es_preferido,
            ])
            ->sortBy(fn (array $proveedor) => [
                $proveedor['preferido'] ? 0 : 1,
                $proveedor['costo'] === null ? 1 : 0,
                (float) ($proveedor['costo'] ?? 0),
                $proveedor['nombre'],
            ])
            ->values()
            ->all();
    }

    /**
     * Un producto que se repone pero no se le puede comprar a nadie.
     *
     * La reposición automática lo iba a detectar como crítico todas las noches sin
     * poder pedirle a nadie, en silencio. El listado lo avisa y el comando lo
     * informa.
     */
    public function noSePuedeReponer(): bool
    {
        $this->loadMissing('proveedores');

        return $this->activo
            && $this->stock_minimo > 0
            && $this->proveedores->where('activo', true)->isEmpty();
    }

    /** El kardex del producto: todo lo que entró y salió, en orden. */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'producto_id');
    }

    /** Cada cambio de precio del producto, con su motivo y su autor. */
    public function historialPrecios(): HasMany
    {
        return $this->hasMany(HistorialPrecio::class, 'producto_id');
    }

    /**
     * Todas las líneas de orden de compra en las que aparece este producto.
     */
    public function ordenCompraLineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class, 'producto_id');
    }

    /**
     * Todas las líneas de venta en las que aparece este producto.
     */
    public function ventaLineas(): HasMany
    {
        return $this->hasMany(VentaLinea::class, 'producto_id');
    }

    public function getStockDisponibleAttribute(): int
    {
        return $this->stock - $this->stock_reservado;
    }

    /**
     * El margen sobre la venta del precio de contado, en porcentaje.
     */
    public function margen(): ?float
    {
        $costo = (float) $this->costo_promedio;

        if ($costo <= 0) {
            return null;
        }

        $neto = (float) $this->precio_contado / (1 + ((float) $this->alicuota_iva / 100));

        return $neto > 0 ? round((($neto - $costo) / $neto) * 100, 2) : null;
    }

    /**
     * Cómo se nombra un producto cuando hay que elegirlo: «TEC-00123 — Teclado».
     */
    public function rotulo(): string
    {
        return "{$this->codigo} — {$this->nombre}";
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

    public function scopeDeProveedor(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->whereHas(
                'proveedores',
                fn (Builder $proveedor) => $proveedor->where('proveedores.id', (int) $id),
            ),
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
            'disponible'    => $query->whereRaw(self::DISPONIBLE.' > 0'),
            'agotado'       => $query->whereRaw(self::DISPONIBLE.' <= 0'),
            'critico'       => $query->stockCritico(),
            'sin_proveedor' => $query->sinProveedorParaReponer(),
            default         => $query,
        };
    }

    /**
     * Los productos elegidos a mano, con las casillas del listado.
     *
     * @param  array<int, int|string>|null  $ids
     */
    public function scopeConIds(Builder $query, ?array $ids): Builder
    {
        $limpios = array_filter((array) $ids, fn ($id) => ctype_digit((string) $id));

        return $query->when(
            $limpios !== [],
            fn (Builder $query) => $query->whereIn('id', array_map('intval', $limpios)),
        );
    }

    /**
     * Todo menos esos ids: lo que el buscador no vuelve a ofrecer porque ya está cargado.
     *
     * @param  array<int, int|string>|null  $ids
     */
    public function scopeSinIds(Builder $query, ?array $ids): Builder
    {
        $limpios = array_filter((array) $ids, fn ($id) => ctype_digit((string) $id));

        return $query->when(
            $limpios !== [],
            fn (Builder $query) => $query->whereNotIn('id', array_map('intval', $limpios)),
        );
    }

    /** Desde ese costo, inclusive. */
    public function scopeConCostoDesde(Builder $query, float|string|null $costo): Builder
    {
        return $query->when(
            is_numeric($costo),
            fn (Builder $query) => $query->where('costo_promedio', '>=', $costo),
        );
    }

    /** Hasta ese costo, inclusive: el recorte «todo lo de menos de $10.000». */
    public function scopeConCostoHasta(Builder $query, float|string|null $costo): Builder
    {
        return $query->when(
            is_numeric($costo),
            fn (Builder $query) => $query->where('costo_promedio', '<=', $costo),
        );
    }

    /**
     * Desde ese precio, inclusive.
     */
    public function scopeConPrecioDesde(Builder $query, float|string|null $precio): Builder
    {
        return $query->when(
            is_numeric($precio),
            fn (Builder $query) => $query->where('precio_contado', '>=', $precio),
        );
    }

    /** Hasta ese precio de contado, inclusive. */
    public function scopeConPrecioHasta(Builder $query, float|string|null $precio): Builder
    {
        return $query->when(
            is_numeric($precio),
            fn (Builder $query) => $query->where('precio_contado', '<=', $precio),
        );
    }

    /**
     * Los que tienen el precio fijado a mano, o los que no.
     */
    public function scopeConPrecioManual(Builder $query, ?bool $manual): Builder
    {
        return $query->when(
            $manual !== null,
            fn (Builder $query) => $query->where('precio_manual', $manual),
        );
    }


    public function scopeStockCritico(Builder $query): Builder
    {
        return $query->whereRaw(self::DISPONIBLE.' <= productos.stock_minimo');
    }

    /**
     * Los productos que la reposición automática mira.
     */
    public function scopeReponibles(Builder $query): Builder
    {
        return $query->where('activo', true)->where('stock_minimo', '>', 0);
    }

    /**
     * Los que no están ya dentro de una orden de compra abierta.
     *
     */
    public function scopeSinPedidoEnCurso(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'ordenCompraLineas.ordenCompra',
            fn (Builder $orden) => $orden->abiertas(),
        );
    }

    /** Hay al menos un proveedor activo a quien pedirle. */
    public function scopeConProveedorActivo(Builder $query): Builder
    {
        return $query->whereHas(
            'proveedores',
            fn (Builder $proveedor) => $proveedor->where('proveedores.activo', true),
        );
    }

    /** No queda ningún proveedor activo: no se le puede pedir a nadie. */
    public function scopeSinProveedorActivo(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'proveedores',
            fn (Builder $proveedor) => $proveedor->where('proveedores.activo', true),
        );
    }

    /** Lo que hay que pedir, y la única definición de eso en el sistema. */
    public function scopeParaReponer(Builder $query): Builder
    {
        return $query->reponibles()->stockCritico()->sinPedidoEnCurso();
    }

    /** Lo que hay que pedir y no se le puede pedir a nadie: el aviso del armador. */
    public function scopeSinProveedorParaReponer(Builder $query): Builder
    {
        return $query->paraReponer()->sinProveedorActivo();
    }
}