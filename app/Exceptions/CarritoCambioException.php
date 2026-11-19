<?php

namespace App\Exceptions;

/**
 * La revalidación del carrito encontró cambios (precio, producto que salió de la tienda). Lleva
 * `cambios` con la forma del plan §6.5; el renderer la traduce a 409 `carrito_cambio`.
 */
class CarritoCambioException extends ReglaDeNegocioException
{
    /** @param  list<array<string, mixed>>  $cambios */
    public function __construct(public readonly array $cambios)
    {
        parent::__construct('Algunos productos de tu carrito cambiaron. Revisalos antes de seguir.');
    }
}
