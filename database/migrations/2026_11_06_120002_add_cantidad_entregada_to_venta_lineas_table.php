<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto se entregó de cada renglón: el saldo que habilita la entrega en dos veces.
 *
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_lineas', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_entregada')->nullable();
        });

        DB::statement('ALTER TABLE venta_lineas ALTER COLUMN cantidad_entregada SET DEFAULT 0');
    }

    public function down(): void
    {
        Schema::table('venta_lineas', function (Blueprint $table) {
            $table->dropColumn('cantidad_entregada');
        });
    }
};