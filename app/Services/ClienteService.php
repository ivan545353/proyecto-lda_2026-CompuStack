<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Cliente;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de clientes.
 *
 * Mismo patrón que los servicios del catálogo: recibe datos ya validados, no
 * conoce la petición ni la sesión
 *
 * Reglas propias:
 *
 *   1. Nunca escribe `user_id`. Este módulo administra al cliente de mostrador
 *      y los datos fiscales de todos; la cuenta de acceso la crea el módulo de
 *      usuarios, que es el único que sabe mantener la invariante rol↔satélite.
 *      Los campos se enumeran uno por uno justamente para que un campo de más
 *      en la entrada no se convierta en una escritura.
 *
 *   2. La baja NO es lógica, y es la única excepción del proyecto a "lo que
 *      está referenciado se desactiva". `clientes` no tiene columna `activo`, y
 *      no es un olvido: la baja lógica existe para lo que aparece en listas de
 *      las que uno elige —marcas, categorías, productos—, donde hay que dejar
 *      de ofrecerlo sin borrar el historial. Un cliente no se elige de un
 *      desplegable: se busca. Esconderlo de un buscador es lo contrario de lo
 *      que se necesita. Así que lo referenciado no se borra ni se desactiva:
 *      se rechaza la baja, que conserva el dato igual y además lo explica.
 *
 * Métodos:
 *   crear()       alta de un cliente de mostrador
 *   actualizar()  edición de los datos fiscales y de contacto
 *   eliminar()    baja física; se niega si tiene ventas o cuenta
 */
class ClienteService
{
    /**
     * Tablas que le dan historial a un cliente.
     *
     * `ventas.cliente_id` está declarada con `constrained()` sin nullOnDelete,
     * así que la base RESTRINGE el borrado: sin este chequeo el usuario
     * recibiría un error de integridad de MariaDB en la cara en lugar de un
     * mensaje (M-30, donde toda excepción se traducía a un 400 genérico).
     */
    private const HISTORIAL = ['ventas' => 'cliente_id'];

    public function crear(array $datos): Cliente
    {
        return DB::transaction(fn () => Cliente::create($this->camposDe($datos)));
    }

    public function actualizar(Cliente $cliente, array $datos): Cliente
    {
        return DB::transaction(function () use ($cliente, $datos) {
            // Ni user_id, aunque el arreglo lo traiga.
            $cliente->update($this->camposDe($datos));

            return $cliente->fresh();
        });
    }

    public function eliminar(Cliente $cliente): void
    {
        // Un usuario de ámbito tienda sin fila en `clientes` rompe la invariante
        // del modelo. La cuenta se elimina desde el módulo de usuarios, y eso
        // deja al cliente como de mostrador con su historial intacto.
        if ($cliente->user_id !== null) {
            throw new ReglaDeNegocioException(
                'Este cliente tiene una cuenta de acceso. Eliminá primero la cuenta desde el '
                .'módulo de usuarios: el cliente va a quedar como cliente de mostrador.'
            );
        }

        if ($this->tieneHistorial($cliente)) {
            throw new ReglaDeNegocioException(
                "«{$cliente->razon_social}» tiene operaciones registradas y no se puede eliminar: "
                .'borrarlo dejaría esas ventas sin saber a quién se le vendió.'
            );
        }

        // Sus direcciones se van con él (cascada del esquema): una dirección sin
        // cliente no significa nada.
        DB::transaction(fn () => $cliente->delete());
    }

    /** @return array<string, mixed> */
    private function camposDe(array $datos): array
    {
        return [
            'razon_social'  => $datos['razon_social'],
            'tipo_doc'      => $datos['tipo_doc'],
            // El consumidor final puede no estar identificado, y la columna es
            // nullable para eso. Cadena vacía y null son lo mismo acá, pero sólo
            // null no colisiona con el UNIQUE.
            'nro_doc'       => blank($datos['nro_doc'] ?? null) ? null : $datos['nro_doc'],
            'condicion_iva' => $datos['condicion_iva'],
            'email'         => $datos['email'] ?? null,
            'telefono'      => $datos['telefono'] ?? null,
        ];
    }

    private function tieneHistorial(Cliente $cliente): bool
    {
        foreach (self::HISTORIAL as $tabla => $columna) {
            if (DB::table($tabla)->where($columna, $cliente->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}