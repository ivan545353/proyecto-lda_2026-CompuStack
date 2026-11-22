<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\View\View;

/**
 * Las pantallas de proveedores (tramo E de la Etapa 3): sólo shells. Las operaciones, los datos y la
 * impresión están en la API (Api\V1\ProveedorController); la pantalla los pide con pantallas/proveedores.js.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas las trae la API
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con el id y nada más (regla 37)
 */
class ProveedorController extends Controller
{
    public function index(): View
    {
        return view('proveedores.index');
    }

    public function create(): View
    {
        return view('proveedores.form');
    }

    // El binding da el 404 de un proveedor que no existe; el proveedor mismo no viaja en la página.
    public function edit(Proveedor $proveedor): View
    {
        return view('proveedores.form', ['proveedorId' => $proveedor->id]);
    }
}
