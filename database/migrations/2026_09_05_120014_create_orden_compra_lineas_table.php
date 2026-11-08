<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');

            $table->unsignedInteger('cantidad_pedida');

            // cantidad_recibida < cantidad_pedida es la recepción parcial. El
            // historial de cada recepción está en movimientos_stock.
            $table->unsignedInteger('cantidad_recibida')->default(0);

            $table->decimal('costo_unitario', 12, 2);
            $table->timestamps();

            $table->unique(['orden_compra_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_compra_lineas');
    }
};
