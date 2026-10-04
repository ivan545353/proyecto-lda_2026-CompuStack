<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de los proveedores de un producto.
 *
 * Servicio propio y no parte de ProductoService, por el mismo motivo por el que
 * DireccionService existe aparte de ClienteService: la invariante es sobre el
 * CONJUNTO de proveedores de un producto, y mezclarla con el alta del producto
 * dejaría un servicio que hace dos cosas distintas.
 *
 * La invariante, en una frase: si el producto tiene al menos un proveedor,
 * exactamente uno es el preferido. El esquema no puede expresarla —un boolean con
 * default false acepta tres marcados o ninguno— y los dos estados inválidos hacen
 * daño: con tres marcados la reposición automática no sabe a quién pedirle, y con
 * ninguno tiene que caer en la regla del más barato cuando en realidad alguien ya
 * había decidido.
 *
 * Toda operación bloquea la fila del PRODUCTO, no las de la pivote. Dos pestañas
 * marcando proveedores distintos del mismo producto dejarían los dos marcados:
 * cada una desmarca lo que ve y después se marca a sí misma. Bloquear el producto
 * serializa también el alta del primer vínculo, cuando todavía no hay ninguna fila
 * de pivote que bloquear.
 *
 * Métodos:
 *   vincular()      agrega un proveedor; el primero queda preferido
 *   actualizar()    cambia costo, código y preferencia; desmarcarlo no lo desmarca
 *   desvincular()   quita el proveedor; si era el preferido, asciende el más antiguo
 */
class ProductoProveedorService
{
    public function vincular(Producto $producto, array $datos): void
    {
        DB::transaction(function () use ($producto, $datos) {
            $this->bloquear($producto->id);

            $esElPrimero = ! $producto->proveedores()->exists();

            $preferido = $esElPrimero || (bool) ($datos['es_preferido'] ?? false);

            $producto->proveedores()->attach($datos['proveedor_id'], [
                'costo_ultimo'     => $datos['costo_ultimo'] ?? null,
                'codigo_proveedor' => $datos['codigo_proveedor'] ?? null,
                // El primero queda preferido aunque nadie lo pida: un producto con
                // un solo proveedor y ninguno elegido es un estado sin sentido, y
                // obligar a marcar la casilla en el primer alta es pedirle al
                // usuario que resuelva un detalle del modelo.
                'es_preferido'     => $preferido,
            ]);

            if ($preferido) {
                $this->desmarcarLosDemas($producto->id, (int) $datos['proveedor_id']);
            }
        });
    }

    public function actualizar(Producto $producto, int $proveedorId, array $datos): void
    {
        DB::transaction(function () use ($producto, $proveedorId, $datos) {
            $this->bloquear($producto->id);

            $actual = $producto->proveedores()->findOrFail($proveedorId);

            // Desmarcar al preferido dejaría al producto con proveedores y ninguno
            // elegido, y el sistema no tiene con qué decidir cuál pasa a serlo. Así
            // que se conserva: para cambiarlo hay que marcar otro, y el controlador
            // se lo dice al usuario en lugar de guardar algo distinto de lo que
            // pidió sin avisar.
            $queda = (bool) $actual->pivot->es_preferido || (bool) ($datos['es_preferido'] ?? false);

            $producto->proveedores()->updateExistingPivot($proveedorId, [
                'costo_ultimo'     => $datos['costo_ultimo'] ?? null,
                'codigo_proveedor' => $datos['codigo_proveedor'] ?? null,
                'es_preferido'     => $queda,
            ]);

            if ($queda) {
                $this->desmarcarLosDemas($producto->id, $proveedorId);
            }
        });
    }

    public function desvincular(Producto $producto, int $proveedorId): void
    {
        DB::transaction(function () use ($producto, $proveedorId) {
            $this->bloquear($producto->id);

            $vinculo = $producto->proveedores()->findOrFail($proveedorId);
            $era     = (bool) $vinculo->pivot->es_preferido;

            $producto->proveedores()->detach($proveedorId);

            // Quedarse con proveedores y ninguno preferido es el mismo estado sin
            // sentido. Asciende el más antiguo, que es el que más tiempo viene
            // usándose y el que menos sorprende.
            if ($era) {
                $siguiente = DB::table('producto_proveedor')
                    ->where('producto_id', $producto->id)
                    ->orderBy('created_at')
                    ->orderBy('proveedor_id')
                    ->first();

                if ($siguiente !== null) {
                    $producto->proveedores()->updateExistingPivot(
                        $siguiente->proveedor_id,
                        ['es_preferido' => true],
                    );
                }
            }
        });
    }

    /**
     * Graba lo que un proveedor cobró en una recepción.
     *
     * La llama CompraService al recibir mercadería, y no StockService: el kardex
     * registra movimientos físicos y no sabe —ni tiene que saber— qué es un
     * proveedor. Así la comparación de precios se mantiene al día sola, sin que
     * nadie la cargue a mano.
     *
     * Si el vínculo no existe no se crea: que llegue mercadería de un proveedor que
     * ya no figura como proveedor de ese producto es posible —se desvinculó después
     * de hacer la orden— y no es razón para volver a vincularlo solo.
     */
    public function registrarCosto(Producto $producto, int $proveedorId, float|string $costo): void
    {
        if (! $producto->proveedores()->whereKey($proveedorId)->exists()) {
            return;
        }

        $producto->proveedores()->updateExistingPivot($proveedorId, [
            'costo_ultimo' => round((float) $costo, 2),
        ]);
    }

    /**
     * Bloquea la fila del producto hasta el fin de la transacción.
     *
     * Sobre el producto y no sobre la pivote: así el alta del primer vínculo
     * también se serializa, y no hace falta un bloqueo de rango.
     */
    private function bloquear(int $productoId): void
    {
        Producto::whereKey($productoId)->lockForUpdate()->first();
    }

    private function desmarcarLosDemas(int $productoId, int $preferidoId): void
    {
        DB::table('producto_proveedor')
            ->where('producto_id', $productoId)
            ->where('proveedor_id', '!=', $preferidoId)
            ->where('es_preferido', true)
            ->update(['es_preferido' => false]);
    }
}