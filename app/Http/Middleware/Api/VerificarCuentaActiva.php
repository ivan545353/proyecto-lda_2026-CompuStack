<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con un usuario autenticado por credencial, exige que su cuenta siga activa. Es lo común a
 * los dos clientes (HL-98): se revalida en CADA pedido, porque una cuenta desactivada no tiene
 * que seguir operando hasta que su credencial venza. `UsuarioService` además revoca las
 * credenciales al desactivar; esto es el cinturón de esa operación.
 *
 * Métodos:
 *   handle()  deja pasar, o lanza el 401 `sesion_invalida`
 */
class VerificarCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->activo) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
