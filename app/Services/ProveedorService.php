<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de proveedores.
 *
 * Mismo patrón que los servicios del catálogo y de personas: recibe datos ya
 * validados, no conoce la petición HTTP ni la sesión, y devuelve modelos. En la
 * Etapa 3 el controlador de la API llama a estos mismos métodos.
 *
 * Reglas propias:
 *
 *   1. La dirección del portal sólo existe si el canal es el portal. El Form
 *      Request ya rechaza una URL con otro canal; esto es la segunda barrera, y
 *      es la que va a proteger a la API de la Etapa 3.
 *
 *   2. Un proveedor referenciado se DESACTIVA, no se borra ni rechaza la baja.
 *      Es distinto del cliente, que rechaza la baja, y el criterio es el mismo
 *      que decide las tres políticas del proyecto: la baja lógica existe para lo
 *      que aparece en listas de las que uno elige. Un proveedor se elige de un
 *      desplegable en dos lugares —el formulario de producto y el alta de una
 *      orden de compra—, así que desactivarlo es exactamente lo que hace falta:
 *      deja de ofrecerse y el historial de compras queda intacto. Un cliente, en
 *      cambio, se busca, y esconderlo de un buscador es lo contrario de lo que
 *      se necesita.
 *
 *   3. La cascada la pide el usuario y sólo aplica al desactivar. Que se te caiga
 *      un proveedor no significa que dejes de vender lo que tenés en depósito.
 *
 * Métodos:
 *   crear()       alta
 *   actualizar()  edición, con la cascada opcional de productos
 *   eliminar()    baja física o lógica según esté referenciado; devuelve cuál fue
 */
class ProveedorService
{
    /**
     * Tablas que referencian a un proveedor, con la columna que lo apunta.
     *
     *
     * `ordenes_compra` se enumera acá aunque el modelo OrdenCompra sea de un paso
     * posterior: el chequeo es sobre la tabla, igual que UsuarioService::HISTORIAL
     * —que ya enumeraba esta tabla desde la Fase 4—, así que la baja respeta las
     * órdenes desde el primer día en que exista una.
     */
    private const REFERENCIAS = [
        'productos'      => 'proveedor_id',
        'ordenes_compra' => 'proveedor_id',
    ];

    public function crear(array $datos): Proveedor
    {
        return DB::transaction(fn () => Proveedor::create($this->camposDe($datos)));
    }

    public function actualizar(
        Proveedor $proveedor,
        array $datos,
        bool $desactivarProductos = false,
    ): Proveedor {
        return DB::transaction(function () use ($proveedor, $datos, $desactivarProductos) {
            $proveedor->update($this->camposDe($datos));

            // Misma regla que en marcas y categorías. Por omisión los productos
            // siguen activos y a la venta, y el mensaje del controlador lo dice.
            if ($desactivarProductos && ! $proveedor->activo) {
                $proveedor->productos()->update(['activo' => false]);
            }

            return $proveedor->fresh();
        });
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminar(Proveedor $proveedor): bool
    {
        if ($this->estaReferenciado($proveedor)) {
            // Un solo UPDATE es atómico por sí mismo: la transacción envuelve la
            // baja física, que es la que toca más de una cosa.
            $proveedor->update(['activo' => false]);

            return false;
        }

        DB::transaction(fn () => $proveedor->delete());

        return true;
    }

    /**
     * Los campos se enumeran uno por uno: nunca create($validated).
     *
     * @return array<string, mixed>
     */
    private function camposDe(array $datos): array
    {
        return [
            'razon_social' => $datos['razon_social'],
            'cuit'         => $datos['cuit'],
            'email'        => $datos['email'] ?? null,
            'telefono'     => $datos['telefono'] ?? null,
            'contacto'     => $datos['contacto'] ?? null,
            'canal_pedido' => $datos['canal_pedido'],

            // Invariante del servicio: una URL sin su canal es un enlace que la
            // pantalla no debe ofrecer. El proveedor que deja de operar por
            // portal se queda sin dirección, y no queda una colgada apuntando a
            // un portal que ya no se usa.
            'portal_url' => $datos['canal_pedido'] === 'portal_externo'
                ? ($datos['portal_url'] ?? null)
                : null,

            'plazo_entrega_dias' => $datos['plazo_entrega_dias'],
            'activo'             => $datos['activo'],
        ];
    }

    private function estaReferenciado(Proveedor $proveedor): bool
    {
        foreach (self::REFERENCIAS as $tabla => $columna) {
            if (DB::table($tabla)->where($columna, $proveedor->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}