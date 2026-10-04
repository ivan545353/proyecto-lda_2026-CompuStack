<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

 /**
     * Tablas que referencian a un proveedor, con la columna que lo apunta.
     *
     * El chequeo es sobre la TABLA, no sobre el modelo —igual que
     * UsuarioService::HISTORIAL—, así la baja respeta las referencias desde el
     * primer día en que exista una fila, sin depender de que el modelo tenga
     * declarada la relación.
     *
     * Las tres se comportan distinto en la base, y por eso el chequeo no se le
     * puede dejar a la foreign key:
     *   productos.proveedor_id            nullOnDelete     (columna de
     *       transición: se elimina en el paso 4 del plan de varios proveedores)
     *   producto_proveedor.proveedor_id   cascadeOnDelete
     *   ordenes_compra.proveedor_id       RESTRICT
     *
     * Un DELETE sin este chequeo dejaría productos sin proveedor y vínculos
     * borrados en silencio en dos de los casos, y en el tercero estallaría con
     * un error de integridad que el usuario no puede interpretar. Desactivar es
     * la respuesta correcta en los tres (M-16).
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
        'producto_proveedor' => 'proveedor_id',
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