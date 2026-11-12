<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\DevolucionRequest;
use App\Http\Requests\EntregaRequest;
use App\Http\Requests\VentaFiltroRequest;
use App\Http\Requests\VentaRequest;
use App\Impresion\ComprobanteVenta;
use App\Impresion\ListadoVentas;
use App\Models\Cliente;
use App\Models\Entrega;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Models\VentaLinea;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ventas y presupuestos.
 *
 * Métodos:
 *   index()             listado filtrado y paginado
 *   show()              la ficha: el documento completo, con sus importes congelados
 *   create() / store()  alta del presupuesto, cotizando contra el catálogo
 *   edit()  / update()  edición; las líneas que ya estaban conservan su precio
 *   recotizar()         trae los precios de hoy a todas las líneas e informa qué cambió
 *   entrega()           la pantalla de la entrega parcial, con lo pendiente de cada renglón
 *   entregar()          la mercadería sale del local, toda o en parte, y deja su comprobante
 *   entregaPdf()        el comprobante interno de una entrega; no es un remito RG 1415
 *   devolucion()        la pantalla de devolución, con lo pendiente de cada renglón
 *   devolver()          repone stock, revierte los pagos y deriva el estado (A-11)
 *   cancelar()          da de baja el presupuesto, antes de que exista cualquier efecto
 */



class VentaController extends Controller
{
    use ImprimeListados;

    public function __construct(private VentaService $service)
    {
    }

