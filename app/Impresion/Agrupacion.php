<?php

namespace App\Impresion;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un corte del listado en papel: por qué columna se agrupa, cómo se ordenan los grupos y cómo se nombran.
 */
final class Agrupacion
{
    /**
     * @param  string  $columna  la clave foránea de la tabla del listado
     * @param  Closure(): Builder  $orden  el nombre del grupo como subconsulta, para ordenar sin un join
     * @param  Closure(Model): ?string  $etiqueta
     * @param  (Closure(Model): ?string)|null  $detalle  una línea tenue debajo del nombre
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $rotulo,
        public readonly string $columna,
        public readonly Closure $orden,
        public readonly Closure $etiqueta,
        public readonly string $sinGrupo,
        public readonly ?Closure $detalle = null,
    ) {
    }

    public function etiquetaDe(Model $fila): string
    {
        return ($this->etiqueta)($fila) ?? $this->sinGrupo;
    }

    public function detalleDe(Model $fila): ?string
    {
        return $this->detalle === null ? null : ($this->detalle)($fila);
    }
}