<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pantalla pública donde una persona fija su contraseña con un enlace. Desde el tramo I sólo sirve la
 * página: el envío va por `POST /api/v1/contrasena/restablecer` (Api\V1\ContrasenaController).
 *
 * Métodos:
 *   mostrar()  el formulario, con el token y el correo del enlace
 */
class RestablecerPasswordController extends Controller
{
    public function mostrar(Request $request, string $token): View
    {
        // No se verifica el token todavía: hacerlo acá revelaría si es válido
        // sin costo. La verificación ocurre al enviar, donde hay throttle.
        return view('auth.restablecer', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }
}
