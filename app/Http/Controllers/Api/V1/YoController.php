<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\YoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoController extends Controller
{
    /** La cuenta con la sesión, su rol y sus permisos. */
    public function show(Request $request): JsonResponse
    {
        return Recurso::respuesta(new YoResource($request->user()->load('rol')));
    }
}
