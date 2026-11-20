<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas públicas que la gestión también consulta con permiso: el árbol de categorías (tramo D).
 *
 * A diferencia de SesionOpcional, una credencial que no sirve —inventada, vencida, revocada o de
 * una cuenta desactivada— no es 401: el pedido sigue como visitante. La ruta ya respondía sin
 * leer la credencial y la app la manda igual; un 401 ahí cambiaría una conducta del contrato
 * (regla 40). Lo que la gestión pide de más lo corta el permiso, con 403.
 *
 * Métodos:
 *   handle()  identifica al usuario si la credencial sirve; si no, deja pasar al visitante
 */
class CredencialSiSirve
{
    public function handle(Request $request, Closure $next): Response
    {
        if (filled($request->header('Authorization'))) {
            $usuario = Auth::guard('sanctum')->user();

            if ($usuario !== null && $usuario->activo) {
                Auth::shouldUse('sanctum');
            }
        }

        return $next($request);
    }
}
