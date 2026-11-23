<?php

namespace App\Impresion;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\OrdenCompra;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * El pedido formal de una orden de compra, en PDF (tramo G, HL-126). Se baja por enlace firmado (D-22),
 * como un listado: la firma cubre quién lo pidió, y al abrirlo se vuelve a exigir cuenta activa y
 * `compra.ver`, porque el papel lleva costos. Descargarlo no cambia el estado ni marca nada como enviado.
 *
 * Métodos:
 *   enlace()    la URL firmada y temporal; una orden sin aprobar no tiene pedido (409)
 *   descarga()  el PDF, después de volver a mirar la cuenta y la orden
 */
final class PedidoCompra
{
    /** El nombre de la ruta firmada (routes/api.php). */
    public const RUTA = 'api.v1.compras.pedido.pdf';

    /** Quién pidió el enlace. La firma impide cambiarlo. */
    private const QUIEN = 'pedido_por';

    /**
     * @return array{url: string, vence: string}
     *
     * @throws ReglaDeNegocioException
     */
    public function enlace(OrdenCompra $orden, User $usuario): array
    {
        $this->exigirAprobada($orden);

        $vence = now()->addMinutes((int) config('impresion.enlace_minutos'));

        return [
            'url'   => URL::temporarySignedRoute(self::RUTA, $vence, ['orden' => $orden->id, self::QUIEN => $usuario->id]),
            'vence' => $vence->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @throws AuthorizationException  la cuenta ya no está activa o perdió el permiso */
    public function descarga(OrdenCompra $orden, int|string|null $pedidoPor): Response
    {
        $usuario = User::find($pedidoPor);

        if ($usuario === null || ! $usuario->activo || ! $usuario->can('compra.ver')) {
            throw new AuthorizationException('Este enlace ya no sirve: pedí el PDF de nuevo.');
        }

        $this->exigirAprobada($orden);

        $orden->load(['proveedor', 'lineas.producto:id,codigo,nombre', 'usuarioAprobo:id,nombre,apellido']);

        return Pdf::loadView('compras.pdf', ['orden' => $orden])
            ->download("orden-compra-{$orden->numeroFormateado()}.pdf");
    }

    private function exigirAprobada(OrdenCompra $orden): void
    {
        if (! $orden->sePuedeImprimir()) {
            throw new ReglaDeNegocioException(
                "La orden {$orden->numeroFormateado()} todavía no se aprobó. El pedido se imprime cuando "
                .'alguien autoriza el gasto, no antes.',
            );
        }
    }
}
