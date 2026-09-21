<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarcaFiltroRequest;
use App\Http\Requests\MarcaRequest;
use App\Models\Marca;
use App\Services\MarcaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * CRUD de marcas. Módulo nuevo, sin equivalente en el sistema original.
 *
 * Es el patrón de referencia de la Fase 3: el controlador traduce entre HTTP y
 * el servicio y no hace nada más. No valida (MarcaRequest), no decide reglas
 * (MarcaService) y no arma consultas de negocio: sólo encadena los scopes que
 * el modelo expone.
 *
 * En la Etapa 3, el controlador de la API va a llamar a los mismos métodos de
 * MarcaService y devolver JSON en lugar de vistas.
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   create() / store()   alta
 *   edit()  / update()   edición
 *   destroy()            baja física o lógica, según tenga productos
 */
class MarcaController extends Controller
{
    public function __construct(private MarcaService $service)
    {
    }

    public function index(MarcaFiltroRequest $request): View
    {
        $marcas = Marca::query()
            // Cuenta en la base. El panel original traía las tablas enteras al
            // navegador para contarlas con .filter() (hallazgo A-26); acá ni
            // siquiera los conteos de una celda viajan como filas.
            ->withCount('productos')
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->orderBy('nombre')
            // Paginación obligatoria (A-25). El original devolvía la tabla
            // completa en todos los listados.
            ->paginate(15)
            // Sin esto, pasar a la página 2 pierde los filtros.
            ->withQueryString();

        return view('marcas.index', compact('marcas'));
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
        $marca = $this->service->actualizar(
            $marca,
            $request->validated(),
            $request->file('logo'),
            $request->boolean('quitar_logo'),
        );

        return redirect()->route('marcas.index')
            ->with('exito', "Marca «{$marca->nombre}» actualizada.");
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
}