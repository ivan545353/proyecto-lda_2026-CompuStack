<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\MarcaFiltroRequest;
use App\Http\Requests\MarcaRequest;
use App\Impresion\ListadoMarcas;
use App\Models\Marca;
use App\Services\MarcaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRUD de marcas. Módulo nuevo, sin equivalente en el sistema original.
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   imprimir()           el mismo listado filtrado, en PDF o en CSV
 *   create() / store()   alta
 *   edit()  / update()   edición
 *   destroy()            baja física o lógica, según tenga productos
 */
class MarcaController extends Controller
{
    use ImprimeListados;
    public function __construct(private MarcaService $service)
    {
    }

    public function index(MarcaFiltroRequest $request): View
    {
        $marcas = $this->filtradas($request)
            ->withCount('productos')
            ->paginate(15)
            ->withQueryString();

        return view('marcas.index', compact('marcas'));
    }

    
    public function imprimir(MarcaFiltroRequest $request, ListadoMarcas $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'marcas.index');
    }

    public function create(): View
    {
        return view('marcas.form', ['marca' => new Marca(['activo' => true])]);
    }

    public function store(MarcaRequest $request): RedirectResponse
    {
        $marca = $this->service->crear($request->validated(), $request->file('logo'));

        return redirect()->route('marcas.index')
            ->with('exito', "Marca «{$marca->nombre}» creada.");
    }

    public function edit(Marca $marca): View
    {
        return view('marcas.form', compact('marca'));
    }

    public function update(MarcaRequest $request, Marca $marca): RedirectResponse
    {
        $activos     = $marca->productosActivos();
        $seDesactiva = $marca->activo && ! $request->boolean('activo');
        $enCascada   = $request->boolean('desactivar_productos') && ! $request->boolean('activo');

        $marca = $this->service->actualizar(
            $marca,
            $request->validated(),
            $request->file('logo'),
            $request->boolean('quitar_logo'),
            $enCascada,
        );

        $mensaje = match (true) {
            ! $seDesactiva || $activos === 0 => "Marca «{$marca->nombre}» actualizada.",
            $enCascada => "«{$marca->nombre}» se desactivó junto con sus {$activos} producto(s).",
            default => "«{$marca->nombre}» se desactivó. Sus {$activos} producto(s) siguen activos y a la venta; la marca ya no se va a ofrecer para productos nuevos.",
        };

        return redirect()->route('marcas.index')->with('exito', $mensaje);
    }

    public function destroy(Marca $marca): RedirectResponse
    {
        $nombre   = $marca->nombre;
        $seBorro  = $this->service->eliminar($marca);

        // El mensaje dice qué pasó realmente. Informar "marca eliminada" cuando
        // en verdad quedó desactivada es mentirle al usuario sobre el estado
        // del sistema.
        return redirect()->route('marcas.index')->with('exito', $seBorro
            ? "Marca «{$nombre}» eliminada."
            : "La marca «{$nombre}» tiene productos asociados: se desactivó en lugar de eliminarse.");
    }

    
    /** Los filtros del listado, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(MarcaFiltroRequest $request): Builder
    {
        return Marca::query()
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->orderBy('nombre');
    }
}