<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RestablecerPasswordRequest;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password as BrokerDePassword;
use Illuminate\View\View;

/**
 * Pantalla pública donde una persona fija su contraseña con un enlace.
 *
 * Métodos:
 *   mostrar()      el formulario, con el token y el correo del enlace
 *   restablecer()  verifica el token con el broker y guarda la contraseña
 */
class RestablecerPasswordController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function mostrar(Request $request, string $token): View
    {
        // No se verifica el token todavía: hacerlo acá revelaría si es válido
        // sin costo. La verificación ocurre al enviar, donde hay throttle.
        return view('auth.restablecer', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function restablecer(RestablecerPasswordRequest $request): RedirectResponse
    {
        // El broker valida el token contra el hash y el vencimiento, llama al
        // callback y después borra el token: el enlace sirve una sola vez.
        $estado = BrokerDePassword::reset(
            $request->validated(),
            fn (User $usuario, string $password) => $this->service->cambiarPassword($usuario, $password),
        );

        if ($estado !== BrokerDePassword::PASSWORD_RESET) {
            // Un token vencido, ya usado o de otra cuenta caen todos acá, con el
            // mensaje del broker traducido por lang/es/passwords.php.
            return back()->withErrors(['email' => __($estado)])->onlyInput('email');
        }

        // No se inicia sesión sola. Que la persona entre con la contraseña nueva
        // confirma que quedó bien, y evita abrirle sesión a una cuenta que el
        // login podría rechazar por otro motivo (desactivada, ámbito tienda):
        // esos casos tienen su propio mensaje y no es tarea de esta pantalla.
        return redirect()->route('login')->with(
            'exito',
            'Tu contraseña quedó configurada. Ya podés iniciar sesión.'
        );
    }
}