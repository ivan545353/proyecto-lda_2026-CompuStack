<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los números únicos del comercio: la ganancia de la que cuelga la cascada, el
 * recargo del precio de lista y la política de redondeo.
 *
 * Son columnas y no filas de `factores_costo` por una razón concreta: el plan
 * pide que la ganancia no se pueda borrar, sólo editar, y una columna no se
 * borra desde ninguna pantalla. La restricción CHECK hace lo mismo con la fila:
 * la tabla tiene una y no puede tener dos. Es la diferencia con `venta_numeracion`
 * del sistema viejo (A-18), que era una tabla de una fila sin clave primaria y sin
 * nada que impidiera la segunda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('politica_precios', function (Blueprint $table) {
            // Sin autoincremento: la fila es la 1 y no hay otra.
            $table->unsignedTinyInteger('id')->primary();

            // Multiplicador sobre el costo, como piensa el mostrador: 2,5 es
            // markup del 150 % y margen del 60 %.
            $table->decimal('ganancia_global', 6, 3);

            // El costo financiero de vender en cuotas, que es lo que distingue
            // `precio_lista` de `precio_contado`.
            $table->decimal('recargo_lista', 6, 3);

            $table->decimal('redondeo_multiplo', 10, 2);
            $table->enum('redondeo_modo', ['cercano', 'arriba', 'abajo']);

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE politica_precios ADD CONSTRAINT politica_precios_fila_unica CHECK (id = 1)'
        );

        $this->precargar();
    }

    public function down(): void
    {
        // La restricción se va con la tabla: no hay que soltarla aparte.
        Schema::dropIfExists('politica_precios');
    }

    /**
     * Valores neutros, no inventados: ganancia 1 es vender al costo y recargo 0
     * es lista igual a contado, así que ningún precio sale de acá. Los carga la
     * persona en la pantalla de factores.
     */
    private function precargar(): void
    {
        DB::table('politica_precios')->insert([
            'id'                => 1,
            'ganancia_global'   => '1.000',
            'recargo_lista'     => '0.000',
            // 0,01 es la identidad: redondear al centavo no mueve nada.
            'redondeo_multiplo' => '0.01',
            'redondeo_modo'     => 'cercano',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
};