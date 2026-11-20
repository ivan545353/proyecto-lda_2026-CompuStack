<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogoRequest;
use App\Http\Requests\Api\ProductoFiltroRequest;
use App\Http\Requests\Api\ProductoRequest;
use App\Http\Resources\Api\ProductoFichaGestionResource;
use App\Http\Resources\Api\ProductoFichaResource;
use App\Http\Resources\Api\ProductoGestionResource;
use App\Http\Resources\Api\ProductoListadoResource;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoProductos;
use App\Models\Producto;
use App\Services\PrecioService;
use App\Services\ProductoService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Los productos: el catálogo de la tienda y, con `forma=gestion`, el de la administración (tramo D).
 * Un recurso, dos representaciones (regla 36); sin `forma`, la respuesta es la de la Etapa 2.
 *
 * Métodos:
 *   index()       el catálogo de la tienda, o el listado de gestión con sus filtros (producto.ver)
 *   show()        la ficha de la tienda, o la de gestión con lo que edita el formulario (producto.ver)
 *   opciones()    las alícuotas que admite el formulario, con la dada de baja que ya tenga
 *   impresion()   el enlace firmado del listado de gestión, en PDF o CSV (D-22)
 *   store()       alta, con imágenes (201)
 *   update()      edición; con imágenes, `POST` + `_method=PATCH` (HL-110)
 *   recalcular()  el precio desde los factores; el resultado lo dice el mensaje
 *   destroy()     baja física o lógica; el mensaje dice cuál fue
 */
class ProductoController extends Controller
{
    public function __construct(private ProductoService $service, private PrecioService $precios)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $request->query('forma') === 'gestion'
            ? $this->listadoDeGestion(app(ProductoFiltroRequest::class))
            : $this->catalogo(app(CatalogoRequest::class));
    }

    /** La ficha de la tienda es sólo de un producto visible; la de gestión, de cualquiera. */
    public function show(Request $request, string $producto): JsonResponse
    {
        if ($request->query('forma') !== 'gestion') {
            return Recurso::respuesta(new ProductoFichaResource(Producto::fichaEnTienda($producto)));
        }

        // Sin sesión es 401, como el listado de gestión: lo que falta es ingresar, no un permiso.
        $request->user() ?? throw new AuthenticationException;
        Gate::authorize('producto.ver');

        return Recurso::respuesta(new ProductoFichaGestionResource(
            Producto::with(['categoria.padre.padre', 'marca', 'proveedores'])->findOrFail($producto),
        ));
    }

    public function opciones(Request $request): JsonResponse
    {
        $producto  = $request->filled('producto') ? Producto::findOrFail($request->integer('producto')) : null;
        $alicuotas = $this->service->opciones($producto)['alicuotas'];

        return Recurso::respuesta(new JsonResource([
            'alicuotas' => array_map(
                fn (string $valor, string $texto) => ['valor' => $valor, 'texto' => $texto],
                array_keys($alicuotas),
                $alicuotas,
            ),
        ]));
    }

    public function impresion(ProductoFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('productos', $request->filtros(), $request->query(), $request->user()),
        ));
    }

    public function store(ProductoRequest $request): JsonResponse
    {
        $producto = $this->service->crear(
            $request->validated(),
            $request->file('imagenes', []),
            $request->ordenDeImagenes(),
            $request->user(),
        );

        return Recurso::respuesta($this->ficha($producto), 201, "Producto «{$producto->nombre}» creado.");
    }

    public function update(ProductoRequest $request, Producto $producto): JsonResponse
    {
        $producto = $this->service->actualizar(
            $producto,
            $request->validated(),
            $request->file('imagenes', []),
            $request->ordenDeImagenes(),
            $request->user(),
        );

        return Recurso::respuesta($this->ficha($producto), 200, "Producto «{$producto->nombre}» actualizado.");
    }

    /** No recalcular no es un error: un precio manual o sin costo queda como estaba, y el mensaje lo dice. */
    public function recalcular(Request $request, Producto $producto): JsonResponse
    {
        $resultado = $this->precios->recalcular($producto, $request->user());

        return Recurso::respuesta(
            new JsonResource([
                'id'        => $producto->id,
                'resultado' => $resultado,
                'aplicado'  => $resultado === PrecioService::APLICADO,
            ]),
            200,
            "«{$producto->nombre}»: ".PrecioService::RESULTADOS[$resultado],
        );
    }

    /** No miente sobre lo que pasó: «eliminado» sólo si de verdad se borró la fila (regla 2). */
    public function destroy(Producto $producto): JsonResponse
    {
        $id      = $producto->id;
        $nombre  = $producto->nombre;
        $seBorro = $this->service->eliminar($producto);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => $seBorro]),
            200,
            $seBorro
                ? "Producto «{$nombre}» eliminado."
                : "«{$nombre}» tiene stock o historial de movimientos: se desactivó en lugar de eliminarse.",
        );
    }

    private function catalogo(CatalogoRequest $request): JsonResponse
    {
        $criterios = $request->criterios();

        // Antes de consultar: el orden mismo revelaría los precios que no viajan.
        if (in_array($criterios['orden'], Producto::ORDENES_CON_PRECIO, true)) {
            $request->user() ?? throw new AuthenticationException;
            Gate::authorize('tienda.ver_precios');
        }

        return Paginado::respuesta(
            Producto::paginaEnTienda($criterios, $request->pagina(), $request->porPagina()),
            ProductoListadoResource::class,
            $criterios,
        );
    }

    private function listadoDeGestion(ProductoFiltroRequest $request): JsonResponse
    {
        $pagina = app(ListadoProductos::class)->consulta($request->filtros())
            ->orderBy('id')
            // La ruta de la categoría, la marca y el aviso de reposición sin una consulta por fila.
            ->with(['categoria.padre.padre', 'marca', 'proveedores'])
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, ProductoGestionResource::class, $request->criterios());
    }

    private function ficha(Producto $producto): ProductoFichaGestionResource
    {
        return new ProductoFichaGestionResource($producto->load(['categoria.padre.padre', 'marca', 'proveedores']));
    }
}
