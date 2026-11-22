<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PoliticaPreciosRequest;
use App\Http\Requests\Api\SimulacionRequest;
use App\Http\Resources\Api\PoliticaPreciosResource;
use App\Models\PoliticaPrecios;
use App\Services\PrecioService;
use App\Support\Api\Desglose;
use App\Support\Importe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La política de precios y el simulador (tramo F): traduce entre el contrato y PrecioService. El permiso
 * lo pide la ruta.
 *
 * Métodos:
 *   show()       la política vigente
 *   update()     los cuatro valores; los precios ya cargados no cambian hasta que se recalculen
 *   simulacion() el precio de un costo suelto, con su desglose; no guarda nada
 */
class PoliticaPreciosController extends Controller
{
    public function __construct(private PrecioService $service)
    {
    }

    public function show(): JsonResponse
    {
        return Recurso::respuesta(new PoliticaPreciosResource(PoliticaPrecios::vigente()));
    }

    public function update(PoliticaPreciosRequest $request): JsonResponse
    {
        return Recurso::respuesta(
            new PoliticaPreciosResource($this->service->actualizarPolitica($request->validated())),
            200,
            'Política de precios actualizada. Los precios ya cargados no cambiaron: se recalculan cuando lo pidas.',
        );
    }

    /** La ganancia ausente es la global: lo dice `ganancia_es_global`, para que la pantalla no lo suponga. */
    public function simulacion(SimulacionRequest $request): JsonResponse
    {
        $ganancia = $request->validated('ganancia');
        $aplicada = $ganancia ?? PoliticaPrecios::vigente()->ganancia_global;

        return Recurso::respuesta(new JsonResource([
            'costo'              => Importe::json($request->validated('costo')),
            'ganancia_es_global' => $ganancia === null,
            'alicuota_iva'       => $request->validated('alicuota_iva'),
            'calculo'            => Desglose::de(
                $this->service->simular($request->validated('costo'), $aplicada, $request->validated('alicuota_iva')),
            ),
        ]));
    }
}
