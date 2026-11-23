<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OrdenCompraFiltroRequest;
use App\Http\Requests\Api\OrdenCompraRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Requests\Api\PedidoCompraRequest;
use App\Http\Requests\Api\ProductosElegidosRequest;
use App\Http\Requests\Api\RecepcionCompraRequest;
use App\Http\Resources\Api\OrdenCompraFichaResource;
use App\Http\Resources\Api\OrdenCompraResource;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoCompras;
use App\Impresion\PedidoCompra;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraLinea;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Support\Importe;
use Illuminate\Database\Eloquent\Collection;
use App\Services\CompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Las órdenes de compra (tramo G): traduce entre el contrato y CompraService. El permiso lo pide la ruta.
 *
 * Métodos:
 *   index()        listado filtrado y paginado, de la más nueva a la más vieja
 *   impresion()    el enlace firmado del mismo listado, en PDF o CSV (D-22)
 *   proveedores()  los proveedores para elegir, sin pedir `proveedor.ver` (HL-125); `activos=1` para pedir
 *   show()         la ficha: líneas, lo que entró y qué acciones admite su estado
 *   pdf()          el enlace firmado del pedido formal (D-22); sin aprobar, 409
 *   descargarPdf() el PDF, por ese enlace: la firma es la autorización
 *   sugeridos()    qué hay que reponer, agrupado por proveedor; el costo, sólo con precio.ver_costo
 *   armador()      los sugeridos elegidos como renglones, y cuántos ya no hacían falta (HL-124)
 *   store()        el pedido del armador: un borrador por proveedor (201)
 *   recibir()      una recepción total o parcial (201): entra stock por StockService
 *   update()       edición del borrador; aprobada, ya no (409)
 *   aprobar() / enviada() / cancelar() / cerrar()  las transiciones; lo que no está en la tabla, 409
 *
 * Cada escritura devuelve la ficha (200) y un mensaje que dice qué cambió y qué sigue: es el de la web.
 */
class CompraController extends Controller
{
    public function __construct(private CompraService $service)
    {
    }

