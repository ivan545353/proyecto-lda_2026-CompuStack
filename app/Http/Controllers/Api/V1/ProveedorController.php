<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProveedorFiltroRequest;
use App\Http\Requests\Api\ProveedorRequest;
use App\Http\Resources\Api\ProveedorResource;
use App\Impresion\ImpresionFirmada;
use App\Models\Proveedor;
use App\Services\ProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Los proveedores (tramos D y E): traduce entre el contrato y ProveedorService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()    listado filtrado y paginado, con cuántos productos y órdenes tiene cada uno
 *   impresion()  el enlace firmado del mismo listado, en PDF o CSV (D-22)
 *   show()     un proveedor, con sus productos activos (lo que la cascada va a tocar)
 *   store()    alta (201)
 *   update()   edición; el mensaje dice qué pasó con los productos al desactivarlo
 *   destroy()  baja física o lógica, según esté referenciado; el mensaje dice cuál fue
 */
class ProveedorController extends Controller
{
    public function __construct(private ProveedorService $service)
    {
    }

    public function index(ProveedorFiltroRequest $request): JsonResponse
    {
        $pagina = Proveedor::query()
            ->buscar($request->validated('q'))
            ->conEstado($request->validated('estado'))
            ->conCanal($request->validated('canal'))
            ->withCount(['productos', 'ordenesCompra'])
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ProveedorResource::class, $request->criterios());
    }

    public function impresion(ProveedorFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('proveedores', $request->validated(), $request->query(), $request->user()),
        ));
    }

    public function show(Proveedor $proveedor): JsonResponse
    {
        return Recurso::respuesta(new ProveedorResource($this->conConteos($proveedor)));
    }

    public function store(ProveedorRequest $request): JsonResponse
    {
        $proveedor = $this->service->crear($request->validated());

        return Recurso::respuesta(
            new ProveedorResource($this->conConteos($proveedor)),
            201,
            "Proveedor «{$proveedor->razon_social}» creado.",
        );
    }

    public function update(ProveedorRequest $request, Proveedor $proveedor): JsonResponse
    {
        $activos     = $proveedor->productosActivos();
        $seDesactiva = $proveedor->activo && ! $request->boolean('activo');
        $enCascada   = $request->boolean('desactivar_productos') && ! $request->boolean('activo');

        $proveedor = $this->service->actualizar($proveedor, $request->validated(), $enCascada);

        // El mensaje dice lo que pasó de verdad, incluido lo que no pasó.
        $mensaje = match (true) {
            ! $seDesactiva || $activos === 0 => "Proveedor «{$proveedor->razon_social}» actualizado.",
            $enCascada => "«{$proveedor->razon_social}» se desactivó junto con sus {$activos} producto(s).",
            default => "«{$proveedor->razon_social}» se desactivó. Sus {$activos} producto(s) siguen activos y a la venta; el proveedor ya no se va a ofrecer al cargar productos nuevos.",
        };

        return Recurso::respuesta(new ProveedorResource($this->conConteos($proveedor)), 200, $mensaje);
    }

    /** No miente sobre lo que pasó: «eliminado» sólo si de verdad se borró la fila (regla 2). */
    public function destroy(Proveedor $proveedor): JsonResponse
    {
        $id      = $proveedor->id;
        $nombre  = $proveedor->razon_social;
        $seBorro = $this->service->eliminar($proveedor);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => $seBorro]),
            200,
            $seBorro
                ? "Proveedor «{$nombre}» eliminado."
                : "«{$nombre}» tiene productos u órdenes de compra registradas: se desactivó en lugar de eliminarse. Deja de ofrecerse al cargar productos nuevos y su historial queda intacto.",
        );
    }

    /** Los conteos los hace la base (A-26); `productos_activos` es el de la cascada. */
    private function conConteos(Proveedor $proveedor): Proveedor
    {
        return $proveedor->loadCount([
            'productos',
            'ordenesCompra',
            'productos as productos_activos' => fn ($consulta) => $consulta->where('productos.activo', true),
        ]);
    }
}
