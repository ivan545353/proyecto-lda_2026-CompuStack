<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La mitad API de `VerificarUsuarioActivo` (plan §1.3 A, hallazgo A-5 reabierto).
 *
 * Con un usuario autenticado por credencial, exige que su cuenta esté activa y sea de
 * la tienda. Se revalida en CADA pedido, no sólo al iniciar sesión: una cuenta
 * desactivada no tiene que seguir operando hasta que su credencial venza. Además,
 * `UsuarioService` revoca las credenciales al desactivar; esto es el cinturón de
 * esa operación.
 * Métodos:
 *   handle()  deja pasar, o lanza el mismo 401 `sesion_invalida` de una credencial vencida
 */
class VerificarCuentaTienda
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && (! $usuario->activo || $usuario->esDeGestion())) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
