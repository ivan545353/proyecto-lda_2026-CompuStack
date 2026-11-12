<?php

namespace App\Pagos;

/** Lo que devuelve una pasarela al abrir el cobro: adónde mandar al cliente, si hay adónde. */
final class Inicio
{
    public function __construct(
        public readonly ?string $url,
        public readonly string $referencia,
    ) {}
}
