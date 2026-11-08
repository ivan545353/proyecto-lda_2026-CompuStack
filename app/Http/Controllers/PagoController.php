<?php

namespace App\Http\Controllers;

use App\Http\Requests\PagoRequest;
use App\Models\Pago;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Services\PagoService;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * El cobro de una venta.
 *
 * Métodos:
 *   create()  la pantalla de cobro, con el saldo y los avisos de lo que va a fallar
 *   store()   registra el cobro y pasa la venta a pagada
 */
class PagoController extends Controller
{
    public function __construct(private PagoService $service)
    {
    }

    /**
     * La pantalla de cobro.
     */
    public function create(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puede($venta->estado, 'pagada')) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no "
                .'se cobra. Una venta se cobra una sola vez.',
            );
        }

        $venta->load([
            'cliente:id,razon_social',
            'lineas.producto:id,codigo,nombre,stock,stock_reservado,activo',
        ]);

        return view('ventas.cobro', [
            'venta'     => $venta,
            'saldo'     => $venta->saldo(),
            'medios'    => Pago::METODOS_EN_USO,
            'problemas' => $this->problemasQueVanAFallar($venta),
        ]);
    }

    public function store(PagoRequest $request, Venta $venta): RedirectResponse
    {
        $venta = $this->service->cobrar($venta, $request->validated(), $request->user());
        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Venta {$venta->numeroFormateado()} cobrada por ".Importe::pesos($venta->pagado())
            .". Se descontó el stock de {$venta->lineas->count()} producto(s) y quedó registrado "
            .'en el kardex.',
        );
    }

    /**
     * Los renglones que van a hacer fallar el cobro, en palabras.
     *
     * @return array<int, string>
     */
    private function problemasQueVanAFallar(Venta $venta): array
    {
         $faltantes = $venta->faltantesDeStock()->keyBy('id');

        return $venta->lineas
            ->map(function (VentaLinea $linea) use ($faltantes) {
                if (! $linea->producto->activo) {
                    return "«{$linea->descripcion}» está dado de baja en el catálogo. Hay que volver a "
                        .'activarlo, o quitar el renglón del presupuesto.';
                }

                if ($faltantes->has($linea->id)) {
                    return "«{$linea->descripcion}»: se venden {$linea->cantidad} unidad(es) y hay "
                        ."{$linea->producto->stock_disponible} disponible(s).";
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }
}