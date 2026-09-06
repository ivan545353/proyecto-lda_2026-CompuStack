<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kardex. Cierra el hallazgo A-13: hoy el stock se pisa con un UPDATE y
        // no queda rastro de quién movió qué ni por qué.
        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos');

            $table->enum('tipo', [
                'venta', 'devolucion', 'compra', 'ajuste', 'reserva_liberada',
            ]);

            $table->integer('cantidad');            // con signo
            $table->integer('stock_resultante');

            // Polimórfico: apunta a una venta, una orden de compra o una nota de
            // crédito. Un par de columnas en lugar de tres FK nullables.
            $table->nullableMorphs('origen');

            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->timestamps();

            $table->index(['producto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_stock');
    }
};
