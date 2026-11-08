<?php

namespace App\Services;

use App\Models\Venta;
use App\Models\VentaLinea;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

// Los dos informes que salieron del panel en el tramo L. Devuelven la consulta armada
// y el controlador la pagina (A-25); las sumas y los conteos los hace la base (A-26).
class InformeVentasService
{
    public const POR_PAGINA = 15;

    // Quién vendió, por lo facturado. El id desempata para que la paginación no repita filas.
    public function vendedores(CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return $this->ventasConVendedor($desde, $hasta)
            ->selectRaw(
                'ventas.usuario_id,
                 users.nombre,
                 users.apellido,
                 COUNT(*) as cantidad,
                 COALESCE(SUM(ventas.total), 0) as facturado,
                 COALESCE(AVG(ventas.total), 0) as ticket'
            )
            ->groupBy('ventas.usuario_id', 'users.nombre', 'users.apellido')
            ->orderByDesc('facturado')
            ->orderBy('ventas.usuario_id');
    }

    // La fila de totales del ranking: la misma base, sin agrupar, así suma lo que listan las filas.
    public function totalDeVendedores(CarbonInterface $desde, CarbonInterface $hasta): object
    {
        return $this->ventasConVendedor($desde, $hasta)
            ->selectRaw(
                'COUNT(*) as cantidad,
                 COALESCE(SUM(ventas.total), 0) as facturado,
                 COALESCE(AVG(ventas.total), 0) as ticket'
            )
            ->first();
    }

    // Por unidades efectivamente vendidas. El HAVING saca los que volvieron enteros:
    // un producto con cero unidades netas no es uno de los más vendidos.
    public function productos(CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return VentaLinea::query()
            ->join('ventas', 'ventas.id', '=', 'venta_lineas.venta_id')
            ->join('productos', 'productos.id', '=', 'venta_lineas.producto_id')
            ->whereIn('ventas.estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('ventas.created_at', [$desde, $hasta])
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
            ->orderBy('venta_lineas.producto_id')
            ->toBase();
    }

    // El join deja afuera las ventas sin vendedor, que son las online de la Etapa 2.
    private function ventasConVendedor(CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        return Venta::query()
            ->join('users', 'users.id', '=', 'ventas.usuario_id')
            ->whereIn('ventas.estado', Venta::ESTADOS_VENDIDOS)
            ->whereBetween('ventas.created_at', [$desde, $hasta])
            ->toBase();
    }
}