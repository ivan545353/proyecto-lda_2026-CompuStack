<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La envoltura de un recurso de la API (docs/api.md §2): `{ "datos": {…} }`, con `mensaje` si
 * la operación lo trae.
 *
 * Se arma acá y no con la envoltura por omisión de Laravel (`data`): la clave es del contrato,
 * y así no hay un ajuste global que un recurso pueda pisar sin querer. El gemelo de `Paginado`
 * para lo que no es un listado.
 *
 * Métodos:
 *   respuesta()  recurso (+ estado y mensaje opcionales) → JsonResponse
 */
final class Recurso
{
    public static function respuesta(JsonResource $recurso, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        return response()->json(
            ['datos' => $recurso->resolve(), ...($mensaje === null ? [] : ['mensaje' => $mensaje])],
            $estado,
        );
    }
}
