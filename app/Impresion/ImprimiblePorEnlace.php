<?php

namespace App\Impresion;

use Illuminate\Database\Eloquent\Builder;

/**
 * Un listado que se imprime por enlace firmado (D-22, tramo D): sabe qué permiso pide y cómo
 * armar su consulta con los filtros ya validados, sin un controlador web de por medio.
 */
interface ImprimiblePorEnlace
{
    /** El permiso que imprimirlo exige: el de ver el listado. */
    public function permiso(): string;

    /** @param  array<string, mixed>  $filtros  los del Form Request de filtros, ya validados */
    public function consulta(array $filtros): Builder;
}
