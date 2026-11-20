<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ClienteFiltroRequest;
use App\Http\Resources\Api\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;

/**
 * Los clientes. En el tramo D sólo el listado, para el buscador de la venta; el tramo E suma el
 * resto del módulo. El permiso lo pide la ruta.
 */
class ClienteController extends Controller
{
    public function index(ClienteFiltroRequest $request): JsonResponse
    {
        $pagina = Cliente::query()
            ->buscar($request->validated('q'))
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'razon_social', 'tipo_doc', 'nro_doc'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ClienteResource::class, $request->criterios());
    }
}
