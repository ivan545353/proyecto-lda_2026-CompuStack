<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\View\View;

/**
 * Las pantallas de direcciones de un cliente (tramo E de la Etapa 3): sólo shells. Las operaciones
 * están en la API (Api\V1\DireccionController); la pantalla las pide con pantallas/direcciones.js.
 *
 * La dirección se busca SIEMPRE dentro de las del cliente, nunca por binding implícito: Laravel resuelve
 * cada parámetro por separado y sin esto /clientes/1/direcciones/99 abriría la dirección de otro cliente.
 * (`scopeBindings()` deduce la relación pluralizando en inglés y no da `direcciones`.)
 * Las dos rutas exigen `cliente.editar`: una dirección no existe sin su cliente.
 *
 * Métodos:
 *   create()  el formulario de alta
 *   edit()    el formulario de edición, con los ids y nada más (regla 37); 404 si la dirección es ajena
 */
class DireccionController extends Controller
{
    public function create(Cliente $cliente): View
    {
        return view('clientes.direccion', ['clienteId' => $cliente->id]);
    }

    public function edit(Cliente $cliente, int $direccion): View
    {
        $cliente->direcciones()->findOrFail($direccion);

        return view('clientes.direccion', ['clienteId' => $cliente->id, 'direccionId' => $direccion]);
    }
}
