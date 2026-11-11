<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CarritoResource;
use App\Services\CarritoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarritoController extends Controller
{
    public function __construct(private readonly CarritoService $carritos)
    {
    }

    /** El carrito vigente; sin uno, vacío y sin crearlo. */
    public function show(Request $request): JsonResponse
    {
        return Recurso::respuesta(new CarritoResource($this->carritos->resumen($this->carritos->paraLeer($request->user()))));
    }

    /** Saca todos los renglones; el carrito queda, vacío. */
    public function vaciar(Request $request): JsonResponse
    {
        $this->carritos->vaciar($request->user());

        return Recurso::respuesta(
            new CarritoResource($this->carritos->resumen($this->carritos->paraLeer($request->user()))),
            mensaje: 'Vaciaste el carrito.',
        );
    }
}
