<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClienteFiltroRequest;
use App\Http\Requests\ClienteRequest;
use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestión de clientes.
 *
 * Módulo nuevo: en el sistema original el comprador no existía como entidad, la
 * venta guardaba un nombre suelto y no había forma de saber a quién se le
 * facturó.
 *
 * El controlador traduce entre HTTP y ClienteService. No valida
 * (ClienteRequest), no decide reglas (ClienteService) y no arma consultas de
 * negocio: encadena los scopes del modelo.
 *
 * Este módulo NO crea cuentas de acceso. La cuenta y su ficha de cliente se
 * crean juntas desde el módulo de usuarios, que es el único que sabe mantener
 * la invariante rol↔satélite. Acá se administra al cliente de mostrador y los
 * datos fiscales de todos.
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   create() / store()   alta de un cliente de mostrador
 *   edit()  / update()   edición de datos fiscales y de contacto
 *   destroy()            baja física; el servicio la niega si corresponde
 */
class ClienteController extends Controller
{
    public function __construct(private ClienteService $service)
    {
    }

    public function index(ClienteFiltroRequest $request): View
    {
        $clientes = Cliente::query()
            // Cuenta en la base. El panel original traía las tablas enteras al
            // navegador para contarlas con .filter() (A-26).
            ->withCount('direcciones')
            ->with('usuario:id,email')
            ->buscar($request->query('q'))
            ->conCondicionIva($request->query('condicion_iva'))
            ->conCuenta($request->query('cuenta'))
            ->orderBy('razon_social')
            ->paginate(15)          // A-25: el original devolvía la tabla entera
            ->withQueryString();    // sin esto, la página 2 pierde los filtros

        return view('clientes.index', compact('clientes'));
    }

    public function create(): View
    {
        return view('clientes.form', [
            'cliente' => new Cliente(['condicion_iva' => 'consumidor_final', 'tipo_doc' => 'dni']),
        ]);
    }

    public function store(ClienteRequest $request): RedirectResponse
    {
        $cliente = $this->service->crear($request->validated());

        // Se va a la edición y no al listado: lo siguiente que suele hacer
        // quien acaba de cargar un cliente es agregarle una dirección, y ahí
        // está el bloque para hacerlo.
        return redirect()->route('clientes.edit', $cliente)
            ->with('exito', "Se creó el cliente «{$cliente->razon_social}». Ya podés agregarle direcciones.");
    }

    public function edit(Cliente $cliente): View
    {
        return view('clientes.form', [
            'cliente' => $cliente->load(['usuario', 'direcciones' => fn ($q) => $q
                // La predeterminada primero, después por antigüedad: es el mismo
                // orden en el que se van a ofrecer al armar un envío.
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

        // Si tiene ventas o cuenta, el servicio lanza ReglaDeNegocioException y
        // bootstrap/app.php la traduce a un mensaje para el usuario. Nada de
        // try/catch acá: eso está resuelto una sola vez para toda la aplicación,
        // que es la corrección de M-30.
        $this->service->eliminar($cliente);

        return redirect()->route('clientes.index')
            ->with('exito', "Se eliminó a «{$nombre}» y sus direcciones.");
    }
}