    public function index(VentaFiltroRequest $request): View
    {
        $clienteId  = $request->query('cliente_id');
        $vendedorId = $request->query('vendedor_id');

        $ventas = $this->filtradas($request)
            ->with(['cliente:id,razon_social', 'usuario:id,nombre,apellido'])
            ->withCount('lineas')
            ->withCount(['lineas as faltantes_count' => fn (Builder $linea) => $linea->superanElDisponible()])
            ->paginate(15)            
            ->withQueryString();     

        return view('ventas.index', [
            'ventas' => $ventas,
            'vendedores' => User::query()
                ->whereHas('ventas')
                ->orderBy('apellido')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'apellido']),

            'clienteFiltrado'  => $clienteId ? Cliente::find($clienteId) : null,
            'vendedorFiltrado' => $vendedorId ? User::find($vendedorId) : null,
        ]);
    }

        public function show(Venta $venta): View
    {
        return view('ventas.show', [
                'venta' => $venta->load([
                'cliente',
                'usuario:id,nombre,apellido',
                'lineas.producto:id,codigo,nombre,stock,stock_reservado',
                'lineas.impuestos',
                'pagos.usuario:id,nombre,apellido',
                'entregas' => fn ($entregas) => $entregas->withSum('lineas as unidades', 'cantidad'),
                'entregas.usuario:id,nombre,apellido',
            ]),
        ]);
    }

        public function create(): View
    {
        $lineas = $this->lineasDelFormulario();

        return view('ventas.form', [
            'venta'         => new Venta,
            'cliente'       => $this->clienteElegido(),
            'lineas'        => $lineas,
            'proximoIndice' => $this->proximoIndice($lineas),
            'productos'     => $this->productosDeLasLineas($lineas),
            'congelados'    => [],
            'hayProductos'  => Producto::where('activo', true)->exists(),
            'tope'          => $this->service->topeDeDescuento(request()->user()),
        ]);
    }

    public function store(VentaRequest $request): RedirectResponse
    {
        $venta = $this->service->crear($request->validated(), $request->user());
        $faltan = $this->avisoDeStock($venta);

        return redirect()->route('ventas.show', $venta)->with(
            $faltan === null ? 'exito' : 'aviso',
            "Presupuesto {$venta->numeroFormateado()} emitido por ".Importe::pesos($venta->total)
            .'. Todavía no descontó stock: eso pasa cuando se cobra.'
            .($faltan === null ? '' : ' '.$faltan),
        );
    }

    public function edit(Venta $venta): View|RedirectResponse
    {
        if (! $venta->esPresupuesto()) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y ya no se puede "
                .'modificar: lo que se cobró no se corrige editando, se devuelve.',
            );
        }

        $venta->load('lineas');

        $lineas = $this->lineasDelFormulario($venta);

        return view('ventas.form', [
            'venta'         => $venta,
            'cliente'       => $this->clienteElegido($venta),
            'lineas'        => $lineas,
            'proximoIndice' => $this->proximoIndice($lineas),
            'productos'     => $this->productosDeLasLineas($lineas),
            'congelados' => $venta->lineas
                ->mapWithKeys(fn (VentaLinea $linea) => [$linea->producto_id => $linea->precio_unitario])
                ->all(),
            'hayProductos' => Producto::where('activo', true)->exists(),
            'tope'         => $this->service->topeDeDescuento(request()->user()),
        ]);
    }

    public function update(VentaRequest $request, Venta $venta): RedirectResponse
    {
        $venta = $this->service->actualizar($venta, $request->validated(), $request->user());
        $faltan = $this->avisoDeStock($venta);

        return redirect()->route('ventas.show', $venta)->with(
            $faltan === null ? 'exito' : 'aviso',
            "Venta {$venta->numeroFormateado()} actualizada. Total: ".Importe::pesos($venta->total)
            .'. Las líneas que ya estaban conservan el precio con el que se cotizaron.'
            .($faltan === null ? '' : ' '.$faltan),
        );
    }

    /**
     * Trae los precios de hoy a todas las líneas.
     */
    public function recotizar(Venta $venta): RedirectResponse
    {
        $resultado = $this->service->recotizar($venta);

        $venta   = $resultado['venta'];
        $cambios = collect($resultado['cambios']);

        if ($cambios->isEmpty()) {
            return redirect()->route('ventas.show', $venta)->with(
                'exito',
                "Los precios no cambiaron: la venta {$venta->numeroFormateado()} sigue en "
                .Importe::pesos($venta->total).'.',
            );
        }

        $detalle = $cambios->take(3)
            ->map(fn (array $cambio) => "{$cambio['descripcion']} de "
                .Importe::pesos($cambio['precio_anterior']).' a '.Importe::pesos($cambio['precio_nuevo']))
            ->implode('; ');

        $resto = $cambios->count() - 3;

        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Venta {$venta->numeroFormateado()} recotizada: el total pasó de "
            .Importe::pesos($resultado['total_anterior']).' a '.Importe::pesos($venta->total)
            .". Cambió {$detalle}"
            .($resto > 0 ? ", y {$resto} producto(s) más." : '.'),
        );
    }

    /** La pantalla de la entrega parcial: cuántas unidades de cada renglón salen ahora. */
    public function entrega(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puede($venta->estado, 'entregada_parcial')) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no se "
                .'entrega en partes. Se entrega lo que se cobró y todavía no salió del local.',
            );
        }

        return view('ventas.entrega', [
            'venta' => $venta->load(['cliente:id,razon_social', 'lineas.producto:id,codigo']),
        ]);
    }

    /** La mercadería sale del local: todo lo pendiente, o lo que se cargó en la pantalla de entrega. */
    public function entregar(EntregaRequest $request, Venta $venta): RedirectResponse
    {
        $venta   = $this->service->entregar($venta, $request->user(), $request->validated()['cantidades'] ?? null);
        $entrega = $venta->entregas->last();

        $texto = $venta->estado === 'entregada'
            ? "Venta {$venta->numeroFormateado()} entregada por completo"
            : "Entrega parcial de la venta {$venta->numeroFormateado()}: quedan unidades por entregar";

        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "{$texto}. Comprobante {$entrega->numeroFormateado()}. No movió stock: eso pasó cuando se cobró.",
        );
    }

    /** El comprobante interno de una entrega; se regenera desde los datos y no se archiva. */
    public function entregaPdf(Venta $venta, Entrega $entrega): Response
    {
        $venta->load('cliente');
        $entrega->load(['usuario:id,nombre,apellido', 'lineas.ventaLinea.producto:id,codigo']);

        return Pdf::loadView('ventas.entrega-pdf', [
            'venta'     => $venta,
            'entrega'   => $entrega,
            'acumulado' => $entrega->acumuladoPorRenglon(),
        ])->download("entrega-{$entrega->numeroFormateado()}.pdf");
    }

    /**
     * La pantalla de devolución.
     */
    public function devolucion(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puedeDevolver($venta->estado)) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no "
                .'se devuelve. Se devuelve lo que se cobró: un presupuesto se cancela.',
            );
        }

        $venta->load([
            'cliente:id,razon_social',
            'lineas.producto:id,codigo,nombre',
            'pagos',
        ]);

        return view('ventas.devolucion', [
            'venta'    => $venta,
            'medios'   => Pago::METODOS_EN_USO,
            'sugerido' => $this->medioSugerido($venta),
        ]);
    }

    public function devolver(DevolucionRequest $request, Venta $venta): RedirectResponse
    {
        $venta = $this->service->devolver($venta, $request->validated(), $request->user());
        $ultimo    = $venta->pagos->sortByDesc('id')->first();
        $devuelto  = $ultimo && (float) $ultimo->monto < 0 ? abs((float) $ultimo->monto) : 0.0;

        $texto = $venta->estado === 'devuelta'
            ? "Devolución registrada: la venta {$venta->numeroFormateado()} quedó devuelta por completo."
            : "Devolución parcial registrada en la venta {$venta->numeroFormateado()}: todavía quedan "
                .'unidades sin devolver, y se pueden devolver más adelante.';

        $texto .= $devuelto > 0
            ? ' El stock volvió al depósito y se devolvieron '.Importe::pesos($devuelto).'.'
            : ' El stock volvió al depósito. No había pagos registrados, así que no hubo plata que devolver.';

        return redirect()->route('ventas.show', $venta)->with('exito', $texto);
    }

    public function cancelar(Venta $venta): RedirectResponse
    {
        $venta = $this->service->cancelar($venta);

        // El pedido de la tienda sí tenía un efecto: retenía stock, y cancelarlo lo devuelve al disponible.
        $texto = $venta->esOnline()
            ? "Pedido {$venta->numeroFormateado()} cancelado. Las unidades que tenía reservadas volvieron "
                .'al disponible. No se había cobrado, así que no hay plata que devolver. El pedido no se borra: queda registrado.'
            : "Presupuesto {$venta->numeroFormateado()} cancelado. No descontó stock ni generó "
                .'comprobante, así que no quedó nada que revertir. La venta no se borra: queda registrada.';

        return redirect()->route('ventas.show', $venta)->with('exito', $texto);
        }

    /**
     * El documento de la venta: presupuesto si no se cobró, comprobante si sí.
     */
    public function pdf(Venta $venta, ComprobanteVenta $comprobante): Response|RedirectResponse
    {
        if (! $venta->sePuedeImprimir()) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está cancelada, así que no hay documento que entregar.",
            );
        }

        return $comprobante->descarga($venta);
    }

    /** El listado filtrado, en PDF con el tope declarado o en planilla completa. */
    public function exportar(VentaFiltroRequest $request, ListadoVentas $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'ventas.index');
    }

    /**
     * El juego de filtros del listado, en un solo lugar.
     */
    private function filtradas(VentaFiltroRequest $request): Builder
    {
        return Venta::query()
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->deCliente($request->query('cliente_id'))
            ->deVendedor($request->query('vendedor_id'))
            ->deCanal($request->query('canal'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            ->orderByDesc('id');
    }


    /**
     * Las filas que el formulario tiene que rendir, en este orden de preferencia:
     * lo que el usuario mandó si hubo un error de validación, las líneas de la
     * venta si se está editando, y nada en el alta —el JS pone una fila vacía—.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineasDelFormulario(?Venta $venta = null): array
    {
        $lineas = old('lineas', $venta?->exists
            ? $venta->lineas
                ->map(fn (VentaLinea $linea) => [
                    'producto_id' => $linea->producto_id,
                    'cantidad'    => $linea->cantidad,
                ])
                ->all()
            : []);

        return is_array($lineas) ? array_filter($lineas, 'is_array') : [];
    }

    /**
     * Cuántos renglones superan el disponible al guardar, en una frase, o null.
     */
    private function avisoDeStock(Venta $venta): ?string
    {
        $cuantos = $venta->load('lineas.producto:id,stock,stock_reservado')
            ->faltantesDeStock()
            ->count();

        if ($cuantos === 0) {
            return null;
        }

        return $cuantos === 1
            ? 'Un renglón supera el disponible de hoy: se puede cotizar, pero no se va a poder cobrar así.'
            : "{$cuantos} renglones superan el disponible de hoy: se puede cotizar, pero no se va a poder cobrar así.";
    }

    /** El índice con el que el JS va a numerar la próxima fila. */
    private function proximoIndice(array $lineas): int
    {
        return $lineas === [] ? 0 : max(array_keys($lineas)) + 1;
    }

    /**
     * El cliente que el selector tiene que rendir ya elegido, si hay uno.
     */
    private function clienteElegido(?Venta $venta = null): ?Cliente
    {
        $id = old('cliente_id', $venta?->cliente_id);

        return ctype_digit((string) $id) ? Cliente::find((int) $id) : null;
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
            ->get(['id', 'codigo', 'nombre', 'precio_contado', 'stock', 'stock_reservado', 'activo'])
            ->keyBy('id');
    }

    /**
     * El medio que la pantalla propone para devolver la plata.
     *
     */
    private function medioSugerido(Venta $venta): string
    {
        $delCobro = $venta->pagos
            ->filter(fn (Pago $pago) => (float) $pago->monto > 0)
            ->sortByDesc(fn (Pago $pago) => (float) $pago->monto)
            ->first()?->metodo;

        return in_array($delCobro, Pago::METODOS_EN_USO, true)
            ? $delCobro
            : Pago::METODOS_EN_USO[0];
    }

}