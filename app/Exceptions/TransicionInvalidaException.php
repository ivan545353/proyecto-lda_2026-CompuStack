<?php

namespace App\Exceptions;

/**
 * Una transición de estado que no está declarada.
 *
 * Extiende ReglaDeNegocioException, así hereda el único punto de traducción de
 * `bootstrap/app.php` y se puede capturar específicamente. La usan la máquina de
 * estados de compras y, en la Fase 6, la de ventas: el hallazgo C-9 es el mismo
 * defecto en los dos lados —el original aceptaba cualquier transición— y merece
 * una sola excepción.
 */
class TransicionInvalidaException extends ReglaDeNegocioException
{
}