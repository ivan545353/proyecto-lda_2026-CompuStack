<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de precios pasa a registrar también el costo y la ganancia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->decimal('costo_anterior', 12, 2)->nullable()->after('precio_lista_nuevo');
            $table->decimal('costo_nuevo', 12, 2)->nullable()->after('costo_anterior');
            $table->decimal('ganancia_anterior', 6, 3)->nullable()->after('costo_nuevo');
            $table->decimal('ganancia_nuevo', 6, 3)->nullable()->after('ganancia_anterior');
        });
    }

    public function down(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->dropColumn(['costo_anterior', 'costo_nuevo', 'ganancia_anterior', 'ganancia_nuevo']);
        });
    }
};