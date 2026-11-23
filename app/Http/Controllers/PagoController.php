<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * La pantalla de cobro de una venta. Registrar el cobro pasó a la API en el tramo H.
 */
class PagoController extends Controller
{
    /** El shell del cobro (tramo H): el saldo y los avisos llegan en la ficha; cobrar es `POST /api/v1/ventas/{venta}/pagos`. */
    public function create(Venta $venta): View|RedirectResponse
    {
        // La API vuelve a exigirlo al cobrar; esto es la red para una URL guardada en favoritos.
        if (! MaquinaEstadosVenta::puede($venta->estado, 'pagada')) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no "
                .'se cobra. Una venta se cobra una sola vez.',
            );
        }

        return view('ventas.cobro', ['venta' => $venta]);
    }
}
