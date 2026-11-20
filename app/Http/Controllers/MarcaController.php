<?php

namespace App\Http\Controllers;

use App\Models\Marca;
use Illuminate\View\View;

/**
 * Las pantallas de marcas (tramo D de la Etapa 3): sólo shells. Las operaciones, los datos y la
 * impresión están en la API (Api\V1\MarcaController); la pantalla los pide con pantallas/marcas.js.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas las trae la API
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con el id y nada más (regla 37)
 */
class MarcaController extends Controller
{
    public function index(): View
    {
        return view('marcas.index');
    }

    public function create(): View
    {
        return view('marcas.form');
    }

    // El binding da el 404 de una marca que no existe; la marca misma no viaja en la página.
    public function edit(Marca $marca): View
    {
        return view('marcas.form', ['marcaId' => $marca->id]);
    }
}
