<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que el cliente intenta pagar por una pasarela (tramo G). No es `pagos`: un intento
 * rechazado o vencido no movió plata, y en `pagos` afirmaría un cobro que no ocurrió (regla 16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intentos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->enum('pasarela', ['simulador', 'mercadopago']);

            // `vencido`: el plazo pasó sin respuesta; `error`: no se pudo iniciar. Ninguno afirma un rechazo.
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado', 'vencido', 'error'])->default('pendiente');

            $table->decimal('importe', 12, 2);
            $table->uuid('referencia')->unique();   // la `external_reference` del proveedor
            $table->string('url_pago', 500)->nullable();
            $table->string('payment_id', 50)->nullable();
            $table->string('detalle', 100)->nullable();   // el `status_detail` del proveedor
            $table->timestamp('vence_at');
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamp('acreditado_at')->nullable();   // aprobado y todavía null: falta acreditar (§1.3 E)
            $table->timestamps();

            $table->index(['venta_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intentos_pago');
    }
};
