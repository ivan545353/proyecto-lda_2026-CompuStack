<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

// El ranking de vendedores y los productos más vendidos, que salieron del panel general
// en el tramo L. Se miran una vez por mes: son informes con su período, no avisos.
// Desde el tramo H son shells (regla 37): las filas llegan por `GET /api/v1/informes/...`.
class InformeVentasController extends Controller
{
    public function vendedores(): View
    {
        return view('ventas.vendedores');
    }

    public function productos(): View
    {
        return view('ventas.productos');
    }
}
