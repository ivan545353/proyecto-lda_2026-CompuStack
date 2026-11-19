<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');

            // `abandonado` se declara sin uso: agregar un valor a un ENUM después reescribe la tabla.
            $table->enum('estado', ['activo', 'en_checkout', 'convertido', 'abandonado'])->default('activo');

            // Sólo en checkout: hasta cuándo vale la reserva (reservas.checkout_minutos).
            $table->timestamp('reservado_hasta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'estado']);           // el carrito vigente del usuario
            $table->index(['estado', 'reservado_hasta']);   // los checkouts vencidos, cada minuto
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carritos');
    }
};
