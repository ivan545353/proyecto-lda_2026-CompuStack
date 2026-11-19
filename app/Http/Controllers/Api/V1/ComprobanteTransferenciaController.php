<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ComprobanteTransferenciaRequest;
use App\Http\Resources\Api\PedidoDetalleResource;
use App\Services\IntentoPagoService;
use App\Services\TransferenciaService;
use App\Support\Api\TextosTienda;
use Illuminate\Http\JsonResponse;

/** El comprobante de la transferencia de un pedido (tramo L, D-27). Pedido ajeno: 404. */
class ComprobanteTransferenciaController extends Controller
{
    /** 201 con el detalle del pedido, ya «en revisión», y el aviso del plazo. */
    public function store(
        ComprobanteTransferenciaRequest $request,
        int $pedido,
        IntentoPagoService $intentos,
        TransferenciaService $transferencias,
    ): JsonResponse {
        $venta = $transferencias->cargarComprobante($intentos->pedidoDe($request->user(), $pedido), $request->file('archivo'));

        return Recurso::respuesta(
            new PedidoDetalleResource($venta->load(['lineas', 'ultimoIntentoPago'])->loadExists('comprobantesTransferencia')),
            201,
            TextosTienda::avisoTransferencia(),
        );
    }
}
