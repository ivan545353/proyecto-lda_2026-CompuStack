<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConfirmarPedidoRequest;
use App\Http\Resources\Api\PedidoResource;
use App\Services\CarritoService;
use Illuminate\Http\JsonResponse;

class PedidoController extends Controller
{
    /** Confirma el checkout. 201 si nace el pedido; 200 si el carrito ya lo tenía (D-8). */
    public function store(ConfirmarPedidoRequest $request, CarritoService $carritos): JsonResponse
    {
        ['venta' => $venta, 'creada' => $creada] = $carritos->confirmar($request->user(), $request->datos());

        return Recurso::respuesta(new PedidoResource($venta->load('lineas')), $creada ? 201 : 200);
    }
}
