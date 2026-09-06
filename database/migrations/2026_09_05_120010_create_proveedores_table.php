<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('razon_social', 150);
            $table->string('cuit', 15)->unique();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('contacto', 100)->nullable();

            // Desacopla el proceso interno del canal de entrega del pedido.
            // Una columna en lugar de un subsistema de login para proveedores.
            $table->enum('canal_pedido', ['email', 'portal_externo', 'manual'])->default('manual');
            $table->string('portal_url', 255)->nullable();   // sólo si canal = portal_externo

            $table->unsignedSmallInteger('plazo_entrega_dias')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
