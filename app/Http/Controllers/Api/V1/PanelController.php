<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PanelFiltroRequest;
use App\Http\Resources\Api\PanelGeneralResource;
use App\Http\Resources\Api\PanelPropioResource;
use App\Services\PanelService;
use Illuminate\Http\JsonResponse;

/**
 * Los dos paneles por la API (tramo H), con un permiso cada uno (C-2): lo propio es una cosa y el conjunto
 * es otra. `PanelService` tal cual: el controlador sólo traduce.
 */
class PanelController extends Controller
{
    public function __construct(private PanelService $service)
    {
    }

    /** `GET /panel`: lo que vendí y cobré en el período, contra el anterior. */
    public function propio(PanelFiltroRequest $request): JsonResponse
    {
        $metricas = $this->service->paraVendedor($request->user(), $request->desde(), $request->hasta());

        return Recurso::respuesta(new PanelPropioResource($metricas, $request->desde(), $request->hasta()));
    }

    /** `GET /panel/general`: el conjunto del período, lo que requiere atención y las dos series. */
    public function general(PanelFiltroRequest $request): JsonResponse
    {
        $metricas = $this->service->paraAdministrativo($request->desde(), $request->hasta());

        return Recurso::respuesta(new PanelGeneralResource($metricas, $request->desde(), $request->hasta()));
    }
}
