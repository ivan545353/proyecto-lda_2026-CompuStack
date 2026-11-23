<?php

namespace App\Impresion;

use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * El listado de ventas: el informe de un período, con el total de todas las coincidencias. Desde el
 * tramo H, la misma consulta lista la API e imprime por enlace firmado (D-22).
 */
final class ListadoVentas extends Listado implements ImprimiblePorEnlace
{
    public function permiso(): string
    {
        return 'venta.ver';
    }

    /** La de la pantalla: de la más nueva a la más vieja. */
    public function consulta(array $filtros): Builder
    {
        return Venta::query()
            ->buscar($filtros['q'] ?? null)
            ->conEstado($filtros['estado'] ?? null)
            ->deCliente($filtros['cliente_id'] ?? null)
            ->deVendedor($filtros['vendedor_id'] ?? null)
            ->deCanal($filtros['canal'] ?? null)
            ->dePago($filtros['pago'] ?? null)
            ->desde($filtros['desde'] ?? null)
            ->hasta($filtros['hasta'] ?? null)
            ->orderByDesc('id');
    }

    public function titulo(): string
    {
        return 'Listado de ventas';
    }

    public function archivo(): string
    {
        return 'ventas';
    }

    protected function sustantivo(): array
    {
        return ['venta', 'ventas'];
    }

    public function relaciones(): array
    {
        return ['cliente:id,razon_social', 'usuario:id,nombre,apellido'];
    }

    public function orientacion(): string
    {
        return 'horizontal';
    }

    /** De la más nueva a la más vieja, como la pantalla. */
    public function tandaDescendente(): bool
    {
        return true;
    }

    public function columnas(): array
    {
        return [
            new Columna('numero', 'Número', fn (Venta $venta) => $venta->numeroFormateado()),
            new Columna('fecha', 'Fecha', fn (Venta $venta) => $venta->created_at->format('d/m/Y H:i')),
            new Columna('cliente', 'Cliente', fn (Venta $venta) => $venta->clienteTexto()),
            new Columna('vendedor', 'Vendedor',
                fn (Venta $venta) => $venta->usuario ? "{$venta->usuario->apellido}, {$venta->usuario->nombre}" : null),
            // El estado en palabras: lo que el informe declara es cómo estaba cada venta al sacarlo.
            new Columna('estado', 'Estado', fn (Venta $venta) => $venta->estadoTexto()),
            new Columna('subtotal', 'Subtotal', fn (Venta $venta) => $venta->subtotal,
                Columna::IMPORTE, porOmision: false, suma: 'ventas.subtotal'),
            new Columna('descuento', 'Descuento', fn (Venta $venta) => $venta->descuento,
                Columna::IMPORTE, porOmision: false, suma: 'ventas.descuento'),
            new Columna('total', 'Total', fn (Venta $venta) => $venta->total,
                Columna::IMPORTE, suma: 'ventas.total'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        $cliente  = filled($filtros['cliente_id'] ?? null) ? Cliente::find($filtros['cliente_id']) : null;
        $vendedor = filled($filtros['vendedor_id'] ?? null) ? User::find($filtros['vendedor_id']) : null;

        return array_filter([
            'Búsqueda' => $filtros['q'] ?? null,
            'Estado'   => Venta::ESTADOS[$filtros['estado'] ?? ''] ?? null,
            'Cliente'  => $cliente?->razon_social,
            'Vendedor' => $vendedor ? "{$vendedor->apellido}, {$vendedor->nombre}" : null,
            'Canal'    => Venta::CANALES[$filtros['canal'] ?? ''] ?? null,
            'Pago'     => Venta::FILTROS_PAGO[$filtros['pago'] ?? ''] ?? null,
            'Desde'    => filled($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta'    => filled($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);
    }
}