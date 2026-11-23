<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InformeFiltroRequest;
use App\Http\Resources\Api\InformeProductoResource;
use App\Http\Resources\Api\InformeVendedorResource;
use App\Services\InformeVentasService;
use App\Support\Importe;
use Illuminate\Http\JsonResponse;

/**
 * El ranking de vendedores y los productos más vendidos por la API (tramo H). Muestran el conjunto, así
 * que piden `panel.ver_global`, como en la web. `InformeVentasService` tal cual: esto sólo traduce.
 */
class InformeController extends Controller
{
    public function __construct(private InformeVentasService $service)
    {
    }

    /** `GET /informes/vendedores`: paginado, y `totales` con la misma consulta sin agrupar. */
    public function vendedores(InformeFiltroRequest $request): JsonResponse
    {
        $pagina = $this->service->vendedores($request->desde(), $request->hasta())
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        $total     = $this->service->totalDeVendedores($request->desde(), $request->hasta());
        $respuesta = Paginado::respuesta($pagina, InformeVendedorResource::class, $request->criterios());

        // Una clave más que un listado común: suma lo que listan todas las páginas, no sólo ésta.
        return $respuesta->setData([...$respuesta->getData(true), 'totales' => [
            'cantidad'  => (int) $total->cantidad,
            'facturado' => Importe::json($total->facturado),
            'ticket'    => Importe::json($total->ticket),
        ]]);
    }

    /** `GET /informes/productos`: paginado por unidades vendidas. */
    public function productos(InformeFiltroRequest $request): JsonResponse
    {
        $pagina = $this->service->productos($request->desde(), $request->hasta())
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, InformeProductoResource::class, $request->criterios());
    }
}
