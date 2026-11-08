<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\OrdenCompraFiltroRequest;
use App\Http\Requests\OrdenCompraRequest;
use App\Impresion\ListadoCompras;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Http\Requests\PedidoCompraFiltroRequest;
use App\Http\Requests\PedidoCompraRequest;
use Illuminate\Database\Eloquent\Collection;
use App\Services\CompraService;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\RecepcionCompraRequest;
use App\Support\MaquinaEstadosCompra;
use Illuminate\View\View;
use App\Models\OrdenCompraLinea;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Órdenes de compra. Módulo nuevo: el sistema original no tenía compras.
 *
 * Este controlador cubre el ciclo del borrador —listar, cargar, editar—. Las
 * acciones de estado (aprobar, enviar, recibir, cerrar, cancelar) tienen sus
 * propias rutas con su propio permiso.
 *
 * Métodos:
 *   index()             listado filtrado y paginado
 *   imprimir()          el mismo listado filtrado, en PDF o en CSV
 *   edit()  / update()  edición del borrador; sólo en borrador
 *   sugeridos()         qué hay que reponer: la entrada al armador
 *   armar() / guardarPedido()  el armador: una pantalla, un borrador por proveedor
 *   edit()  / update()         edición de un borrador, un proveedor
 *   show()                     la ficha, con el historial de recepciones
 *   aprobar() / marcarEnviada() / cerrarIncompleta() / cancelar()
 *   recepcion() / recibir()    recepción total o parcial; ingresa stock por StockService
 *   pdf()                      el pedido formal, descargable; no cambia el estado
 */
class CompraController extends Controller
{
    use ImprimeListados;
    public function __construct(private CompraService $service)
    {
    }

