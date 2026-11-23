<?php

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use App\Support\MaquinaEstadosCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Órdenes de compra: sólo las pantallas (tramo G). Toda escritura —el armador, la edición, las
 * transiciones, la recepción— y el PDF van por `Api\V1\CompraController`, y cada shell los pide
 * desde el JS. Lo único que queda acá son las guardias de la edición y la recepción: leen el
 * estado para no abrir una pantalla que no sirve, y no rinden datos.
 *
 * Métodos:
 *   index()      el listado
 *   sugeridos()  qué hay que reponer: la entrada al armador
 *   armar()      el armador: un borrador por proveedor
 *   show()       la ficha, con el historial de recepciones
 *   edit()       la edición del borrador; si ya no es borrador, vuelve al listado
 *   recepcion()  la recepción; si el estado no deja recibir, vuelve a la ficha
 */
class CompraController extends Controller
{
    /** El shell del listado (tramo G): las órdenes, los filtros y el paginado llegan por `GET /api/v1/compras`. */
    public function index(): View
    {
        return view('compras.index');
    }

    /** Qué hay que reponer: el shell; la lista llega por `GET /api/v1/compras/sugeridos`. */
    public function sugeridos(): View
    {
        return view('compras.sugeridos');
    }

    /** El armador: el shell; los renglones llegan por `GET /api/v1/compras/armador` y guarda `POST /api/v1/compras`. */
    public function armar(): View
    {
        return view('compras.pedido');
    }

    /** El shell de la ficha (tramo G): la orden llega por `GET /api/v1/compras/{orden}`; acá sólo su id. */
    public function show(OrdenCompra $orden): View
    {
        return view('compras.show', ['orden' => $orden]);
    }

    /** El shell de la edición (tramo G): la guardia lee el estado; la orden llega por `GET /api/v1/compras/{orden}`. */
    public function edit(OrdenCompra $orden): View|RedirectResponse
    {
        if (! $orden->esEditable()) {
            return redirect()->route('compras.index')->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y ya no se puede modificar: "
                .'el monto quedó comprometido al aprobarla.'
            );
        }

        return view('compras.form', ['orden' => $orden]);
    }

    /** El shell de la recepción (tramo G): la guardia lee el estado; las líneas llegan por `GET /api/v1/compras/{orden}`. */
    public function recepcion(OrdenCompra $orden): View|RedirectResponse
    {
        if (! MaquinaEstadosCompra::puedeRecibir($orden->estado)) {
            return redirect()->route('compras.show', $orden)->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y no puede recibir "
                .'mercadería. Sólo se recibe contra una orden aprobada, enviada o parcialmente recibida.',
            );
        }

        return view('compras.recepcion', ['orden' => $orden]);
    }
}
