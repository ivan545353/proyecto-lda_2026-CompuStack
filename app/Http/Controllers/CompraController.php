<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrdenCompraFiltroRequest;
use App\Http\Requests\OrdenCompraRequest;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
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

/**
 * Órdenes de compra. Módulo nuevo: el sistema original no tenía compras.
 *
 * Este controlador cubre el ciclo del borrador —listar, cargar, editar—. Las
 * acciones de estado (aprobar, enviar, recibir, cerrar, cancelar) tienen sus
 * propias rutas con su propio permiso.
 *
 * Métodos:
 *   index()             listado filtrado y paginado
 *   edit()  / update()  edición del borrador; sólo en borrador
 *   armar() / guardarPedido()  el armador: una pantalla, un borrador por proveedor
 *   edit()  / update()         edición de un borrador, un proveedor
 *   show()                     la ficha, con el historial de recepciones
 *   aprobar() / marcarEnviada() / cerrarIncompleta() / cancelar()
 *   recepcion() / recibir()    recepción total o parcial; ingresa stock por StockService
 *   pdf()                      el pedido formal, descargable; no cambia el estado
 */
class CompraController extends Controller
{
    public function __construct(private CompraService $service)
    {
    }

    public function index(OrdenCompraFiltroRequest $request): View
    {
        $ordenes = OrdenCompra::query()
            ->with('proveedor:id,razon_social')
            ->withCount('lineas')
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->deProveedor($request->query('proveedor_id'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            // El id es el número de orden, así que ordenar por id es ordenar de la
            // más nueva a la más vieja, y es un orden total: no hace falta desempate.
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('compras.index', [
            'ordenes'     => $ordenes,
            'proveedores' => Proveedor::orderBy('razon_social')->get(['id', 'razon_social']),
        ]);
    }

    public function edit(OrdenCompra $orden): View|RedirectResponse
    {
        // El listado sólo ofrece «Editar» en los borradores; esto es la red de
        // contención para una URL escrita a mano o guardada en favoritos. Se
        // redirige con un aviso en lugar de abortar: la orden existe, lo que no
        // corresponde es modificarla.
        if (! $orden->esEditable()) {
            return redirect()->route('compras.index')->with(
                'error',
                "La orden {$orden->numeroFormateado()} está «{$orden->estadoTexto()}» y ya no se puede modificar: "
                .'el monto quedó comprometido al aprobarla.'
            );
        }

        return view('compras.form', [
            'orden'       => $orden->load('lineas.producto'),
            'proveedores' => $this->proveedoresDisponibles(),
            'productos'   => $this->productosDisponibles($orden),
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
                // Las recepciones de esta orden, con su fecha y su usuario. Es el
                // historial que hace innecesaria una tabla de recepciones: el
                // kardex ya lo guarda.
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

        // El sistema no manda el pedido y el mensaje no finge que sí: la fecha
        // registra que una persona lo hizo llegar. Es la misma razón por la que el
        // canal de pedido es un dato y no un despachador.
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

    /**
     * Productos que se le pueden pedir a este proveedor.
     *
     * En la EDICIÓN, sólo los activos que ese proveedor provee
     */
    private function productosDisponibles(?OrdenCompra $orden = null)
    {
        $yaEnLaOrden = $orden?->exists
            ? $orden->lineas->pluck('producto_id')
            : collect();

        return Producto::query()
            ->where(function ($query) use ($orden) {
                $query->where('activo', true);

                if ($orden?->exists) {
                    $query->whereHas('proveedores', fn ($p) => $p->whereKey($orden->proveedor_id));
                }
            })
            ->orWhereIn('id', $yaEnLaOrden)
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'costo_promedio']);
    }

        public function armar(): View
    {
        // Sólo productos activos que se le puedan comprar a alguien. Un producto sin
        // proveedor activo no se puede pedir, y no se ofrece lo que el sistema va a
        // rechazar;
        $productos = Producto::query()
            ->where('activo', true)
            ->whereHas('proveedores', fn ($p) => $p->where('proveedores.activo', true))
            ->with(['proveedores' => fn ($p) => $p->where('proveedores.activo', true)])
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre']);

        return view('compras.pedido', [
            'productos'   => $productos,
            'proveedores' => $this->proveedoresDisponibles(),
            'mapa'        => $this->proveedoresPorProducto($productos),
        ]);
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
     * El mapa producto → proveedores que la pantalla usa para filtrar el selector de
     * cada línea y sugerir el costo.
     *
     * Ordenados como el usuario los quiere ver al elegir: el preferido primero,
     * después por costo, y los que no tienen costo cargado al final. Es el mismo
     * criterio que `Producto::proveedorParaReponer()`, para que la pantalla proponga
     * lo mismo que elegiría la reposición automática.
     */
    private function proveedoresPorProducto(Collection $productos): array
    {
        return $productos->mapWithKeys(fn (Producto $producto) => [
            $producto->id => $producto->proveedores
                ->map(fn (Proveedor $proveedor) => [
                    'id'        => $proveedor->id,
                    'nombre'    => $proveedor->razon_social,
                    'costo'     => $proveedor->pivot->costo_ultimo,
                    'preferido' => (bool) $proveedor->pivot->es_preferido,
                ])
                ->sortBy(fn (array $p) => [
                    $p['preferido'] ? 0 : 1,
                    $p['costo'] === null ? 1 : 0,
                    (float) ($p['costo'] ?? 0),
                ])
                ->values()
                ->all(),
        ])->all();
    }

    public function recepcion(OrdenCompra $orden): View|RedirectResponse
    {
        // La ficha ofrece el botón sólo cuando corresponde; esto es la red de
        // contención para una URL escrita a mano o guardada en favoritos. Se
        // redirige con un aviso en lugar de abortar: la orden existe, lo que no
        // corresponde es recibir contra ella.
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
        $cantidades = $request->validated()['cantidades'];

        // Se suma lo que el usuario declaró. Si el servicio rechaza —porque alguna
        // línea recibe más de lo pendiente— nada de esto se usa: la excepción se
        // traduce a un aviso en bootstrap/app.php y la transacción ya revirtió.
        $unidades = collect($cantidades)->sum(fn ($cantidad) => (int) $cantidad);

        $orden = $this->service->recibir($orden, $cantidades, $request->user());

        $pendientes = $orden->lineas->sum(fn (OrdenCompraLinea $linea) => $linea->cantidadPendiente());

        // El mensaje dice los tres efectos, porque ninguno es evidente desde el
        // botón: entró stock, cambió el costo promedio del producto, y quedó al día
        // el último costo de ese proveedor para la comparación de precios.
        return redirect()->route('compras.show', $orden)->with(
            'exito',
            $pendientes === 0
                ? "Se registraron {$unidades} unidad(es) y la orden quedó recibida completa. Se actualizó el "
                  .'stock, el costo promedio de cada producto y el último costo de este proveedor.'
                : "Se registraron {$unidades} unidad(es). Quedan {$pendientes} pendientes, así que la orden "
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
        // se puede repetir. Marcar enviada es una acción aparte, con su permiso, que
        // hace una persona después de que el pedido salió de verdad. El sistema no
        // afirma hechos del mundo que no puede verificar.
        return Pdf::loadView('compras.pdf', ['orden' => $orden])
            ->download("orden-compra-{$orden->numeroFormateado()}.pdf");
    }
}