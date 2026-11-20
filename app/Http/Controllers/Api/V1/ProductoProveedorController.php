<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductoProveedorRequest;
use App\Http\Requests\Api\ProveedoresDelProductoRequest;
use App\Http\Resources\Api\VinculoProveedorResource;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProductoProveedorService;
use App\Support\Importe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Los proveedores de un producto (tramo D): traduce entre el contrato y ProductoProveedorService.
 * Recurso anidado: el producto lo determina la URL, nunca el cuerpo. Los mensajes dicen lo que pasó
 * de verdad (el primero queda elegido, el que asciende al quitar al elegido), como en la web.
 *
 * Métodos:
 *   index()      a quién se le puede pedir (compras), el comparador o los disponibles para el alta
 *   show()       un vínculo, para su edición
 *   store()      agrega un proveedor (201)
 *   update()     cambia costo, código y preferencia
 *   preferido()  elige a quién pedirle, sin tocar el resto del vínculo
 *   destroy()    quita el vínculo; con un pedido en curso es 409
 */
class ProductoProveedorController extends Controller
{
    public function __construct(private ProductoProveedorService $service)
    {
    }

    public function index(ProveedoresDelProductoRequest $request, Producto $producto): JsonResponse
    {
        $filas = match ($request->forma()) {
            'comparador'  => $this->comparador($producto, $request),
            'disponibles' => $this->disponibles($producto),
            default       => $this->paraElegir($producto, $request),
        };

        $pagina = new LengthAwarePaginator(
            $filas->forPage($request->pagina(), $request->porPagina())->values(),
            $filas->count(),
            $request->porPagina(),
            $request->pagina(),
        );

        return Paginado::respuesta($pagina, JsonResource::class, [
            'forma' => $request->forma(),
        ]);
    }

    public function show(Request $request, Producto $producto, int $proveedor): JsonResponse
    {
        return Recurso::respuesta($this->vinculo($request, $producto, $this->vinculoDe($producto, $proveedor)));
    }

    public function store(ProductoProveedorRequest $request, Producto $producto): JsonResponse
    {
        $datos       = $request->validated();
        $proveedor   = Proveedor::findOrFail($datos['proveedor_id']);
        $esElPrimero = $producto->proveedores()->doesntExist();

        $this->service->vincular($producto, $datos);

        // El primero queda elegido aunque nadie marque la casilla: callarlo dejaría al usuario
        // creyendo que no eligió a nadie.
        return Recurso::respuesta(
            $this->vinculo($request, $producto, $this->vinculoDe($producto, $proveedor->id)),
            201,
            $esElPrimero
                ? "Se agregó {$proveedor->razon_social}, y es a quien se le va a pedir la reposición."
                : "Se agregó {$proveedor->razon_social}.",
        );
    }

