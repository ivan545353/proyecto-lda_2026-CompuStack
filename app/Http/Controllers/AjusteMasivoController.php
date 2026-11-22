<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Las pantallas del ajuste masivo (shells, regla 37). Desde el tramo F de la Etapa 3 la vista previa,
 * aplicar, el historial y deshacer son de la API: Api\V1\AjusteMasivoController. El permiso lo pide la ruta.
 */
class AjusteMasivoController extends Controller
{
    /** El formulario: lo que el operador eligió viene en la URL, y la vista previa la pide el JS. */
    public function index(): View
    {
        return view('precios.ajuste');
    }

    public function lotes(): View
    {
        return view('precios.lotes');
    }
}
