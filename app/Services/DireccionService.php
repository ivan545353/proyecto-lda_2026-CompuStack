<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Direccion;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de las direcciones de un cliente.
 *
 * Servicio propio y no parte de ClienteService porque la invariante es sobre el
 * CONJUNTO de direcciones de un cliente, y mezclarla con el alta del cliente
 * dejaría un servicio que hace dos cosas distintas.
 *
 * La invariante, en una frase: si el cliente tiene al menos una dirección,
 * exactamente una es la predeterminada. El esquema no puede expresarla —un
 * boolean con default false acepta tres marcadas o ninguna— y los dos estados
 * inválidos hacen daño: con tres marcadas el envío de la Etapa 2 no sabe cuál
 * usar, y con ninguna no tiene nada que ofrecer.
 *
 * Toda operación bloquea la fila del CLIENTE, no las de las direcciones. Dos
 * pestañas marcando direcciones distintas del mismo cliente al mismo tiempo
 * dejarían las dos marcadas: cada una desmarca lo que ve y después se marca a
 * sí misma. Bloquear el cliente serializa también el alta de la primera, cuando
 * todavía no hay ninguna fila de dirección que bloquear.
 *
 * Es el mismo patrón que la Fase 6 necesita para el descuento de stock y para
 * el saldo de los pagos (C-10, donde el monto se validaba fuera de la
 * transacción y dos pagos concurrentes podían exceder el total).
 *
 * Métodos:
 *   crear()       alta; la primera dirección queda predeterminada
 *   actualizar()  edición; desmarcarla no la desmarca
 *   eliminar()    baja; si era la predeterminada, asciende la más antigua
 */
class DireccionService
{
    public function crear(Cliente $cliente, array $datos): Direccion
    {
        return DB::transaction(function () use ($cliente, $datos) {
            $this->bloquear($cliente->id);

            $esLaPrimera = ! $cliente->direcciones()->exists();

            $direccion = $cliente->direcciones()->create($this->camposDe($datos, [
                // La primera es predeterminada aunque nadie lo pida: un cliente
                // con una sola dirección y ninguna elegida es un estado sin
                // sentido, y obligar a marcar el checkbox en el primer alta es
                // pedirle al usuario que resuelva un detalle del modelo.
                'es_predeterminada' => $esLaPrimera || $datos['es_predeterminada'],
            ]));

            if ($direccion->es_predeterminada) {
                $this->desmarcarLasDemas($cliente->id, $direccion->id);
            }

            return $direccion;
        });
    }

    public function actualizar(Direccion $direccion, array $datos): Direccion
    {
        return DB::transaction(function () use ($direccion, $datos) {
            $this->bloquear($direccion->cliente_id);

            // Desmarcar la predeterminada dejaría al cliente con direcciones y
            // ninguna elegida, y el sistema no tiene con qué decidir cuál pasa
            // a serlo. Así que se conserva: para cambiarla hay que marcar otra,
            // y el controlador se lo dice al usuario en lugar de guardar algo
            // distinto de lo que pidió sin avisar.
            $queda = $direccion->es_predeterminada || $datos['es_predeterminada'];

            $direccion->update($this->camposDe($datos, ['es_predeterminada' => $queda]));

            if ($queda) {
                $this->desmarcarLasDemas($direccion->cliente_id, $direccion->id);
            }

            return $direccion->fresh();
        });
    }

    public function eliminar(Direccion $direccion): void
    {
        DB::transaction(function () use ($direccion) {
            $this->bloquear($direccion->cliente_id);

            $eraPredeterminada = $direccion->es_predeterminada;
            $clienteId         = $direccion->cliente_id;

            $direccion->delete();

            // Quedarse con direcciones y ninguna predeterminada es el mismo
            // estado sin sentido. Asciende la más antigua, que es la que más
            // tiempo viene usándose y la que menos sorprende.
            if ($eraPredeterminada) {
                Direccion::where('cliente_id', $clienteId)
                    ->orderBy('id')
                    ->first()
                    ?->update(['es_predeterminada' => true]);
            }
        });
    }

    /**
     * Bloquea la fila del cliente hasta el fin de la transacción.
     *
     * Sobre el cliente y no sobre sus direcciones: así el alta de la primera
     * también se serializa, y no hace falta un bloqueo de rango.
     */
    private function bloquear(int $clienteId): void
    {
        Cliente::whereKey($clienteId)->lockForUpdate()->first();
    }

    private function desmarcarLasDemas(int $clienteId, int $predeterminadaId): void
    {
        Direccion::where('cliente_id', $clienteId)
            ->whereKeyNot($predeterminadaId)
            ->where('es_predeterminada', true)
            ->update(['es_predeterminada' => false]);
    }

    /**
     * Los campos se enumeran uno por uno: nunca `create($validated)`. `cliente_id`
     * no está, y por eso una dirección no se puede mudar a otro cliente por más
     * que el id venga en la petición.
     *
     * @return array<string, mixed>
     */
    private function camposDe(array $datos, array $extra): array
    {
        return array_merge([
            'calle'         => $datos['calle'],
            'numero'        => $datos['numero'],
            'piso_depto'    => $datos['piso_depto'] ?? null,
            'codigo_postal' => $datos['codigo_postal'],
            'localidad'     => $datos['localidad'],
            'provincia'     => $datos['provincia'],
        ], $extra);
    }
}