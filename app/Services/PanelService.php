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

// Las métricas del panel. Cierra A-26: cada número es una consulta de agregación y
// nunca viaja una fila completa al servidor de aplicación.
//
// Dos vocabularios que la pantalla no debe mezclar en el mismo recuadro:
//   «Facturado» es SUM(ventas.total) por ventas.created_at y NO descuenta devoluciones.
//   «Cobrado» es SUM(pagos.monto) por pagos.fecha y SÍ las descuenta, porque el
//   contra-asiento es una fila negativa que entra en la misma suma.
class PanelService
{
    private const TOPE_RANKING = 5;

    // Lo propio: lo que vendí y lo que cobré, dos preguntas distintas en dos columnas
    // de dos tablas (M-19). El Cajero no vende, así que sin el segundo bloque su panel
    // sería todo ceros.
    public function paraVendedor(User $usuario, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $vendido = Venta::query()
            ->where('usuario_id', $usuario->id)
            ->whereIn('estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as facturado, COALESCE(AVG(total), 0) as ticket')
            ->toBase()
            ->first();

        $cobrado = Pago::query()
            ->where('usuario_id', $usuario->id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw('COUNT(*) as movimientos, COALESCE(SUM(monto), 0) as neto')
            ->toBase()
            ->first();

        return [
            'vendi' => [
                'cantidad'  => (int) $vendido->cantidad,
                'facturado' => round((float) $vendido->facturado, 2),
                'ticket'    => round((float) $vendido->ticket, 2),
            ],
            'cobre' => [
                'movimientos' => (int) $cobrado->movimientos,
                'neto'        => round((float) $cobrado->neto, 2),
            ],
            // Instantánea, no del período: un presupuesto abierto espera respuesta hoy
            // sin importar cuándo se emitió.
            'presupuestosAbiertos' => Venta::query()
                ->where('usuario_id', $usuario->id)
                ->where('estado', 'presupuesto')
                ->count(),
        ];
    }

    public function paraAdministrativo(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $resumen = Venta::query()
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
            'cobrado'    => round((float) Pago::query()->whereBetween('fecha', [$desde, $hasta])->sum('monto'), 2),
            'facturado'  => round((float) $resumen->facturado, 2),
            'cantidad'   => (int) $resumen->cantidad,
            'ticket'     => round((float) $resumen->ticket, 2),
            'descuentos' => round((float) $resumen->descuentos, 2),

            'margenBruto'       => $this->margenBruto($desde, $hasta),
            'porMetodo'         => $this->cobradoPorMetodo($desde, $hasta),
            'porDia'            => $this->facturadoPorDia($desde, $hasta),
            'rankingVendedores' => $this->rankingVendedores($desde, $hasta),
            'masVendidos'       => $this->masVendidos($desde, $hasta),

            // Instantáneas: no son del período.
            'stockCritico'    => Producto::query()->where('activo', true)->stockCritico()->count(),
            'comprasAbiertas' => OrdenCompra::query()->abiertas()->count(),
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
    //
    // `cantidad - cantidad_devuelta` nunca da negativo: lo garantiza la validación de
    // la devolución, y las dos columnas son unsigned.
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

    // El join deja afuera las ventas sin vendedor, que son las online de la Etapa 2.
    private function rankingVendedores(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Venta::query()
            ->join('users', 'users.id', '=', 'ventas.usuario_id')
            ->whereIn('ventas.estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('ventas.created_at', [$desde, $hasta])
            ->selectRaw(
                'ventas.usuario_id,
                 users.nombre,
                 users.apellido,
                 COUNT(*) as cantidad,
                 COALESCE(SUM(ventas.total), 0) as facturado'
            )
            ->groupBy('ventas.usuario_id', 'users.nombre', 'users.apellido')
            ->orderByDesc('facturado')
            ->limit(self::TOPE_RANKING)
            ->toBase()
            ->get();
    }

    // Por unidades efectivamente vendidas. El HAVING saca los que volvieron enteros:
    // un producto con cero unidades netas no es uno de los más vendidos.
    //
    // No hay «menos vendidos»: los que no vendieron nada no aparecen en una agregación
    // sobre `venta_lineas`, así que es la pregunta inversa y necesita otra consulta.
    private function masVendidos(CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $this->lineasVendidas($desde, $hasta)
            ->join('productos', 'productos.id', '=', 'venta_lineas.producto_id')
            ->selectRaw(
                'venta_lineas.producto_id,
                 productos.codigo,
                 productos.nombre,
                 SUM(venta_lineas.cantidad - venta_lineas.cantidad_devuelta) as unidades,
                 COALESCE(SUM((venta_lineas.cantidad - venta_lineas.cantidad_devuelta)
                     * venta_lineas.precio_unitario), 0) as importe'
            )
            ->groupBy('venta_lineas.producto_id', 'productos.codigo', 'productos.nombre')
            ->havingRaw('SUM(venta_lineas.cantidad - venta_lineas.cantidad_devuelta) > 0')
            ->orderByDesc('unidades')
            ->limit(self::TOPE_RANKING)
            ->toBase()
            ->get();
    }
}