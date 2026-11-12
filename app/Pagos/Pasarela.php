<?php

namespace App\Pagos;

use App\Models\IntentoPago;

/**
 * Lo que el sistema le pide a una pasarela de pago (tramo G). Ninguna de las dos operaciones
 * escribe en la base: el que guarda y acredita es `IntentoPagoService`.
 */
interface Pasarela
{
    /**
     * Abre el cobro del intento en el proveedor. Si no se pudo, lanza: el servicio deja el intento en `error`.
     *
     * @throws \App\Pagos\PasarelaNoDisponible
     */
    public function iniciar(IntentoPago $intento): Inicio;

    /** Pregunta al proveedor cómo está el intento. Sin respuesta, `pendiente`: no inventa un resultado. */
    public function consultar(IntentoPago $intento): Consulta;
}
