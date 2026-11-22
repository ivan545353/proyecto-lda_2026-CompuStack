<?php

namespace App\Http\Controllers;

use App\Models\FactorCosto;
use App\Models\Impuesto;
use Illuminate\View\View;

/**
 * Las pantallas del módulo Precios (tramo F de la Etapa 3): sólo shells. La política, los impuestos, los
 * factores y el simulador están en la API (Api\V1\PoliticaPreciosController, ImpuestoController y
 * FactorCostoController); la pantalla los pide con pantallas/precios.js.
 *
 * Métodos:
 *   index()          la configuración, vacía: los datos los trae la API
 *   simulador()      el simulador, vacío: lee la URL y pide el cálculo
 *   crearImpuesto() / editarImpuesto()   el formulario de impuesto; en la edición, sólo el id (regla 37)
 *   crearFactor()   / editarFactor()     el formulario de factor; ídem
 */
class PrecioController extends Controller
{
    public function index(): View
    {
        return view('precios.index');
    }

    public function simulador(): View
    {
        return view('precios.simulador');
    }

    public function crearImpuesto(): View
    {
        return view('precios.impuesto');
    }

    // El binding da el 404 de un impuesto que no existe; el impuesto mismo no viaja en la página.
    public function editarImpuesto(Impuesto $impuesto): View
    {
        return view('precios.impuesto', ['impuestoId' => $impuesto->id]);
    }

    public function crearFactor(): View
    {
        return view('precios.factor');
    }

    public function editarFactor(FactorCosto $factor): View
    {
        return view('precios.factor', ['factorId' => $factor->id]);
    }
}
