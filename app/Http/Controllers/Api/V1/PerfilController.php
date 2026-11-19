<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActualizarPerfilRequest;
use App\Http\Resources\Api\PerfilResource;
use App\Services\PerfilService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    public function __construct(private readonly PerfilService $perfiles) {}

    /** La cuenta de quien tiene la sesión. */
    public function show(Request $request): JsonResponse
    {
        return Recurso::respuesta(new PerfilResource($request->user()->load('cliente.direccionPredeterminada')));
    }

    /** Edita la cuenta propia y la devuelve como `show`. */
    public function update(ActualizarPerfilRequest $request): JsonResponse
    {
        $usuario = $this->perfiles->actualizar($request->user(), $request->datos());

        return Recurso::respuesta(new PerfilResource($usuario), mensaje: 'Guardamos tus datos.');
    }
}
