<?php

namespace App\Http\Controllers;

use App\Http\Requests\PagoRequest;
use App\Models\Pago;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Services\PagoService;
use App\Support\Importe;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * El cobro de una venta.
 *
 * Es la mitad de `SaleController` que la trazabilidad dejó anotada como pendiente:
 * el original resolvía el cobro con un `cobrar` que caía en el fallback `can_update`
 * del middleware (C-2) y validaba el monto fuera de la transacción (C-10).
 *
 * El controlador traduce entre HTTP y `PagoService`. No valida —eso es
 * `PagoRequest`—, no decide reglas —eso es el servicio— y **no vuelve a preguntar el
 * saldo para compararlo**: eso pasa dentro del lock, en el servicio, y es el
 * hallazgo entero.
 *
 * Métodos:
 *   create()  la pantalla de cobro, con el saldo y los avisos de lo que va a fallar
 *   store()   registra el cobro y pasa la venta a pagada
 */
class PagoController extends Controller
{
    public function __construct(private PagoService $service)
    {
    }

    /**
     * La pantalla de cobro.
     *
     * La red de contención para una URL escrita a mano o guardada en favoritos: la
     * ficha ofrece el botón sólo cuando la transición existe, y acá se redirige con
     * un aviso en lugar de abortar, porque la venta existe y lo que no corresponde es
     * cobrarla. El servicio lo vuelve a rechazar, que es la barrera que hereda la API
     * de la Etapa 3.
     *
     * La pregunta se le hace a `MaquinaEstadosVenta` y no se compara contra
     * `'presupuesto'` escrito a mano, por lo mismo que en el servicio: la respuesta
     * sale de la única fuente que la tiene.
     */
    public function create(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puede($venta->estado, 'pagada')) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no "
                .'se cobra. Una venta se cobra una sola vez.',
            );
        }

        $venta->load([
            'cliente:id,razon_social',
            // El stock y el disponible de cada producto, para poder avisar antes de
            // que el cobro falle. `activo` también: un producto dado de baja hace
            // fallar el pase a `pagada`.
            'lineas.producto:id,codigo,nombre,stock,stock_reservado,activo',
        ]);

        return view('ventas.cobro', [
            'venta'     => $venta,
            'saldo'     => $venta->saldo(),
            'medios'    => Pago::METODOS_EN_USO,
            'problemas' => $this->problemasQueVanAFallar($venta),
        ]);
    }

    public function store(PagoRequest $request, Venta $venta): RedirectResponse
    {
        $venta = $this->service->cobrar($venta, $request->validated(), $request->user());

        // El mensaje dice las dos cosas que pasaron, porque el cobro es la operación
        // que convierte un presupuesto en una venta: entró la plata **y** salió la
        // mercadería. Que el stock se descontó no es evidente para quien acaba de
        // tocar un botón que dice «cobrar».
        return redirect()->route('ventas.show', $venta)->with(
            'exito',
            "Venta {$venta->numeroFormateado()} cobrada por ".Importe::pesos($venta->pagado())
            .". Se descontó el stock de {$venta->lineas->count()} producto(s) y quedó registrado "
            .'en el kardex.',
        );
    }

    /**
     * Los renglones que van a hacer fallar el cobro, en palabras.
     *
     * La pantalla lo dice **antes** de que el operador cargue los montos, que es lo
     * que la hace usable: las dos cosas que rechazan el pase a `pagada` son un
     * producto dado de baja y un disponible que no alcanza, y las dos se arreglan en
     * otra pantalla —el catálogo o el depósito—. Enterarse después de tipear el monto
     * es enterarse tarde.
     *
     * **Avisa y no impide**, y el botón de cobrar queda igual. No es una excepción a
     * «no ofrecer lo que el sistema va a negar»: el disponible puede cambiar entre
     * que esta pantalla se dibuja y que el operador envía —justo llegó la mercadería
     * de una orden de compra—, y deshabilitar el botón lo obligaría a recargar para
     * descubrirlo. El servidor es el que decide, y lo decide con la fila bloqueada.
     *
     * El disponible es `stock - stock_reservado`, la misma definición que usan
     * `StockService::descontar()`, `Producto::scopeConStock()` y la reposición
     * automática. Si acá fuera otra, la pantalla avisaría de un problema que el
     * servidor no tiene, o al revés.
     *
     * @return array<int, string>
     */
    private function problemasQueVanAFallar(Venta $venta): array
    {
        return $venta->lineas
            ->map(function (VentaLinea $linea) {
                $producto = $linea->producto;

                if (! $producto->activo) {
                    return "«{$linea->descripcion}» está dado de baja en el catálogo. Hay que volver a "
                        .'activarlo, o quitar el renglón del presupuesto.';
                }

                $disponible = $producto->stock - $producto->stock_reservado;

                if ($disponible < $linea->cantidad) {
                    return "«{$linea->descripcion}»: se venden {$linea->cantidad} unidad(es) y hay "
                        ."{$disponible} disponible(s).";
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }
}