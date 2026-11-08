<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ClienteFiltroRequest;
use App\Http\Requests\ClienteRequest;
use App\Impresion\ListadoClientes;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestión de clientes.
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   imprimir()           el mismo listado filtrado, en PDF o en CSV
 *   create() / store()   alta de un cliente de mostrador; store() contesta JSON a la ventana de la venta
 *   edit()  / update()   edición de datos fiscales y de contacto
 *   destroy()            baja física; el servicio la niega si corresponde
 */
class ClienteController extends Controller
{
    use ImprimeListados;
    public function __construct(private ClienteService $service)
    {
    }

    public function index(ClienteFiltroRequest $request): View
    {
        $clientes = $this->filtradas($request)
            ->withCount('direcciones')
            ->with('usuario:id,email')
            ->paginate(15)
            ->withQueryString();   

        return view('clientes.index', compact('clientes'));
    }

    
    public function imprimir(ClienteFiltroRequest $request, ListadoClientes $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'clientes.index');
    }

    public function create(): View
    {
        return view('clientes.form', [
            'cliente' => new Cliente(['condicion_iva' => 'consumidor_final', 'tipo_doc' => 'dni']),
        ]);
    }

    public function store(ClienteRequest $request): RedirectResponse|JsonResponse
    {
        $cliente = $this->service->crear($request->validated());

        // La ventana de la venta pide JSON, con el mismo criterio con que Laravel le contesta el 422.
        if ($request->expectsJson()) {
            return response()->json($cliente->resultadoDeBusqueda(), 201);
        }
        return redirect()->route('clientes.edit', $cliente)
            ->with('exito', "Se creó el cliente «{$cliente->razon_social}». Ya podés agregarle direcciones.");
    }

    public function edit(Cliente $cliente): View
    {
        return view('clientes.form', [
            'cliente' => $cliente->load(['usuario', 'direcciones' => fn ($q) => $q
                ->orderByDesc('es_predeterminada')
                ->orderBy('id')]),
        ]);
    }

    public function update(ClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        $cliente = $this->service->actualizar($cliente, $request->validated());

        return redirect()->route('clientes.index')
            ->with('exito', "Se guardaron los datos de «{$cliente->razon_social}».");
    }

    public function destroy(Cliente $cliente): RedirectResponse
    {
        $nombre = $cliente->razon_social;

        $this->service->eliminar($cliente);

        return redirect()->route('clientes.index')
            ->with('exito', "Se eliminó a «{$nombre}» y sus direcciones.");
    }

    
    /** Los filtros del listado, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(ClienteFiltroRequest $request): Builder
    {
        return Cliente::query()
            ->buscar($request->query('q'))
            ->conCondicionIva($request->query('condicion_iva'))
            ->conCuenta($request->query('cuenta'))
            ->orderBy('razon_social');
    }
}