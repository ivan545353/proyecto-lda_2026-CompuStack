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
 */
class GenerarOrdenesReposicion extends Command implements Isolatable
{
    protected $signature = 'compras:generar-reposicion';

    protected $description = 'Genera órdenes de compra en borrador para los productos que están por debajo del stock mínimo';

    public function handle(CompraService $compras): int
    {
        $criticos = Producto::query()
            ->paraReponer()
            ->with('proveedores')
            ->orderBy('codigo')
            ->get();

        if ($criticos->isEmpty()) {
            $this->info('No hay productos por debajo del mínimo que no estén ya pedidos.');

            return self::SUCCESS;
        }

        [$pedibles, $huerfanos] = $criticos->partition(
            fn (Producto $producto) => $producto->proveedorParaReponer() !== null,
        );

        $this->informar($this->generar($compras, $pedibles), $huerfanos);

        return self::SUCCESS;
    }

    /**
     * Una orden por proveedor, en orden alfabético.
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
            $this->warn(
                "Quedaron {$huerfanos->count()} producto(s) sin pedir porque no tienen ningún proveedor activo:"
            );

            foreach ($huerfanos as $producto) {
                $this->line("  - {$producto->codigo}  {$producto->nombre}");
            }
        }
        
        Log::info('Reposición automática', [
            'ordenes'       => $ordenes->map(fn (OrdenCompra $orden) => $orden->numeroFormateado())->all(),
            'sin_proveedor' => $huerfanos->pluck('codigo')->all(),
        ]);
    }
}