    public function index(OrdenCompraFiltroRequest $request, ListadoCompras $listado): JsonResponse
    {
        $pagina = $listado->consulta($request->validated())
            ->with('proveedor:id,razon_social')
            ->withCount('lineas')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, OrdenCompraResource::class, $request->criterios());
    }

    public function impresion(OrdenCompraFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('compras', $request->validated(), $request->query(), $request->user()),
        ));
    }

    /** El filtro ofrece todos (una orden vieja puede ser de uno inactivo); el armador, sólo los activos. */
    public function proveedores(PaginadoRequest $request): JsonResponse
    {
        $request->validate(['activos' => ['nullable', 'boolean']], ['activos.boolean' => '«activos» es 1 o 0.']);

        $pagina = Proveedor::query()
            ->when($request->boolean('activos'), fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'razon_social', 'activo'], 'pagina', $request->pagina())
            ->through(fn (Proveedor $proveedor) => [
                'id'           => $proveedor->id,
                'razon_social' => $proveedor->razon_social,
                'activo'       => (bool) $proveedor->activo,
            ]);

        return Paginado::respuesta($pagina, JsonResource::class, ['activos' => $request->boolean('activos')]);
    }

    public function show(OrdenCompra $orden): JsonResponse
    {
        return Recurso::respuesta(new OrdenCompraFichaResource($this->conFicha($orden)));
    }

    public function pdf(Request $request, OrdenCompra $orden, PedidoCompra $pedido): JsonResponse
    {
        return Recurso::respuesta(new JsonResource($pedido->enlace($orden, $request->user())));
    }

    public function descargarPdf(Request $request, OrdenCompra $orden, PedidoCompra $pedido): Response
    {
        return $pedido->descarga($orden, $request->query('pedido_por'));
    }

    /** El sobre suma `grupos` (por proveedor, de la página), `sin_proveedor` y, con el permiso, `estimado_pagina`. */
    public function sugeridos(PaginadoRequest $request): JsonResponse
    {
        $verCosto = $request->user()->can('precio.ver_costo');

        $pagina = Producto::query()
            ->paraReponer()
            ->conProveedorActivo()
            // proveedorParaReponer() y el costo del vínculo leen esta relación (A-26).
            ->with('proveedores')
            ->orderBy('codigo')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        $productos = $pagina->getCollection();

        $grupos = $productos
            ->groupBy(fn (Producto $producto) => $producto->proveedorParaReponer()->id)
            ->map(fn (Collection $deUno) => [
                'proveedor' => [
                    'id'           => $deUno->first()->proveedorParaReponer()->id,
                    'razon_social' => $deUno->first()->proveedorParaReponer()->razon_social,
                ],
                'productos' => $deUno->pluck('id')->all(),
                ...($verCosto ? ['estimado' => Importe::json($this->estimadoDe($deUno))] : []),
            ])
            ->sortBy('proveedor.razon_social')
            ->values()
            ->all();

        $estimadoPagina = $verCosto ? Importe::json($this->estimadoDe($productos)) : null;

        $pagina->through(fn (Producto $producto) => $this->sugerido($producto, $verCosto));

        $respuesta = Paginado::respuesta($pagina, JsonResource::class, []);

        return $respuesta->setData([
            ...$respuesta->getData(true),
            'grupos'        => $grupos,
            'sin_proveedor' => Producto::query()->sinProveedorParaReponer()->count(),
            ...($verCosto ? ['estimado_pagina' => $estimadoPagina] : []),
        ]);
    }

    /** Rechaza, no corrige (M-31): un sugerido que dejó de hacer falta no entra, y `descartados` lo cuenta. */
    public function armador(ProductosElegidosRequest $request): JsonResponse
    {
        $elegidos  = $request->elegidos();
        $sugeridas = $this->service->lineasSugeridas($elegidos);

        $productos   = Producto::whereIn('id', array_column($sugeridas, 'producto_id'))->get(['id', 'codigo', 'nombre'])->keyBy('id');
        $proveedores = Proveedor::whereIn('id', array_column($sugeridas, 'proveedor_id'))->get(['id', 'razon_social'])->keyBy('id');

        $lineas = array_map(fn (array $linea) => [
            'producto'        => $productos[$linea['producto_id']]->only(['id', 'codigo', 'nombre']),
            'proveedor'       => $proveedores[$linea['proveedor_id']]->only(['id', 'razon_social']),
            'cantidad_pedida' => (int) $linea['cantidad_pedida'],
            // Lo que el armador de la web precargaba (HL-127): el renglón propone el último costo.
            'costo_unitario'  => Importe::json($linea['costo_unitario']),
        ], $sugeridas);

        $descartados = count($elegidos) - count($sugeridas);

        $respuesta = Recurso::respuesta(new JsonResource($lineas));

        return $respuesta->setData([
            ...$respuesta->getData(true),
            'descartados'  => $descartados,
            'aviso'        => match (true) {
                $descartados === 0 => null,
                $descartados === 1 => 'Uno de los productos que elegiste ya no hace falta pedirlo: le cargaron stock o ya '
                    .'tiene un pedido en curso. No se agregó.',
                default => "{$descartados} de los productos que elegiste ya no hacen falta: les cargaron stock o "
                    .'ya tienen un pedido en curso. No se agregaron.',
            },
            'hay_pedibles' => Producto::query()->where('activo', true)->conProveedorActivo()->exists(),
        ]);
    }

    public function store(PedidoCompraRequest $request): JsonResponse
    {
        $ordenes = $this->service->armarPedido($request->validated(), $request->user());
        $ordenes->each->loadCount('lineas');

        $detalle = $ordenes
            ->map(fn (OrdenCompra $orden) => "{$orden->numeroFormateado()} a {$orden->proveedor->razon_social}")
            ->implode(', ');

        $cuantos = $ordenes->count();

        return Recurso::respuesta(OrdenCompraResource::collection($ordenes), 201, $cuantos === 1
            ? "Se creó el pedido {$detalle}, en borrador. Hay que aprobarlo para poder enviarlo."
            : "Se crearon {$cuantos} pedidos en borrador: {$detalle}. Hay que aprobar cada uno para poder enviarlo.");
    }

    public function recibir(RecepcionCompraRequest $request, OrdenCompra $orden): JsonResponse
    {
        $datos    = $request->validated();
        $remito   = $datos['remito_numero'] ?? null;
        $unidades = collect($datos['cantidades'])->sum(fn ($cantidad) => (int) $cantidad);

        $orden = $this->service->recibir($orden, $datos['cantidades'], $request->user(), [
            'numero' => $remito,
            'fecha'  => $datos['remito_fecha'] ?? null,
        ]);

        $conRemito  = $remito === null ? '' : " con el remito {$remito}";
        $pendientes = $orden->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        return $this->ficha($orden, $pendientes === 0
            ? "Se registraron {$unidades} unidad(es){$conRemito} y la orden quedó recibida completa. Se actualizó el "
              .'stock, el costo promedio de cada producto y el último costo de este proveedor.'
            : "Se registraron {$unidades} unidad(es){$conRemito}. Quedan {$pendientes} pendientes, así que la orden "
              .'sigue parcial. Se actualizó el stock, el costo promedio y el último costo de este proveedor.',
            201);
    }

    public function update(OrdenCompraRequest $request, OrdenCompra $orden): JsonResponse
    {
        $orden = $this->service->actualizarBorrador($orden, $request->validated());

        return $this->ficha($orden, "Orden {$orden->numeroFormateado()} actualizada.");
    }

    public function aprobar(Request $request, OrdenCompra $orden): JsonResponse
    {
        $orden = $this->service->aprobar($orden, $request->user());

        // Aprobar no envía nada, y eso no es evidente para quien recién apretó el botón.
        return $this->ficha($orden,
            "Orden {$orden->numeroFormateado()} aprobada: el monto quedó comprometido y las líneas "
            .'se congelaron. El próximo paso es hacerle llegar el pedido al proveedor y marcarla como enviada.',
        );
    }

    public function enviada(OrdenCompra $orden): JsonResponse
    {
        $orden = $this->service->marcarEnviada($orden);

        return $this->ficha($orden,
            "Orden {$orden->numeroFormateado()} marcada como enviada. Queda registrado que el pedido "
            .'salió y cuándo; el envío lo hiciste vos, el sistema sólo lo anota.',
        );
    }

    public function cancelar(OrdenCompra $orden): JsonResponse
    {
        $orden = $this->service->cancelar($orden);

        return $this->ficha($orden,
            "Orden {$orden->numeroFormateado()} cancelada. No se pidió nada y no se movió stock.",
        );
    }

    public function cerrar(OrdenCompra $orden): JsonResponse
    {
        // Se cuenta antes de cerrar: el número es parte de lo que el mensaje explica.
        $pendientes = $orden->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        $orden = $this->service->cerrarIncompleta($orden);

        return $this->ficha($orden,
            "Orden {$orden->numeroFormateado()} cerrada con lo que llegó. Quedaron {$pendientes} unidad(es) "
            .'sin recibir, y las líneas lo siguen diciendo.',
        );
    }

    private function ficha(OrdenCompra $orden, string $mensaje, int $estado = 200): JsonResponse
    {
        return Recurso::respuesta(new OrdenCompraFichaResource($this->conFicha($orden)), $estado, $mensaje);
    }

    /** Lo que la ficha lee, cargado de una vez (A-26). */
    private function conFicha(OrdenCompra $orden): OrdenCompra
    {
        return $orden->load([
            'proveedor',
            'lineas.producto:id,codigo,nombre,activo',
            'usuarioCreo:id,nombre,apellido',
            'usuarioAprobo:id,nombre,apellido',
            'movimientos.usuario:id,nombre,apellido',
            'movimientos.producto:id,codigo,nombre',
            'movimientos.origen',
        ]);
    }

    /** Un renglón de los sugeridos: lo que la pantalla muestra. El costo, sólo con precio.ver_costo (regla 36). */
    private function sugerido(Producto $producto, bool $verCosto): array
    {
        $proveedor = $producto->proveedorParaReponer();

        return [
            'id'                  => $producto->id,
            'codigo'              => $producto->codigo,
            'nombre'              => $producto->nombre,
            'disponible'          => $producto->stock_disponible,
            'stock_minimo'        => (int) $producto->stock_minimo,
            'cantidad_reposicion' => (int) $producto->cantidad_reposicion,
            'proveedor'           => ['id' => $proveedor->id, 'razon_social' => $proveedor->razon_social],
            ...($verCosto ? [
                'costo_ultimo' => $proveedor->pivot->costo_ultimo === null ? null : Importe::json($proveedor->pivot->costo_ultimo),
            ] : []),
        ];
    }

    /**
     * Lo que saldría pedir estos productos, al último costo de cada proveedor. Es un número para mirar y no se
     * persiste: el intermedio opera en float y redondea una sola vez, al final (A-17). Es el de la web.
     *
     * @param  Collection<int, Producto>  $productos
     */
    private function estimadoDe(Collection $productos): float
    {
        return round($productos->sum(function (Producto $producto) {
            $costo = $producto->proveedorParaReponer()->pivot->costo_ultimo;

            return $producto->cantidad_reposicion * (float) ($costo ?? 0);
        }), 2);
    }
}
