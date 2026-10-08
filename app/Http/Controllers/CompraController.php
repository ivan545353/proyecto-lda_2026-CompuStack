<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrdenCompraFiltroRequest;
use App\Http\Requests\OrdenCompraRequest;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\CompraService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Órdenes de compra. Módulo nuevo: el sistema original no tenía compras.
 *
 * Este controlador cubre el ciclo del borrador —listar, cargar, editar—. Las
 * acciones de estado (aprobar, enviar, recibir, cerrar, cancelar) tienen sus
 * propias rutas con su propio permiso.
 *
 * Métodos:
 *   index()             listado filtrado y paginado
 *   create() / store()  alta del borrador
 *   edit()  / update()  edición del borrador; sólo en borrador
 */
class CompraController extends Controller
{
    public function __construct(private CompraService $service)
    {
    }

    public function index(OrdenCompraFiltroRequest $request): View
    {
        $ordenes = OrdenCompra::query()
            ->with('proveedor:id,razon_social')
            ->withCount('lineas')
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->deProveedor($request->query('proveedor_id'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            // El id es el número de orden, así que ordenar por id es ordenar de la
            // más nueva a la más vieja, y es un orden total: no hace falta desempate.
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('compras.index', [
            'ordenes'     => $ordenes,
            'proveedores' => Proveedor::orderBy('razon_social')->get(['id', 'razon_social']),
        ]);
    }

    public function create(): View
    {
        return view('compras.form', [
            'orden'       => new OrdenCompra(),
            'proveedores' => $this->proveedoresDisponibles(),
            'productos'   => $this->productosDisponibles(),
        ]);
    }

    public function store(OrdenCompraRequest $request): RedirectResponse
    {
        $orden = $this->service->crearBorrador($request->validated(), $request->user());

        // El mensaje dice qué sigue: un borrador no hace nada hasta que alguien lo
        // aprueba, y eso no es evidente para quien recién cargó una orden.
        return redirect()->route('compras.index')->with(
            'exito',
            "Orden {$orden->numeroFormateado()} creada en borrador. Hay que aprobarla para poder enviarla al proveedor."
        );
    }

    public function edit(OrdenCompra $orden): View|RedirectResponse
    {
        // El listado sólo ofrece «Editar» en los borradores; esto es la red de
        // contención para una URL escrita a mano o guardada en favoritos. Se
        // redirige con un aviso en lugar de abortar: la orden existe, lo que no
        // corresponde es modificarla.
        if (! $orden->esEditable()) {
            return redirect()->route('compras.index')->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y ya no se puede modificar: "
                .'el monto quedó comprometido al aprobarla.'
            );
        }

        return view('compras.form', [
            'orden'       => $orden->load('lineas.producto'),
            'proveedores' => $this->proveedoresDisponibles(),
            'productos'   => $this->productosDisponibles($orden),
        ]);
    }

    public function update(OrdenCompraRequest $request, OrdenCompra $orden): RedirectResponse
    {
        $orden = $this->service->actualizarBorrador($orden, $request->validated());

        return redirect()->route('compras.index')
            ->with('exito', "Orden {$orden->numeroFormateado()} actualizada.");
    }

    /** Sólo proveedores activos: una orden es un compromiso de plata. */
    private function proveedoresDisponibles()
    {
        return Proveedor::where('activo', true)->orderBy('razon_social')->get(['id', 'razon_social']);
    }

    /**
     * Productos que se pueden pedir: los activos.
     *
     * Más los que ya están en la orden que se edita, aunque se hayan desactivado
     * mientras tanto: si no, la línea cargada apuntaría a un producto que el
     * selector no ofrece y guardar perdería el dato sin avisar.
     */
    private function productosDisponibles(?OrdenCompra $orden = null)
    {
        return Producto::query()
            ->where('activo', true)
            ->when($orden?->exists, fn ($query) => $query->orWhereIn(
                'id',
                $orden->lineas->pluck('producto_id'),
            ))
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'costo_promedio']);
    }
}