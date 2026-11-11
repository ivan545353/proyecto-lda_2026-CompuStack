<?php

namespace App\Exceptions;

/**
 * La reserva del checkout venció antes de confirmar. La emite el tramo F; se declara acá para que
 * el contrato y el renderer nombren lo mismo desde el E.
 */
class CheckoutVencidoException extends ReglaDeNegocioException
{
    public function __construct()
    {
        parent::__construct('La reserva de tu carrito venció. Volvé a continuar desde el carrito.');
    }
}