    public function index(OrdenCompraFiltroRequest $request): View
    {
        $ordenes = $this->filtradas($request)
            ->with('proveedor:id,razon_social')
            ->withCount('lineas')
            ->paginate(15)
            ->withQueryString();

        return view('compras.index', [
            'ordenes'     => $ordenes,
            'proveedores' => Proveedor::orderBy('razon_social')->get(['id', 'razon_social']),
        ]);
    }

    
    public function imprimir(OrdenCompraFiltroRequest $request, ListadoCompras $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'compras.index');
    }

    public function edit(OrdenCompra $orden): View|RedirectResponse
    {
    
        if (! $orden->esEditable()) {
            return redirect()->route('compras.index')->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y ya no se puede modificar: "
                .'el monto quedó comprometido al aprobarla.'
            );
        }

        $orden->load('lineas.producto');

        $lineas = $this->lineasDeLaOrden($orden);

        return view('compras.form', [
            'orden'         => $orden,
            'lineas'        => $lineas,
            'proximoIndice' => $this->proximoIndice($lineas),
            'productos'     => $this->productosDeLasLineas($lineas),
            'proveedores'   => $this->proveedoresDisponibles(),
        ]);
    }

    public function update(OrdenCompraRequest $request, OrdenCompra $orden): RedirectResponse
    {
        $orden = $this->service->actualizarBorrador($orden, $request->validated());

        return redirect()->route('compras.index')
            ->with('exito', "Orden {$orden->numeroFormateado()} actualizada.");
    }

    public function show(OrdenCompra $orden): View
    {
        return view('compras.show', [
            'orden' => $orden->load([
                'proveedor',
                'lineas.producto:id,codigo,nombre',
                'usuarioCreo:id,nombre,apellido',
                'usuarioAprobo:id,nombre,apellido',
                'movimientos.usuario:id,nombre,apellido',
                'movimientos.producto:id,codigo,nombre',
            ]),
        ]);
    }

    public function aprobar(Request $request, OrdenCompra $orden): RedirectResponse
    {
        $orden = $this->service->aprobar($orden, $request->user());

        // El mensaje dice qué cambió y qué sigue: aprobar no envía nada, y eso no
        // es evidente para quien recién apretó el botón.
        return redirect()->route('compras.show', $orden)->with(
            'exito',
            "Orden {$orden->numeroFormateado()} aprobada: el monto quedó comprometido y las líneas "
            .'se congelaron. El próximo paso es hacerle llegar el pedido al proveedor y marcarla como enviada.',
        );
    }

    public function marcarEnviada(OrdenCompra $orden): RedirectResponse
    {
        $orden = $this->service->marcarEnviada($orden);

        return redirect()->route('compras.show', $orden)->with(
            'exito',
            "Orden {$orden->numeroFormateado()} marcada como enviada. Queda registrado que el pedido "
            .'salió y cuándo; el envío lo hiciste vos, el sistema sólo lo anota.',
        );
    }

    public function cerrarIncompleta(OrdenCompra $orden): RedirectResponse
    {
        // Se cuenta ANTES de cerrar: las líneas no cambian, pero el número es parte
        // de lo que el mensaje tiene que explicar y conviene leerlo del estado que
        // el usuario estaba viendo.
        $pendientes = $orden->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        $orden = $this->service->cerrarIncompleta($orden);

        return redirect()->route('compras.show', $orden)->with(
            'exito',
            "Orden {$orden->numeroFormateado()} cerrada con lo que llegó. Quedaron {$pendientes} unidad(es) "
            .'sin recibir, y las líneas lo siguen diciendo.',
        );
    }

    public function cancelar(OrdenCompra $orden): RedirectResponse
    {
        $orden = $this->service->cancelar($orden);

        // No se guarda el motivo, y está decidido así en los pendientes: una orden
        // cancelada no produjo ningún efecto que haya que explicar, y
        // `observaciones` es el campo libre del usuario, que el sistema no pisa.
        return redirect()->route('compras.show', $orden)->with(
            'exito',
            "Orden {$orden->numeroFormateado()} cancelada. No se pidió nada y no se movió stock.",
        );
    }

    /** Sólo proveedores activos: una orden es un compromiso de plata. */
    private function proveedoresDisponibles()
    {
        return Proveedor::where('activo', true)->orderBy('razon_social')->get(['id', 'razon_social']);
    }


    /** Qué hay que reponer: la pantalla con la que se entra a armar un pedido. */
    public function sugeridos(): View
    {
        $sugeridos = Producto::query()
            ->paraReponer()
            ->conProveedorActivo()
            // proveedorParaReponer() y el costo del vínculo leen esta relación (A-26).
            ->with('proveedores')
            ->orderBy('codigo')
            ->paginate(20)
            ->withQueryString();

        return view('compras.sugeridos', [
            'sugeridos' => $sugeridos,
            'grupos' => $sugeridos->getCollection()
                ->groupBy(fn (Producto $producto) => $producto->proveedorParaReponer()->razon_social)
                ->sortKeys()
                ->map(fn (Collection $productos, string $razonSocial) => [
                    'proveedor' => $razonSocial,
                    'productos' => $productos,
                    'estimado'  => $this->estimadoDe($productos),
                ])
                ->values(),
            'estimadoPagina' => $this->estimadoDe($sugeridos->getCollection()),
            'sinProveedor'   => Producto::query()->sinProveedorParaReponer()->count(),
        ]);
    }

    /**
     * Lo que saldría pedir estos productos, al último costo de cada proveedor.
     *
     * Es un número para mirar y no se persiste, así que el intermedio opera en float y
     * redondea una sola vez, al final (A-17).
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

    public function armar(PedidoCompraFiltroRequest $request): View
    {
        $elegidos  = $request->elegidos();
        $sugeridas = $this->service->lineasSugeridas($elegidos);

        // Rechaza, no corrige (M-31): entre que la pantalla se cargó y el botón se
        // apretó, un sugerido pudo dejar de hacer falta. No entra, y se dice.
        $descartados = count($elegidos) - count($sugeridas);

        if ($descartados > 0) {
            session()->now('aviso', $descartados === 1
                ? 'Uno de los productos que elegiste ya no hace falta pedirlo: le cargaron stock o ya '
                  .'tiene un pedido en curso. No se agregó.'
                : "{$descartados} de los productos que elegiste ya no hacen falta: les cargaron stock o "
                  .'ya tienen un pedido en curso. No se agregaron.');
        }

        $lineas = $this->lineasDelPedido($sugeridas);

        return view('compras.pedido', [
            'lineas'        => $lineas,
            'proximoIndice' => $this->proximoIndice($lineas),
            'productos'     => $this->productosDeLasLineas($lineas),
            'proveedores'   => $this->proveedoresDisponibles(),
            'hayPedibles'   => Producto::query()
                ->where('activo', true)
                ->conProveedorActivo()
                ->exists(),
        ]);
    }

    /**
     * Las filas que el formulario de la orden tiene que rendir: lo que el usuario
     * mandó si hubo un error de validación, y si no, las líneas guardadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineasDeLaOrden(OrdenCompra $orden): array
    {
        $lineas = old('lineas', $orden->exists
            ? $orden->lineas
                ->map(fn (OrdenCompraLinea $linea) => [
                    'producto_id'     => $linea->producto_id,
                    'cantidad_pedida' => $linea->cantidad_pedida,
                    'costo_unitario'  => $linea->costo_unitario,
                ])
                ->all()
            : []);

        return is_array($lineas) ? array_filter($lineas, 'is_array') : [];
    }

    public function guardarPedido(PedidoCompraRequest $request): RedirectResponse
    {
        $ordenes = $this->service->armarPedido($request->validated(), $request->user());

        $detalle = $ordenes
            ->map(fn (OrdenCompra $orden) => "{$orden->numeroFormateado()} a {$orden->proveedor->razon_social}")
            ->implode(', ');

        $cuantos = $ordenes->count();

        // El mensaje nombra cada orden y a quién. El listado ordena por id
        // descendente, así que las nuevas son las primeras filas; con el número a la
        // vista, el usuario sabe cuál es cuál sin abrirlas. Es la alternativa a
        // inventar un filtro `?ids=` cuyo único uso sería esta redirección.
        return redirect()->route('compras.index', ['estado' => 'borrador'])->with(
            'exito',
            $cuantos === 1
                ? "Se creó el pedido {$detalle}, en borrador. Hay que aprobarlo para poder enviarlo."
                : "Se crearon {$cuantos} pedidos en borrador: {$detalle}. Hay que aprobar cada uno para poder enviarlo.",
        );
    }

    /**
     * Las filas que el armador tiene que rendir: lo que el usuario mandó si hubo un
     * error de validación, los sugeridos con los que entró si viene de esa pantalla, y
     * nada si abre a mano —el JS pone una fila vacía—.
     *
     * old() va primero: si la validación rechazó el pedido, lo que vale es lo que el
     * usuario tenía escrito y no la canasta con la que había entrado.
     *
     * @param  array<int, array<string, mixed>>  $sugeridas
     * @return array<int, array<string, mixed>>
     */
    private function lineasDelPedido(array $sugeridas = []): array
    {
        $lineas = old('lineas', $sugeridas);

        return is_array($lineas) ? array_filter($lineas, 'is_array') : [];
    }

    /** El índice con el que el JS va a numerar la próxima fila. */
    private function proximoIndice(array $lineas): int
    {
        return $lineas === [] ? 0 : max(array_keys($lineas)) + 1;
    }

    /**
     * Los productos que las filas ya cargadas mencionan, por id.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return Collection<int, Producto>
     */
    private function productosDeLasLineas(array $lineas): Collection
    {
        $ids = collect($lineas)
            ->pluck('producto_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->unique();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return Producto::whereIn('id', $ids)
            ->get(['id', 'codigo', 'nombre', 'costo_promedio', 'activo'])
            ->keyBy('id');
    }

    public function recepcion(OrdenCompra $orden): View|RedirectResponse
    {
        if (! MaquinaEstadosCompra::puedeRecibir($orden->estado)) {
            return redirect()->route('compras.show', $orden)->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y no puede recibir "
                .'mercadería. Sólo se recibe contra una orden aprobada, enviada o parcialmente recibida.',
            );
        }

        return view('compras.recepcion', [
            'orden' => $orden->load(['proveedor:id,razon_social', 'lineas.producto:id,codigo,nombre']),
        ]);
    }

    public function recibir(RecepcionCompraRequest $request, OrdenCompra $orden): RedirectResponse
    {
        $datos      = $request->validated();
        $cantidades = $datos['cantidades'];
        $remito     = $datos['remito_numero'] ?? null;

        $unidades = collect($cantidades)->sum(fn ($cantidad) => (int) $cantidad);

        $orden = $this->service->recibir($orden, $cantidades, $request->user(), [
            'numero' => $remito,
            'fecha'  => $datos['remito_fecha'] ?? null,
        ]);

        $conRemito = $remito === null ? '' : " con el remito {$remito}";

        $pendientes = $orden->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        return redirect()->route('compras.show', $orden)->with(
            'exito',
            $pendientes === 0
                ? "Se registraron {$unidades} unidad(es){$conRemito} y la orden quedó recibida completa. Se actualizó el "
                  .'stock, el costo promedio de cada producto y el último costo de este proveedor.'
                : "Se registraron {$unidades} unidad(es){$conRemito}. Quedan {$pendientes} pendientes, así que la orden "
                  .'sigue parcial. Se actualizó el stock, el costo promedio y el último costo de este proveedor.',
        );
    }

    public function pdf(OrdenCompra $orden): Response|RedirectResponse
    {
        // La ficha ofrece el botón sólo cuando corresponde; esto es la red de
        // contención para una URL escrita a mano.
        if (! $orden->sePuedeImprimir()) {
            return redirect()->route('compras.show', $orden)->with(
                'error',
                "La orden {$orden->numeroFormateado()} todavía no se aprobó. El pedido se imprime cuando "
                .'alguien autoriza el gasto, no antes.',
            );
        }

        $orden->load([
            'proveedor',
            'lineas.producto:id,codigo,nombre',
            'usuarioAprobo:id,nombre,apellido',
        ]);

        // Descargar es una lectura: no cambia el estado, no marca nada como enviado y
        // se puede repetir. 
        return Pdf::loadView('compras.pdf', ['orden' => $orden])
            ->download("orden-compra-{$orden->numeroFormateado()}.pdf");
    }

    
    /** Los filtros del listado, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(OrdenCompraFiltroRequest $request): Builder
    {
        return OrdenCompra::query()
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->deOrigen($request->query('origen'))
            ->deProveedor($request->query('proveedor_id'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            ->orderByDesc('id');
    }
}