<?php

namespace App\Pagos;

use App\Models\IntentoPago;

/**
 * El simulador no cobra (D-13): no hay URL ni proveedor. El resultado lo pone quien prueba, con
 * `IntentoPagoService::resolverSimulado()`, y la consulta devuelve lo que ya está guardado.
 */
final class PasarelaSimulador implements Pasarela
{
    public function iniciar(IntentoPago $intento): Inicio
    {
        return new Inicio(null, $intento->referencia);
    }

    public function consultar(IntentoPago $intento): Consulta
    {
        // `vencido` y `error` no se consultan; si llegaran, no hay nada nuevo que decir.
        if (! in_array($intento->estado, Consulta::ESTADOS, true)) {
            return Consulta::sinRespuesta();
        }

        return new Consulta($intento->estado, importe: (string) $intento->importe);
    }
}
