<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El pago que acredita un intento lo nombra. UNIQUE: un intento aprobado da, como mucho, un
 * pago (HL-2). `mp_payment_id` no alcanza: el simulador no tiene identificador de Mercado Pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('intento_pago_id')->nullable()->unique()->constrained('intentos_pago');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intento_pago_id');
        });
    }
};
