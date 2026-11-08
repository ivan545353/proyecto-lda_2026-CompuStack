<?php

namespace App\Http\Controllers;

use App\Http\Requests\FactorCostoRequest;
use App\Http\Requests\ImpuestoRequest;
use App\Http\Requests\PoliticaPreciosRequest;
use App\Models\FactorCosto;
use App\Models\Impuesto;
use App\Models\PoliticaPrecios;
use App\Services\PrecioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Requests\SimuladorRequest;

/**
 * El módulo Precios: la política del comercio, los impuestos y los factores.
 *
 * Es el noveno módulo y no existe en el sistema original, donde los dos
 * precios se tipeaban producto por producto y las alícuotas estaban fijas en
 * el código.
 *
 * Los dos listados paginan con nombres de página distintos —`impuestos` y
 * `factores`— porque comparten pantalla: con un solo nombre, pasar de página
 * en uno movería también el otro.
 *
 * Métodos:
 *   index()                            la pantalla de factores completa
 *   actualizarPolitica()               los tres números del comercio
 *   crearImpuesto() … eliminarImpuesto()   ABM de impuestos
 *   crearFactor()   … eliminarFactor()     ABM de factores de costo
 */
class PrecioController extends Controller
{
    public function __construct(private PrecioService $service)
    {
    }

    public function index(): View
    {
        return view('precios.index', [
            'politica'  => PoliticaPrecios::vigente(),
            'impuestos' => Impuesto::query()->ordenados()->paginate(25, ['*'], 'impuestos')->withQueryString(),
            'factores'  => FactorCosto::query()->ordenados()->paginate(25, ['*'], 'factores')->withQueryString(),
            'uso'       => $this->service->productosPorAlicuota(),
        ]);
    }

    /**
     * El simulador: un costo suelto y su precio, sin guardar nada.
     *
     * La ganancia vacía significa la global, y la alícuota por omisión es la
     * primera del catálogo, que es la de 21 %.
     */
    public function simulador(SimuladorRequest $request): View
    {
        $politica  = PoliticaPrecios::vigente();
        $alicuotas = Impuesto::opcionesDeAlicuota();

        $costo    = $request->validated('costo');
        $ganancia = $request->validated('ganancia') ?: $politica->ganancia_global;
        $alicuota = $request->validated('alicuota_iva') ?? (string) (array_key_first($alicuotas) ?? '0.00');

        return view('precios.simulador', [
            'politica'  => $politica,
            'alicuotas' => $alicuotas,
            'costo'     => $costo,
            'ganancia'  => $ganancia,
            'alicuota'  => $alicuota,
            'calculo'   => $costo === null ? null : $this->service->simular($costo, $ganancia, $alicuota),
        ]);
    }

    public function actualizarPolitica(PoliticaPreciosRequest $request): RedirectResponse
    {
        $this->service->actualizarPolitica($request->validated());

        return redirect()->route('precios.index')->with(
            'exito',
            'Política de precios actualizada. Los precios ya cargados no cambiaron: se recalculan cuando lo pidas.',
        );
    }

    public function crearImpuesto(): View
    {
        // Las alícuotas de IVA vienen precargadas, así que lo que se crea acá
        // casi siempre es un impuesto que alcanza a todo el catálogo.
        return view('precios.impuesto', [
            'impuesto' => new Impuesto(['alcance' => 'general', 'comprobante' => 'otros_nacionales']),
            'enUso'    => false,
        ]);
    }

    public function guardarImpuesto(ImpuestoRequest $request): RedirectResponse
    {
        $impuesto = $this->service->guardarImpuesto($request->validated());

        return redirect()->route('precios.index')->with(
            'exito',
            "Impuesto «{$impuesto->nombre}» creado. Los precios ya calculados no lo tienen adentro hasta que recalcules.",
        );
    }

    public function editarImpuesto(Impuesto $impuesto): View
    {
        return view('precios.impuesto', [
            'impuesto' => $impuesto,
            'enUso'    => $this->service->estaEnUso($impuesto),
        ]);
    }

    public function actualizarImpuesto(ImpuestoRequest $request, Impuesto $impuesto): RedirectResponse
    {
        $this->service->guardarImpuesto($request->validated(), $impuesto);

        return redirect()->route('precios.index')->with(
            'exito',
            "Impuesto «{$impuesto->nombre}» actualizado. Los precios ya calculados no cambiaron.",
        );
    }

    public function eliminarImpuesto(Impuesto $impuesto): RedirectResponse
    {
        $nombre  = $impuesto->nombre;
        $seBorro = $this->service->eliminarImpuesto($impuesto);

        return redirect()->route('precios.index')->with('exito', $seBorro
            ? "Impuesto «{$nombre}» eliminado."
            : "«{$nombre}» es la alícuota de productos del catálogo: se desactivó en lugar de eliminarse.");
    }

    public function crearFactor(): View
    {
        return view('precios.factor', ['factor' => new FactorCosto(['modo' => 'recargo'])]);
    }

    public function guardarFactor(FactorCostoRequest $request): RedirectResponse
    {
        $factor = $this->service->guardarFactor($request->validated());

        return redirect()->route('precios.index')->with(
            'exito',
            "Factor «{$factor->nombre}» creado. Los precios ya calculados no lo tienen adentro hasta que recalcules.",
        );
    }

    public function editarFactor(FactorCosto $factor): View
    {
        return view('precios.factor', ['factor' => $factor]);
    }

    public function actualizarFactor(FactorCostoRequest $request, FactorCosto $factor): RedirectResponse
    {
        $this->service->guardarFactor($request->validated(), $factor);

        return redirect()->route('precios.index')->with(
            'exito',
            "Factor «{$factor->nombre}» actualizado. Los precios ya calculados no cambiaron.",
        );
    }

    public function eliminarFactor(FactorCosto $factor): RedirectResponse
    {
        $nombre = $factor->nombre;

        $this->service->eliminarFactor($factor);

        return redirect()->route('precios.index')->with(
            'exito',
            "Factor «{$nombre}» eliminado. Los precios que ya lo tenían adentro no cambiaron.",
        );
    }
}