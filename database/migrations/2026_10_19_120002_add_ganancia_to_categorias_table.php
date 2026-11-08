<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El nivel del medio de la cascada: global → categoría → producto.
 *
 * No toca ningún dato. Todas las categorías quedan en null, que es «heredá de
 * la global», y es exactamente lo que el sistema hacía hasta ahora, cuando la
 * ganancia no existía en ninguna parte.
 *
 * Un cable y una notebook no se venden con el mismo multiplicador, y la
 * categoría es donde esa diferencia ya está expresada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->decimal('ganancia', 6, 3)->nullable()->after('peso_default_gramos');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('ganancia');
        });
    }
};