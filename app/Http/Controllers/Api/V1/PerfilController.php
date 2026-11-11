<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PerfilResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    /** La cuenta de quien tiene la sesión. */
    public function show(Request $request): JsonResponse
    {
        return Recurso::respuesta(new PerfilResource($request->user()->load('cliente')));
    }
}
