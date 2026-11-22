<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DireccionRequest;
use App\Http\Resources\Api\DireccionResource;
use App\Models\Cliente;
use App\Models\Direccion;
use App\Services\DireccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Las direcciones de un cliente (tramo E). Recurso anidado: la dirección se busca SIEMPRE dentro de las
 * del cliente de la ruta, y una ajena es 404 igual que una que no existe. Las tres acciones piden
 * `cliente.editar`: una dirección no tiene existencia autónoma. La lista viaja en la ficha del cliente
 * (`GET /clientes/{cliente}`), por eso no hay `index` ni `show`.
 *
 * Métodos:
 *   store()    alta (201); la primera queda predeterminada y el mensaje lo dice
 *   update()   edición; si se quiso desmarcar la predeterminada, el mensaje dice que sigue siéndolo
 *   destroy()  baja; si era la predeterminada, el mensaje dice cuál la reemplaza
 */
class DireccionController extends Controller
{
    public function __construct(private DireccionService $service)
    {
    }

    public function store(DireccionRequest $request, Cliente $cliente): JsonResponse
    {
        $direccion = $this->service->crear($cliente, $request->validated());

        return Recurso::respuesta(new DireccionResource($direccion), 201, $direccion->es_predeterminada
            ? 'Se agregó la dirección y quedó como predeterminada.'
            : 'Se agregó la dirección.');
    }

    public function update(DireccionRequest $request, Cliente $cliente, int $direccion): JsonResponse
    {
        $modelo = $this->direccionDe($cliente, $direccion);

        // Se calcula ANTES de guardar: después el dato ya cambió.
        $seQuisoDesmarcar = $modelo->es_predeterminada && ! $request->boolean('es_predeterminada');

        $modelo = $this->service->actualizar($modelo, $request->validated());

        // El mensaje dice lo que pasó de verdad: guardar algo distinto de lo que se pidió, sin avisar, es mentir.
        return Recurso::respuesta(new DireccionResource($modelo), 200, $seQuisoDesmarcar
            ? 'Se guardó la dirección. Sigue siendo la predeterminada: para cambiarla, marcá otra.'
            : 'Se guardó la dirección.');
    }

    public function destroy(Cliente $cliente, int $direccion): JsonResponse
    {
        $modelo = $this->direccionDe($cliente, $direccion);
        $id     = $modelo->id;
        $era    = $modelo->es_predeterminada;

        $this->service->eliminar($modelo);

        $quedan = $cliente->direcciones()->exists();

        return Recurso::respuesta(new JsonResource(['id' => $id, 'eliminada' => true]), 200, $era && $quedan
            ? 'Se eliminó la dirección. La más antigua de las que quedaban pasó a ser la predeterminada.'
            : 'Se eliminó la dirección.');
    }

    /** La dirección, buscada DENTRO de las del cliente: una de otro cliente da 404. */
    private function direccionDe(Cliente $cliente, int $id): Direccion
    {
        return $cliente->direcciones()->findOrFail($id);
    }
}
