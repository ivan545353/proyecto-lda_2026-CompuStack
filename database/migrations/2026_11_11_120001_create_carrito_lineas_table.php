<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrito_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrito_id')->constrained('carritos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->unsignedInteger('cantidad');

            // Referencias, no precios de venta (D-11): sólo sirven para avisar que el precio cambió.
            $table->decimal('contado_referencia', 12, 2)->nullable();
            $table->decimal('lista_referencia', 12, 2)->nullable();

            $table->timestamps();

            // Un producto, un renglón: agregarlo de nuevo suma cantidad.
            $table->unique(['carrito_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrito_lineas');
    }
};
