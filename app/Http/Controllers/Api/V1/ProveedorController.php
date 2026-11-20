<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProveedorFiltroRequest;
use App\Http\Resources\Api\ProveedorResource;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;

/**
 * Los proveedores. En el tramo D sólo el listado, para los selectores del catálogo; el tramo E
 * suma el resto del módulo. El permiso lo pide la ruta.
 */
class ProveedorController extends Controller
{
    public function index(ProveedorFiltroRequest $request): JsonResponse
    {
        $pagina = Proveedor::query()
            ->buscar($request->validated('q'))
            ->conEstado($request->validated('estado'))
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ProveedorResource::class, $request->criterios());
    }
}
