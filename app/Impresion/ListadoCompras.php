<?php

namespace App\Impresion;

use App\Models\OrdenCompra;
use App\Models\Proveedor;
use Illuminate\Support\Carbon;

/**
 * El listado de órdenes de compra, con el total estimado de todas las coincidencias.
 */
final class ListadoCompras extends Listado
{
    public function titulo(): string
    {
        return 'Órdenes de compra';
    }

    public function archivo(): string
    {
        return 'compras';
    }

    protected function sustantivo(): array
    {
        return ['orden', 'órdenes'];
    }

    public function relaciones(): array
    {
        return ['proveedor:id,razon_social'];
    }

    /** De la más nueva a la más vieja, como la pantalla. */
    public function tandaDescendente(): bool
    {
        return true;
    }

    public function columnas(): array
    {
        return [
            new Columna('numero', 'Orden', fn (OrdenCompra $orden) => $orden->numeroFormateado()),
            new Columna('fecha', 'Fecha', fn (OrdenCompra $orden) => $orden->created_at->format('d/m/Y')),
            new Columna('proveedor', 'Proveedor', fn (OrdenCompra $orden) => $orden->proveedor->razon_social),
            new Columna('estado', 'Estado', fn (OrdenCompra $orden) => $orden->estadoTexto()),
            new Columna('enviada', 'Enviada el', fn (OrdenCompra $orden) => $orden->fecha_envio?->format('d/m/Y'),
                porOmision: false),
            // El costo que se carga es neto de IVA (decisión 1), y el papel lo dice.
            new Columna('total', 'Total estimado (sin IVA)', fn (OrdenCompra $orden) => $orden->total_estimado,
                Columna::IMPORTE, suma: 'ordenes_compra.total_estimado'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        $proveedor = filled($filtros['proveedor_id'] ?? null) ? Proveedor::find($filtros['proveedor_id']) : null;

        return array_filter([
            'Búsqueda'  => $filtros['q'] ?? null,
            'Estado'    => OrdenCompra::ESTADOS_DEL_FILTRO[$filtros['estado'] ?? ''] ?? null,
            'Origen'    => OrdenCompra::ORIGENES[$filtros['origen'] ?? ''] ?? null,
            'Proveedor' => $proveedor?->razon_social,
            'Desde'     => filled($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta'     => filled($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);
    }
}