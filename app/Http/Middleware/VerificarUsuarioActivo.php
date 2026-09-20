<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la sesión de un usuario deshabilitado en su próximo pedido.
 *
 * Registrado en el grupo web completo, así que corre en cada pedido con
 * sesión. Cierra el hallazgo A-5: en el sistema original el perfil viajaba
 * dentro del JWT y el estado se leía una sola vez, en el login. Deshabilitar a
 * alguien no lo desconectaba: seguía operando hasta que expirara el token, una
 * hora más tarde.
 *
 * Métodos:
 *   handle()        verifica `activo` y deja pasar o corta
 *   cerrarSesion()  cierra, invalida, regenera el token CSRF y redirige
 */
class VerificarUsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->activo) {
            return $this->cerrarSesion($request, 'Su cuenta fue deshabilitada.');
        }

        return $next($request);
    }

    private function cerrarSesion(Request $request, string $motivo): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $motivo]);
    }
}