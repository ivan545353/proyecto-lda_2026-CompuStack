<?php

namespace App\Impresion;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * El documento de una venta, en un solo lugar: la web lo baja desde la ficha y la tienda por un
 * enlace firmado (D-22). El mismo PDF para los dos, sin costo ni vendedor de un pedido online (HL-1).
 *
 * Métodos:
 *   descarga()   el PDF: presupuesto si no se cobró, comprobante si sí
 *   enlace()     la URL firmada y temporal del comprobante de un pedido cobrado; si no, 409
 *   firmado()    el pedido que nombra un enlace ya verificado; lo no cobrado no existe (404)
 */
final class ComprobanteVenta
{
    /** El nombre de la ruta firmada (routes/api.php). */
    public const RUTA = 'api.v1.pedidos.comprobante.pdf';

    public function descarga(Venta $venta): Response
    {
        $venta->load(['cliente', 'usuario:id,nombre,apellido', 'lineas.producto:id,codigo']);

        $nombre = $venta->esPresupuesto() ? 'presupuesto' : 'comprobante';

        return Pdf::loadView('ventas.pdf', [
            'venta'       => $venta,
            'validoHasta' => $venta->created_at->copy()->addDays((int) config('venta.presupuesto.validez_dias')),
        ])->download("{$nombre}-{$venta->numeroFormateado()}.pdf");
    }

    /**
     * @return array{url: string, vence: string}
     *
     * @throws ReglaDeNegocioException
     */
    public function enlace(Venta $venta): array
    {
        if ($venta->situacionDelPago() !== 'pagado') {
            throw new ReglaDeNegocioException(
                "El pedido {$venta->numeroFormateado()} todavía no está pagado, así que no tiene comprobante.",
            );
        }

        $vence = now()->addMinutes((int) config('tienda.comprobante_minutos'));

        return [
            'url'   => URL::temporarySignedRoute(self::RUTA, $vence, ['pedido' => $venta->id]),
            'vence' => $vence->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** La firma ya la verificó el middleware `signed`: acá sólo se vuelve a exigir lo cobrado. */
    public function firmado(int $pedido): Venta
    {
        return Venta::query()
            ->where('ventas.canal', 'online')
            ->whereIn('ventas.estado', Venta::ESTADOS_VENDIDOS)
            ->findOrFail($pedido);
    }
}
