<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelFiltroRequest;
use App\Services\InformeVentasService;
use Illuminate\View\View;

// El ranking de vendedores y los productos más vendidos, que salieron del panel general
// en el tramo L. Se miran una vez por mes: son informes con su período, no avisos.
class InformeVentasController extends Controller
{
    public function __construct(private InformeVentasService $service)
    {
    }

    public function vendedores(PanelFiltroRequest $request): View
    {
        return view('ventas.vendedores', [
            'filas' => $this->service->vendedores($request->desde(), $request->hasta())
                ->paginate(InformeVentasService::POR_PAGINA)
                ->withQueryString(),
            'total'   => $this->service->totalDeVendedores($request->desde(), $request->hasta()),
            'desde'   => $request->desde(),
            'hasta'   => $request->hasta(),
            'elegido' => $request->hasAny(['desde', 'hasta']),
        ]);
    }

    public function productos(PanelFiltroRequest $request): View
    {
        return view('ventas.productos', [
            'filas' => $this->service->productos($request->desde(), $request->hasta())
                ->paginate(InformeVentasService::POR_PAGINA)
                ->withQueryString(),
            'desde'   => $request->desde(),
            'hasta'   => $request->hasta(),
            'elegido' => $request->hasAny(['desde', 'hasta']),
        ]);
    }
}