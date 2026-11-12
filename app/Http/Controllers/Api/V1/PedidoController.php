<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConfirmarPedidoRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\PedidoDetalleResource;
use App\Http\Resources\Api\PedidoListadoResource;
use App\Http\Resources\Api\PedidoResource;
use App\Impresion\ComprobanteVenta;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\IntentoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class PedidoController extends Controller
{
    /** Mis pedidos (tramo H): sólo los online de esta cuenta, el más nuevo primero. */
    public function index(PaginadoRequest $request): JsonResponse
    {
        return Paginado::respuesta(
            Venta::paginaDelCliente($request->user()->cliente, $request->pagina(), $request->porPagina()),
            PedidoListadoResource::class,
            [],
        );
    }

    /** El detalle. La consulta por dueño es la misma de los pagos: ajeno o inexistente, 404. */
    public function show(Request $request, int $pedido, IntentoPagoService $intentos): JsonResponse
    {
        $venta = $intentos->pedidoDe($request->user(), $pedido)->load(['lineas', 'ultimoIntentoPago']);

        return Recurso::respuesta(new PedidoDetalleResource($venta));
    }

    /** El enlace firmado del comprobante (D-22): el token no viaja en la URL. No cobrado, 409. */
    public function comprobante(Request $request, int $pedido, IntentoPagoService $intentos, ComprobanteVenta $comprobante): JsonResponse
    {
        return Recurso::respuesta(new JsonResource($comprobante->enlace($intentos->pedidoDe($request->user(), $pedido))));
    }

    /** El PDF, por el enlace firmado: la firma es la autorización. Vencida o tocada, 403. */
    public function descargarComprobante(int $pedido, ComprobanteVenta $comprobante): Response
    {
        return $comprobante->descarga($comprobante->firmado($pedido));
    }

    /** Confirma el checkout. 201 si nace el pedido; 200 si el carrito ya lo tenía (D-8). */
    public function store(ConfirmarPedidoRequest $request, CarritoService $carritos): JsonResponse
    {
        ['venta' => $venta, 'creada' => $creada] = $carritos->confirmar($request->user(), $request->datos());

        return Recurso::respuesta(new PedidoResource($venta->load('lineas')), $creada ? 201 : 200);
    }
}
