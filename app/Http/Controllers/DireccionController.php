<?php

namespace App\Http\Controllers;

use App\Http\Requests\DireccionRequest;
use App\Models\Cliente;
use App\Models\Direccion;
use App\Services\DireccionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Direcciones de un cliente.
 *
 * Recurso anidado: siempre se accede desde la ficha de su cliente, y a ella se
 * vuelve después de guardar.
 *
 * La dirección se resuelve SIEMPRE a través de la relación del cliente
 * (`direccionDe()`), nunca por binding implícito directo. Laravel resuelve cada
 * parámetro de la ruta por separado y no verifica que estén relacionados: sin
 * esto, /clientes/1/direcciones/99 abriría la dirección 99 desde la ficha del
 * cliente 1 aunque sea del cliente 2.
 *
 * No se usa `scopeBindings()`, que sería la forma automática, porque deduce el
 * nombre de la relación pluralizando en inglés: Str::plural('direccion') no
 * devuelve 'direcciones'. Resolverla a mano además deja el control a la vista
 * de quien lee el método.
 *
 * Las cinco rutas exigen `cliente.editar`. Una dirección no tiene existencia
 * autónoma —el esquema la borra en cascada con su cliente— y no hay escenario
 * donde tenga sentido administrar direcciones sin poder editar al cliente.
 *
 * Métodos:
 *   create() / store()   alta
 *   edit()  / update()   edición
 *   destroy()            baja
 */
class DireccionController extends Controller
{
    public function __construct(private DireccionService $service)
    {
    }

    public function create(Cliente $cliente): View
    {
        return view('clientes.direccion', [
            'cliente'   => $cliente,
            // Si es la primera, el servicio la va a marcar predeterminada
            // igual; el checkbox llega marcado para que la pantalla no diga
            // una cosa y el resultado sea otra.
            'direccion' => new Direccion([
                'provincia'         => 'Santa Cruz',
                'es_predeterminada' => ! $cliente->direcciones()->exists(),
            ]),
        ]);
    }

    public function store(DireccionRequest $request, Cliente $cliente): RedirectResponse
    {
        $direccion = $this->service->crear($cliente, $request->validated());

        return redirect()->route('clientes.edit', $cliente)->with('exito', $direccion->es_predeterminada
            ? 'Se agregó la dirección y quedó como predeterminada.'
            : 'Se agregó la dirección.');
    }

    public function edit(Cliente $cliente, int $direccion): View
    {
        return view('clientes.direccion', [
            'cliente'   => $cliente,
            'direccion' => $this->direccionDe($cliente, $direccion),
        ]);
    }

    public function update(DireccionRequest $request, Cliente $cliente, int $direccion): RedirectResponse
    {
        $modelo = $this->direccionDe($cliente, $direccion);

        // Se calcula ANTES de guardar, igual que MarcaController calcula el
        // impacto de desactivar: después el dato ya cambió.
        $seQuisoDesmarcar = $modelo->es_predeterminada && ! $request->boolean('es_predeterminada');

        $this->service->actualizar($modelo, $request->validated());

        // El mensaje dice lo que pasó de verdad. Guardar algo distinto de lo que
        // el usuario marcó sin avisarle es mentirle sobre el estado del sistema.
        return redirect()->route('clientes.edit', $cliente)->with('exito', $seQuisoDesmarcar
            ? 'Se guardó la dirección. Sigue siendo la predeterminada: para cambiarla, marcá otra.'
            : 'Se guardó la dirección.');
    }

    public function destroy(Cliente $cliente, int $direccion): RedirectResponse
    {
        $modelo = $this->direccionDe($cliente, $direccion);
        $era    = $modelo->es_predeterminada;

        $this->service->eliminar($modelo);

        $quedan = $cliente->direcciones()->exists();

        return redirect()->route('clientes.edit', $cliente)->with('exito', $era && $quedan
            ? 'Se eliminó la dirección. La más antigua de las que quedaban pasó a ser la predeterminada.'
            : 'Se eliminó la dirección.');
    }

    /**
     * La dirección, buscada DENTRO de las del cliente.
     *
     * Una dirección de otro cliente da 404 en lugar de abrirse desde la ficha
     * equivocada.
     */
    private function direccionDe(Cliente $cliente, int $id): Direccion
    {
        return $cliente->direcciones()->findOrFail($id);
    }
}