<?php

namespace App\Http\Controllers;

use App\Http\Requests\BusquedaRequest;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * El buscador remoto: las listas largas se buscan en el servidor.
 * Métodos:
 *   productos()             código o nombre, con los recortes de compras
 *   clientes()              razón social, documento, correo o teléfono
 *   proveedores()           sólo activos: una orden es un compromiso de plata
 *   proveedoresDeProducto() a quién se le puede pedir, en el orden de conveniencia
 */
class BusquedaController extends Controller
{
    /** Cuántos resultados entra el desplegable. Más que esto se afina escribiendo. */
    private const MAXIMO = 20;

    /**
     * Productos por código o nombre.
     */
    public function productos(BusquedaRequest $request): JsonResponse
    {
        $conCosto = $request->user()->can('precio.ver_costo');

        $encontrados = Producto::query()
            ->conEstado($request->query('estado', 'activos'))
            ->buscar($request->input('q'))
            ->sinIds($request->input('excluir'))
            ->deProveedor($request->query('proveedor_id'))
            ->when($request->boolean('con_proveedor'), fn (Builder $query) => $query->whereHas(
                'proveedores',
                fn (Builder $proveedor) => $proveedor->where('proveedores.activo', true),
            ))
            ->orderBy('nombre')
            ->limit(self::MAXIMO + 1)
            ->get(['id', 'codigo', 'nombre', 'precio_contado', 'costo_promedio', 'stock', 'stock_reservado']);

        return $this->sobre($encontrados->map(function (Producto $producto) use ($conCosto) {
             $fila = [
                'id'     => $producto->id,
                'texto'      => $producto->rotulo(),
                'codigo'     => $producto->codigo,
                'nombre'     => $producto->nombre,
                'precio'     => $producto->precio_contado,
                'disponible' => $producto->stock_disponible,
            ];

            if ($conCosto) {
                $fila['costo'] = $producto->costo_promedio;
            }

            return $fila;
        }));
    }

    /**
     * Clientes por razón social, documento, correo o teléfono.
     */
    public function clientes(BusquedaRequest $request): JsonResponse
    {
        $encontrados = Cliente::query()
            ->buscar($request->input('q'))
            ->orderBy('razon_social')
            ->limit(self::MAXIMO + 1)
            ->get(['id', 'razon_social', 'tipo_doc', 'nro_doc']);

        return $this->sobre($encontrados->map(fn (Cliente $cliente) => $cliente->resultadoDeBusqueda()));
    }

    /**
     * Proveedores por razón social, contacto, correo o CUIT.
     */
    public function proveedores(BusquedaRequest $request): JsonResponse
    {
        $encontrados = Proveedor::query()
            ->where('activo', true)
            ->buscar($request->input('q'))
            ->orderBy('razon_social')
            ->limit(self::MAXIMO + 1)
            ->get(['id', 'razon_social']);

        return $this->sobre($encontrados->map(fn (Proveedor $proveedor) => [
            'id'           => $proveedor->id,
            'texto'        => $proveedor->razon_social,
            'razon_social' => $proveedor->razon_social,
        ]));
    }

    /**
     * A quién se le puede pedir este producto, en el orden en que conviene elegirlo.
     */
    public function proveedoresDeProducto(Request $request, Producto $producto): JsonResponse
    {
        $conCosto = $request->user()->can('precio.ver_costo');

        return $this->sobre(collect($producto->proveedoresParaElegir())->map(
            function (array $proveedor) use ($conCosto) {
                if (! $conCosto) {
                    unset($proveedor['costo']);
                }

                return $proveedor;
            },
        ));
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /**
     * El sobre común a los cuatro endpoints.
     *
     * @param  Collection<int, array<string, mixed>>  $resultados
     */
    private function sobre(Collection $resultados): JsonResponse
    {
        return response()->json([
            'resultados' => $resultados->take(self::MAXIMO)->values(),
            'hay_mas'    => $resultados->count() > self::MAXIMO,
        ]);
    }

}