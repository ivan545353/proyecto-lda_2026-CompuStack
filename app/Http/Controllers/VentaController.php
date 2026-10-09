<?php

namespace App\Http\Controllers;

use App\Http\Requests\DevolucionRequest;
use App\Http\Requests\VentaFiltroRequest;
use App\Http\Requests\VentaRequest;
use App\Models\Cliente;
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

/**
 * Ventas y presupuestos.
 *
 * Es el módulo que más se reescribe respecto del original: `SaleController` resolvía
 * cobros y cambios de estado con un `updateEstado` que aceptaba cualquier transición
 * (C-9) y validaba el monto de un pago fuera de la transacción (C-10).
 *
 * El controlador traduce entre HTTP y `VentaService`. No valida —eso es
 * `VentaRequest`—, no decide reglas —eso es el servicio— y no arma consultas de
 * negocio: encadena los scopes del modelo.
 *
 * Métodos:
 *   index()             listado filtrado y paginado
 *   show()              la ficha: el documento completo, con sus importes congelados
 *   create() / store()  alta del presupuesto, cotizando contra el catálogo
 *   edit()  / update()  edición; las líneas que ya estaban conservan su precio
 *   recotizar()         trae los precios de hoy a todas las líneas e informa qué cambió
 *   entregar()          marca que la mercadería salió del local
 *   devolucion()        la pantalla de devolución, con lo pendiente de cada renglón
 *   devolver()          repone stock, revierte los pagos y deriva el estado (A-11)
 *   cancelar()          da de baja el presupuesto, antes de que exista cualquier efecto
 */



class VentaController extends Controller
{
        public function __construct(private VentaService $service)
    {
    }

