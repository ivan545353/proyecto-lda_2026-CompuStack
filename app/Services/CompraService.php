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
 *   lineasSugeridas()     los sugeridos elegidos, convertidos en renglones del
 *                         armador, con el proveedor y el costo que decide el servidor
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
                'usuario_creo_id' => $usuario?->id,
            ]);

            $this->reemplazarLineas($orden, $lineas);

            return $orden->fresh('lineas');
        });
    }

    /**
     * El alta de la tarea de reposición.
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
     * Los sugeridos elegidos, convertidos en renglones del armador.
     *
     * @param  array<int, int>  $elegidos  ids de productos
     * @return array<int, array<string, mixed>>
     */
    public function lineasSugeridas(array $elegidos): array
    {
        if ($elegidos === []) {
            return [];
        }

        return Producto::query()
            ->paraReponer()
            ->conProveedorActivo()
            ->conIds($elegidos)
            // proveedorParaReponer() y el costo del vínculo leen esta relación (A-26).
            ->with('proveedores')
            // Por código, para que los renglones salgan en el orden en que la
            // pantalla los mostró y no en el que se tildaron.
            ->orderBy('codigo')
            ->get()
            ->map(function (Producto $producto) {
                $proveedor = $producto->proveedorParaReponer();

                return [
                    'producto_id'     => $producto->id,
                    'proveedor_id'    => $proveedor->id,
                    // Fija, y puede no alcanzar: es el pendiente #34. Si quedó en
                    // cero el armador lo rechaza al guardar, que es lo correcto.
                    'cantidad_pedida' => $producto->cantidad_reposicion,
                    'costo_unitario'  => $this->costoDelProveedor($producto, $proveedor->id),
                ];
            })
            ->all();
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

            $this->recalcularTotal($o);
            $this->cambiarEstado($o, 'aprobada', [
                'usuario_aprobo_id' => $usuario->id,
                'fecha_aprobacion'  => now(),
            ]);

            return $o->fresh('lineas');
        });
    }

    /**
     * El administrativo declara que el pedido salió hacia el proveedor.
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
     * @param  array{numero?: string|null, fecha?: string|null}  $remito  el del proveedor: los dos datos o ninguno
     */
    public function recibir(OrdenCompra $orden, array $cantidades, User $usuario, array $remito = []): OrdenCompra
    {
        $cantidades = array_filter(array_map('intval', $cantidades), fn (int $c) => $c > 0);

        if ($cantidades === []) {
            throw new ReglaDeNegocioException('Indicá cuántas unidades llegaron en al menos un producto.');
        }

        [$remitoNumero, $remitoFecha] = $this->remitoDe($remito);

        return DB::transaction(function () use ($orden, $cantidades, $usuario, $remitoNumero, $remitoFecha) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            if (! MaquinaEstadosCompra::puedeRecibir($o->estado)) {
                throw new ReglaDeNegocioException(
                    "Una orden «{$o->estadoTexto()}» no puede recibir mercadería."
                );
            }

            foreach ($cantidades as $lineaId => $cantidad) {
                $linea = $o->lineas()->findOrFail($lineaId);

                $pendiente = $linea->cantidadPendiente();

                if ($cantidad > $pendiente) {
                    throw new ReglaDeNegocioException(
                        "De «{$linea->producto->nombre}» quedan {$pendiente} unidad(es) pendientes y se están "
                        ."recibiendo {$cantidad}. Si el proveedor mandó más de lo pedido, recibí lo pedido y cargá "
                        .'la diferencia como ajuste de inventario: así la orden sigue diciendo lo que se acordó.'
                    );
                }

                $this->stock->recibirCompra(
                    $linea->producto,
                    $cantidad,
                    $linea->costo_unitario,
                    $o,
                    $usuario,
                    $remitoNumero,
                    $remitoFecha,
                );

                $this->proveedores->registrarCosto(
                    $linea->producto,
                    $o->proveedor_id,
                    $linea->costo_unitario,
                );

                $linea->cantidad_recibida = $linea->cantidad_recibida + $cantidad;
                $linea->save();
            }

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

            $this->cambiarEstado($o, 'recibida');

            return $o->fresh('lineas');
        });
    }

    public function cancelar(OrdenCompra $orden): OrdenCompra
    {
        return DB::transaction(function () use ($orden) {
            $o = OrdenCompra::lockForUpdate()->findOrFail($orden->id);

            $this->cambiarEstado($o, 'cancelada');

            return $o->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /**
     * Cambia el estado validando la transición.
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
     * El remito del proveedor como par [número, fecha]: completo o vacío, nunca a medias.
     *
     * @param  array{numero?: string|null, fecha?: string|null}  $remito
     * @return array{0: string|null, 1: string|null}
     */
    private function remitoDe(array $remito): array
    {
        $numero = trim((string) ($remito['numero'] ?? ''));
        $fecha  = trim((string) ($remito['fecha'] ?? ''));

        if (($numero === '') !== ($fecha === '')) {
            throw new ReglaDeNegocioException(
                'El remito del proveedor se registra con su número y su fecha, o no se registra.'
            );
        }

        return $numero === '' ? [null, null] : [$numero, $fecha];
    }

    /**
     * Lo último que este proveedor cobró por este producto, o cero si no consta.
     */
    private function costoDelProveedor(Producto $producto, int $proveedorId): string|float
    {
        $vinculo = $producto->loadMissing('proveedores')
            ->proveedores
            ->firstWhere('id', $proveedorId);

        return $vinculo?->pivot->costo_ultimo ?? 0;
    }
}