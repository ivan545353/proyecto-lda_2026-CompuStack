<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saca el `ON UPDATE CURRENT_TIMESTAMP` implícito de las dos fechas de hecho (HL-73).
 *
 * En MariaDB con `explicit_defaults_for_timestamp` apagado, el primer `TIMESTAMP NOT NULL` sin
 * `DEFAULT` de una tabla recibe en silencio `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`:
 *
 *   - `intentos_pago.vence_at`: guardar la `url_pago` le pisaba el vencimiento con la hora actual,
 *     y el intento de Mercado Pago nacía vencido.
 *   - `pagos.fecha` (Etapa 1): la fecha en que entró la plata. Hoy ningún camino actualiza un pago,
 *     pero el primer `UPDATE` la cambiaría por la de la escritura, y el documento imprime ésa (M-16).
 *
 * Un `DEFAULT` explícito apaga el `ON UPDATE`. Los valores guardados no cambian; las dos columnas
 * las siguen escribiendo siempre los servicios, campo por campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intentos_pago', function (Blueprint $table) {
            $table->timestamp('vence_at')->useCurrent()->change();
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->timestamp('fecha')->useCurrent()->change();
        });
    }

    // La vuelta atrás no repone el `ON UPDATE`: sería volver a poner el defecto.
    public function down(): void
    {
    }
};
