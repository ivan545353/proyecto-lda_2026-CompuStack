<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->index(['fecha', 'metodo', 'monto']);
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            // Por columnas y no por nombre: Laravel calcula el mismo nombre que usó
            // al crearlo, así que la vuelta atrás no depende de que acá se escriba
            // bien `pagos_fecha_metodo_monto_index`.
            $table->dropIndex(['fecha', 'metodo', 'monto']);
        });
    }
};