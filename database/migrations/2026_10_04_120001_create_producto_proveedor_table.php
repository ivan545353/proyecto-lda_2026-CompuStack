<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un producto se le puede comprar a varios proveedores, y a cada uno a un
        // precio distinto: es lo que permite elegir a quién pedirle.
        //
        // El modelo de datos decía "un producto, un proveedor" y era una decisión
        // explícita; cambió porque apareció el requerimiento de comparar precios,
        // que es la única razón por la que el modelo cerrado se abre.
        Schema::create('producto_proveedor', function (Blueprint $table) {
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();

            // Lo último que ESTE proveedor cobró por ESTE producto. Es el número
            // que se compara entre proveedores, y el que se sugiere al armar una
            // orden: `productos.costo_promedio` es el promedio de todos y no el
            // precio de ninguno.
            $table->decimal('costo_ultimo', 12, 2)->nullable();

            // El código con el que el PROVEEDOR identifica el producto. Va impreso
            // en el pedido: el proveedor busca por su código, no por el nuestro, y
            // es lo que evita que manden otra cosa.
            $table->string('codigo_proveedor', 40)->nullable();

            // A quién le pide la reposición automática. Misma invariante que
            // direcciones.es_predeterminada: si hay al menos un proveedor,
            // exactamente uno está marcado. La garantiza ProductoProveedorService.
            $table->boolean('es_preferido')->default(false);

            $table->timestamps();

            // Clave primaria compuesta, como en rol_permiso: no hace falta un id
            // propio porque el vínculo se identifica por sus dos extremos, y la URL
            // de la pantalla es /productos/{producto}/proveedores/{proveedor}.
            $table->primary(['producto_id', 'proveedor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_proveedor');
    }
};