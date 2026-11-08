<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los factores que FORMAN el neto: flete, IIBB que el comercio absorbe, y los
 * que el negocio agregue. No se itemizan: van adentro del precio.
 *
 * `modo` es lo que hace que el motor no se equivoque solo. 'recargo' multiplica
 * por (1 + p); 'recupero' divide por (1 − p), que es como se recupera un
 * impuesto que se calcula sobre la venta: con 3 %, multiplicar por 1,03
 * recupera 2,91 %.
 *
 * Arranca vacía a propósito: el plan no precarga ninguno y el motor con la
 * tabla vacía da el mismo precio que hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factores_costo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            $table->enum('modo', ['recargo', 'recupero']);

            // Tres decimales: un flete de 2,375 % es un número que existe.
            $table->decimal('porcentaje', 6, 3);

            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factores_costo');
    }
};