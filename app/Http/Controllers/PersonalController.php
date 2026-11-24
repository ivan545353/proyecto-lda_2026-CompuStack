<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * Las pantallas del personal (tramo I de la Etapa 3): sólo shells. El alta, la edición, la baja, el cambio
 * de rol y el enlace de restablecimiento están en la API (Api\V1\PersonalController); la pantalla los pide
 * con pantallas/personal.js.
 *
 * Reemplaza al UserController original, que devolvía hashes de contraseña (C-1), permitía la escalada de
 * privilegios (C-3) y cuyos filtros no filtraban (A-24). Esas correcciones viven ahora en el endpoint.
 *
 * Toda ruta que recibe una persona pasa por `soloPersonal()`: el binding resuelve cualquier fila de
 * `users`, y una cuenta de la tienda no es de esta pantalla (404).
 *
 * Métodos:
 *   index()      el listado, vacío: las filas las trae la API (regla 37)
 *   create()     el formulario de alta
 *   edit()       el formulario de edición, con el id y nada más
 *   editarRol()  la pantalla del cambio de rol, con el id y nada más
 */
class PersonalController extends Controller
{
    public function index(): View
    {
        return view('personal.index');
    }

    public function create(): View
    {
        return view('personal.form');
    }

    public function edit(User $usuario): View
    {
        return view('personal.form', ['usuarioId' => $this->soloPersonal($usuario)->id]);
    }

    public function editarRol(User $usuario): View
    {
        return view('personal.rol', ['usuarioId' => $this->soloPersonal($usuario)->id]);
    }

    /** Una cuenta de la tienda da 404: no es que no se pueda editar, es que esta pantalla no es la suya. */
    private function soloPersonal(User $usuario): User
    {
        abort_unless($usuario->esDeGestion(), 404);

        return $usuario;
    }
}
