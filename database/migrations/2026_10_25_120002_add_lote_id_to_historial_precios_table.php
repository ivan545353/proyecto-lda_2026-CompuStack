<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `historial_precios.lote_id`: a qué ajuste masivo pertenece un cambio de precio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->foreignId('lote_id')->nullable()->after('usuario_id')
                ->constrained('lotes_ajuste')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->dropForeign(['lote_id']);
            $table->dropColumn('lote_id');
        });
    }
};