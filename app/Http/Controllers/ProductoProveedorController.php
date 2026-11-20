<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\View\View;

/**
 * Proveedores de un producto: las pantallas del comparador (tramo D). Son shells: los vínculos, el
 * alta y las acciones van por /api/v1/productos/{p}/proveedores. Las dos rutas exigen producto.editar.
 */
class ProductoProveedorController extends Controller
{
    public function index(Producto $producto): View
    {
        return view('productos.proveedores', ['productoId' => $producto->id]);
    }

    /** Un proveedor que no provee este producto da 404, en lugar de abrir una pantalla que va a fallar. */
    public function edit(Producto $producto, int $proveedor): View
    {
        $producto->proveedores()->findOrFail($proveedor);

        return view('productos.proveedor', ['productoId' => $producto->id, 'proveedorId' => $proveedor]);
    }
}
