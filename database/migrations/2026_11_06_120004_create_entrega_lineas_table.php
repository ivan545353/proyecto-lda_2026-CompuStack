<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué renglones y cuántas unidades salieron en cada entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrega_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id')->constrained('entregas');
            $table->foreignId('venta_linea_id')->constrained('venta_lineas');
            $table->unsignedInteger('cantidad');

            $table->unique(['entrega_id', 'venta_linea_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrega_lineas');
    }
};