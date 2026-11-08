<?php

namespace App\Support;

use App\Exceptions\TransicionInvalidaException;
use App\Models\Venta;

/**
 * Transiciones permitidas de una venta. **Lo que no está acá, no se puede.**
 */
final class MaquinaEstadosVenta
{
    /** @var array<string, array<int, string>> */
    private const TRANSICIONES = [
        'presupuesto'      => ['pagada', 'cancelada'],
        'pendiente_pago'   => ['pagada', 'cancelada'],
        'pagada'           => ['en_preparacion', 'entregada_parcial', 'entregada', 'devuelta_parcial', 'devuelta'],
        'en_preparacion'   => ['despachada', 'lista_retiro'],
        'despachada'       => ['entregada'],
        'lista_retiro'     => ['entregada'],
        'entregada_parcial' => ['entregada_parcial', 'entregada', 'devuelta_parcial', 'devuelta'],
        'entregada'        => ['devuelta_parcial', 'devuelta'],
        'devuelta_parcial' => ['devuelta_parcial', 'devuelta'],
        'cancelada'        => [],
        'devuelta'         => [],
    ];

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    /** @throws TransicionInvalidaException */
    public static function validar(string $desde, string $hacia): void
    {
        if (! self::puede($desde, $hacia)) {
            $textoDesde = Venta::ESTADOS[$desde] ?? $desde;
            $textoHacia = Venta::ESTADOS[$hacia] ?? $hacia;

            throw new TransicionInvalidaException(
                "Una venta «{$textoDesde}» no puede pasar a «{$textoHacia}»."
            );
        }
    }

    /**
     * Los destinos posibles desde un estado.
     *
     * La usa la ficha para ofrecer sólo lo que existe, igual que en compras, y la
     * usa el test para verificar que la tabla no nombre ningún estado que el modelo
     * no conozca —un destino mal escrito permitiría una transición hacia un estado
     * que después ninguna pantalla sabe nombrar, y nada más fallaría—.
     *
     * @return array<int, string>
     */
    public static function destinosDesde(string $estado): array
    {
        return self::TRANSICIONES[$estado] ?? [];
    }

    /**
     * ¿Desde este estado se puede registrar una devolución?
     */
    public static function puedeDevolver(string $estado): bool
    {
        return self::puede($estado, 'devuelta_parcial') || self::puede($estado, 'devuelta');
    }

    
    /** ¿Desde este estado sale mercadería? Parcial o total se deriva después, como en la devolución. */
    public static function puedeEntregar(string $estado): bool
    {
        return self::puede($estado, 'entregada_parcial') || self::puede($estado, 'entregada');
    }
}