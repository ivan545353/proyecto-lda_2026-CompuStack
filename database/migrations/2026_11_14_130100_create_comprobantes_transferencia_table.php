<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los comprobantes de transferencia que carga el comprador (D-27). Sólo suma filas: cargar otro no
 * pisa el anterior (regla 1), así que no hay `updated_at`. El archivo vive en el disco privado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_transferencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas');
            $table->string('ruta', 255);
            $table->string('nombre_original', 255);
            $table->string('tipo', 100);
            $table->unsignedInteger('tamano');
            // Con DEFAULT explícito: sin él, MariaDB le agrega ON UPDATE CURRENT_TIMESTAMP (HL-73).
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_transferencia');
    }
};
