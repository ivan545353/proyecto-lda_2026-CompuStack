<?php

namespace App\Impresion;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;

/**
 * El catálogo: qué columnas imprime, cuáles piden permiso y cómo nombra sus filtros.
 */
final class ListadoProductos extends Listado
{
    private const ESTADOS = ['activos' => 'Activos', 'inactivos' => 'Inactivos'];

    private const STOCK = [
        'disponible'    => 'Con stock',
        'agotado'       => 'Sin stock',
        'critico'       => 'Stock bajo o agotado',
        'sin_proveedor' => 'Para reponer y sin proveedor',
    ];

    public function titulo(): string
    {
        return 'Catálogo de productos';
    }

    public function archivo(): string
    {
        return 'catalogo';
    }

    protected function sustantivo(): array
    {
        return ['producto', 'productos'];
    }

    public function relaciones(): array
    {
        return ['categoria.padre.padre', 'marca'];
    }

    /** El código es único y es por donde se busca en una lista impresa. */
    public function columnaDeTanda(): string
    {
        return 'codigo';
    }

    /** Por proveedor no: un producto que se le compra a dos aparecería en los dos grupos (§5, punto 3). */
    public function agrupaciones(): array
    {
        return [
            'categoria' => new Agrupacion('categoria', 'Categoría', 'categoria_id',
                orden: fn () => Categoria::select('nombre')->whereColumn('categorias.id', 'productos.categoria_id'),
                etiqueta: fn (Producto $producto) => $producto->categoria->nombre,
                sinGrupo: 'Sin categoría',
                detalle: fn (Producto $producto) => $producto->categoria->ruta),
            'marca' => new Agrupacion('marca', 'Marca', 'marca_id',
                orden: fn () => Marca::select('nombre')->whereColumn('marcas.id', 'productos.marca_id'),
                etiqueta: fn (Producto $producto) => $producto->marca?->nombre,
                sinGrupo: 'Sin marca'),
        ];
    }

    public function columnas(): array
    {
        return [
            new Columna('codigo', 'Código', fn (Producto $producto) => $producto->codigo),
            new Columna('nombre', 'Producto', fn (Producto $producto) => $producto->nombre),
            new Columna('marca', 'Marca', fn (Producto $producto) => $producto->marca?->nombre),
            new Columna('categoria', 'Categoría', fn (Producto $producto) => $producto->categoria->ruta,
                porOmision: false),
            new Columna('precio_contado', 'Precio contado', fn (Producto $producto) => $producto->precio_contado,
                Columna::IMPORTE),
            new Columna('precio_lista', 'Precio de lista', fn (Producto $producto) => $producto->precio_lista,
                Columna::IMPORTE, porOmision: false),
            new Columna('disponible', 'Disponible', fn (Producto $producto) => $producto->stock_disponible,
                Columna::ENTERO),
            new Columna('stock_minimo', 'Mínimo', fn (Producto $producto) => $producto->stock_minimo,
                Columna::ENTERO, porOmision: false),
            new Columna('estado', 'Estado', fn (Producto $producto) => $producto->activo ? 'Activo' : 'Inactivo',
                porOmision: false),
            // Cero es «todavía no se recibió»: no es un costo y no se imprime como uno.
            new Columna('costo', 'Costo (sin IVA)',
                fn (Producto $producto) => (float) $producto->costo_promedio > 0 ? $producto->costo_promedio : null,
                Columna::IMPORTE, porOmision: false, permiso: 'precio.ver_costo'),
            new Columna('margen', 'Margen', fn (Producto $producto) => $producto->margen(),
                Columna::PORCENTAJE, porOmision: false, permiso: 'precio.ver_costo'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        return array_filter([
            'Búsqueda'  => $filtros['q'] ?? null,
            'Categoría' => self::nombreDe($filtros['categoria_id'] ?? null,
                fn (int $id) => Categoria::with('padre.padre')->find($id)?->ruta, 'una categoría que no existe'),
            'Marca'     => self::nombreDe($filtros['marca_id'] ?? null,
                fn (int $id) => Marca::find($id)?->nombre, 'una marca que no existe'),
            'Proveedor' => self::nombreDe($filtros['proveedor_id'] ?? null,
                fn (int $id) => Proveedor::find($id)?->razon_social, 'un proveedor que no existe'),
            'Estado'    => self::ESTADOS[$filtros['estado'] ?? ''] ?? null,
            'Stock'     => self::STOCK[$filtros['stock'] ?? ''] ?? null,
        ]);
    }

    /** Un id que no existe filtra y no devuelve nada: el papel lo dice en lugar de omitirlo. */
    private static function nombreDe(mixed $id, \Closure $buscar, string $inexistente): ?string
    {
        if (! ctype_digit((string) $id)) {
            return null;
        }

        return $buscar((int) $id) ?? $inexistente;
    }
}