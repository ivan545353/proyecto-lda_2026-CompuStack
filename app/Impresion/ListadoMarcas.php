<?php

namespace App\Impresion;

use App\Models\Marca;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las marcas, con cuántos productos tiene cada una.
 */
final class ListadoMarcas extends Listado implements ImprimiblePorEnlace
{
    private const ESTADOS = ['activas' => 'Activas', 'inactivas' => 'Inactivas'];

    public function permiso(): string
    {
        return 'marca.ver';
    }

    /** La del listado de la pantalla, la misma para mirar y para imprimir. */
    public function consulta(array $filtros): Builder
    {
        return Marca::query()
            ->buscar($filtros['q'] ?? null)
            ->conEstado($filtros['estado'] ?? null)
            ->orderBy('nombre');
    }

    public function titulo(): string
    {
        return 'Marcas';
    }

    public function archivo(): string
    {
        return 'marcas';
    }

    protected function sustantivo(): array
    {
        return ['marca', 'marcas'];
    }

    public function conteos(): array
    {
        return ['productos'];
    }

    /** El logo no: es una imagen, y en un listado impreso no identifica nada que el nombre no diga. */
    public function columnas(): array
    {
        return [
            new Columna('nombre', 'Marca', fn (Marca $marca) => $marca->nombre),
            new Columna('estado', 'Estado', fn (Marca $marca) => $marca->activo ? 'Activa' : 'Inactiva'),
            new Columna('productos', 'Productos', fn (Marca $marca) => $marca->productos_count, Columna::ENTERO),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        return array_filter([
            'Búsqueda' => $filtros['q'] ?? null,
            'Estado'   => self::ESTADOS[$filtros['estado'] ?? ''] ?? null,
        ]);
    }
}