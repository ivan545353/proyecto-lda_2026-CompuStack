<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El remito del proveedor, en el asiento de la recepción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->string('remito_numero', 30)->nullable();
            $table->date('remito_fecha')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_stock', function (Blueprint $table) {
            $table->dropColumn(['remito_numero', 'remito_fecha']);
        });
    }
};