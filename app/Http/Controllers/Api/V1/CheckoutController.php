<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CheckoutRequest;
use App\Http\Resources\Api\ResumenCheckoutResource;
use App\Services\CarritoService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /** Revalida y reserva. 200 también en el reintento: no crea un recurso, reserva el que hay. */
    public function store(CheckoutRequest $request, CarritoService $carritos): JsonResponse
    {
        return Recurso::respuesta(new ResumenCheckoutResource($carritos->iniciarCheckout($request->user())));
    }
}
