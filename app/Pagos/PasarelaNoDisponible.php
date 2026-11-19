<?php

namespace App\Pagos;

use RuntimeException;

/** La pasarela no pudo abrir el cobro (red, credencial, respuesta inválida). El intento queda en `error`. */
class PasarelaNoDisponible extends RuntimeException {}
