<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El código con el que el proveedor identifica el producto, congelado en la
     * línea.
     *
     * **Por qué se congela.** El PDF del pedido lo imprime: el proveedor busca por
     * su código, no por el nuestro, y es lo que evita que manden otra cosa. Si el
     * documento lo leyera de `producto_proveedor`, el PDF regenerado de una orden
     * vieja cambiaría cada vez que el proveedor renumera su catálogo, y quedaría en
     * blanco si el vínculo se quitó. Eso rompe la decisión de **no archivar el
     * PDF**, que se sostiene en que regenerarlo da siempre el mismo documento.
     *
     * Es el mismo principio que `venta_lineas.precio_unitario` y que la dirección
     * copiada en `envios`: un documento copia lo que imprime, no lo referencia.
     *
     * **Por qué nullable.** Un vínculo puede no tener código cargado —no todos los
     * proveedores usan uno—, y las líneas escritas antes de esta columna no lo
     * tienen. Null significa «no consta», y el PDF lo omite.
     *
     * **Por qué no hay backfill.** Se podría completar desde la pivote, pero eso
     * escribiría en documentos ya emitidos un dato que en su momento no se guardó:
     * sería inventar qué decía un papel que ya salió. Las líneas viejas quedan en
     * null, que es la verdad.
     */
    public function up(): void
    {
        Schema::table('orden_compra_lineas', function (Blueprint $table) {
            $table->string('codigo_proveedor', 40)->nullable()->after('producto_id');
        });
    }

    public function down(): void
    {
        Schema::table('orden_compra_lineas', function (Blueprint $table) {
            $table->dropColumn('codigo_proveedor');
        });
    }
};