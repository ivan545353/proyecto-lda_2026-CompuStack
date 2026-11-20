<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MarcaFiltroRequest;
use App\Http\Requests\Api\MarcaRequest;
use App\Http\Resources\Api\MarcaResource;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoMarcas;
use App\Models\Marca;
use App\Services\MarcaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Las marcas (tramo D): traduce entre el contrato y MarcaService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()      listado filtrado y paginado, con cuántos productos tiene cada una
 *   impresion()  el enlace firmado del mismo listado, en PDF o CSV (D-22)
 *   show()       una marca
 *   store()      alta (201)
 *   update()     edición; el mensaje dice qué pasó con los productos al desactivarla
 *   destroy()    baja física o lógica, según tenga productos; el mensaje dice cuál fue
 */
class MarcaController extends Controller
{
    public function __construct(private MarcaService $service)
    {
    }

    public function index(MarcaFiltroRequest $request, ListadoMarcas $listado): JsonResponse
    {
        $pagina = $listado->consulta($request->validated())
            ->orderBy('id')
            ->withCount('productos')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, MarcaResource::class, $request->criterios());
    }

    public function impresion(MarcaFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('marcas', $request->validated(), $request->query(), $request->user()),
        ));
    }

    public function show(Marca $marca): JsonResponse
    {
        return Recurso::respuesta(new MarcaResource($marca->loadCount('productos')));
    }

    public function store(MarcaRequest $request): JsonResponse
    {
        $marca = $this->service->crear($this->datos($request), $request->file('logo'));

        return Recurso::respuesta(new MarcaResource($marca), 201, "Marca «{$marca->nombre}» creada.");
    }

    public function update(MarcaRequest $request, Marca $marca): JsonResponse
    {
        $activos     = $marca->productosActivos();
        $seDesactiva = $marca->activo && ! $request->boolean('activo');
        $enCascada   = $request->boolean('desactivar_productos') && ! $request->boolean('activo');

        $marca = $this->service->actualizar(
            $marca,
            $this->datos($request),
            $request->file('logo'),
            $request->boolean('quitar_logo'),
            $enCascada,
        );

        $mensaje = match (true) {
            ! $seDesactiva || $activos === 0 => "Marca «{$marca->nombre}» actualizada.",
            $enCascada => "«{$marca->nombre}» se desactivó junto con sus {$activos} producto(s).",
            default => "«{$marca->nombre}» se desactivó. Sus {$activos} producto(s) siguen activos y a la venta; la marca ya no se va a ofrecer para productos nuevos.",
        };

        return Recurso::respuesta(new MarcaResource($marca->loadCount('productos')), 200, $mensaje);
    }

    /** No miente sobre lo que pasó: «eliminada» sólo si de verdad se borró la fila (regla 2). */
    public function destroy(Marca $marca): JsonResponse
    {
        $id      = $marca->id;
        $nombre  = $marca->nombre;
        $seBorro = $this->service->eliminar($marca);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminada' => $seBorro]),
            200,
            $seBorro
                ? "Marca «{$nombre}» eliminada."
                : "La marca «{$nombre}» tiene productos asociados: se desactivó en lugar de eliminarse.",
        );
    }

    /** @return array{nombre: string, activo: bool} */
    private function datos(MarcaRequest $request): array
    {
        return [
            'nombre' => (string) $request->validated('nombre'),
            'activo' => $request->boolean('activo'),
        ];
    }
}
