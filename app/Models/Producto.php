<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /** Qué ve la tienda del stock: un nivel, nunca el número (claves de TextosTienda::DISPONIBILIDAD). */
    public function disponibilidad(): string
    {
        $disponible = $this->stock_disponible;

        return match (true) {
            $disponible <= 0                                           => 'sin_stock',
            $disponible <= config('tienda.disponibilidad.ultimas_unidades') => 'ultimas_unidades',
            default                                                    => 'disponible',
        };
    }

    /**
     * Las primeras `descripcion_breve.palabras` palabras de la descripción (D-12), con «…» si se cortó.
     *
     * Además, nunca más de `descripcion_breve.caracteres`: un texto de 5.000 caracteres sin espacios
     * es una sola «palabra» y viajaría entero en cada fila del listado (pendiente #83).
     */
    public function descripcionBreve(): string
    {
        $palabras = preg_split('/\s+/u', trim((string) $this->descripcion), -1, PREG_SPLIT_NO_EMPTY);
        $limite   = (int) config('tienda.descripcion_breve.palabras');
        $maximo   = (int) config('tienda.descripcion_breve.caracteres');

        $breve  = implode(' ', array_slice($palabras, 0, $limite));
        $cortada = count($palabras) > $limite;

        // mb_*: cortar en bytes partiría una «ñ» o una tilde por la mitad.
        if (mb_strlen($breve) > $maximo) {
            $breve   = rtrim(mb_substr($breve, 0, $maximo));
            $cortada = true;
        }

        return $cortada ? $breve.'…' : $breve;
    }

    /**
     * Los ids de las novedades: los `novedades.tope` más recientes de la tienda, creados dentro
     * de `novedades.dias`. Es la única definición: la sección y la marca `es_novedad` la comparten.
     *
     * Con el catálogo de volumen todos nacen el mismo día: la ventana sola daría todos, y el
     * tope solo, los que sean. Por eso van los dos, y el desempate por id vuelve estable la lista.
     *
     * @return array<int, int>
     */
    public static function idsNovedades(?array $categoriasVisibles = null): array
    {
        return static::query()
            ->enTienda($categoriasVisibles)
            ->where('productos.created_at', '>=', now()->subDays((int) config('tienda.novedades.dias')))
            ->orderByDesc('productos.created_at')
            ->orderByDesc('productos.id')
            ->limit((int) config('tienda.novedades.tope'))
            ->pluck('productos.id')
            ->all();
    }

    /** Si es novedad. En un listado se le pasan los ids ya leídos: una consulta por pedido, no por fila. */
    public function esNovedad(?array $idsNovedades = null): bool
    {
        return in_array($this->id, $idsNovedades ?? self::idsNovedades(), true);
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


    // ------------------------------------------------------------------
    // Tienda (API pública)
    // ------------------------------------------------------------------

    /** Los órdenes que admite el catálogo; `CatalogoRequest` valida contra esta lista. */
    public const ORDENES_TIENDA = ['nombre', 'recientes', 'precio_asc', 'precio_desc'];

    /** Los que exigen `tienda.ver_precios`: ordenar por precio deja inferirlo aunque no viaje. */
    public const ORDENES_CON_PRECIO = ['precio_asc', 'precio_desc'];

    /**
     * Lo que la tienda puede mostrar: activo y en una categoría visible.
     *
     * @param  array<int, int>|null  $categoriasVisibles  ya calculadas por quien arma el pedido; sin ellas se leen
     */
    public function scopeEnTienda(Builder $query, ?array $categoriasVisibles = null): Builder
    {
        return $query
            ->where('productos.activo', true)
            ->whereIn('productos.categoria_id', $categoriasVisibles ?? Categoria::idsVisiblesEnTienda());
    }

    /**
     * Las novedades (ver `idsNovedades`). MariaDB no admite LIMIT dentro de un IN: los ids se leen antes.
     *
     * @param  array<int, int>|null  $ids  ya leídos por quien arma el pedido; sin ellos se leen
     */
    public function scopeNovedades(Builder $query, ?array $ids = null): Builder
    {
        return $query->whereIn('productos.id', $ids ?? self::idsNovedades());
    }

    public function scopeDestacados(Builder $query): Builder
    {
        return $query->where('productos.destacado', true);
    }

    /** Siempre desempata por id: sin eso una página puede repetir o saltear filas. */
    public function scopeOrdenTienda(Builder $query, string $orden): Builder
    {
        return match ($orden) {
            'nombre'      => $query->orderBy('productos.nombre')->orderBy('productos.id'),
            'recientes'   => $query->orderByDesc('productos.created_at')->orderByDesc('productos.id'),
            // Por el de contado: es el que se paga en el local y el más bajo de los dos.
            'precio_asc'  => $query->orderBy('productos.precio_contado')->orderBy('productos.id'),
            'precio_desc' => $query->orderByDesc('productos.precio_contado')->orderByDesc('productos.id'),
            default       => throw new \InvalidArgumentException("Orden de tienda desconocido: {$orden}"),
        };
    }

    /**
     * Una página del catálogo público con los criterios ya normalizados (`CatalogoRequest::criterios()`).
     *
     * Las categorías visibles y los ids de novedad se leen una vez y se reparten: la consulta
     * principal y la marca `es_novedad` de cada fila salen de los mismos números, y una página
     * de 50 productos no cuesta 50 consultas más.
     *
     * @param  array{categoria_id: ?int, q: ?string, orden: string, seccion: ?string}  $criterios
     */
    public static function paginaEnTienda(array $criterios, int $pagina, int $porPagina): LengthAwarePaginator
    {
        $visibles  = Categoria::idsVisiblesEnTienda();
        $novedades = static::idsNovedades($visibles);

        $paginador = static::query()
            ->enTienda($visibles)
            ->when($criterios['seccion'] === 'novedades', fn (Builder $q) => $q->novedades($novedades))
            ->when($criterios['seccion'] === 'destacados', fn (Builder $q) => $q->destacados())
            ->buscar($criterios['q'])
            ->deCategoria($criterios['categoria_id'])
            ->ordenTienda($criterios['orden'])
            ->with('categoria:id,nombre')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $paginador->getCollection()->each(
            fn (Producto $producto) => $producto->setAttribute('es_novedad', in_array($producto->id, $novedades, true))
        );

        return $paginador;
    }

    /**
     * La ficha pública de un producto. Inexistente, inactivo o en una categoría oculta es lo
     * mismo para quien mira: no existe (ModelNotFoundException → 404).
     */
    public static function fichaEnTienda(string $id): Producto
    {
        // La ruta sólo deja pasar dígitos, pero un número más grande que cualquier id no cabe en un
        // int: llega como texto y la base responde que no hay fila, en vez de un TypeError.
        $visibles  = Categoria::idsVisiblesEnTienda();
        $novedades = static::idsNovedades($visibles);

        $producto = static::query()
            ->enTienda($visibles)
            ->with(['categoria:id,nombre', 'marca:id,nombre'])
            ->findOrFail($id);

        $producto->setAttribute('es_novedad', in_array($producto->id, $novedades, true));

        return $producto;
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