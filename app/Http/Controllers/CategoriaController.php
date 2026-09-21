<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaFiltroRequest;
use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Services\CategoriaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD de categorías, con jerarquía.
 *
 * Reemplaza a CategoryController del original, que manejaba una lista plana y
 * cuyos filtros no filtraban . Sigue el patrón de MarcaController: el
 * controlador traduce, el Request valida, el servicio decide.
 *
 * La jerarquía se navega con el filtro parent_id: cada fila enlaza a sus
 * subcategorías y la vista muestra una miga de pan para volver. El listado
 * sigue siendo plano y paginado (A-25).
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   create() / store()   alta; acepta ?parent_id= para precargar el padre
 *   edit()  / update()   edición
 *   destroy()            baja física o lógica
 */
class CategoriaController extends Controller
{
    public function __construct(private CategoriaService $service)
    {
    }

    public function index(CategoriaFiltroRequest $request): View
    {
        $padre = $request->query('parent_id');

        $categorias = Categoria::query()
            ->with('padre.padre')                  // la ruta, sin una consulta por fila
            ->withCount(['hijas', 'productos'])    // contados en la base (A-26)
            ->buscar($request->query('q'))
            ->dePadre($padre)
            ->conEstado($request->query('estado'))
            // `orden` sólo tiene sentido entre hermanas: se aplica cuando el
            // listado muestra las hijas de un mismo padre.
            ->when(filled($padre), fn ($query) => $query->orderBy('orden'))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return view('categorias.index', [
            'categorias'     => $categorias,
            'padreFiltrado'  => ctype_digit((string) $padre)
                ? Categoria::with('padre.padre')->find($padre)
                : null,
            'padresConHijas' => Categoria::has('hijas')->with('padre.padre')->get()->sortBy('ruta'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('categorias.form', [
            'categoria' => new Categoria([
                'activo'    => true,
                'orden'     => 0,
                // Desde el listado de las hijas de "Componentes", el botón de
                // alta precarga ese padre. Si el id no es un padre posible, el
                // select simplemente no lo encuentra y queda en "Ninguna".
                'parent_id' => $request->integer('parent_id') ?: null,
            ]),
            'padres' => $this->service->padresPosibles(),
        ]);
    }

    public function store(CategoriaRequest $request): RedirectResponse
    {
        $categoria = $this->service->crear($request->validated());

        return redirect()->route('categorias.index', array_filter(['parent_id' => $categoria->parent_id]))
            ->with('exito', "Categoría «{$categoria->nombre}» creada.");
    }

    public function edit(Categoria $categoria): View
    {
        return view('categorias.form', [
            'categoria' => $categoria->loadCount('hijas'),
            'padres'    => $this->service->padresPosibles($categoria),
        ]);
    }

    public function update(CategoriaRequest $request, Categoria $categoria): RedirectResponse
    {
        $categoria = $this->service->actualizar($categoria, $request->validated());

        return redirect()->route('categorias.index', array_filter(['parent_id' => $categoria->parent_id]))
            ->with('exito', "Categoría «{$categoria->nombre}» actualizada.");
    }

    public function destroy(Categoria $categoria): RedirectResponse
    {
        $nombre  = $categoria->nombre;
        $padreId = $categoria->parent_id;
        $seBorro = $this->service->eliminar($categoria);

        return redirect()->route('categorias.index', array_filter(['parent_id' => $padreId]))
            ->with('exito', $seBorro
                ? "Categoría «{$nombre}» eliminada."
                : "«{$nombre}» tiene subcategorías o productos asociados: se desactivó en lugar de eliminarse.");
    }
}