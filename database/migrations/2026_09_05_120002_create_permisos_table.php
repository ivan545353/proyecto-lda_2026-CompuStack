<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();

            // Acción nombrada: 'venta.anular', 'compra.aprobar'. Reemplaza a las
            // cuatro banderas CRUD por (perfil, modulo) del sistema original,
            // que no podían expresar acciones fuera del CRUD (hallazgos C-2, M-20).
            $table->string('clave', 60)->unique();

            $table->string('modulo', 40);   // agrupador para la pantalla de asignación
            $table->string('descripcion', 150);
            $table->timestamps();

            $table->index('modulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos');
    }
};
