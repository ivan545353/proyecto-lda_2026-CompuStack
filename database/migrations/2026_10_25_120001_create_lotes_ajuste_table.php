<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un ajuste masivo de precios: qué se hizo, sobre qué conjunto, quién y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_ajuste', function (Blueprint $table) {
            $table->id();

            // Null = lo corrió el sistema y no una persona, por ejemplo un ajuste
            // programado. Mismo criterio que `historial_precios` y el kardex.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('operacion', [
                'costo', 'precio', 'ganancia', 'recalculo', 'redondeo', 'deshacer',
            ]);

            // Cada operación usa uno de los tres y deja los otros en null: no hay
            // valor por omisión que después haya que interpretar. El porcentaje
            // admite negativo, que es una baja de precios.
            $table->decimal('porcentaje', 6, 3)->nullable();
            $table->decimal('ganancia', 6, 3)->nullable();
            $table->decimal('redondeo_multiplo', 10, 2)->nullable();

            $table->json('criterio');
            $table->string('criterio_texto', 255);

            // Los dos conteos del momento, que no se pueden derivar después: el
            // conjunto que hoy tiene 3.000 productos mañana tiene otros.
            $table->unsignedInteger('alcanzados');
            $table->unsignedInteger('omitidos');

            // RESTRICT y no cascade: un lote es un hecho y no se borra. Si alguna vez
            // se intentara, la base se niega antes de dejar colgada la reversión.
            $table->foreignId('revierte_a')->nullable()
                ->constrained('lotes_ajuste')->restrictOnDelete();

            // Sin updated_at: un hecho pasado no se edita. Si un lote se revierte, lo
            // que se escribe es otro lote.
            $table->timestamp('created_at')->nullable();

            // El listado se lee del más nuevo al más viejo, y «el último reversible»
            // es esa misma consulta con un filtro.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_ajuste');
    }
};