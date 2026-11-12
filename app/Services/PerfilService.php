<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La cuenta que edita el cliente desde la tienda (D-22). Es la misma ficha que el cajero ve y
 * corrige en la web: dos entradas, un dato. Un pedido ya hecho no cambia: su dirección está
 * copiada en `ventas.entrega_*`.
 *
 * Métodos:
 *   actualizar()  guarda lo que vino (`ActualizarPerfilRequest::datos()`) y devuelve la cuenta
 */
class PerfilService
{
    public function __construct(private readonly DireccionService $direcciones) {}

    public function actualizar(User $usuario, array $datos): User
    {
        try {
            DB::transaction(function () use ($usuario, $datos) {
                // Bloqueo antes de leer (regla 7): el cajero puede estar guardando la misma ficha.
                $cliente = Cliente::where('user_id', $usuario->id)->lockForUpdate()->firstOrFail();

                $this->guardarNombre($usuario, $cliente, $datos);
                $this->guardarFicha($cliente, $datos);

                if (array_key_exists('direccion', $datos)) {
                    $this->guardarDireccion($cliente, $datos['direccion']);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Otro cliente cargó el mismo documento entre la validación y el guardado.
            throw ValidationException::withMessages([
                'nro_doc' => 'Ese documento ya está cargado en otra cuenta. Si es tuyo, avisanos.',
            ]);
        }

        return $usuario->fresh()->load('cliente.direccionPredeterminada');
    }

    /** Nombre y apellido viven en `users`; la razón social los sigue, sólo si cambiaron (HL-53). */
    private function guardarNombre(User $usuario, Cliente $cliente, array $datos): void
    {
        if (! array_key_exists('nombre', $datos) && ! array_key_exists('apellido', $datos)) {
            return;
        }

        $usuario->update([
            'nombre'   => $datos['nombre'] ?? $usuario->nombre,
            'apellido' => $datos['apellido'] ?? $usuario->apellido,
        ]);

        $cliente->razon_social = trim($usuario->nombre.' '.$usuario->apellido);
    }

    /** Teléfono y documento, uno por uno. Sin documento queda el número en null: `tipo_doc` no admite vacío (HL-65). */
    private function guardarFicha(Cliente $cliente, array $datos): void
    {
        if (array_key_exists('telefono', $datos)) {
            $cliente->telefono = $datos['telefono'];
        }

        if (array_key_exists('documento', $datos)) {
            $cliente->nro_doc = $datos['documento']['nro_doc'] ?? null;
            if ($datos['documento'] !== null) {
                $cliente->tipo_doc = $datos['documento']['tipo_doc'];
            }
        }

        $cliente->save();
    }

    /** La predeterminada: la crea, la cambia o la quita, con las reglas de `DireccionService` (HL-68). */
    private function guardarDireccion(Cliente $cliente, ?array $direccion): void
    {
        $actual = $cliente->direccionPredeterminada()->first();

        match (true) {
            $direccion === null && $actual !== null => $this->direcciones->eliminar($actual),
            $direccion === null                     => null,
            $actual !== null                        => $this->direcciones->actualizar($actual, [...$direccion, 'es_predeterminada' => true]),
            default                                 => $this->direcciones->crear($cliente, [...$direccion, 'es_predeterminada' => true]),
        };
    }
}
