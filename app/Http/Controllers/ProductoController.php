<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\ProductoFiltroRequest;
use App\Http\Requests\ProductoRequest;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\PrecioService;
use App\Impresion\ListadoProductos;
use App\Services\ProductoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD de productos.
 *
 * Métodos:
 *   index()              listado filtrado y paginado de a 20
 *   imprimir()           el mismo listado filtrado, en PDF o en CSV
 *   create() / store()   alta, con imágenes
 *   edit()  / update()   edición, agregando y quitando imágenes
 *   destroy()            baja física o lógica
 */
class ProductoController extends Controller
{
    use ImprimeListados;

    public function __construct(private ProductoService $service, private PrecioService $precios)
    {
    }

    public function index(ProductoFiltroRequest $request): View
    {
        $productos = $this->filtradas($request)
            // La ruta de la categoría y la marca sin una consulta por fila.
            ->with(['categoria.padre.padre', 'marca', 'proveedores'])
            ->paginate(20)
            ->withQueryString();

        return view('productos.index', [
            'productos'  => $productos,
            'categorias' => Categoria::with('padre.padre')->get()->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE),
            'marcas'     => Marca::orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'proveedores' => Proveedor::orderBy('razon_social')->get(['id', 'razon_social', 'activo']),
        ]);
    }

    public function imprimir(ProductoFiltroRequest $request, ListadoProductos $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'productos.index');
    }

    public function create(): View
    {
        return view('productos.form', [
            'producto' => new Producto([
                'activo'              => true,
                'alicuota_iva'        => '21.00',
                'stock_minimo'        => 0,
                'cantidad_reposicion' => 0,
                // Un producto nuevo no tiene costo, así que su precio se carga
                // a mano: la casilla va marcada y el servidor lo exige.
                'precio_manual'       => true,
            ]),
            'calculo'   => null,
            'historial' => collect(),
            ...$this->service->opciones(),
        ]);
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $producto = $this->service->crear(
            $request->validated(),
            $request->file('imagenes', []),
            $request->ordenDeImagenes(),
            $request->user(),
        );

        return redirect()->route('productos.index')
            ->with('exito', "Producto «{$producto->nombre}» creado.");
    }

    public function edit(Producto $producto): View
    {
        return view('productos.form', [
            'producto' => $producto,
            // El desglose de cómo saldría su precio hoy, y los últimos cambios.
            'calculo'   => $this->precios->calcular($producto),
            'historial' => $producto->historialPrecios()->with('usuario')->recientes()->limit(5)->get(),
            ...$this->service->opciones($producto),
        ]);
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $producto = $this->service->actualizar(
            $producto,
            $request->validated(),
            $request->file('imagenes', []),
            $request->ordenDeImagenes(),
            $request->user(),
        );

        return redirect()->route('productos.index')
            ->with('exito', "Producto «{$producto->nombre}» actualizado.");
    }

    /**
     * Recalcula el precio del producto desde los factores.
     */
    public function recalcular(Producto $producto): RedirectResponse
    {
        $resultado = $this->precios->recalcular($producto, request()->user());
        $mensaje   = "«{$producto->nombre}»: ".PrecioService::RESULTADOS[$resultado];

        return redirect()->route('productos.edit', $producto)->with(
            $resultado === PrecioService::APLICADO ? 'exito' : 'aviso',
            $mensaje,
        );
    }

    public function destroy(Producto $producto): RedirectResponse
    {
        $nombre  = $producto->nombre;
        $seBorro = $this->service->eliminar($producto);

        return redirect()->route('productos.index')->with('exito', $seBorro
            ? "Producto «{$nombre}» eliminado."
            : "«{$nombre}» tiene stock o historial de movimientos: se desactivó en lugar de eliminarse.");
    }


    /** Los filtros del listado, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(ProductoFiltroRequest $request): Builder
    {
        return Producto::query()
            ->buscar($request->query('q'))
            ->deCategoria($request->query('categoria_id'))
            ->deMarca($request->query('marca_id'))
            ->deProveedor($request->query('proveedor_id'))
            ->conEstado($request->query('estado'))
            ->conStock($request->query('stock'))
            ->orderBy('nombre');
    }
}