    public function update(ProductoProveedorRequest $request, Producto $producto, int $proveedor): JsonResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);

        // Antes de guardar: después el dato ya cambió.
        $seQuisoDesmarcar = $vinculo->pivot->es_preferido && ! $request->boolean('es_preferido');

        $this->service->actualizar($producto, $proveedor, $request->validated());

        return Recurso::respuesta(
            $this->vinculo($request, $producto, $this->vinculoDe($producto, $proveedor)),
            200,
            $seQuisoDesmarcar
                ? "Se guardó el vínculo con {$vinculo->razon_social}. Sigue siendo el elegido: para cambiarlo, marcá otro."
                : "Se guardó el vínculo con {$vinculo->razon_social}.",
        );
    }

    public function preferido(Request $request, Producto $producto, int $proveedor): JsonResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);

        $this->service->marcarPreferido($producto, $proveedor);

        return Recurso::respuesta(
            $this->vinculo($request, $producto, $this->vinculoDe($producto, $proveedor)),
            200,
            "La reposición de «{$producto->nombre}» se le va a pedir a {$vinculo->razon_social}.",
        );
    }

    /** Con un pedido en curso, el servicio lanza ReglaDeNegocioException: es el 409 (M-30). */
    public function destroy(Producto $producto, int $proveedor): JsonResponse
    {
        $vinculo = $this->vinculoDe($producto, $proveedor);
        $era     = (bool) $vinculo->pivot->es_preferido;

        $this->service->desvincular($producto, $proveedor);

        $quedan = $producto->proveedores()->exists();

        return Recurso::respuesta(
            new JsonResource(['producto_id' => $producto->id, 'proveedor_id' => $proveedor, 'quitado' => true]),
            200,
            $era && $quedan
                ? "Se quitó a {$vinculo->razon_social}. El más antiguo de los que quedaban pasó a ser el elegido."
                : "Se quitó a {$vinculo->razon_social}.",
        );
    }

    /**
     * El comparador: por costo, con los que no lo tienen cargado al FINAL (el criterio de
     * Producto::proveedorParaReponer, para que la pantalla y la reposición no se contradigan).
     * El más barato y los pedidos vivos, una vez para toda la tabla.
     */
    private function comparador(Producto $producto, Request $request): Collection
    {
        $producto->load('proveedores');
        $masBarato  = $producto->proveedorMasBarato();
        $referencia = $masBarato?->pivot->costo_ultimo;
        $pedidos    = $this->service->pedidosEnCurso($producto);

        return $producto->proveedores
            ->sortBy(fn (Proveedor $proveedor) => [
                $proveedor->pivot->costo_ultimo === null ? 1 : 0,
                (float) ($proveedor->pivot->costo_ultimo ?? 0),
                $proveedor->id,
            ])
            ->values()
            ->map(function (Proveedor $proveedor) use ($masBarato, $referencia, $pedidos, $request) {
                $costo    = $proveedor->pivot->costo_ultimo;
                $esBarato = $masBarato !== null && $masBarato->id === $proveedor->id;

                return (new VinculoProveedorResource($proveedor))->conContexto([
                    'mas_barato' => $esBarato,
                    // Cuánto más caro que el más barato: lo que convierte una lista en una comparación.
                    'diferencia' => $costo !== null && $referencia !== null && (float) $referencia > 0 && ! $esBarato
                        ? (int) round(((float) $costo / (float) $referencia - 1) * 100)
                        : null,
                    'pedido'     => $pedidos[$proveedor->id] ?? null,
                ])->resolve($request);
            });
    }

    /**
     * Los que se pueden agregar: sólo activos y que todavía no estén (vincular es preparar una
     * compra). Es lo que exige ProductoProveedorRequest: la pantalla no ofrece lo que va a rechazar.
     * Va aparte de GET /proveedores porque lo pide quien edita el producto, no quien ve proveedores.
     */
    private function disponibles(Producto $producto): Collection
    {
        return Proveedor::query()
            ->where('activo', true)
            ->whereNotIn('id', $producto->proveedores()->select('proveedores.id'))
            ->orderBy('razon_social')
            ->orderBy('id')
            ->get(['id', 'razon_social'])
            ->map(fn (Proveedor $proveedor) => ['id' => $proveedor->id, 'razon_social' => $proveedor->razon_social]);
    }

    /** A quién se le puede pedir, en el orden en que conviene (Producto::proveedoresParaElegir). */
    private function paraElegir(Producto $producto, Request $request): Collection
    {
        $conCosto = $request->user()->can('precio.ver_costo');

        return collect($producto->proveedoresParaElegir())->map(fn (array $proveedor) => [
            'id'           => $proveedor['id'],
            'razon_social' => $proveedor['nombre'],
            'es_preferido' => $proveedor['preferido'],
            // Sin precio.ver_costo la clave no viaja, ni en null (regla 36).
            ...($conCosto ? ['costo_ultimo' => $proveedor['costo'] === null ? null : Importe::json($proveedor['costo'])] : []),
        ]);
    }

    /** El vínculo como lo devuelven show y las escrituras, con el producto al que pertenece. */
    private function vinculo(Request $request, Producto $producto, Proveedor $proveedor): JsonResource
    {
        $pedido = $this->service->pedidosEnCurso($producto)[$proveedor->id] ?? null;

        return new JsonResource([
            ...(new VinculoProveedorResource($proveedor))->conContexto(['pedido' => $pedido])->resolve($request),
            'producto' => ['id' => $producto->id, 'nombre' => $producto->nombre, 'codigo' => $producto->codigo],
        ]);
    }

    /** El vínculo, buscado DENTRO de los proveedores del producto: uno ajeno es 404. */
    private function vinculoDe(Producto $producto, int $proveedorId): Proveedor
    {
        return $producto->proveedores()->findOrFail($proveedorId);
    }
}
