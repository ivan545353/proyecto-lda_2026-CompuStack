<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\View\View;

/**
 * Las pantallas del kardex y del ajuste de inventario (shells, regla 37). Desde el tramo F de la Etapa 3
 * los movimientos, su impresión y el ajuste son de la API: Api\V1\StockController. El permiso lo pide la ruta.
 */
class StockController extends Controller
{
    /** Los filtros vienen en la URL; los movimientos y el panel del producto los pide el JS. */
    public function index(): View
    {
        return view('stock.index');
    }

    /** El producto se resuelve para que uno que no existe sea un 404, pero a la vista sólo va su id. */
    public function crearAjuste(Producto $producto): View
    {
        return view('stock.ajuste', ['productoId' => $producto->id]);
    }
}
