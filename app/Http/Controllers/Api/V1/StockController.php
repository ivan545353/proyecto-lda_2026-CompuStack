<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AjusteStockRequest;
use App\Http\Requests\Api\MovimientoFiltroRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\MovimientoStockResource;
use App\Http\Resources\Api\StockProductoResource;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoMovimientos;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El kardex y el ajuste de inventario (tramo F): traduce entre el contrato y StockService. El permiso
 * lo pide la ruta: ver el kardex (`stock.ver`) y ajustar (`stock.ajustar`) son responsabilidades distintas.
 *
 * Métodos:
 *   movimientos()  el kardex filtrado y paginado, del más nuevo al más viejo
 *   impresion()    el enlace firmado del mismo kardex, en PDF o CSV (D-22)
 *   usuarios()     quiénes registraron algún movimiento: las opciones del filtro «Lo registró»
 *   producto()     el stock de un producto y quién retiene lo reservado
 *   ajustar()      el ajuste por conteo físico (201); el mensaje dice de cuánto a cuánto
 */
class StockController extends Controller
{
    public function __construct(private StockService $service)
    {
    }

    public function movimientos(MovimientoFiltroRequest $request, ListadoMovimientos $listado): JsonResponse
    {
        $pagina = $listado->consulta($request->validated())
            ->with(['producto:id,codigo,nombre', 'usuario:id,nombre,apellido', 'origen'])
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, MovimientoStockResource::class, $request->criterios());
    }

    public function impresion(MovimientoFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('movimientos', $request->validated(), $request->query(), $request->user()),
        ));
    }

    /** Sólo los que tienen algún movimiento: un filtro que no puede devolver nada no se ofrece. */
    public function usuarios(PaginadoRequest $request): JsonResponse
    {
        $pagina = User::query()
            ->whereIn('id', MovimientoStock::query()->whereNotNull('usuario_id')->select('usuario_id'))
            ->orderBy('apellido')->orderBy('nombre')->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'nombre', 'apellido'], 'pagina', $request->pagina())
            ->through(fn (User $usuario) => [
                'id'    => $usuario->id,
                'texto' => "{$usuario->apellido}, {$usuario->nombre}",
            ]);

        return Paginado::respuesta($pagina, JsonResource::class, []);
    }

    public function producto(Producto $producto): JsonResponse
    {
        return Recurso::respuesta(new StockProductoResource(
            $producto->setAttribute('reservas', $this->service->reservas($producto)),
        ));
    }

    public function ajustar(AjusteStockRequest $request, Producto $producto): JsonResponse
    {
        $movimiento = $this->service->ajustar(
            $producto,
            $request->integer('stock_contado'),
            (string) $request->validated('motivo'),
            $request->user(),
            $request->integer('stock_esperado'),
        );

        $previo = $movimiento->stock_resultante - $movimiento->cantidad;
        $verbo  = $movimiento->cantidad > 0 ? 'subió' : 'bajó';
        $signo  = $movimiento->cantidad > 0 ? '+' : '';

        return Recurso::respuesta(
            new MovimientoStockResource($movimiento->load(['producto:id,codigo,nombre', 'usuario:id,nombre,apellido'])),
            201,
            "El stock de «{$producto->nombre}» {$verbo} de {$previo} a {$movimiento->stock_resultante} ({$signo}{$movimiento->cantidad}).",
        );
    }
}
