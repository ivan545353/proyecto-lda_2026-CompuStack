<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Direcciones habituales del cliente. La dirección de un envío no se
        // referencia desde acá: se copia, para que editarla no reescriba
        // adónde se mandó un paquete el año pasado (Etapa 2).
        Schema::create('direcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('calle', 150);
            $table->string('numero', 10);
            $table->string('piso_depto', 20)->nullable();
            $table->string('codigo_postal', 10);
            $table->string('localidad', 100);
            $table->string('provincia', 100);
            $table->boolean('es_predeterminada')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direcciones');
    }
};
