<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogoRequest;
use App\Http\Resources\Api\ProductoFichaResource;
use App\Http\Resources\Api\ProductoListadoResource;
use App\Models\Producto;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ProductoController extends Controller
{
    /** El catálogo público, filtrado, ordenado y paginado. */
    public function index(CatalogoRequest $request): JsonResponse
    {
        $criterios = $request->criterios();

        // Antes de consultar: el orden mismo revelaría los precios que no viajan.
        if (in_array($criterios['orden'], Producto::ORDENES_CON_PRECIO, true)) {
            $request->user() ?? throw new AuthenticationException;
            Gate::authorize('tienda.ver_precios');
        }

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
