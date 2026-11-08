<?php

namespace App\Exceptions;

/**
 * No hay stock disponible suficiente para la operación.
 *
 * Extiende ReglaDeNegocioException y no Exception: hereda el único punto de
 * traducción de `bootstrap/app.php` —así ningún controlador necesita try/catch—
 * y a la vez se puede capturar específicamente, que es lo que la Fase 6 necesita
 * para distinguir «no hay stock» de «esa transición de estado no corresponde».
 */
class StockInsuficienteException extends ReglaDeNegocioException
{
}