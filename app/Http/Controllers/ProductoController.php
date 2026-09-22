<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductoFiltroRequest;
use App\Http\Requests\ProductoRequest;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Services\ProductoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * CRUD de productos.
 *
 * Reemplaza a ItemController del original, cuyos filtros no coincidían con
 * los del DAO  y cuyo listado devolvía la tabla entera . Sigue el
 * patrón de marcas y categorías: traduce entre HTTP y ProductoService.
 *
 * Métodos:
 *   index()              listado filtrado y paginado de a 20
 *   create() / store()   alta, con imágenes
 *   edit()  / update()   edición, agregando y quitando imágenes
 *   destroy()            baja física o lógica
 */
class ProductoController extends Controller
{
    public function __construct(private ProductoService $service)
    {
    }

    public function index(ProductoFiltroRequest $request): View
    {
        $productos = Producto::query()
            // La ruta de la categoría y la marca sin una consulta por fila.
            ->with(['categoria.padre.padre', 'marca'])
            ->buscar($request->query('q'))
            ->deCategoria($request->query('categoria_id'))
            ->deMarca($request->query('marca_id'))
            ->conEstado($request->query('estado'))
            ->conStock($request->query('stock'))
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString();

        return view('productos.index', [
            'productos'  => $productos,
            // Los filtros ofrecen también las inactivas: sirven para encontrar
            // productos que quedaron en una categoría dada de baja.
            'categorias' => Categoria::with('padre.padre')->get()->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE),
            'marcas'     => Marca::orderBy('nombre')->get(['id', 'nombre', 'activo']),
        ]);
    }

    public function create(): View
    {
        return view('productos.form', [
            'producto' => new Producto([
                'activo'              => true,
                'alicuota_iva'        => '21.00',
                'stock_minimo'        => 0,
                'cantidad_reposicion' => 0,
            ]),
            ...$this->service->opciones(),
        ]);
    }

    public function store(ProductoRequest $request): RedirectResponse
    {
        $producto = $this->service->crear($request->validated(), $request->file('imagenes', []));

        return redirect()->route('productos.index')
            ->with('exito', "Producto «{$producto->nombre}» creado.");
    }

    public function edit(Producto $producto): View
    {
        return view('productos.form', [
            'producto' => $producto,
            ...$this->service->opciones($producto),
        ]);
    }

    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $producto = $this->service->actualizar(
            $producto,
            $request->validated(),
            $request->file('imagenes', []),
            $request->input('quitar_imagenes', []),
        );

        return redirect()->route('productos.index')
            ->with('exito', "Producto «{$producto->nombre}» actualizado.");
    }

    public function destroy(Producto $producto): RedirectResponse
    {
        $nombre  = $producto->nombre;
        $seBorro = $this->service->eliminar($producto);

        return redirect()->route('productos.index')->with('exito', $seBorro
            ? "Producto «{$nombre}» eliminado."
            : "«{$nombre}» tiene stock o historial de movimientos: se desactivó en lugar de eliminarse.");
    }
}