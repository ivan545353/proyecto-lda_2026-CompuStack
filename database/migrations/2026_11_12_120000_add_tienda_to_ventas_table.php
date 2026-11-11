<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El pedido de la tienda es una venta más (tramo F): todo nullable, el mostrador no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // UNIQUE: un carrito da un solo pedido, aunque la confirmación llegue dos veces (D-8).
            $table->foreignId('carrito_id')->nullable()->unique()->constrained('carritos');

            // Null en el mostrador: allá el medio se elige al cobrar, no al pedir.
            $table->enum('medio_pago', ['contado', 'pasarela'])->nullable();

            // Hasta cuándo el pedido retiene su stock; null cuando la reserva ya se consumió o se liberó.
            $table->timestamp('reserva_vence')->nullable();

            // La dirección se copia, no se referencia: editarla después no cambia adónde se mandó.
            $table->string('entrega_calle', 150)->nullable();
            $table->string('entrega_numero', 10)->nullable();
            $table->string('entrega_piso_depto', 20)->nullable();
            $table->string('entrega_codigo_postal', 10)->nullable();
            $table->string('entrega_localidad', 100)->nullable();
            $table->string('entrega_provincia', 100)->nullable();

            $table->index(['estado', 'reserva_vence']);   // los pedidos vencidos, cada minuto
            $table->index('canal');                       // el filtro del listado
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['estado', 'reserva_vence']);
            $table->dropIndex(['canal']);
            $table->dropConstrainedForeignId('carrito_id');
            $table->dropColumn([
                'medio_pago', 'reserva_vence', 'entrega_calle', 'entrega_numero', 'entrega_piso_depto',
                'entrega_codigo_postal', 'entrega_localidad', 'entrega_provincia',
            ]);
        });
    }
};
