<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FactorCostoRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\FactorCostoResource;
use App\Models\FactorCosto;
use App\Services\PrecioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los factores de costo (tramo F): traduce entre el contrato y PrecioService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()    listado en el orden en que se aplican
 *   show()     un factor
 *   store()    alta (201)
 *   update()   edición
 *   destroy()  baja física: ninguna fila lo referencia
 */
class FactorCostoController extends Controller
{
    public function __construct(private PrecioService $service)
    {
    }

    public function index(PaginadoRequest $request): JsonResponse
    {
        $pagina = FactorCosto::query()->ordenados()
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, FactorCostoResource::class, []);
    }

    public function show(FactorCosto $factor): JsonResponse
    {
        return Recurso::respuesta(new FactorCostoResource($factor));
    }

    public function store(FactorCostoRequest $request): JsonResponse
    {
        $factor = $this->service->guardarFactor($request->validated());

        return Recurso::respuesta(
            new FactorCostoResource($factor),
            201,
            "Factor «{$factor->nombre}» creado. Los precios ya calculados no lo tienen adentro hasta que recalcules.",
        );
    }

    public function update(FactorCostoRequest $request, FactorCosto $factor): JsonResponse
    {
        $factor = $this->service->guardarFactor($request->validated(), $factor);

        return Recurso::respuesta(
            new FactorCostoResource($factor),
            200,
            "Factor «{$factor->nombre}» actualizado. Los precios ya calculados no cambiaron.",
        );
    }

    public function destroy(FactorCosto $factor): JsonResponse
    {
        $id     = $factor->id;
        $nombre = $factor->nombre;

        $this->service->eliminarFactor($factor);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => true]),
            200,
            "Factor «{$nombre}» eliminado. Los precios que ya lo tenían adentro no cambiaron.",
        );
    }
}
