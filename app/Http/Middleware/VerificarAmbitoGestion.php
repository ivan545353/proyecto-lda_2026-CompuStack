<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el sistema de gestión a los roles de ámbito 'gestion'.
 *
 * Registrado como alias 'gestion' y aplicado sólo a ese grupo de rutas, porque
 * cuando exista la tienda habrá rutas donde no debe aplicarse.
 *
 * El ámbito se lee de la tabla roles. En la Etapa 1 no existe la tienda, así
 * que a un usuario de ámbito 'tienda' se le cierra la sesión en vez de
 * redirigirlo: no hay a dónde mandarlo.
 *
 * Métodos:
 *   handle()  verifica el ámbito del rol
 */
class VerificarAmbitoGestion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->esDeGestion()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Esta cuenta no tiene acceso al sistema de gestión.',
            ]);
        }

        return $next($request);
    }
}