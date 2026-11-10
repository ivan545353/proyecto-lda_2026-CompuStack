<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\CategoriaResource;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    /** El árbol de categorías visibles, paginado por raíz. */
    public function index(PaginadoRequest $request): JsonResponse
    {
        return Paginado::respuesta(
            Categoria::arbolEnTienda($request->pagina(), $request->porPagina()),
            CategoriaResource::class,
            [],
        );
    }
}
