<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El producto entra al motor de precios: su ganancia propia y el precio manual.
 *
 * **Qué pasa con los productos que ya existen.** Las dos columnas se agregan y
 * después un único UPDATE pone `precio_manual = true` en todas las filas
 * anteriores. Ningún precio cambia: los productos cargados se quedan con el que
 * tienen y quedan fuera del recálculo hasta que alguien los saque a mano. Los
 * que se creen después nacen con el default `false` y los calcula el motor.
 *
 * `ganancia` en null significa «heredá»: de la categoría si la define, y si no
 * de la ganancia global. No hay valor por omisión que interpretar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('ganancia', 6, 3)->nullable()->after('alicuota_iva');
            $table->boolean('precio_manual')->default(false)->after('ganancia');
        });

        $this->conservarLosPreciosExistentes();
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['ganancia', 'precio_manual']);
        });
    }

    /** Una sentencia para toda la tabla: nada de recorrer 20.011 modelos. */
    private function conservarLosPreciosExistentes(): void
    {
        DB::table('productos')->update(['precio_manual' => true]);
    }
};