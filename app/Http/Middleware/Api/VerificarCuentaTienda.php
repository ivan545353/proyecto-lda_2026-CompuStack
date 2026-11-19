<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La frontera de las rutas propias de la tienda (perfil de cliente, carrito, pedidos online,
 * D-3): una cuenta de gestión no tiene nada de eso. Corre después de `VerificarCuentaActiva`,
 * que revisa que la cuenta siga activa; acá sólo se mira el ámbito.
 *
 * Métodos:
 *   handle()  deja pasar, o lanza el mismo 401 `sesion_invalida` de una credencial que no sirve
 */
class VerificarCuentaTienda
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && $usuario->esDeGestion()) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
