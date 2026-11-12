<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\IntentoPagoResource;
use App\Services\IntentoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El simulador de pagos: no es un cobro real (D-13). Sus rutas existen sólo con
 * `tienda.pagos.simulador` encendido. Un intento ajeno es 404.
 */
class SimuladorController extends Controller
{
    public function __construct(private readonly IntentoPagoService $intentos)
    {
    }

    public function aprobar(Request $request, int $intento): JsonResponse
    {
        return $this->resolver($request, $intento, 'aprobado', 'El simulador aprobó el pago.');
    }

    public function rechazar(Request $request, int $intento): JsonResponse
    {
        return $this->resolver($request, $intento, 'rechazado', 'El simulador rechazó el pago.');
    }

    private function resolver(Request $request, int $intento, string $resultado, string $mensaje): JsonResponse
    {
        $actual   = $this->intentos->intentoDe($request->user(), $intento);
        $resuelto = $this->intentos->resolverSimulado($actual, $resultado);

        return Recurso::respuesta(new IntentoPagoResource($resuelto->load('venta')), mensaje: $mensaje);
    }
}
