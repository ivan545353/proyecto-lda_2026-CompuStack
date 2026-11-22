<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImpuestoRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\ImpuestoResource;
use App\Models\Impuesto;
use App\Services\PrecioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los impuestos (tramo F): traduce entre el contrato y PrecioService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()    listado en el orden del desglose, con cuántos productos usa cada alícuota
 *   show()     un impuesto, con el mismo conteo (dice si eliminarlo lo va a desactivar)
 *   store()    alta (201)
 *   update()   edición; el alcance no cambia
 *   destroy()  baja física, o desactivación si hay productos con esa alícuota
 */
class ImpuestoController extends Controller
{
    public function __construct(private PrecioService $service)
    {
    }

    public function index(PaginadoRequest $request): JsonResponse
    {
        $pagina = Impuesto::query()->conUso()->ordenados()
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ImpuestoResource::class, []);
    }

    public function show(Impuesto $impuesto): JsonResponse
    {
        return Recurso::respuesta(new ImpuestoResource($this->conUso($impuesto)));
    }

    public function store(ImpuestoRequest $request): JsonResponse
    {
        $impuesto = $this->service->guardarImpuesto($request->validated());

        return Recurso::respuesta(
            new ImpuestoResource($this->conUso($impuesto)),
            201,
            "Impuesto «{$impuesto->nombre}» creado. Los precios ya calculados no lo tienen adentro hasta que recalcules.",
        );
    }

    public function update(ImpuestoRequest $request, Impuesto $impuesto): JsonResponse
    {
        $impuesto = $this->service->guardarImpuesto($request->validated(), $impuesto);

        return Recurso::respuesta(
            new ImpuestoResource($this->conUso($impuesto)),
            200,
            "Impuesto «{$impuesto->nombre}» actualizado. Los precios ya calculados no cambiaron.",
        );
    }

    /** No miente sobre lo que pasó: «eliminado» sólo si de verdad se borró la fila (regla 2). */
    public function destroy(Impuesto $impuesto): JsonResponse
    {
        $id      = $impuesto->id;
        $nombre  = $impuesto->nombre;
        $seBorro = $this->service->eliminarImpuesto($impuesto);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => $seBorro]),
            200,
            $seBorro
                ? "Impuesto «{$nombre}» eliminado."
                : "«{$nombre}» es la alícuota de productos del catálogo: se desactivó en lugar de eliminarse.",
        );
    }

    /** El mismo conteo que la fila del listado, para que la ficha y el alta tengan su forma (HL-115). */
    private function conUso(Impuesto $impuesto): Impuesto
    {
        return Impuesto::query()->conUso()->findOrFail($impuesto->id);
    }
}
