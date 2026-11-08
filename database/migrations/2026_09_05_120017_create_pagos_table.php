<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->enum('metodo', ['efectivo', 'transferencia', 'qr', 'mercadopago']);
            $table->decimal('monto', 12, 2);

            // Columnas de Mercado Pago desde ahora, nulas hasta la Etapa 2.
            // mp_payment_id con índice único es el mecanismo de idempotencia
            // frente a los reintentos de webhook (hallazgo C-10).
            $table->decimal('comision', 12, 2)->nullable();
            $table->decimal('neto_acreditado', 12, 2)->nullable();
            $table->unsignedTinyInteger('cuotas')->nullable();
            $table->string('mp_payment_id', 50)->nullable()->unique();
            $table->string('mp_status', 30)->nullable();

            $table->foreignId('usuario_id')->nullable()->constrained('users');   // el cajero que cobró
            $table->timestamp('fecha');
            $table->timestamps();

            $table->index(['venta_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
