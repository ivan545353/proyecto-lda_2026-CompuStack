<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\View\View;

/**
 * Las pantallas de clientes (tramo E de la Etapa 3): sólo shells. Las operaciones, los datos, el
 * acceso a la tienda y la impresión están en la API (Api\V1\ClienteController); la pantalla los pide
 * con pantallas/clientes.js.
 *
 * Métodos:
 *   index()   el listado, vacío: las filas las trae la API
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con el id y nada más (regla 37)
 */
class ClienteController extends Controller
{
    public function index(): View
    {
        return view('clientes.index');
    }

    public function create(): View
    {
        return view('clientes.form');
    }

    // El binding da el 404 de un cliente que no existe; el cliente mismo no viaja en la página.
    public function edit(Cliente $cliente): View
    {
        return view('clientes.form', ['clienteId' => $cliente->id]);
    }
}
