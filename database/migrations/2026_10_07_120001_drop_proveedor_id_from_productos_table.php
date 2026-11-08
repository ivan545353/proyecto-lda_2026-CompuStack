<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El *contract* del parallel change: se va la columna que decía
     * «un producto, un proveedor».
     *
     * Para cuando esta migración corre, nadie escribe la columna —el formulario de
     * producto perdió el selector, `ProductoRequest` su regla, el modelo su campo
     * asignable, `ProductoService::atributos()` su línea, y la factory y el seeder
     * dejaron de cargarla— y nadie la lee: `Proveedor::productos()` pasa por la
     * pivote desde el paso 3.
     *
     * No hay backfill que hacer acá. Lo hizo el paso 1, manteniendo las dos
     * estructuras en sincronía mientras convivían; esta migración sólo tira lo que
     * quedó vacío. Es la parte del expand/contract que suele saltearse, y
     * saltearla deja dos fuentes para la misma pregunta: tarde o temprano alguien
     * escribe la que ya no manda.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // La clave foránea se borra ANTES que la columna. MariaDB no permite
            // eliminar una columna que todavía participa de una restricción, y el
            // error que devuelve no nombra la clave: pide adivinar.
            $table->dropForeign(['proveedor_id']);
            $table->dropColumn('proveedor_id');
        });
    }

    /**
     * El rollback devuelve la columna, no los datos.
     *
     * Vuelve el esquema a como estaba —nullable, con su clave foránea y en su
     * posición—, pero los valores no se reconstruyen. Se podrían derivar de
     * `producto_proveedor` tomando el proveedor preferido de cada producto, y eso
     * es una migración de datos, no de esquema. Queda escrito en lugar de dar a
     * entender que `migrate:rollback` deja todo como estaba.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->nullable()->after('marca_id')
                ->constrained('proveedores')->nullOnDelete();
        });
    }
};