<?php

namespace App\Support;

use App\Exceptions\TransicionInvalidaException;
use App\Models\OrdenCompra;

/**
 * Transiciones permitidas de una orden de compra. **Lo que no está acá, no se puede.**
 *
 *
 * Decisiones que están en esta tabla y no en ningún otro lado:
 *
 *   - **`aprobada` puede recibir sin pasar por `enviada`.** La mercadería ya está
 *     en la puerta; bloquear la recepción porque alguien se olvidó de marcar el
 *     envío sería negarse a registrar un hecho. Que nunca se marcó queda igual en
 *     `fecha_envio`, que sigue en null.
 *
 *   - **`recibida_parcial` NO puede cancelarse.** Cancelar una orden donde ya
 *     entró mercadería es un estado que miente: la plata se gastó y las unidades
 *     están en el depósito. Ese caso se cierra con `recibida`, que significa
 *     «cerrada»; cuánto llegó de cada producto lo dicen las líneas, igual que la
 *     cantidad devuelta de una venta vive en su línea.
 *
 *   - **`recibida_parcial → recibida_parcial` está permitido**, porque una segunda
 *     entrega parcial deja la orden en el mismo estado y el servicio valida
 *     siempre el estado destino, sin casos especiales.
 */
final class MaquinaEstadosCompra
{
    /** @var array<string, array<int, string>> */
    private const TRANSICIONES = [
        'borrador'         => ['aprobada', 'cancelada'],
        'aprobada'         => ['enviada', 'recibida_parcial', 'recibida', 'cancelada'],
        'enviada'          => ['recibida_parcial', 'recibida', 'cancelada'],
        'recibida_parcial' => ['recibida_parcial', 'recibida'],
        'recibida'         => [],
        'cancelada'        => [],
    ];

    /** Estados desde los que se puede registrar una recepción. */
    public const RECIBEN = ['aprobada', 'enviada', 'recibida_parcial'];

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    /** @throws TransicionInvalidaException */
    public static function validar(string $desde, string $hacia): void
    {
        if (! self::puede($desde, $hacia)) {
            $textoDesde = OrdenCompra::ESTADOS[$desde] ?? $desde;
            $textoHacia = OrdenCompra::ESTADOS[$hacia] ?? $hacia;

            throw new TransicionInvalidaException(
                "Una orden «{$textoDesde}» no puede pasar a «{$textoHacia}»."
            );
        }
    }

    public static function puedeRecibir(string $estado): bool
    {
        return in_array($estado, self::RECIBEN, true);
    }

    /** Los destinos posibles desde un estado. Lo usa la vista para ofrecer sólo lo que existe. */
    public static function destinosDesde(string $estado): array
    {
        return self::TRANSICIONES[$estado] ?? [];
    }
}