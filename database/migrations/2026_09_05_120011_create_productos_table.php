<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->foreignId('marca_id')->nullable()->constrained('marcas');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();

            $table->string('codigo', 30)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->json('imagenes')->nullable();

            // Todo importe en decimal. El original tenía productos.precio en
            // float(12,2) y acumulaba error de redondeo (hallazgo A-17).
            $table->decimal('precio_lista', 12, 2);      // tarjeta / cuotas
            $table->decimal('precio_contado', 12, 2);    // efectivo / transferencia / QR
            $table->decimal('alicuota_iva', 4, 2)->default(21.00);
            $table->decimal('costo_promedio', 12, 2)->default(0);

            $table->integer('stock')->default(0);
            $table->integer('stock_reservado')->default(0);   // checkouts en curso (Etapa 2)
            $table->integer('stock_minimo')->default(0);
            $table->integer('cantidad_reposicion')->default(0);
            $table->unsignedInteger('peso_gramos')->nullable();   // null = usa el de la categoría

            $table->boolean('destacado')->default(false);
            $table->boolean('activo')->default(true);   // baja lógica
            $table->timestamps();

            $table->index(['activo', 'categoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
