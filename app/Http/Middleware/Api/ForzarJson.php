<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toda petición a `api/*` se trata como si pidiera JSON.
 *
 * Sin esto, un 401 de Laravel es una redirección al login web, y un 404 o un 500 son
 * una página HTML: la app recibiría algo que no puede leer. Va primero en el grupo
 * `api`, antes que cualquier otro middleware.
 *
 * Una ruta inexistente de `api/*` no corre middlewares de grupo, así que esto no la
 * alcanza: ésa la cubre el renderer de errores, que decide por la URL y no por este
 * encabezado.
 *
 * Métodos:
 *   handle()  fija `Accept: application/json`
 */
class ForzarJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
