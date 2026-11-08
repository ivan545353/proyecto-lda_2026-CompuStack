<?php

namespace App\Services;

use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaLinea;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// Las métricas del panel. Cada número del período se cuenta otra vez con las fechas
// corridas al período anterior de igual largo

class PanelService
{
    public function paraVendedor(User $usuario, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        [$antesDesde, $antesHasta] = $this->periodoAnterior($desde, $hasta);

        return [
            'vendi' => $this->vendidoPor($usuario, $desde, $hasta),
            'cobre' => $this->cobradoPor($usuario, $desde, $hasta),

            'presupuestosAbiertos' => Venta::query()
                ->where('usuario_id', $usuario->id)
                ->where('estado', 'presupuesto')
                ->count(),

            'anterior' => [
                'desde' => $antesDesde,
                'hasta' => $antesHasta,
                'vendi' => $this->vendidoPor($usuario, $antesDesde, $antesHasta),
                'cobre' => $this->cobradoPor($usuario, $antesDesde, $antesHasta),
            ],
        ];
    }

    public function paraAdministrativo(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        [$antesDesde, $antesHasta] = $this->periodoAnterior($desde, $hasta);

        $resumen = $this->resumenDeVentas($desde, $hasta);

        return [
            'cobrado'    => $this->cobrado($desde, $hasta),
            'facturado'  => $resumen['facturado'],
            'cantidad'   => $resumen['cantidad'],
            'ticket'     => $resumen['ticket'],
            'descuentos' => $resumen['descuentos'],

            'margenBruto'       => $this->margenBruto($desde, $hasta),
            'porMetodo'         => $this->cobradoPorMetodo($desde, $hasta),
            'porDia'            => $this->facturadoPorDia($desde, $hasta),
            // Instantáneas: no son del período, y no hay contra qué compararlas, porque el
            // sistema no guarda cuántas había antes.
            'stockCritico'           => Producto::query()->paraReponer()->count(),
            'comprasAbiertas'        => OrdenCompra::query()->abiertas()->count(),
            'borradoresDeReposicion' => OrdenCompra::query()
                ->conEstado('borrador')
                ->deOrigen('reposicion')
                ->count(),

            'anterior' => [
                'desde'       => $antesDesde,
                'hasta'       => $antesHasta,
                'cobrado'     => $this->cobrado($antesDesde, $antesHasta),
                'facturado'   => $this->resumenDeVentas($antesDesde, $antesHasta)['facturado'],
                'margenBruto' => $this->margenBruto($antesDesde, $antesHasta),
            ],
        ];
    }

    // Del mismo largo que el elegido y pegado a él: del 1 al 8 se compara con los ocho días previos.
    public function periodoAnterior(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $dias = (int) round($desde->copy()->startOfDay()->diffInDays($hasta->copy()->startOfDay())) + 1;

        return [
            $desde->copy()->subDays($dias)->startOfDay(),
            $desde->copy()->subDay()->endOfDay(),
        ];
    }

    private function vendidoPor(User $usuario, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $fila = Venta::query()
            ->where('usuario_id', $usuario->id)
            ->whereIn('estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as facturado, COALESCE(AVG(total), 0) as ticket')
            ->toBase()
            ->first();

        return [
            'cantidad'  => (int) $fila->cantidad,
            'facturado' => round((float) $fila->facturado, 2),
            'ticket'    => round((float) $fila->ticket, 2),
        ];
    }

    private function cobradoPor(User $usuario, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $fila = Pago::query()
            ->where('usuario_id', $usuario->id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COUNT(*) as movimientos, COALESCE(SUM(monto), 0) as neto')
            ->toBase()
            ->first();

        return [
            'movimientos' => (int) $fila->movimientos,
            'neto'        => round((float) $fila->neto, 2),
        ];
    }

    private function cobrado(CarbonInterface $desde, CarbonInterface $hasta): float
    {
        return round((float) Pago::query()->whereBetween('fecha', [$desde, $hasta])->sum('monto'), 2);
    }

    private function resumenDeVentas(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $fila = Venta::query()
            ->whereIn('estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw(
                'COUNT(*) as cantidad,
                 COALESCE(SUM(total), 0) as facturado,
                 COALESCE(AVG(total), 0) as ticket,
                 COALESCE(SUM(descuento), 0) as descuentos'
            )
            ->toBase()
            ->first();

        return [
            'cantidad'   => (int) $fila->cantidad,
            'facturado'  => round((float) $fila->facturado, 2),
            'ticket'     => round((float) $fila->ticket, 2),
            'descuentos' => round((float) $fila->descuentos, 2),
        ];
    }

    private function lineasVendidas(CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return VentaLinea::query()
            ->join('ventas', 'ventas.id', '=', 'venta_lineas.venta_id')
            ->whereIn('ventas.estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('ventas.created_at', [$desde, $hasta]);
    }

    // Margen BRUTO: sale de los importes congelados en la línea, que no conocen
    // `ventas.descuento` porque vive en la cabecera. La pantalla lo rotula como bruto
    // y muestra los descuentos otorgados al lado.
    private function margenBruto(CarbonInterface $desde, CarbonInterface $hasta): float
    {
        $fila = $this->lineasVendidas($desde, $hasta)
            ->selectRaw(
                'COALESCE(SUM((venta_lineas.cantidad - venta_lineas.cantidad_devuelta)
                 * (venta_lineas.precio_unitario - venta_lineas.costo_unitario)), 0) as margen'
            )
            ->toBase()
            ->first();

        return round((float) $fila->margen, 2);
    }

    // Con qué entró la plata. Un medio puede quedar negativo en el período si volvió
    // por ahí más de lo que entró, y eso es lo que un libro de caja tiene que decir.
    // No es el pendiente #47: no hay caja por día, ni listado, ni filtros propios.
    private function cobradoPorMetodo(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Pago::query()
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('metodo, COUNT(*) as movimientos, COALESCE(SUM(monto), 0) as neto')
            ->groupBy('metodo')
            ->orderByDesc('neto')
            ->toBase()
            ->get();
    }

    private function facturadoPorDia(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Venta::query()
            ->whereIn('estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as cantidad, COALESCE(SUM(total), 0) as facturado')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->toBase()
            ->get();
    }

}