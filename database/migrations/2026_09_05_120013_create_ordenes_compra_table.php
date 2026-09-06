<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');

            // Nace en borrador: una compra que se dispara y se envía sola es un
            // compromiso de plata sin supervisión.
            $table->enum('estado', [
                'borrador', 'aprobada', 'enviada', 'recibida_parcial', 'recibida', 'cancelada',
            ])->default('borrador');

            $table->decimal('total_estimado', 12, 2)->default(0);

            $table->foreignId('usuario_creo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('usuario_aprobo_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->timestamp('fecha_envio')->nullable();
            $table->string('observaciones', 255)->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra');
    }
};
