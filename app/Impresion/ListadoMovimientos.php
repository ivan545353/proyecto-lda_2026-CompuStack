<?php

namespace App\Impresion;

use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * El kardex: cada entrada y salida de stock, con lo que había antes y lo que quedó.
 */
final class ListadoMovimientos extends Listado
{
    public function titulo(): string
    {
        return 'Movimientos de stock';
    }

    public function archivo(): string
    {
        return 'movimientos';
    }

    protected function sustantivo(): array
    {
        return ['movimiento', 'movimientos'];
    }

    public function relaciones(): array
    {
        return ['producto:id,codigo,nombre', 'usuario:id,nombre,apellido'];
    }

    public function orientacion(): string
    {
        return 'horizontal';
    }

    /** Del más nuevo al más viejo, como la pantalla. */
    public function tandaDescendente(): bool
    {
        return true;
    }

    /** Sin total de cantidades: sumar unidades de productos distintos no da un número que signifique algo. */
    public function columnas(): array
    {
        return [
            new Columna('fecha', 'Fecha', fn (MovimientoStock $movimiento) => $movimiento->created_at->format('d/m/Y H:i')),
            new Columna('codigo', 'Código', fn (MovimientoStock $movimiento) => $movimiento->producto->codigo),
            new Columna('producto', 'Producto', fn (MovimientoStock $movimiento) => $movimiento->producto->nombre),
            new Columna('tipo', 'Tipo', fn (MovimientoStock $movimiento) => $movimiento->tipoTexto()),
            new Columna('cantidad', 'Cantidad', fn (MovimientoStock $movimiento) => $movimiento->cantidad,
                Columna::ENTERO),
            // «12 → 15», como en la pantalla: el stock que había y el que quedó (tramo B).
            new Columna('stock', 'Stock',
                fn (MovimientoStock $movimiento) => "{$movimiento->stock_anterior} → {$movimiento->stock_resultante}"),
            new Columna('anterior', 'Stock anterior', fn (MovimientoStock $movimiento) => $movimiento->stock_anterior,
                Columna::ENTERO, porOmision: false),
            new Columna('resultante', 'Stock resultante', fn (MovimientoStock $movimiento) => $movimiento->stock_resultante,
                Columna::ENTERO, porOmision: false),
            new Columna('motivo', 'Motivo u origen',
                fn (MovimientoStock $movimiento) => $movimiento->motivo ?? $movimiento->origenTexto()),
            new Columna('remito', 'Remito del proveedor', fn (MovimientoStock $movimiento) => $movimiento->remitoTexto()),
            new Columna('usuario', 'Lo registró', fn (MovimientoStock $movimiento) => $movimiento->usuario
                ? "{$movimiento->usuario->nombre} {$movimiento->usuario->apellido}"
                : 'Generado por el sistema'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        $producto = filled($filtros['producto_id'] ?? null) ? Producto::find($filtros['producto_id']) : null;
        $usuario  = filled($filtros['usuario_id'] ?? null) ? User::find($filtros['usuario_id']) : null;

        return array_filter([
            'Producto'    => $producto?->rotulo(),
            'Tipo'        => MovimientoStock::TIPOS[$filtros['tipo'] ?? ''] ?? null,
            'Lo registró' => $usuario ? "{$usuario->apellido}, {$usuario->nombre}" : null,
            'Desde'       => filled($filtros['desde'] ?? null) ? Carbon::parse($filtros['desde'])->format('d/m/Y') : null,
            'Hasta'       => filled($filtros['hasta'] ?? null) ? Carbon::parse($filtros['hasta'])->format('d/m/Y') : null,
        ]);
    }
}