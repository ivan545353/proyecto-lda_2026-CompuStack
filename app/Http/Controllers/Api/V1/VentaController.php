<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CobroVentaRequest;
use App\Http\Requests\Api\DevolucionRequest;
use App\Http\Requests\Api\EntregaRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Requests\Api\VentaFiltroRequest;
use App\Http\Requests\Api\VentaRequest;
use App\Http\Resources\Api\VentaFichaResource;
use App\Http\Resources\Api\VentaResource;
use App\Impresion\DocumentosDeVenta;
use App\Impresion\ImpresionFirmada;
use App\Impresion\ListadoVentas;
use App\Models\ComprobanteTransferencia;
use App\Models\Entrega;
use App\Models\User;
use App\Models\Venta;
use App\Services\PagoService;
use App\Services\VentaService;
use App\Support\Importe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las ventas de la administración (tramo H): traduce entre el contrato y VentaService. El permiso lo pide
 * la ruta. Los pedidos del cliente siguen en `/pedidos`: el mismo modelo, otro alcance y otro permiso.
 *
 * Métodos:
 *   index()       listado filtrado y paginado, de la más nueva a la más vieja, con la insignia de stock
 *   vendedores()  quienes cargaron ventas, para el filtro del listado (HL-125)
 *   show()        la ficha: renglones, entregas, pagos, comprobantes y qué acciones admite su estado
 *   store()       el presupuesto (201); avisa si un renglón supera el disponible, sin impedirlo
 *   update()      la edición del presupuesto; cobrado, ya no (409)
 *   recotizar()   los precios de hoy en todas las líneas, y qué cambió
 *   cancelar()    el presupuesto o el pedido, sin borrarlo; el pedido libera su reserva
 *   cobrar()      el cobro (201) por PagoService: el stock sale acá (regla 5)
 *   entregar()    todo lo pendiente o las cantidades elegidas (201): no mueve stock
 *   devolver()    la devolución (201): repone stock y escribe el pago en contra (M-16)
 *   impresion()   el enlace firmado del listado, en PDF o CSV (D-22)
 *   pdf() / remito() / transferencia()  el enlace firmado de cada documento (D-22, HL-129, HL-130)
 *   descargarPdf() / descargarRemito() / descargarTransferencia()  el archivo, por ese enlace
 *
 * Cada escritura devuelve la ficha y el mensaje de la web. El tono (verde o azul) lo decide la pantalla
 * con `sin_stock` de la ficha: el contrato no tiene niveles de mensaje y no se agregan por esto.
 */
class VentaController extends Controller
{
    public function __construct(private VentaService $service)
    {
    }

