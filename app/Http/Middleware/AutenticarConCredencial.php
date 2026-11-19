<?php

namespace App\Http\Middleware;

use App\Services\CredencialService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * La administración entra con la credencial de la API, no con la sesión (D-30, HL-109).
 *
 * El módulo central guarda la credencial en la cookie `credencial` y la manda como Bearer a la API;
 * la navegación entre pantallas la manda sola, y este middleware la lee para servirlas. Es la misma
 * credencial: revocarla, que venza o desactivar la cuenta corta las dos cosas en el próximo pedido.
 * No guarda nada en la sesión, que queda sólo para el CSRF y los avisos. La API no lee esta cookie.
 */
class AutenticarConCredencial
{
    public const COOKIE = 'credencial';

    public function __construct(private readonly CredencialService $credenciales) {}

    /** Pone en el guard web la cuenta de la credencial, si sirve; si no, la olvida y avisa. */
    public function handle(Request $request, Closure $next): Response
    {
        $credencial = $request->cookie(self::COOKIE);

        if (blank($credencial)) {
            return $next($request);
        }

        $token   = PersonalAccessToken::findToken($credencial);
        $usuario = $token?->tokenable;

        if ($token === null || $this->credenciales->vencio($credencial)
            || ! $usuario?->activo || ! $usuario->esDeGestion()) {
            $vencio = $this->credenciales->vencio($credencial);
            $motivo = $vencio ? 'Tu sesión venció. Ingresá de nuevo.' : 'Tu sesión terminó. Ingresá de nuevo.';

            Cookie::queue(Cookie::forget(self::COOKIE));

            // Un componente que pide JSON seguiría la redirección y tomaría el login por una respuesta.
            if ($request->expectsJson()) {
                return response()->json([
                    'mensaje' => $motivo,
                    'codigo'  => $vencio ? 'sesion_expirada' : 'sesion_invalida',
                    'errores' => (object) [],
                ], 401);
            }

            return redirect()->route('login')->withErrors(['email' => $motivo]);
        }

        Auth::guard('web')->setUser($usuario->withAccessToken($token));

        return $next($request);
    }
}
