<?php

namespace App\Console\Commands;

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\CompraService;
use App\Support\Importe;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reposición automática: un borrador de orden de compra por proveedor con todo lo
 * que está por debajo del stock mínimo.
 *
 * Es el requerimiento 11, y es la pieza que el hallazgo **A-13** nombraba como
 * bloqueada: «esto es bloqueante para los requerimientos 5, 9 y 11 (compra
 * automática)». Sin kardex no se podía, porque el stock era una columna que se
 * pisaba con un UPDATE y nadie sabía si la baja había sido una venta, un ajuste o
 * un error. Con el libro de movimientos y el stock disponible de la Fase 5, sí.
 *
 * Genera **borradores, no pedidos**. El comando no decide gastar plata: propone, y
 * una persona con `compra.aprobar` autoriza el monto. Esa es la razón por la que la
 * orden queda con `usuario_creo_id = null` y la ficha dice «la generó el sistema»
 * en lugar de mostrar una celda vacía.
 *
 * Tampoco pasa por el Gate, y no es un olvido: no hay sesión ni usuario a quien
 * pedirle un permiso. El control está después, en la aprobación.
 *
 * Implementa Isolatable para que `--isolated` tome un lock antes de correr. Sin
 * eso, dos corridas simultáneas —el cron y alguien que lo ejecuta a mano— verían
 * las dos que el producto no está pedido y generarían dos borradores del mismo
 * pedido. La idempotencia de scopeSinPedidoEnCurso() protege de correr dos veces
 * SEGUIDAS; el lock, de correr dos veces A LA VEZ.
 */
class GenerarOrdenesReposicion extends Command implements Isolatable
{
    protected $signature = 'compras:generar-reposicion';

    protected $description = 'Genera órdenes de compra en borrador para los productos que están por debajo del stock mínimo';

    public function handle(CompraService $compras): int
    {
        $criticos = Producto::query()
            ->reponibles()
            ->stockCritico()
            ->sinPedidoEnCurso()
            // proveedorParaReponer() y el costo del vínculo leen esta relación. Sin
            // el eager load serían dos consultas por producto (A-26).
            ->with('proveedores')
            // Orden determinista: las líneas de la orden se crean en este orden, así
            // que el PDF sale ordenado por código y no por id de inserción.
            ->orderBy('codigo')
            ->get();

        if ($criticos->isEmpty()) {
            $this->info('No hay productos por debajo del mínimo que no estén ya pedidos.');

            return self::SUCCESS;
        }

        // Se parte en dos ANTES de generar nada. proveedorParaReponer() devuelve
        // null exactamente cuando el producto no tiene ningún proveedor activo, que
        // es la misma condición que el listado avisa con noSePuedeReponer().
        [$pedibles, $huerfanos] = $criticos->partition(
            fn (Producto $producto) => $producto->proveedorParaReponer() !== null,
        );

        $this->informar($this->generar($compras, $pedibles), $huerfanos);

        return self::SUCCESS;
    }

    /**
     * Una orden por proveedor, en orden alfabético.
     *
     * Alfabético y no por id de proveedor para que los números de orden salgan en el
     * mismo orden en que el informe las lista, igual que hace el armador manual.
     *
     * A quién se le pide cada producto lo decide Producto::proveedorParaReponer()
     * —el preferido, y si no hay ninguno marcado, el más barato de los activos—. El
     * comando sólo agrupa: la regla vive en el modelo, donde la usa también el
     * comparador de proveedores.
     *
     * @param  Collection<int, Producto>  $productos
     * @return Collection<int, OrdenCompra>
     */
    private function generar(CompraService $compras, Collection $productos): Collection
    {
        $grupos = $productos->groupBy(
            fn (Producto $producto) => $producto->proveedorParaReponer()->id,
        );

        return Proveedor::query()
            ->whereIn('id', $grupos->keys())
            ->orderBy('razon_social')
            ->get(['id', 'razon_social'])
            ->map(fn (Proveedor $proveedor) => $compras
                ->generarBorrador($proveedor->id, $grupos->get($proveedor->id))
                // El proveedor ya está en memoria: se lo pega a la orden para
                // imprimir la razón social sin volver a consultarlo.
                ->setRelation('proveedor', $proveedor));
    }

    /**
     * @param  Collection<int, OrdenCompra>  $ordenes
     * @param  Collection<int, Producto>  $huerfanos
     */
    private function informar(Collection $ordenes, Collection $huerfanos): void
    {
        if ($ordenes->isNotEmpty()) {
            $this->info($ordenes->count() === 1
                ? 'Se generó 1 orden en borrador:'
                : "Se generaron {$ordenes->count()} órdenes en borrador:");

            $this->table(
                ['Orden', 'Proveedor', 'Productos', 'Total estimado'],
                $ordenes->map(fn (OrdenCompra $orden) => [
                    $orden->numeroFormateado(),
                    $orden->proveedor->razon_social,
                    $orden->lineas->count(),
                    Importe::pesos($orden->total_estimado),
                ])->all(),
            );
        }

        if ($huerfanos->isNotEmpty()) {
            // Un producto crítico sin proveedor activo no es una falla del comando:
            // es un dato que alguien tiene que corregir. Si no se dijera, el
            // producto quedaría sin reponer todas las noches en silencio, que es
            // exactamente el escenario que el aviso del listado también cubre.
            $this->warn(
                "Quedaron {$huerfanos->count()} producto(s) sin pedir porque no tienen ningún proveedor activo:"
            );

            foreach ($huerfanos as $producto) {
                $this->line("  - {$producto->codigo}  {$producto->nombre}");
            }
        }

        // El comando corre a las siete de la mañana y la salida de la consola no la
        // lee nadie. El log es la evidencia de que corrió y de qué decidió: una
        // línea por corrida, con los números de orden y los códigos que quedaron
        // afuera.
        Log::info('Reposición automática', [
            'ordenes'       => $ordenes->map(fn (OrdenCompra $orden) => $orden->numeroFormateado())->all(),
            'sin_proveedor' => $huerfanos->pluck('codigo')->all(),
        ]);
    }
}