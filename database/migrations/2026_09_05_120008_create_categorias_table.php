<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();

            // Jerarquía por autorreferencia (Componentes > Almacenamiento > SSD).
            // Con dos o tres niveles no justifica un nested set.
            $table->foreignId('parent_id')->nullable()->constrained('categorias')->nullOnDelete();

            $table->string('nombre', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('orden')->default(0);

            // Zipnova cotiza con el peso de la categoría cuando el producto no
            // tiene el suyo (Etapa 2). La columna existe desde ahora.
            $table->unsignedInteger('peso_default_gramos')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
