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
 *   stockCritico()      lo reusan la reposición automática y el panel (Fase 7)
 *   reponibles()        los que se reponen solos: activos y con mínimo declarado
 *   sinPedidoEnCurso()  los que no están ya dentro de una orden de compra abierta
 *
 * Relaciones:
 *   proveedores()       BelongsToMany  a quiénes se le compra, con el precio de cada uno
 *   ordenCompraLineas() HasMany        en qué órdenes de compra aparece
 *   movimientos()       HasMany        el kardex del producto
 *
 * Todo lo relativo a stock se calcula sobre el DISPONIBLE (stock menos lo
 * reservado), que es la misma definición que usa la reposición automática. Si
 * el filtro y el job usaran definiciones distintas, dirían cosas distintas
 * sobre el mismo producto.
 *
 * A quién se le compra NO es un campo del producto: se le puede comprar a varios,
 * a precios distintos, y eso vive en la pivote `producto_proveedor`. La columna
 * `productos.proveedor_id` existió hasta la Fase 5 y se eliminó: la relación es
 * proveedores(), y la administra ProductoProveedorService.
 * 
 * @
 *   proveedores()  BelongsToMany  a quiénes se le compra, con el precio de cada uno
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
        'categoria_id', 'marca_id', 'codigo', 'nombre',
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
     * La regla, en orden: el preferido si está marcado; si no hay, el de menor
     * `costo_ultimo`; y si ninguno tiene costo cargado, el primero por razón
     * social, para que la decisión sea determinista y no dependa del orden en que
     * se cargaron los vínculos.
     *
     * Que el preferido gane sobre el precio es a propósito: a veces el barato
     * entrega en tres semanas, y eso no es más barato.
     *
     * Sólo se consideran los proveedores activos: a uno dado de baja no se le
     * manda un pedido automático.
     */
    public function proveedorParaReponer(): ?Proveedor
    {
        $this->loadMissing('proveedores');

        $candidatos = $this->proveedores->where('activo', true);

        return $candidatos->firstWhere('pivot.es_preferido', true)
            ?? $candidatos->sortBy(fn (Proveedor $proveedor) => [
                // Los que no tienen costo cargado van al final, no adelante como
                // haría un null tratado como cero.
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

    /**
     * Todas las líneas de orden de compra en las que aparece este producto.
     *
     * No es un historial para mostrar —para eso está el kardex, que tiene fecha y
     * usuario—: existe para poder preguntarle a la base si el producto ya está
     * pedido sin traer ninguna línea a PHP. La usa scopeSinPedidoEnCurso().
     */
    public function ordenCompraLineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class, 'producto_id');
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

    /**
     * Los productos que la reposición automática mira.
     *
     * `stock_minimo > 0` ES la declaración de «este producto se repone solo»:
     * ProductoRequest exige `cantidad_reposicion` mayor que cero exactamente
     * cuando el mínimo lo es, así que todo producto que entra acá tiene una
     * cantidad a pedir y el comando nunca genera una línea de cero unidades.
     *
     * Por eso no alcanza con stockCritico(): con mínimo cero y stock cero,
     * `disponible <= minimo` da verdadero, y el comando pediría algo que nadie
     * quiere reponer. El scope del listado no se toca —ahí «crítico» significa «en
     * o por debajo de su mínimo», y está bien—; lo que la reposición necesita es
     * una condición más.
     *
     * Es el mismo criterio que noSePuedeReponer() usa para el aviso del listado,
     * escrito en SQL en vez de en PHP: los dos lugares dicen lo mismo del mismo
     * producto.
     */
    public function scopeReponibles(Builder $query): Builder
    {
        return $query->where('activo', true)->where('stock_minimo', '>', 0);
    }

    /**
     * Los que no están ya dentro de una orden de compra abierta.
     *
     * «Abierta» lo define OrdenCompra::ESTADOS_ABIERTOS —borrador, aprobada,
     * enviada, recibida parcial—, el mismo lugar que usa el resto del sistema. Si
     * mañana se agrega un estado vivo, esta consulta lo contempla sola.
     *
     * De CUALQUIER proveedor, a propósito: si la mercadería ya viene en camino, da
     * igual quién la manda. Esto es lo que hace que el comando sea idempotente —se
     * puede correr dos veces seguidas sin pedir dos veces lo mismo— y lo que lo
     * deja reintentar solo: si una corrida generó dos de tres órdenes, la
     * siguiente genera la que faltaba y nada más.
     */
    public function scopeSinPedidoEnCurso(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'ordenCompraLineas.ordenCompra',
            fn (Builder $orden) => $orden->abiertas(),
        );
    }
}