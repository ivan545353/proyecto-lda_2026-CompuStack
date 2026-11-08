<?php

namespace App\Impresion;

use App\Models\Categoria;

/**
 * El árbol de categorías en una lista, con cuántas subcategorías y productos cuelgan de cada una.
 */
final class ListadoCategorias extends Listado
{
    private const ESTADOS = ['activas' => 'Activas', 'inactivas' => 'Inactivas'];

    public function titulo(): string
    {
        return 'Categorías';
    }

    public function archivo(): string
    {
        return 'categorias';
    }

    protected function sustantivo(): array
    {
        return ['categoría', 'categorías'];
    }

    /** Tres niveles: la ruta del padre de una nieta llega hasta la raíz sin una consulta por fila. */
    public function relaciones(): array
    {
        return ['padre.padre.padre'];
    }

    public function conteos(): array
    {
        return ['hijas', 'productos'];
    }

    public function columnas(): array
    {
        return [
            new Columna('nombre', 'Categoría', fn (Categoria $categoria) => $categoria->nombre),
            new Columna('dentro_de', 'Dentro de', fn (Categoria $categoria) => $categoria->padre?->ruta),
            new Columna('estado', 'Estado', fn (Categoria $categoria) => $categoria->activo ? 'Activa' : 'Inactiva'),
            new Columna('subcategorias', 'Subcategorías', fn (Categoria $categoria) => $categoria->hijas_count,
                Columna::ENTERO),
            new Columna('productos', 'Productos', fn (Categoria $categoria) => $categoria->productos_count,
                Columna::ENTERO),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        $padre = $filtros['parent_id'] ?? null;

        return array_filter([
            'Búsqueda' => $filtros['q'] ?? null,
            'Mostrar'  => match (true) {
                $padre === 'raiz'            => 'Sólo las categorías principales',
                ctype_digit((string) $padre) => 'Subcategorías de '
                    .(Categoria::with('padre.padre')->find((int) $padre)?->ruta ?? 'una categoría que no existe'),
                default                      => null,
            },
            'Estado'   => self::ESTADOS[$filtros['estado'] ?? ''] ?? null,
        ]);
    }
}