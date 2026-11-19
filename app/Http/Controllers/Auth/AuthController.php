<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
/**
 * El formulario de ingreso a la administración.
 *
 * Reemplaza al AuthenticationController y al AuthenticationService originales,
 * que emitían un JWT firmado con un secreto escrito en el código y cuyo
 * logout() era un no-op: la sesión no se podía revocar (hallazgos A-4 y A-5).
 *
 * Desde el tramo C de la Etapa 3, entrar y salir van por la API (`POST` y `DELETE
 * /api/v1/sesion`, CredencialService), igual que la app: el formulario lo envía el
 * módulo central y la credencial queda en una cookie (D-30). Las defensas siguen,
 * ahora del lado del servicio: el mismo mensaje para correo inexistente y contraseña
 * incorrecta, una credencial nueva en cada ingreso y el límite de intentos.
 */
class AuthController extends Controller
{
    public function mostrarLogin(): View
    {
        return view('auth.login');
    }
}
