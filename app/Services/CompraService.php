<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraLinea;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Support\MaquinaEstadosCompra;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de las órdenes de compra.
 *
 * No conoce la petición HTTP ni la sesión: recibe datos validados y modelos, y
 * devuelve modelos. En la Etapa 3 la API llama a estos mismos métodos.
 *
 * Tres invariantes propias:
 *
 *   1. **Sólo un borrador se edita.** Una vez aprobada, el monto está comprometido
 *      y las líneas quedan congeladas. Por eso `actualizarBorrador()` reemplaza las
 *      líneas: en borrador ninguna tiene cantidad recibida, así que no se pierde
 *      nada. Es la misma técnica que el `SaleDao::update()` original usaba mal
 *      (M-14) —borraba las líneas y las reinsertaba releyendo el precio, y un
 *      presupuesto emitido cambiaba de total—; acá el costo lo trae el formulario y
 *      no hay nada guardado en la línea que el formulario no cargue. La misma
 *      técnica está bien o mal según si la fila tiene estado propio.
 *
 *   2. **El total es derivado.** Se recalcula desde las líneas al guardar y al
 *      aprobar, nunca llega desde el formulario. El monto que se autoriza es el de
 *      las líneas que están guardadas en ese momento.
 *
 *   3. **Todo cambio de estado pasa por la máquina de estados**, y `estado` está
 *      fuera de `$fillable` para que no pueda llegar desde una petición.
 *   4. **El costo del vínculo se graba al recibir, no al pedir.** Un borrador puede
 *      cambiar de costo, o no llegar nunca; lo que se graba en
 *      `producto_proveedor.costo_ultimo` es el costo de la línea de una orden que
 *      efectivamente entró al depósito. Es el costo **acordado**, no el facturado:
 *      la recepción no pide un costo, así que si el proveedor facturara otro hoy no
 *      hay dónde registrarlo. Está anotado en los pendientes de usabilidad.
 *   5. **Lo que el documento imprime se copia en la línea, no se referencia.**
 *      `costo_unitario` y `codigo_proveedor` quedan congelados al escribirla. Es lo
 *      que permite no archivar el PDF del pedido: regenerarlo dentro de un año da
 *      el mismo documento, aunque el proveedor haya cambiado de precios y de
 *      códigos. Mismo principio que `venta_lineas.precio_unitario`.
 *
 * Métodos:
 *   crearBorrador()       alta; sin usuario significa que la generó el sistema
 *   actualizarBorrador()  reemplaza las líneas de un borrador
 *   aprobar()             autoriza el gasto y congela las líneas
 *   marcarEnviada()       el administrativo declara que el pedido salió
 *   cerrarIncompleta()    cierra una orden que el proveedor no va a completar
 *   cancelar()            antes de que entre mercadería
 *   generarBorrador()     el alta que usa la tarea de reposición, con el costo del
 *                         vínculo de ese proveedor
 *   armarPedido()         el armador: una pantalla, un borrador por proveedor
 *   recibir()             recepción total o parcial; ingresa stock y graba el costo
 */
class CompraService
{
    public function __construct(
        private StockService $stock,
        private ProductoProveedorService $proveedores,
    ) {
    }

