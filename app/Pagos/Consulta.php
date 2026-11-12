<?php

namespace App\Pagos;

use InvalidArgumentException;

/**
 * Lo que dice el proveedor de un intento, ya traducido. `importe` es lo que el proveedor cobró
 * (cadena decimal), para que el servicio lo compare con el del intento antes de acreditar.
 */
final class Consulta
{
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado'];

    public function __construct(
        public readonly string $estado,
        public readonly ?string $paymentId = null,
        public readonly ?string $detalle = null,
        public readonly ?string $importe = null,
        // false: el proveedor no contestó. Un intento así no vence: la plata pudo haber entrado.
        public readonly bool $respondida = true,
    ) {
        if (! in_array($estado, self::ESTADOS, true)) {
            throw new InvalidArgumentException("Una consulta no puede decir «{$estado}».");
        }
    }

    /** Sin respuesta del proveedor: el intento sigue como estaba (regla 16). */
    public static function sinRespuesta(?string $detalle = null): self
    {
        return new self('pendiente', detalle: $detalle, respondida: false);
    }
}
