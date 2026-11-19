<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CarritoCantidadRequest;
use App\Http\Requests\Api\CarritoLineaRequest;
use App\Http\Resources\Api\CarritoResource;
use App\Services\CarritoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los renglones del carrito. Cada escritura devuelve el carrito entero: la app necesita los
 * totales y los avisos nuevos, y así no hace un segundo pedido.
 */
class CarritoLineaController extends Controller
{
    public function __construct(private readonly CarritoService $carritos)
    {
    }

    /** 201 si el renglón es nuevo; 200 si sumó a uno que ya estaba. */
    public function store(CarritoLineaRequest $request): JsonResponse
    {
        $linea = $this->carritos->agregar(
            $request->user(),
            (int) $request->validated('producto_id'),
            (int) $request->validated('cantidad'),
        );

        return $this->carrito($request, $linea->wasRecentlyCreated ? 201 : 200, 'Agregaste el producto al carrito.');
    }

    public function update(CarritoCantidadRequest $request, int $linea): JsonResponse
    {
        $this->carritos->cambiarCantidad($request->user(), $linea, (int) $request->validated('cantidad'));

        return $this->carrito($request);
    }

    public function destroy(Request $request, int $linea): JsonResponse
    {
        $this->carritos->quitar($request->user(), $linea);

        return $this->carrito($request, mensaje: 'Quitaste el producto del carrito.');
    }

    private function carrito(Request $request, int $estado = 200, ?string $mensaje = null): JsonResponse
    {
        $resumen = $this->carritos->resumen($this->carritos->paraLeer($request->user()));

        return Recurso::respuesta(new CarritoResource($resumen), $estado, $mensaje);
    }
}
