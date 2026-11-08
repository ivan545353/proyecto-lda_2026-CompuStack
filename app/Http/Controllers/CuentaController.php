<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\CambiarPasswordRequest;
use App\Services\UsuarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La cuenta propia del usuario que está usando el sistema.
 *
 * Reemplaza a `features/account/` del frontend Angular. 
 *
 * Métodos:
 *   editarPassword()      el formulario
 *   actualizarPassword()  el cambio
 */
class CuentaController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function editarPassword(): View
    {
        return view('cuenta.password');
    }

    public function actualizarPassword(CambiarPasswordRequest $request): RedirectResponse
    {
        $this->service->cambiarPassword($request->user(), $request->validated()['password']);

        // Rota el id de sesión después de un cambio de credenciales. La sesión
        // actual sigue viva —cambiar la contraseña no te expulsa, que sería un
        // castigo por hacer lo correcto— pero con un identificador nuevo.
        $request->session()->regenerate();

        return redirect()->route('panel')->with(
            'exito',
            'Tu contraseña se cambió. La próxima vez que inicies sesión usá la nueva.'
        );
    }
}