<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use Illuminate\View\View;

/**
 * Las pantallas de roles y permisos (tramo I de la Etapa 3): sólo shells. El alta, la edición y la baja
 * están en la API (Api\V1\RolController), que llama a RolService; la pantalla las pide con
 * pantallas/roles.js.
 *
 * Es el módulo que pide la consigna de la Etapa 1: el administrador tiene que poder ver y modificar qué
 * hace cada rol sin que nadie recompile nada.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas las trae la API (regla 37)
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con el id y nada más
 */
class RolController extends Controller
{
    public function index(): View
    {
        return view('roles.index');
    }

    public function create(): View
    {
        return view('roles.form');
    }

    // El binding da el 404 de un rol que no existe; el rol mismo no viaja en la página.
    public function edit(Rol $rol): View
    {
        return view('roles.form', ['rolId' => $rol->id]);
    }
}
