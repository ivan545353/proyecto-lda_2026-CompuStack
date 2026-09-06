<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->unique();
            $table->string('descripcion', 150)->nullable();

            // Decide a qué mitad del sistema entra el usuario. No se deduce del
            // nombre del rol: renombrarlo rompería el sistema en silencio.
            $table->enum('ambito', ['gestion', 'tienda']);

            // Protege los roles base del borrado desde el módulo de roles.
            $table->boolean('es_sistema')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void 
    {
        Schema::dropIfExists('roles');
    }
};
