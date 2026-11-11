<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SesionRequest;
use App\Services\CredencialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class SesionController extends Controller
{
    public function __construct(private readonly CredencialService $credenciales) {}

    /** Inicia sesión y devuelve la credencial (200). */
    public function store(SesionRequest $request): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $this->credenciales->iniciar($request->input('email'), $request->input('password')),
        ));
    }

    /** Cierra la sesión de este dispositivo: la credencial con la que llegó deja de servir (204). */
    public function destroy(Request $request): Response
    {
        $this->credenciales->revocarActual($request->user());

        return response()->noContent();
    }
}
