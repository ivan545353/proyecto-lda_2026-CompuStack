<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AccesoTiendaRequest;
use App\Http\Requests\Api\ClienteFiltroRequest;
use App\Http\Requests\Api\ClienteRequest;
use App\Http\Resources\Api\ClienteResource;
use App\Impresion\ImpresionFirmada;
use App\Models\Cliente;
use App\Services\ClienteService;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los clientes (tramos D y E): traduce entre el contrato y ClienteService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()    listado filtrado y paginado, con cuántas direcciones y qué cuenta tiene cada uno
 *   impresion()  el enlace firmado del mismo listado, en PDF o CSV (D-22)
 *   show()     un cliente, con sus direcciones
 *   store()    alta (201); contesta la misma fila que el listado, para el alta rápida de la venta
 *   update()   edición de datos fiscales y de contacto
 *   accesoTienda()  cuenta de la tienda para un cliente del mostrador, con su enlace de un solo uso (D-26)
 *   destroy()  baja física; el servicio la niega (409) si tiene cuenta o ventas
 */
class ClienteController extends Controller
{
    public function __construct(private ClienteService $service)
    {
    }

    public function index(ClienteFiltroRequest $request): JsonResponse
    {
        $pagina = Cliente::query()
            ->buscar($request->validated('q'))
            ->conCondicionIva($request->validated('condicion_iva'))
            ->conCuenta($request->validated('cuenta'))
            ->withCount('direcciones')
            ->with('usuario:id,email')
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ClienteResource::class, $request->criterios());
    }

    public function impresion(ClienteFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('clientes', $request->validated(), $request->query(), $request->user()),
        ));
    }

    public function show(Cliente $cliente): JsonResponse
    {
        // La ficha trae sus direcciones, la predeterminada primero (como la pantalla de edición).
        $cliente->load(['direcciones' => fn ($consulta) => $consulta
            ->orderByDesc('es_predeterminada')
            ->orderBy('id')]);

        return Recurso::respuesta(new ClienteResource($this->conExtras($cliente)));
    }

    public function store(ClienteRequest $request): JsonResponse
    {
        $cliente = $this->service->crear($request->validated());

        return Recurso::respuesta(
            new ClienteResource($this->conExtras($cliente)),
            201,
            "Se creó el cliente «{$cliente->razon_social}». Ya podés agregarle direcciones.",
        );
    }

    public function update(ClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $cliente = $this->service->actualizar($cliente, $request->validated());

        return Recurso::respuesta(
            new ClienteResource($this->conExtras($cliente)),
            200,
            "Se guardaron los datos de «{$cliente->razon_social}».",
        );
    }

    /**
     * Le da cuenta de la tienda a un cliente del mostrador y devuelve el enlace para que elija su
     * contraseña. El enlace es un secreto de un solo uso: viaja sólo en esta respuesta, que no se
     * guarda en ninguna caché, y la ficha no lo vuelve a mostrar. Una URL es de la capa HTTP, por eso
     * se arma acá y no en el servicio (como en Personal).
     */
    public function accesoTienda(AccesoTiendaRequest $request, Cliente $cliente, UsuarioService $usuarios): JsonResponse
    {
        $usuario = $usuarios->darAccesoATienda($cliente, $request->validated('nombre'), $request->validated('apellido'));
        $token   = $usuarios->crearTokenDeRestablecimiento($usuario);

        return Recurso::respuesta(
            new JsonResource([
                'cliente_id'              => $cliente->id,
                'nombre'                  => $usuario->nombre_completo,
                'enlace_restablecimiento' => route('password.reset', ['token' => $token, 'email' => $usuario->email]),
            ]),
            201,
            "Se creó la cuenta de la tienda de «{$usuario->nombre} {$usuario->apellido}».",
        )->header('Cache-Control', 'no-store');
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        $id     = $cliente->id;
        $nombre = $cliente->razon_social;

        $this->service->eliminar($cliente);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => true]),
            200,
            "Se eliminó a «{$nombre}» y sus direcciones.",
        );
    }

    /** Lo mismo que trae una fila del listado, para que el alta y la búsqueda no difieran. */
    private function conExtras(Cliente $cliente): Cliente
    {
        return $cliente->loadCount('direcciones')->load('usuario:id,email');
    }
}