    public function index(VentaFiltroRequest $request, ListadoVentas $listado): JsonResponse
    {
        $pagina = $listado->consulta($request->validated())
            ->with(['cliente:id,razon_social', 'usuario:id,nombre,apellido'])
            ->withCount('lineas')
            ->withCount(['lineas as faltantes_count' => fn (Builder $linea) => $linea->superanElDisponible()])
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, VentaResource::class, $request->criterios());
    }

    public function vendedores(PaginadoRequest $request): JsonResponse
    {
        $pagina = User::query()
            ->whereHas('ventas')
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'nombre', 'apellido'], 'pagina', $request->pagina())
            ->through(fn (User $usuario) => ['id' => $usuario->id, 'nombre' => $usuario->nombre_completo]);

        return Paginado::respuesta($pagina, JsonResource::class, []);
    }

    public function impresion(VentaFiltroRequest $request, ImpresionFirmada $impresion): JsonResponse
    {
        return Recurso::respuesta(new JsonResource(
            $impresion->enlace('ventas', $request->validated(), $request->query(), $request->user()),
        ));
    }

    public function pdf(Request $request, Venta $venta, DocumentosDeVenta $documentos): JsonResponse
    {
        return Recurso::respuesta(new JsonResource($documentos->enlaceDocumento($venta, $request->user())));
    }

    public function descargarPdf(Request $request, Venta $venta, DocumentosDeVenta $documentos): Response
    {
        return $documentos->descargaDocumento($venta, $request->query('pedido_por'));
    }

    public function remito(Request $request, Venta $venta, Entrega $entrega, DocumentosDeVenta $documentos): JsonResponse
    {
        return Recurso::respuesta(new JsonResource($documentos->enlaceRemito($venta, $entrega, $request->user())));
    }

    public function descargarRemito(Request $request, Venta $venta, Entrega $entrega, DocumentosDeVenta $documentos): Response
    {
        return $documentos->descargaRemito($venta, $entrega, $request->query('pedido_por'));
    }

    public function transferencia(Request $request, Venta $venta, ComprobanteTransferencia $comprobante, DocumentosDeVenta $documentos): JsonResponse
    {
        return Recurso::respuesta(new JsonResource($documentos->enlaceTransferencia($venta, $comprobante, $request->user())));
    }

    public function descargarTransferencia(Request $request, Venta $venta, ComprobanteTransferencia $comprobante, DocumentosDeVenta $documentos): StreamedResponse
    {
        return $documentos->descargaTransferencia($venta, $comprobante, $request->query('pedido_por'));
    }

    public function show(Venta $venta): JsonResponse
    {
        return Recurso::respuesta(new VentaFichaResource($this->conFicha($venta)));
    }

    public function store(VentaRequest $request): JsonResponse
    {
        $venta  = $this->service->crear($request->validated(), $request->user());
        $faltan = $this->avisoDeStock($venta);

        return $this->ficha($venta, "Presupuesto {$venta->numeroFormateado()} emitido por ".Importe::pesos($venta->total)
            .'. Todavía no descontó stock: eso pasa cuando se cobra.'
            .($faltan === null ? '' : ' '.$faltan), 201);
    }

    public function update(VentaRequest $request, Venta $venta): JsonResponse
    {
        $venta  = $this->service->actualizar($venta, $request->validated(), $request->user());
        $faltan = $this->avisoDeStock($venta);

        return $this->ficha($venta, "Venta {$venta->numeroFormateado()} actualizada. Total: ".Importe::pesos($venta->total)
            .'. Las líneas que ya estaban conservan el precio con el que se cotizaron.'
            .($faltan === null ? '' : ' '.$faltan));
    }

    public function recotizar(Venta $venta): JsonResponse
    {
        $resultado = $this->service->recotizar($venta);
        $venta     = $resultado['venta'];
        $cambios   = collect($resultado['cambios']);

        if ($cambios->isEmpty()) {
            return $this->ficha($venta, "Los precios no cambiaron: la venta {$venta->numeroFormateado()} sigue en "
                .Importe::pesos($venta->total).'.');
        }

        $detalle = $cambios->take(3)
            ->map(fn (array $cambio) => "{$cambio['descripcion']} de "
                .Importe::pesos($cambio['precio_anterior']).' a '.Importe::pesos($cambio['precio_nuevo']))
            ->implode('; ');

        $resto = $cambios->count() - 3;

        return $this->ficha($venta, "Venta {$venta->numeroFormateado()} recotizada: el total pasó de "
            .Importe::pesos($resultado['total_anterior']).' a '.Importe::pesos($venta->total)
            .". Cambió {$detalle}"
            .($resto > 0 ? ", y {$resto} producto(s) más." : '.'));
    }

    public function cancelar(Venta $venta): JsonResponse
    {
        $venta = $this->service->cancelar($venta);

        // El pedido de la tienda sí tenía un efecto: retenía stock, y cancelarlo lo devuelve al disponible.
        return $this->ficha($venta, $venta->esOnline()
            ? "Pedido {$venta->numeroFormateado()} cancelado. Las unidades que tenía reservadas volvieron "
                .'al disponible. No se había cobrado, así que no hay plata que devolver. El pedido no se borra: queda registrado.'
            : "Presupuesto {$venta->numeroFormateado()} cancelado. No descontó stock ni generó "
                .'comprobante, así que no quedó nada que revertir. La venta no se borra: queda registrada.');
    }

    public function cobrar(CobroVentaRequest $request, Venta $venta, PagoService $pagos): JsonResponse
    {
        $venta = $pagos->cobrar($venta, $request->validated(), $request->user());

        return $this->ficha($venta, "Venta {$venta->numeroFormateado()} cobrada por ".Importe::pesos($venta->pagado())
            .". Se descontó el stock de {$venta->lineas->count()} producto(s) y quedó registrado en el kardex.", 201);
    }

    public function entregar(EntregaRequest $request, Venta $venta): JsonResponse
    {
        $venta   = $this->service->entregar($venta, $request->user(), $request->validated()['cantidades'] ?? null);
        $entrega = $venta->entregas->last();

        $texto = $venta->estado === 'entregada'
            ? "Venta {$venta->numeroFormateado()} entregada por completo"
            : "Entrega parcial de la venta {$venta->numeroFormateado()}: quedan unidades por entregar";

        return $this->ficha($venta, "{$texto}. Comprobante {$entrega->numeroFormateado()}. No movió stock: eso pasó cuando se cobró.", 201);
    }

    public function devolver(DevolucionRequest $request, Venta $venta): JsonResponse
    {
        $venta    = $this->service->devolver($venta, $request->validated(), $request->user());
        $ultimo   = $venta->pagos->sortByDesc('id')->first();
        $devuelto = $ultimo && (float) $ultimo->monto < 0 ? abs((float) $ultimo->monto) : 0.0;

        $texto = $venta->estado === 'devuelta'
            ? "Devolución registrada: la venta {$venta->numeroFormateado()} quedó devuelta por completo."
            : "Devolución parcial registrada en la venta {$venta->numeroFormateado()}: todavía quedan "
                .'unidades sin devolver, y se pueden devolver más adelante.';

        $texto .= $devuelto > 0
            ? ' El stock volvió al depósito y se devolvieron '.Importe::pesos($devuelto).'.'
            : ' El stock volvió al depósito. No había pagos registrados, así que no hubo plata que devolver.';

        return $this->ficha($venta, $texto, 201);
    }

    private function ficha(Venta $venta, string $mensaje, int $estado = 200): JsonResponse
    {
        return Recurso::respuesta(new VentaFichaResource($this->conFicha($venta)), $estado, $mensaje);
    }

    /** Cuántos renglones superan el disponible al guardar, en una frase, o null. */
    private function avisoDeStock(Venta $venta): ?string
    {
        $cuantos = $venta->load('lineas.producto:id,stock,stock_reservado')->faltantesDeStock()->count();

        return match (true) {
            $cuantos === 0 => null,
            $cuantos === 1 => 'Un renglón supera el disponible de hoy: se puede cotizar, pero no se va a poder cobrar así.',
            default        => "{$cuantos} renglones superan el disponible de hoy: se puede cotizar, pero no se va a poder cobrar así.",
        };
    }

    /** Lo que la ficha lee, en una tanda (A-26). */
    private function conFicha(Venta $venta): Venta
    {
        return $venta->load([
            'cliente',
            'usuario:id,nombre,apellido',
            'lineas' => fn ($lineas) => $lineas->orderBy('id'),
            'lineas.producto:id,codigo,nombre,precio_contado,stock,stock_reservado,activo',
            'lineas.impuestos',
            'pagos.usuario:id,nombre,apellido',
            'entregas' => fn ($entregas) => $entregas->orderBy('id')->withSum('lineas as unidades', 'cantidad'),
            'entregas.usuario:id,nombre,apellido',
            'comprobantesTransferencia' => fn ($comprobantes) => $comprobantes->orderBy('id'),
        ]);
    }
}
