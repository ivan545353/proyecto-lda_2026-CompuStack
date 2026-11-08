<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * La envoltura de todo listado de la API (docs/api.md §6.2).
 *
 * Un listado pagina siempre: sin una sola excepción, la app tiene una sola forma de
 * leerlos. Una página después de la última devuelve `datos` vacío con sus metadatos,
 * no 404: la app no tiene que distinguir «no hay más» de «se rompió».
 *
 * `criterios` devuelve lo que el servidor aplicó, ya normalizado. La app descarta la
 * respuesta que no coincide con los criterios de su pantalla: es lo que impide que
 * una respuesta lenta pise a una más nueva (1.12). Siempre es un objeto, aunque no
 * haya criterios.
 *
 * El orden (que desempata siempre por `id`) lo arma quien construye el paginador:
 * esta clase sólo da forma a la respuesta.
 *
 * Métodos:
 *   respuesta()  paginador + clase de recurso + criterios → JsonResponse
 */
final class Paginado
{
    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @param  array<string, mixed>  $criterios
     */
    public static function respuesta(LengthAwarePaginator $pagina, string $resource, array $criterios): JsonResponse
    {
        return response()->json([
            'datos'         => $resource::collection($pagina->getCollection())->resolve(),
            'pagina'        => $pagina->currentPage(),
            'por_pagina'    => $pagina->perPage(),
            'ultima_pagina' => $pagina->lastPage(),
            'total'         => $pagina->total(),
            'criterios'     => $criterios === [] ? new \stdClass : $criterios,
        ]);
    }
}
