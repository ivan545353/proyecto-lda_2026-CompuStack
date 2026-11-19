<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IniciarPagoRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\IntentoPagoResource;
use App\Services\IntentoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Los pagos de un pedido de la tienda (tramo G). Pedido o intento ajeno: 404. */
class PagoPedidoController extends Controller
{
    public function __construct(private readonly IntentoPagoService $intentos)
    {
    }

    /** 201 si nace el intento; 200 con el pendiente si ya había uno (§6.6). */
    public function store(IniciarPagoRequest $request, int $pedido): JsonResponse
    {
        $venta = $this->intentos->pedidoDe($request->user(), $pedido);

        ['intento' => $intento, 'creado' => $creado] = $this->intentos->iniciar($venta, $request->pasarela());

        return Recurso::respuesta(
            new IntentoPagoResource($intento->load('venta')),
            $creado ? 201 : 200,
            $creado ? 'Iniciaste el pago.' : 'Ya tenías un pago en curso para este pedido.',
        );
    }

    /** Los intentos del pedido, el último primero. */
    public function index(PaginadoRequest $request, int $pedido): JsonResponse
    {
        $venta  = $this->intentos->pedidoDe($request->user(), $pedido);
        $pagina = $venta->intentosPago()->orderByDesc('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        // El mismo pedido en todos: su estado del pago se lee una sola vez.
        $pagina->getCollection()->each->setRelation('venta', $venta);

        return Paginado::respuesta($pagina, IntentoPagoResource::class, []);
    }

    /** El resultado. Si está pendiente, antes se le pregunta al proveedor: el regreso de la app no confirma nada. */
    public function show(Request $request, int $pedido, int $intento): JsonResponse
    {
        $actual = $this->intentos->intentoDe($request->user(), $intento, $pedido);

        return Recurso::respuesta(new IntentoPagoResource($this->intentos->verificar($actual)->load('venta')));
    }
}