    public function crearBorrador(array $datos, ?User $usuario = null): OrdenCompra
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una orden de compra necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        return DB::transaction(function () use ($datos, $lineas, $usuario) {
            $orden = OrdenCompra::create([
                'proveedor_id'  => $datos['proveedor_id'],
                'observaciones' => $datos['observaciones'] ?? null,
                // null SIGNIFICA «la generó el sistema». La pantalla lo dice con
                // palabras en lugar de mostrar una celda vacía.
                'usuario_creo_id' => $usuario?->id,
            ]);

            $this->reemplazarLineas($orden, $lineas);

            return $orden->fresh('lineas');
        });
    }

    /**
     * El alta de la tarea de reposición.
     *
     * El costo de cada línea sale de `producto_proveedor.costo_ultimo` **de este
     * proveedor**: es lo último que ESE proveedor cobró por ESE producto, que es el
     * número que el pedido necesita. `productos.costo_promedio` no sirve acá —es el
     * promedio ponderado de todas las compras a todos los proveedores, y no es el
     * precio de ninguno—; queda donde es correcto, en el margen de la venta.
     *
     */
    public function generarBorrador(int $proveedorId, iterable $productos): OrdenCompra
    {
        $lineas = [];

        foreach ($productos as $producto) {
            $lineas[] = [
                'producto_id'     => $producto->id,
                'cantidad_pedida' => $producto->cantidad_reposicion,
                'costo_unitario'  => $this->costoDelProveedor($producto, $proveedorId),
            ];
        }

        return $this->crearBorrador([
            'proveedor_id'  => $proveedorId,
            'observaciones' => 'Generada automáticamente: stock por debajo del mínimo.',
            'lineas'        => $lineas,
        ]);
    }

    /**
     * El armador de pedido: varias líneas con su proveedor cada una, y al guardar
     * **un borrador por proveedor**.
     *
     * @return Collection<int, OrdenCompra>  los borradores creados, en el orden en
     *                                       que se numeraron
     */
    public function armarPedido(array $datos, ?User $usuario = null): Collection
    {
        $lineas = $datos['lineas'] ?? [];
        if ($lineas === []) {
            throw new ReglaDeNegocioException('El pedido necesita al menos un producto.');
        }
        $this->exigirParesDistintos($lineas);

        $grupos = collect($lineas)->groupBy(fn (array $linea) => (int) $linea['proveedor_id']);

        $proveedores = Proveedor::query()
            ->whereIn('id', $grupos->keys())
            ->orderBy('razon_social')
            ->get(['id', 'razon_social']);

        return DB::transaction(fn () => $proveedores->map(
            fn (Proveedor $proveedor) => $this->crearBorrador([
                'proveedor_id' => $proveedor->id,
                'lineas'       => $grupos->get($proveedor->id)->all(),
            ], $usuario)
                ->setRelation('proveedor', $proveedor),
        ));
    }

    public function actualizarBorrador(OrdenCompra $orden, array $datos): OrdenCompra
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una orden de compra necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        return DB::transaction(function () use ($orden, $datos, $lineas) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            if (! $o->esEditable()) {
                throw new ReglaDeNegocioException(
                    "Esta orden está «{$o->estadoTexto()}» y ya no se puede modificar: "
                    .'el monto quedó comprometido al aprobarla.'
                );
            }

            $o->update([
                'proveedor_id'  => $datos['proveedor_id'],
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $this->reemplazarLineas($o, $lineas);

            return $o->fresh('lineas');
        });
    }

    public function aprobar(OrdenCompra $orden, User $usuario): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $usuario) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            if ($o->lineas()->count() === 0) {
                throw new ReglaDeNegocioException('No se puede aprobar una orden sin productos.');
            }

            // El total se recalcula justo antes: es el monto que esta persona
            // autoriza, y tiene que ser el de las líneas guardadas en este momento.
            $this->recalcularTotal($o);

            // No se prohíbe que apruebe quien la creó. A diferencia del cambio de
            // rol propio —que no tiene ningún uso legítimo y por eso C-3 se cierra
            // prohibiéndolo—, aprobar tu propia orden sí lo tiene: en un negocio
            // con un solo administrativo es el único camino posible. El control es
            // que las dos identidades quedan grabadas y una auditoría ve cuándo
            // coinciden.
            $this->cambiarEstado($o, 'aprobada', [
                'usuario_aprobo_id' => $usuario->id,
                'fecha_aprobacion'  => now(),
            ]);

            return $o->fresh('lineas');
        });
    }

    /**
     * El administrativo declara que el pedido salió hacia el proveedor.
     *
     * No recibe el usuario porque no hay dónde guardarlo: la decisión de no agregar
     * `usuario_envio_id` está escrita en `modelo-datos.md`, y el responsable del
     * compromiso es quien aprobó. Un parámetro que no se usa sería decoración.
     */
    public function marcarEnviada(OrdenCompra $orden): OrdenCompra
    {
        return DB::transaction(function () use ($orden) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            $this->cambiarEstado($o, 'enviada', ['fecha_envio' => now()]);

            return $o->fresh();
        });
    }

    /**
     * Recepción de mercadería, total o parcial.
     *
     * @param  array<int|string, int|string>  $cantidades  [id de línea => cuántas llegaron ahora]
     */
    public function recibir(OrdenCompra $orden, array $cantidades, User $usuario): OrdenCompra
    {
        // Las líneas donde no llegó nada no son un error: el formulario las manda
        // en cero porque muestra todas. Lo que no puede pasar es que no llegue nada
        // en ninguna.
        $cantidades = array_filter(array_map('intval', $cantidades), fn (int $c) => $c > 0);

        if ($cantidades === []) {
            throw new ReglaDeNegocioException('Indicá cuántas unidades llegaron en al menos un producto.');
        }

        return DB::transaction(function () use ($orden, $cantidades, $usuario) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            if (! MaquinaEstadosCompra::puedeRecibir($o->estado)) {
                throw new ReglaDeNegocioException(
                    "Una orden «{$o->estadoTexto()}» no puede recibir mercadería."
                );
            }

            foreach ($cantidades as $lineaId => $cantidad) {
                // El hijo se resuelve a través de la relación del padre. Buscar la
                // línea por su id suelto permitiría recibir mercadería contra la
                // orden de otro proveedor con una petición armada a mano: Laravel
                // no verifica que dos parámetros estén relacionados.
                $linea = $o->lineas()->findOrFail($lineaId);

                $pendiente = $linea->cantidadPendiente();

                if ($cantidad > $pendiente) {
                    throw new ReglaDeNegocioException(
                        "De «{$linea->producto->nombre}» quedan {$pendiente} unidad(es) pendientes y se están "
                        ."recibiendo {$cantidad}. Si el proveedor mandó más de lo pedido, recibí lo pedido y cargá "
                        .'la diferencia como ajuste de inventario: así la orden sigue diciendo lo que se acordó.'
                    );
                }

                // StockService abre su propia transacción. En Laravel una
                // transacción anidada es un savepoint, así que si una línea
                // posterior falla se revierte todo junto, incluido el kardex.
                $this->stock->recibirCompra(
                    $linea->producto,
                    $cantidad,
                    $linea->costo_unitario,
                    $o,
                    $usuario,
                );

                $this->proveedores->registrarCosto(
                    $linea->producto,
                    $o->proveedor_id,
                    $linea->costo_unitario,
                );

                $linea->cantidad_recibida = $linea->cantidad_recibida + $cantidad;
                $linea->save();
            }

            // El estado se deriva de las líneas, no se elige: mientras quede algo
            // pendiente la orden sigue parcial.
            $this->cambiarEstado(
                $o,
                $o->fresh('lineas')->tienePendientes() ? 'recibida_parcial' : 'recibida',
            );

            return $o->fresh('lineas');
        });
    }

    /** El proveedor no va a entregar el resto: la orden se cierra con lo que llegó. */
    public function cerrarIncompleta(OrdenCompra $orden): OrdenCompra
    {
        return DB::transaction(function () use ($orden) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);
            $o->load('lineas');

            if ((int) $o->lineas->sum('cantidad_recibida') === 0) {
                throw new ReglaDeNegocioException(
                    'No entró ninguna unidad de esta orden. Si el proveedor no va a entregar, '
                    .'lo que corresponde es cancelarla.'
                );
            }

            if (! $o->tienePendientes()) {
                throw new ReglaDeNegocioException(
                    'Esta orden no tiene unidades pendientes: ya se recibió completa.'
                );
            }

            // «recibida» significa cerrada. Cuánto llegó de cada producto lo dicen
            // las líneas, que es donde vive el saldo.
            $this->cambiarEstado($o, 'recibida');

            return $o->fresh('lineas');
        });
    }

    public function cancelar(OrdenCompra $orden): OrdenCompra
    {
        return DB::transaction(function () use ($orden) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            // `recibida_parcial` no está entre los orígenes de `cancelada` en la
            // máquina de estados, así que una orden con mercadería recibida se
            // rechaza acá sin ningún `if`: la tabla de transiciones ya lo dice.
            $this->cambiarEstado($o, 'cancelada');

            return $o->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /**
     * Cambia el estado validando la transición.
     *
     * `estado` está fuera de `$fillable`, así que se asigna por propiedad: un
     * `update(['estado' => …])` lo **descartaría en silencio** y la orden se
     * quedaría en el estado anterior sin que nada falle. Pasar siempre por acá
     * evita tener que recordarlo en cada método.
     */
    private function cambiarEstado(OrdenCompra $orden, string $nuevo, array $columnas = []): void
    {
        MaquinaEstadosCompra::validar($orden->estado, $nuevo);

        $orden->estado = $nuevo;

        foreach ($columnas as $columna => $valor) {
            $orden->{$columna} = $valor;
        }

        $orden->save();
    }

        private function reemplazarLineas(OrdenCompra $orden, array $lineas): void
    {
        $orden->lineas()->delete();

        // Una consulta para toda la orden, no una por línea.
        $codigos = $this->proveedores->codigosDe(
            $orden->proveedor_id,
            array_column($lineas, 'producto_id'),
        );

        foreach ($lineas as $linea) {
            $nueva = $orden->lineas()->make([
                'producto_id'     => $linea['producto_id'],
                'cantidad_pedida' => $linea['cantidad_pedida'],
                'costo_unitario'  => $linea['costo_unitario'],
            ]);

            $nueva->codigo_proveedor = $codigos->get($linea['producto_id']);

            $nueva->save();
        }

        $this->recalcularTotal($orden);
    }

    private function recalcularTotal(OrdenCompra $orden): void
    {
        // total_estimado está fuera de $fillable: asignación directa, igual que el
        // estado.
        $orden->total_estimado = $orden->lineas()->get()
            ->sum(fn (OrdenCompraLinea $linea) => $linea->subtotalPedido());

        $orden->save();
    }

    /**
     * La base tiene UNIQUE (orden_compra_id, producto_id).
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function exigirProductosDistintos(array $lineas): void
    {
        $productos = array_column($lineas, 'producto_id');

        if (count($productos) !== count(array_unique($productos))) {
            throw new ReglaDeNegocioException(
                'La orden tiene el mismo producto cargado más de una vez. Dejá una sola línea '
                .'por producto, con la cantidad total.'
            );
        }
    }

    /**
     * En el armador la regla es sobre el PAR, no sobre el producto.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function exigirParesDistintos(array $lineas): void
    {
        $pares = array_map(
            fn (array $linea) => $linea['producto_id'].'-'.$linea['proveedor_id'],
            $lineas,
        );

        if (count($pares) !== count(array_unique($pares))) {
            throw new ReglaDeNegocioException(
                'Hay un producto cargado dos veces para el mismo proveedor. Dejá una sola línea '
                .'por producto y proveedor, con la cantidad total.'
            );
        }
    }

    /**
     * Lo último que este proveedor cobró por este producto, o cero si no consta.
     *
     * Devuelve el valor de la pivote tal como lo da la base, sin castear: lo
     * consume `reemplazarLineas()`, que escribe en una columna `decimal(12,2)`.
     */
    private function costoDelProveedor(Producto $producto, int $proveedorId): string|float
    {
        $vinculo = $producto->loadMissing('proveedores')
            ->proveedores
            ->firstWhere('id', $proveedorId);

        return $vinculo?->pivot->costo_ultimo ?? 0;
    }
}