<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
/**
 * Inicio y cierre de sesión.
 *
 * Reemplaza al AuthenticationController y al AuthenticationService originales,
 * que emitían un JWT firmado con un secreto escrito en el código y cuyo
 * logout() era un no-op: la sesión no se podía revocar (hallazgos A-4 y A-5).
 *
 * Tres defensas que el original no tenía:
 *   - mismo mensaje para correo inexistente y contraseña incorrecta, para no
 *     permitir averiguar qué cuentas existen
 *   - regeneración del identificador de sesión al autenticar, contra la
 *     fijación de sesión
 *   - límite de intentos, declarado en la ruta (hallazgo A-6)
 *
 * Métodos:
 *   mostrarLogin()  devuelve el formulario
 *   login()         verifica credenciales, estado y ámbito; abre la sesión
 *   logout()        cierra, invalida y regenera el token CSRF
 *   rechazar()      arma la vuelta al formulario con el error
 */
class AuthController extends Controller
{
    public function mostrarLogin(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credenciales = $request->validated();

        // Mismo mensaje para correo inexistente y contraseña incorrecta: decir
        // cuál de los dos falló permite enumerar las cuentas del sistema.
        if (! Auth::attempt(
            ['email' => $credenciales['email'], 'password' => $credenciales['password']],
            $request->boolean('recordarme'),
        )) {
            return $this->rechazar('Las credenciales no coinciden con nuestros registros.');
        }

        if (! Auth::user()->activo) {
            Auth::logout();

            return $this->rechazar('Su cuenta está deshabilitada. Contacte a un administrador.');
        }

        // En la Etapa 1 no existe la tienda: una cuenta de ámbito 'tienda' no
        // tiene a dónde ir. El ámbito se lee de la tabla roles, nunca del nombre.
        if (! Auth::user()->esDeGestion()) {
            Auth::logout();

            return $this->rechazar('Esta cuenta no tiene acceso al sistema de gestión.');
        }

        // Previene la fijación de sesión: el identificador cambia al autenticarse.
        $request->session()->regenerate();

        return redirect()->intended(route('panel'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function rechazar(string $motivo): RedirectResponse
    {
        return back()->withErrors(['email' => $motivo])->onlyInput('email');
    }
}