    public function index(VentaFiltroRequest $request): View
    {
        $clienteId  = $request->query('cliente_id');
        $vendedorId = $request->query('vendedor_id');

        $ventas = Venta::query()
            // Las relaciones que la vista usa, con las columnas que necesita.
            ->with(['cliente:id,razon_social', 'usuario:id,nombre,apellido'])
            // El conteo lo hace la base (A-26): el panel original se traía las
            // tablas enteras al navegador para contarlas con .filter().
            ->withCount('lineas')
            ->buscar($request->query('q'))
            ->conEstado($request->query('estado'))
            ->deCliente($clienteId)
            ->deVendedor($vendedorId)
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            // El id es el número de venta, así que ordenar por id es ordenar de la
            // más nueva a la más vieja, y es un orden total: no hace falta desempate.
            ->orderByDesc('id')
            ->paginate(15)            // A-25: el original devolvía la tabla entera
            ->withQueryString();      // sin esto, la página 2 pierde los filtros

        return view('ventas.index', [
            'ventas' => $ventas,

            // Sólo quienes vendieron alguna vez. Ofrecer en el filtro a alguien sin
            // ventas es ofrecer una opción cuyo único resultado posible es vacío, que
            // es la misma heurística por la que no se muestra un botón que el sistema
            // va a rechazar.
            'vendedores' => User::query()
                ->whereHas('ventas')
                ->orderBy('apellido')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'apellido']),

            // Los dos filtros que llegan por enlace y no por el formulario: se
            // resuelven sólo si están presentes, para que el chip pueda nombrarlos
            // en lugar de mostrar un id.
            'clienteFiltrado'  => $clienteId ? Cliente::find($clienteId) : null,
            'vendedorFiltrado' => $vendedorId ? User::find($vendedorId) : null,
        ]);
    }

        public function show(Venta $venta): View
    {
        return view('ventas.show', [
            // Las relaciones que la ficha usa, con las columnas que necesita. El
            // cliente va completo porque la ficha muestra sus datos fiscales.
                'venta' => $venta->load([
                'cliente',
                'usuario:id,nombre,apellido',
                'lineas.producto:id,codigo,nombre',
                'pagos.usuario:id,nombre,apellido',
            ]),
        ]);
    }

    public function create(): View
    {
        $productos = $this->productosVendibles();

        return view('ventas.form', [
            // Una venta nueva, sin guardar: la vista la usa para `old()` y para
            // distinguir el alta de la edición con `$venta->exists`. El estado, el
            // canal y el modo de entrega ya vienen de `$attributes`, así que el
            // objeto dice lo mismo que va a decir la fila.
            'venta'     => new Venta,
            'clientes'  => $this->clientesDisponibles(),
            'productos' => $productos,
            'catalogo'  => $this->mapaDePrecios($productos),
            // En el alta no hay ningún precio congelado: todo se cotiza hoy.
            'congelados' => [],
            'tope'       => $this->service->topeDeDescuento(request()->user()),
        ]);
    }

    public function store(VentaRequest $request): RedirectResponse
    {
        $venta = $this->service->crear($request->validated(), $request->user());

        // El mensaje dice lo que pasó **y lo que no**: que no se descontó stock no es
        // evidente para quien acaba de emitir un presupuesto por medio millón de
        // pesos, y es la diferencia entre un presupuesto y una venta.
        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Presupuesto {$venta->numeroFormateado()} emitido por ".Importe::pesos($venta->total)
            .'. Todavía no descontó stock: eso pasa cuando se cobra.',
        );
    }

        public function edit(Venta $venta): View|RedirectResponse
    {
        // La ficha y el listado ofrecen «Editar» sólo en los presupuestos; esto es la
        // red de contención para una URL escrita a mano o guardada en favoritos. Se
        // redirige con un aviso en lugar de abortar: la venta existe, lo que no
        // corresponde es modificarla. El servicio lo vuelve a rechazar con una
        // excepción, que es la barrera que hereda la API de la Etapa 3.
        if (! $venta->esPresupuesto()) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y ya no se puede "
                .'modificar: lo que se cobró no se corrige editando, se devuelve.',
            );
        }

        $venta->load('lineas');

        $productos = $this->productosVendibles($venta);

        return view('ventas.form', [
            'venta'     => $venta,
            'clientes'  => $this->clientesDisponibles(),
            'productos' => $productos,
            'catalogo'  => $this->mapaDePrecios($productos),
            // Los precios congelados de esta venta, por producto. Es lo que hace que
            // la pantalla muestre lo mismo que va a guardar el servidor: un producto
            // que ya estaba conserva su precio. La clave es el producto y no la
            // línea, igual que en `VentaService::ajustarLineas()`.
            'congelados' => $venta->lineas
                ->mapWithKeys(fn (VentaLinea $linea) => [$linea->producto_id => $linea->precio_unitario])
                ->all(),
            'tope' => $this->service->topeDeDescuento(request()->user()),
        ]);
    }

    public function update(VentaRequest $request, Venta $venta): RedirectResponse
    {
        $venta = $this->service->actualizar($venta, $request->validated(), $request->user());

        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Venta {$venta->numeroFormateado()} actualizada. Total: ".Importe::pesos($venta->total)
            .'. Las líneas que ya estaban conservan el precio con el que se cotizaron.',
        );
    }

    /**
     * Trae los precios de hoy a todas las líneas.
     *
     * El mensaje es la mitad de la corrección de M-14: el defecto del original no era
     * recotizar, era recotizar sin que nadie lo pidiera ni se enterara. Acá lo pide
     * una persona y el resultado dice **qué cambió**, no sólo que se guardó.
     *
     * El detalle se corta en tres renglones: una venta de veinte líneas produciría un
     * aviso que nadie lee, y lo que importa no es el listado completo sino que el
     * total cambió y por qué. El documento tiene el detalle.
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

    /**
     * La mercadería salió del local.
     *
     * El mensaje dice lo que **no** pasó, que es lo que un botón llamado «entregar»
     * no deja claro: el stock ya salió del depósito cuando se cobró, y esta acción no
     * mueve ni una unidad. Sin eso, alguien podría creer que entregar descuenta —que
     * es exactamente el `presupuesto → cobrada` del original, descontando dos veces
     * o ninguna según el camino.
     */
    public function entregar(Venta $venta): RedirectResponse
    {
        $venta = $this->service->entregar($venta);

        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Venta {$venta->numeroFormateado()} marcada como entregada. No movió stock: eso pasó "
            .'cuando se cobró.',
        );
    }

        /**
     * La pantalla de devolución.
     *
     * Muestra cuánto se vendió, cuánto volvió ya y cuánto queda por volver de cada
     * renglón, que son los tres números que el operador necesita y que viven en
     * `cantidad` y `cantidad_devuelta`. La red de contención para una URL a mano es la
     * misma que en el cobro: se redirige con un aviso en lugar de abortar, porque la
     * venta existe y lo que no corresponde es devolverla.
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

        // El contra-asiento que acaba de escribirse, si hubo. Puede no haber: una
        // venta que llegó a `pagada` sin pagos registrados no tiene plata que
        // devolver, y entonces el mensaje lo dice en lugar de hablar de cero pesos.
        $ultimo    = $venta->pagos->sortByDesc('id')->first();
        $devuelto  = $ultimo && (float) $ultimo->monto < 0 ? abs((float) $ultimo->monto) : 0.0;

        // El mensaje dice el estado en el que quedó, y eso importa más que de
        // costumbre: el operador no lo eligió. Se deriva de cuánto quedó sin
        // devolver, así que si no se lo dice la pantalla no tiene de dónde saberlo.
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

        // El mensaje dice lo que NO pasó, que es lo que distingue cancelar un
        // presupuesto de anular una venta cobrada: no hay stock que reponer ni plata
        // que devolver, porque nunca hubo.
        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Presupuesto {$venta->numeroFormateado()} cancelado. No descontó stock ni generó "
            .'comprobante, así que no quedó nada que revertir. La venta no se borra: queda registrada.',
        );
    }

    /**
     * Los clientes que se pueden elegir.
     *
     * Todos: `clientes` no tiene columna `activo` y está decidido así —un cliente no
     * se elige de una lista corta, se busca, y esconderlo de un buscador es lo
     * contrario de lo que se necesita—. Se trae el documento además del nombre
     * porque dos clientes pueden llamarse igual y el documento es lo que los
     * distingue.
     *
     * Carga todas las opciones en el HTML, igual que el armador de pedido. Con el
     * padrón de la demostración son unos kilobytes; con miles de clientes hace falta
     * un buscador contra el servidor, y está anotado como deuda conocida del
     * frontend en el README.
     */
    private function clientesDisponibles(): Collection
    {
        return Cliente::query()
            ->orderBy('razon_social')
            ->get(['id', 'razon_social', 'tipo_doc', 'nro_doc']);
    }

        /**
     * El medio que la pantalla propone para devolver la plata.
     *
     * El del cobro más grande, que es el caso habitual. Es **una propuesta y no una
     * imposición**: el medio por el que vuelve la plata lo elige quien devuelve,
     * porque una venta cobrada por transferencia se puede devolver en efectivo de la
     * caja y escribir un negativo en transferencia afirmaría un hecho que no ocurrió.
     *
     * Sólo se propone si está entre los medios operativos: una venta cobrada por
     * Mercado Pago —que en la Etapa 2 va a existir— no puede proponer un medio que
     * esta pantalla no ofrece, porque el selector se abriría en una opción que no
     * tiene y guardar sin tocar nada elegiría otra. Es el mismo criterio que
     * `ProductoService::opcionesDeSelector()` con una referencia desactivada.
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

    /**
     * Los productos que se pueden vender.
     *
     * Sólo los activos, **más** los que la venta ya tiene aunque se hayan dado de
     * baja después: si no, dar de baja un producto dejaría sin poder editar los
     * presupuestos que lo mencionan, y el usuario no tendría ni cómo quitar ese
     * renglón. Es el mismo criterio que `VentaRequest::vendible()`, y los dos lugares
     * tienen que decir lo mismo o la pantalla ofrecería algo que el servidor rechaza.
     *
     * Se traen `stock` y `stock_reservado` porque la pantalla avisa cuando no hay
     * disponible suficiente. **Avisar no es impedir**: un presupuesto no compromete
     * stock y se puede cotizar lo que todavía no llegó; lo que no se va a poder es
     * cobrarlo hasta que haya unidades.
     */
    private function productosVendibles(?Venta $venta = null): Collection
    {
        $yaEnLaVenta = $venta?->exists
            ? $venta->lineas()->pluck('producto_id')
            : collect();

        return Producto::query()
            ->where('activo', true)
            ->orWhereIn('id', $yaEnLaVenta)
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'precio_contado', 'alicuota_iva', 'stock', 'stock_reservado', 'activo']);
    }

    /**
     * Precio y disponible de cada producto, para la vista previa de la pantalla.
     *
     * Va como JSON y no en atributos `data-` de cada `<option>`: ahí tendría que
     * repetirse en cada fila que se agregue. Es el mismo recurso que el mapa
     * producto → proveedores del armador de pedido, y tiene su misma deuda anotada
     * en el README: con miles de productos el JSON pesa, y la salida es un endpoint
     * que devuelva el precio de un producto cuando se lo elige.
     *
     * El disponible es `stock - stock_reservado`, la misma definición que usan
     * `Producto::scopeConStock()`, la reposición automática y
     * `StockService::descontar()`. Si la pantalla usara otra, el sistema diría dos
     * cosas distintas del mismo producto.
     *
     * @param  Collection<int, Producto>  $productos
     * @return array<int, array{precio: string, disponible: int}>
     */
    private function mapaDePrecios(Collection $productos): array
    {
        return $productos->mapWithKeys(fn (Producto $producto) => [
            $producto->id => [
                'precio'     => $producto->precio_contado,
                'disponible' => $producto->stock - $producto->stock_reservado,
            ],
        ])->all();
    }
}