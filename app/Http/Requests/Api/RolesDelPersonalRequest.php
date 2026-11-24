<?php

namespace App\Http\Requests\Api;

/**
 * Las opciones de rol del personal (tramo I): las usan el filtro del listado (`usuario.ver`) y el alta
 * (`usuario.crear`). Es la única ruta con dos permisos posibles, y por eso se decide acá y no con un
 * `can:` en la ruta: quien puede dar de alta tiene que poder elegir el rol aunque no vea el listado.
 */
class RolesDelPersonalRequest extends PaginadoRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('usuario.ver') || $this->user()->can('usuario.crear');
    }
}
