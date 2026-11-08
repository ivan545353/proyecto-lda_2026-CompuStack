<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\ProveedorFiltroRequest;
use App\Http\Requests\ProveedorRequest;
use App\Impresion\ListadoProveedores;
use App\Models\Proveedor;
use App\Services\ProveedorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
/**
 * CRUD de proveedores. Módulo nuevo: el sistema original no tenía proveedores.
 *
 *
 * Métodos:
 *   imprimir()           el mismo listado filtrado, en PDF o en CSV
 *   create() / store()   alta
 *   edit()  / update()   edición
 *   destroy()            baja física o lógica, según esté referenciado
 */
class ProveedorController extends Controller
{
    use ImprimeListados;
    public function __construct(private ProveedorService $service)
    {
    }

    public function index(ProveedorFiltroRequest $request): View
    {
        $proveedores = $this->filtradas($request)
            ->withCount('productos','ordenesCompra')
            ->paginate(15)
            // Sin esto, pasar a la página 2 pierde los filtros.
            ->withQueryString();

        return view('proveedores.index', compact('proveedores'));
    }


    public function imprimir(ProveedorFiltroRequest $request, ListadoProveedores $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'proveedores.index');
    }

    public function create(): View
    {
        return view('proveedores.form', [
            // Los valores por omisión los trae la pantalla, no el servidor: lo
            // que se guarda es exactamente lo que el usuario vio.
            'proveedor' => new Proveedor([
                'activo'             => true,
                'canal_pedido'       => 'manual',
                'plazo_entrega_dias' => 0,
            ]),
            'productosActivos' => 0,
        ]);
    }

    public function store(ProveedorRequest $request): RedirectResponse
    {
        $proveedor = $this->service->crear($request->validated());

        return redirect()->route('proveedores.index')
            ->with('exito', "Proveedor «{$proveedor->razon_social}» creado.");
    }

    public function edit(Proveedor $proveedor): View
    {
        return view('proveedores.form', [
            'proveedor' => $proveedor,
            // Se le muestra al usuario cuántos productos afecta ANTES de que
            // decida la cascada: una cascada se pide con el número a la vista.
            'productosActivos' => $proveedor->productosActivos(),
        ]);
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): RedirectResponse
    {
        $activos     = $proveedor->productosActivos();
        $seDesactiva = $proveedor->activo && ! $request->boolean('activo');
        $enCascada   = $request->boolean('desactivar_productos') && ! $request->boolean('activo');

        $proveedor = $this->service->actualizar($proveedor, $request->validated(), $enCascada);

        // El mensaje dice lo que pasó de verdad, incluido lo que NO pasó.
        $mensaje = match (true) {
            ! $seDesactiva || $activos === 0 => "Proveedor «{$proveedor->razon_social}» actualizado.",
            $enCascada => "«{$proveedor->razon_social}» se desactivó junto con sus {$activos} producto(s).",
            default => "«{$proveedor->razon_social}» se desactivó. Sus {$activos} producto(s) siguen activos y a la venta; el proveedor ya no se va a ofrecer al cargar productos nuevos.",
        };

        return redirect()->route('proveedores.index')->with('exito', $mensaje);
    }

    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $nombre  = $proveedor->razon_social;
        $seBorro = $this->service->eliminar($proveedor);

        // Informar "proveedor eliminado" cuando en verdad quedó desactivado es
        // mentirle al usuario sobre el estado del sistema.
        return redirect()->route('proveedores.index')->with('exito', $seBorro
            ? "Proveedor «{$nombre}» eliminado."
            : "«{$nombre}» tiene productos u órdenes de compra registradas: se desactivó en lugar de eliminarse. Deja de ofrecerse al cargar productos nuevos y su historial queda intacto.");
    }

    
    /** Los filtros del listado, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(ProveedorFiltroRequest $request): Builder
    {
        return Proveedor::query()
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->conCanal($request->query('canal'))
            ->orderBy('razon_social');
    }
}