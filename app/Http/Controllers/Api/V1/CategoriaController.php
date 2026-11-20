<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CategoriaFiltroRequest;
use App\Http\Requests\Api\CategoriaRequest;
use App\Http\Requests\Api\PadresPosiblesRequest;
use App\Http\Resources\Api\CategoriaFichaGestionResource;
use App\Http\Resources\Api\CategoriaGestionResource;
use App\Http\Resources\Api\CategoriaResource;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoCategorias;
use App\Models\Categoria;
use App\Services\CategoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Las categorías: el árbol de la tienda y la administración (tramo D), un recurso con su
 * representación por permiso (regla 36). Traduce entre el contrato y CategoriaService.
 *
 * Métodos:
 *   index()           el árbol visible, o con `forma=lista` el listado de la administración
 *   impresion()       el enlace firmado del listado, en PDF o CSV (D-22)
 *   padresPosibles()  dónde se puede poner una categoría, para el formulario
 *   show()            una categoría, con su ubicación y su contenido activo
 *   store()           alta (201)
 *   update()          edición; el mensaje dice qué pasó con el contenido al desactivarla
 *   destroy()         baja física o lógica; el mensaje dice cuál fue
 */
class CategoriaController extends Controller
{
    /** Tres niveles: la ruta del padre de una nieta llega a la raíz sin una consulta por fila. */
    private const RAMA = 'padre.padre.padre';

    public function __construct(private CategoriaService $service)
    {
    }

    public function index(CategoriaFiltroRequest $request, ListadoCategorias $listado): JsonResponse
    {
        if (! $request->esLista()) {
            return Paginado::respuesta(
                Categoria::arbolEnTienda($request->pagina(), $request->porPagina()),
                CategoriaResource::class,
                [],
            );
        }

        $pagina = $listado->consulta($request->validated())
            ->when($request->boolean('con_hijas'), fn ($consulta) => $consulta->has('hijas'))
            ->orderBy('id')
            ->with(self::RAMA)
            ->withCount(['hijas', 'productos'])
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, CategoriaGestionResource::class, $request->criterios());
    }

    public function impresion(CategoriaFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        $filtros = array_diff_key($request->validated(), array_flip(['forma', 'con_hijas']));

        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('categorias', $filtros, $request->query(), $request->user()),
        ));
    }

    /** La lista viene del servicio ya filtrada por profundidad y en orden de ruta: se pagina acá. */
    public function padresPosibles(PadresPosiblesRequest $request): JsonResponse
    {
        $categoria = $request->filled('categoria') ? Categoria::findOrFail($request->integer('categoria')) : null;
        $todas     = $this->service->padresPosibles($categoria);
        $porPagina = $request->porPagina();

        $pagina = new LengthAwarePaginator(
            $todas->forPage($request->pagina(), $porPagina)->values(),
            $todas->count(),
            $porPagina,
            $request->pagina(),
        );

        return Paginado::respuesta($pagina, CategoriaGestionResource::class, ['categoria' => $categoria?->id]);
    }

    public function show(Categoria $categoria): JsonResponse
    {
        return Recurso::respuesta(new CategoriaFichaGestionResource(
            $categoria->load(self::RAMA)->loadCount(['hijas', 'productos']),
        ));
    }

    public function store(CategoriaRequest $request): JsonResponse
    {
        $categoria = $this->service->crear($this->datos($request));

        return Recurso::respuesta(
            new CategoriaGestionResource($categoria->load(self::RAMA)),
            201,
            "Categoría «{$categoria->nombre}» creada.",
        );
    }

    public function update(CategoriaRequest $request, Categoria $categoria): JsonResponse
    {
        // Se cuenta ANTES: después del update, la rama ya cambió.
        $contenido   = $categoria->contenidoActivo();
        $enCascada   = $request->boolean('desactivar_contenido') && ! $request->boolean('activo');
        $seDesactiva = $categoria->activo && ! $request->boolean('activo');

        $categoria = $this->service->actualizar($categoria, $this->datos($request), $enCascada);

        return Recurso::respuesta(
            new CategoriaGestionResource($categoria->load(self::RAMA)),
            200,
            $this->mensaje($categoria, $contenido, $seDesactiva, $enCascada),
        );
    }

    /** No miente sobre lo que pasó (regla 2); `parent_id` es el grupo al que vuelve la pantalla. */
    public function destroy(Categoria $categoria): JsonResponse
    {
        $id      = $categoria->id;
        $nombre  = $categoria->nombre;
        $padreId = $categoria->parent_id;
        $seBorro = $this->service->eliminar($categoria);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminada' => $seBorro, 'parent_id' => $padreId]),
            200,
            $seBorro
                ? "Categoría «{$nombre}» eliminada."
                : "«{$nombre}» tiene subcategorías o productos asociados: se desactivó en lugar de eliminarse.",
        );
    }

    /** @return array{parent_id: ?int, nombre: string, orden: int, activo: bool} */
    private function datos(CategoriaRequest $request): array
    {
        return [
            'parent_id' => $request->filled('parent_id') ? $request->integer('parent_id') : null,
            'nombre'    => (string) $request->validated('nombre'),
            'orden'     => $request->integer('orden', 0),
            'activo'    => $request->boolean('activo'),
        ];
    }

    /** El mensaje dice qué pasó con el contenido de la categoría. */
    private function mensaje(Categoria $categoria, array $contenido, bool $seDesactiva, bool $enCascada): string
    {
        $base = "Categoría «{$categoria->nombre}» actualizada.";

        if (! $seDesactiva) {
            return $base;
        }

        $partes = array_filter([
            $contenido['subcategorias'] > 0
                ? $contenido['subcategorias'].' '.($contenido['subcategorias'] === 1 ? 'subcategoría' : 'subcategorías')
                : null,
            $contenido['productos'] > 0
                ? $contenido['productos'].' '.($contenido['productos'] === 1 ? 'producto' : 'productos')
                : null,
        ]);

        if ($partes === []) {
            return "«{$categoria->nombre}» se desactivó. Ya no se va a ofrecer para cargar productos nuevos.";
        }

        $listado = implode(' y ', $partes);

        return $enCascada
            ? "«{$categoria->nombre}» se desactivó junto con {$listado}."
            : "«{$categoria->nombre}» se desactivó. {$listado} siguen activos y a la venta; la categoría ya no se va a ofrecer para cargar productos nuevos.";
    }
}
