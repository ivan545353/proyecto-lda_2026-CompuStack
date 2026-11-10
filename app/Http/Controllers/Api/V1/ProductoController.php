<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogoRequest;
use App\Http\Resources\Api\ProductoFichaResource;
use App\Http\Resources\Api\ProductoListadoResource;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;

class ProductoController extends Controller
{
    /** El catálogo público, filtrado, ordenado y paginado. */
    public function index(CatalogoRequest $request): JsonResponse
    {
        $criterios = $request->criterios();

        return Paginado::respuesta(
            Producto::paginaEnTienda($criterios, $request->pagina(), $request->porPagina()),
            ProductoListadoResource::class,
            $criterios,
        );
    }

    /** La ficha de un producto visible; cualquier otro caso es 404. */
    public function show(string $producto): JsonResponse
    {
        return Recurso::respuesta(new ProductoFichaResource(Producto::fichaEnTienda($producto)));
    }
}
