<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\View\View;

/**
 * Las pantallas de categorías (tramo D de la Etapa 3): sólo shells. Las operaciones, los datos y la
 * impresión están en la API (Api\V1\CategoriaController); la pantalla los pide con
 * pantallas/categorias.js.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas, la miga de pan y los grupos los trae la API
 *   create()  el formulario de alta; el grupo de la URL (?parent_id=) viene elegido
 *   edit()    el formulario de edición, con el id y nada más (regla 37)
 */
class CategoriaController extends Controller
{
    public function index(): View
    {
        return view('categorias.index');
    }

    public function create(): View
    {
        return view('categorias.form');
    }

    // El binding da el 404 de una categoría que no existe; la categoría misma no viaja en la página.
    public function edit(Categoria $categoria): View
    {
        return view('categorias.form', ['categoriaId' => $categoria->id]);
    }
}
