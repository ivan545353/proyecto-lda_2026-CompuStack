<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * La cuenta propia del usuario que está usando el sistema. Desde el tramo I sólo sirve la pantalla: el
 * cambio va por `PUT /api/v1/yo/contrasena` (Api\V1\YoController), que rota la credencial (HL-132).
 *
 * Métodos:
 *   editarPassword()  el formulario, sin datos: el correo lo trae el JS de /yo
 */
class CuentaController extends Controller
{
    public function editarPassword(): View
    {
        return view('cuenta.password');
    }
}
