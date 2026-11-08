<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas que sirven a todos y mejoran con sesión (el catálogo: con sesión y permiso,
 * trae los precios).
 *
 * Sin `Authorization`, el pedido sigue como visitante. Con `Authorization`, la
 * credencial tiene que servir: si no, **401 `sesion_invalida`** y no un visitante
 * silencioso. Si no, una credencial vencida haría desaparecer los precios sin
 * ninguna explicación (docs/api.md §6.4).
 *
 * Cualquier encabezado `Authorization` cuenta como un intento, aunque no sea
 * `Bearer`: quien lo manda cree estar identificándose.
 *
 * Métodos:
 *   handle()  deja pasar al visitante, o autentica con el guard `sanctum`
 */
class SesionOpcional
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! filled($request->header('Authorization'))) {
            return $next($request);
        }

        if (Auth::guard('sanctum')->user() === null) {
            throw new AuthenticationException;
        }

        // Para que `$request->user()` y `auth()->user()` lean este guard.
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
