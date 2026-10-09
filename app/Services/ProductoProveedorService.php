<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\Proveedor;
use App\Exceptions\ReglaDeNegocioException;
use App\Models\OrdenCompra;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 *   vincular()        agrega un proveedor; el primero queda preferido
 *   actualizar()      cambia costo, código y preferencia; desmarcarlo no lo desmarca
 *   marcarPreferido() elige a quién comprarle, sin tocar el resto del vínculo
 *   desvincular()     quita el proveedor; si era el preferido, asciende el más antiguo
 *   registrarCosto()  graba lo que se pagó en una recepción
 *   pedidoEnCurso()   la orden abierta de ese par, si hay; es lo que bloquea la baja
 *   pedidosEnCurso()  el mismo dato para toda la tabla, en una consulta
 *   codigosDe()       los códigos de un proveedor, para congelarlos en la orden
 */
class ProductoProveedorService
{
    public function vincular(Producto $producto, array $datos): void
    {
        DB::transaction(function () use ($producto, $datos) {
            $this->bloquear($producto->id);

             if ($producto->proveedores()->whereKey((int) $datos['proveedor_id'])->exists()) {
                $proveedor = Proveedor::findOrFail($datos['proveedor_id']);

                throw new ReglaDeNegocioException(
                    "{$proveedor->razon_social} ya figura como proveedor de «{$producto->nombre}». "
                    .'Editá el vínculo que ya existe para cambiarle el costo o el código.'
                );
            }

            $esElPrimero = ! $producto->proveedores()->exists();

            $preferido = $esElPrimero || (bool) ($datos['es_preferido'] ?? false);

            $producto->proveedores()->attach($datos['proveedor_id'], [
                'costo_ultimo'     => $datos['costo_ultimo'] ?? null,
                'codigo_proveedor' => $datos['codigo_proveedor'] ?? null,
                // El primero queda preferido aunque nadie lo pida
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

    /**
     * Marca a este proveedor como el preferido, y desmarca al que lo fuera.
     */
    public function marcarPreferido(Producto $producto, int $proveedorId): void
    {
        DB::transaction(function () use ($producto, $proveedorId) {
            $this->bloquear($producto->id);

            // Dentro del bloqueo: si el vínculo se desvinculó en otra pestaña entre
            // que se dibujó la tabla y se apretó el botón, esto da 404 en lugar de
            // marcar preferido a un proveedor que ya no provee el producto.
            $producto->proveedores()->findOrFail($proveedorId);

            $producto->proveedores()->updateExistingPivot($proveedorId, ['es_preferido' => true]);

            $this->desmarcarLosDemas($producto->id, $proveedorId);
        });
    }

    public function desvincular(Producto $producto, int $proveedorId): void
    {
        DB::transaction(function () use ($producto, $proveedorId) {
            $this->bloquear($producto->id);

            $vinculo = $producto->proveedores()->findOrFail($proveedorId);

            // La baja se rechaza, no se convierte en otra cosa. No hay `activo` en
            // la pivote y es una decisión: la baja lógica existe para lo que
            // aparece en listas de las que uno elige, y un vínculo no se elige de
            // una lista. Sumaría un estado a filtrar en todas las consultas de la
            // pivote para resolver algo que el congelado del dato en la línea de la
            // orden resuelve mejor.
            $enCurso = $this->pedidoEnCurso($producto, $proveedorId);

            if ($enCurso !== null) {
                throw new ReglaDeNegocioException(
                    "No se puede quitar a {$vinculo->razon_social} de «{$producto->nombre}»: "
                    ."la orden {$enCurso->numeroFormateado()} está «{$enCurso->estadoTexto()}» y "
                    .'le pide ese producto a ese proveedor. Recibila o cancelala primero.'
                );
            }

            $era = (bool) $vinculo->pivot->es_preferido;

            $producto->proveedores()->detach($proveedorId);

            // Quedarse con proveedores y ninguno preferido es un estado sin
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
     * La orden ABIERTA más antigua que le pide este producto a este proveedor.
     *
     * **Las órdenes cerradas no impiden nada.** La orden guarda su propio
     * historial: el proveedor en la cabecera, y el producto con su
     * `costo_unitario` congelado en la línea. El par se reconstruye con un join
     * sin pasar por la pivote, así que desvincular no borra ni un dato del
     * historial. Lo único que vive sólo acá es `codigo_proveedor`, y por eso se
     * congela en la línea de la orden al escribirla (paso 5 del plan).
     *
     * **Las abiertas sí.** Hay un pedido en curso que nombra a ese par, y el
     * formulario de edición de la orden ofrece sólo los productos que el proveedor
     * provee: con el vínculo borrado, la línea apuntaría a un producto que el
     * selector no ofrece y volver a guardar la orden la perdería sin avisar. Es el
     * mismo pozo que `CompraController::productosDisponibles()` ya tapa para los
     * productos desactivados.
     *
     * La más antigua y no cualquiera: es la que viene esperando hace más tiempo, y
     * la que conviene nombrar en el mensaje.
     */
    public function pedidoEnCurso(Producto $producto, int $proveedorId): ?OrdenCompra
    {
        return $this->ordenesEnCurso($producto)
            ->where('proveedor_id', $proveedorId)
            ->orderBy('id')
            ->first();
    }

    /**
     * Lo mismo para toda la tabla del comparador, en una consulta y no una por fila.
     *
     * Comparte el constructor de consulta con `pedidoEnCurso()`, así «pedido en
     * curso» tiene **una sola definición**: con dos, la pantalla ofrecería un botón
     * que el servicio rechaza, o lo esconderia sin motivo. Es el desajuste de A-24
     * en su forma de pantalla.
     *
     * `keyBy` conserva la ÚLTIMA aparición de cada clave, así que el orden
     * descendente deja la de id más bajo: la misma orden que elige
     * `pedidoEnCurso()`.
     *
     * @return Collection<int, OrdenCompra>  proveedor_id => orden
     */
    public function pedidosEnCurso(Producto $producto): Collection
    {
        return $this->ordenesEnCurso($producto)
            ->orderByDesc('id')
            ->get()
            ->keyBy('proveedor_id');
    }

    /** Las órdenes vivas que piden este producto, sin filtrar por proveedor. */
    private function ordenesEnCurso(Producto $producto): Builder
    {
        return OrdenCompra::query()
            ->abiertas()
            ->whereHas('lineas', fn (Builder $lineas) => $lineas->where('producto_id', $producto->id));
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

    /**
     * El código con el que un proveedor identifica cada uno de estos productos.
     *
     * Lo usa `CompraService` para congelarlo en la línea de la orden. Vive acá y no
     * allá porque la estructura de la pivote es de este servicio: `CompraService`
     * pide «los códigos de este proveedor para estos productos» y no sabe en qué
     * tabla están, igual que no sabe cómo se guarda `costo_ultimo`.
     *
     * Una consulta para todos los productos juntos, no una por línea. Los pares que
     * no existen simplemente no aparecen en el resultado: un producto que este
     * proveedor no provee no tiene código, y eso es null, no un error.
     *
     * @param  array<int, int|string>  $productoIds
     * @return Collection<int, ?string>  producto_id => código
     */
    public function codigosDe(int $proveedorId, array $productoIds): Collection
    {
        if ($productoIds === []) {
            return collect();
        }

        return DB::table('producto_proveedor')
            ->where('proveedor_id', $proveedorId)
            ->whereIn('producto_id', $productoIds)
            ->pluck('codigo_proveedor', 'producto_id');
    }
}