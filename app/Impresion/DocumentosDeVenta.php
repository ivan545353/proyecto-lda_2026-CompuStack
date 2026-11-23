<?php

namespace App\Impresion;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ComprobanteTransferencia;
use App\Models\Entrega;
use App\Models\User;
use App\Models\Venta;
use App\Services\TransferenciaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los documentos de una venta que la administración baja por enlace firmado (tramo H, D-22): el
 * presupuesto o comprobante, el remito de una entrega y el comprobante de transferencia que cargó el
 * comprador. Molde `PedidoCompra`: la firma cubre quién lo pidió, y al abrirlo se vuelve a exigir cuenta
 * activa y el permiso, y que el documento sea de esa venta.
 *
 * No reusa la ruta de la tienda (`ComprobanteVenta::RUTA`): ésa exige lo cobrado y sólo un pedido online;
 * la administración imprime también el presupuesto (HL-129). El PDF sí es el mismo: `ComprobanteVenta::descarga()`.
 *
 * Métodos:
 *   enlaceDocumento() / descargaDocumento()        presupuesto o comprobante; cancelada, no hay (409)
 *   enlaceRemito() / descargaRemito()              el comprobante interno de una entrega (HL-130)
 *   enlaceTransferencia() / descargaTransferencia() el archivo que subió el comprador, en el navegador (HL-130)
 */
final class DocumentosDeVenta
{
    public const RUTA_DOCUMENTO     = 'api.v1.ventas.documento.pdf';
    public const RUTA_REMITO        = 'api.v1.ventas.remito.pdf';
    public const RUTA_TRANSFERENCIA = 'api.v1.ventas.transferencia.archivo';

    /** Quién pidió el enlace. La firma impide cambiarlo. */
    private const QUIEN = 'pedido_por';

    public function __construct(private ComprobanteVenta $comprobante)
    {
    }

    /** @throws ReglaDeNegocioException */
    public function enlaceDocumento(Venta $venta, User $usuario): array
    {
        $this->exigirImprimible($venta);

        return $this->enlace(self::RUTA_DOCUMENTO, ['venta' => $venta->id], $usuario);
    }

    public function descargaDocumento(Venta $venta, int|string|null $pedidoPor): Response
    {
        $this->exigirCuenta($pedidoPor, 'venta.ver');
        $this->exigirImprimible($venta);

        return $this->comprobante->descarga($venta);
    }

    public function enlaceRemito(Venta $venta, Entrega $entrega, User $usuario): array
    {
        abort_unless($entrega->venta_id === $venta->id, 404);

        return $this->enlace(self::RUTA_REMITO, ['venta' => $venta->id, 'entrega' => $entrega->id], $usuario);
    }

    /** Se regenera desde los datos y no se archiva. */
    public function descargaRemito(Venta $venta, Entrega $entrega, int|string|null $pedidoPor): Response
    {
        $this->exigirCuenta($pedidoPor, 'venta.ver');
        abort_unless($entrega->venta_id === $venta->id, 404);

        $venta->load('cliente');
        $entrega->load(['usuario:id,nombre,apellido', 'lineas.ventaLinea.producto:id,codigo']);

        return Pdf::loadView('ventas.entrega-pdf', [
            'venta'     => $venta,
            'entrega'   => $entrega,
            'acumulado' => $entrega->acumuladoPorRenglon(),
        ])->download("entrega-{$entrega->numeroFormateado()}.pdf");
    }

    public function enlaceTransferencia(Venta $venta, ComprobanteTransferencia $comprobante, User $usuario): array
    {
        abort_unless($comprobante->venta_id === $venta->id, 404);

        return $this->enlace(self::RUTA_TRANSFERENCIA, ['venta' => $venta->id, 'comprobante' => $comprobante->id], $usuario);
    }

    /** Lo abre en el navegador, con el nombre y el tipo con que se cargó. */
    public function descargaTransferencia(Venta $venta, ComprobanteTransferencia $comprobante, int|string|null $pedidoPor): StreamedResponse
    {
        $this->exigirCuenta($pedidoPor, 'venta.ver_comprobante_transferencia');
        abort_unless($comprobante->venta_id === $venta->id, 404);

        return Storage::disk(TransferenciaService::DISCO)->response(
            $comprobante->ruta,
            $comprobante->nombre_original,
            ['Content-Type' => $comprobante->tipo],
            'inline',
        );
    }

    /** @return array{url: string, vence: string} */
    private function enlace(string $ruta, array $parametros, User $usuario): array
    {
        $vence = now()->addMinutes((int) config('impresion.enlace_minutos'));

        return [
            'url'   => URL::temporarySignedRoute($ruta, $vence, [...$parametros, self::QUIEN => $usuario->id]),
            'vence' => $vence->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @throws AuthorizationException  la cuenta ya no está activa o perdió el permiso */
    private function exigirCuenta(int|string|null $pedidoPor, string $permiso): void
    {
        $usuario = User::find($pedidoPor);

        if ($usuario === null || ! $usuario->activo || ! $usuario->can($permiso)) {
            throw new AuthorizationException('Este enlace ya no sirve: pedí el documento de nuevo.');
        }
    }

    private function exigirImprimible(Venta $venta): void
    {
        if (! $venta->sePuedeImprimir()) {
            throw new ReglaDeNegocioException(
                "La venta {$venta->numeroFormateado()} está cancelada, así que no hay documento que entregar.",
            );
        }
    }
}
