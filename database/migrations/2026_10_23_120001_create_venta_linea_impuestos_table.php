<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El desglose de impuestos de cada renglón, congelado.
 *
 * `venta_lineas` guarda un solo `iva`, que alcanzaba cuando el IVA era el único
 * impuesto. Con N impuestos hace falta una fila por cada uno, con su nombre y su
 * alícuota COPIADOS: es la misma razón por la que la línea copia la descripción
 * del producto y el costo del día. Un comprobante reimpreso el año que viene
 * tiene que decir lo que se cobró entonces, aunque el impuesto se haya borrado.
 *
 * No toca ningún dato: las ventas que ya existen no tienen filas acá y siguen
 * leyéndose por `venta_lineas.iva`, que sigue estando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_linea_impuestos', function (Blueprint $table) {
            $table->id();

            // En cascada: el desglose es parte del renglón. Quitar un producto de
            // un presupuesto borra su línea, y su desglose se va con ella.
            $table->foreignId('venta_linea_id')->constrained('venta_lineas')->cascadeOnDelete();

            // Copiados, no referenciados. Si mañana el impuesto se borra o cambia
            // de alícuota, este renglón sigue diciendo lo que dijo.
            $table->string('nombre', 60);
            $table->decimal('alicuota', 4, 2);
            $table->decimal('importe', 12, 2);
            $table->enum('comprobante', ['iva', 'otros_nacionales', 'no_se_muestra']);

            $table->unsignedSmallInteger('orden')->default(0);

            // Sin updated_at: un renglón emitido no se edita.
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_linea_impuestos');
    }
};