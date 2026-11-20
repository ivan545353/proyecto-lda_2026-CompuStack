<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\View\View;

/**
 * Las pantallas de productos (tramo D de la Etapa 3): sólo shells. Las operaciones, los datos, el
 * recálculo y la impresión están en la API (Api\V1\ProductoController); la pantalla los pide con
 * pantallas/productos.js.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas y las opciones de los filtros las trae la API
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con el id y nada más (regla 37)
 */
class ProductoController extends Controller
{
    public function index(): View
    {
        return view('productos.index');
    }

    public function create(): View
    {
        return view('productos.form');
    }

    // El binding da el 404 de un producto que no existe; el producto mismo no viaja en la página.
    public function edit(Producto $producto): View
    {
        return view('productos.form', ['productoId' => $producto->id]);
    }
}
