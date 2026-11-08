<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué precio tenía un producto, cuál pasó a tener, por qué y quién lo cambió.
 *
 * Es el invariante 1 aplicado al precio: no se pisa un número, se escribe el
 * hecho siguiente. Y es lo que hace posible deshacer un ajuste en el tramo D.
 *
 * El ENUM de `motivo` ya declara los dos valores del tramo D. Esta es la única
 * tabla nueva de la fase que va a crecer —una fila por cambio de precio sobre
 * 20.011 productos—, así que agregarle un valor más adelante reescribe una tabla
 * grande. Hoy está vacía y declararlos es gratis.
 *
 * `lote_id` NO se declara: la tabla de lotes es del tramo D y una clave foránea
 * nullable es un ADD COLUMN que no migra datos ni reescribe nada, por el mismo
 * criterio con el que `modelo-datos.md` dejó afuera las dimensiones del producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_precios', function (Blueprint $table) {
            $table->id();

            // En cascada, no RESTRICT: si fuera RESTRICT, un producto que alguna
            // vez cambió de precio no se podría borrar nunca y ProductoService
            // perdería la baja física. Lo que retiene a un producto siguen siendo
            // las ventas, las órdenes de compra y el kardex.
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            // Null = lo cambió el sistema, no una persona: una tarea programada
            // del tramo D, por ejemplo. Mismo criterio que movimientos_stock.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('precio_contado_anterior', 12, 2);
            $table->decimal('precio_contado_nuevo', 12, 2);
            $table->decimal('precio_lista_anterior', 12, 2);
            $table->decimal('precio_lista_nuevo', 12, 2);

            $table->enum('motivo', ['alta', 'recalculo', 'manual', 'ajuste_masivo', 'deshacer']);

            // Sin updated_at: un hecho pasado no se edita.
            $table->timestamp('created_at')->nullable();

            // El historial se lee siempre de un producto y del más nuevo al más
            // viejo. Es el mismo par que `movimientos_stock`.
            $table->index(['producto_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_precios');
    }
};