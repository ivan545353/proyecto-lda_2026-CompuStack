<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los impuestos que se aplican SOBRE el neto y se itemizan en el comprobante.
 *
 * `alcance` distingue las dos formas de llegar a un producto: 'producto' son las
 * alícuotas de IVA, de las que cada producto elige una; 'general' son los que
 * alcanzan a todo el catálogo. `comprobante` es la clasificación de la Ley 27.743,
 * que pide separar IVA contenido de otros impuestos nacionales indirectos.
 *
 * No toca ningún dato existente: crea una tabla nueva y la precarga.
 */
return new class extends Migration
{
    /**
     * Las alícuotas de IVA vigentes. Son las tres que el sistema ya admitía más
     * 27 %, que pide el plan de la fase.
     */
    private const ALICUOTAS = [
        ['nombre' => 'IVA 21 %',   'alicuota' => '21.00', 'orden' => 10],
        ['nombre' => 'IVA 10,5 %', 'alicuota' => '10.50', 'orden' => 20],
        ['nombre' => 'IVA 27 %',   'alicuota' => '27.00', 'orden' => 30],
        ['nombre' => 'Exento',     'alicuota' => '0.00',  'orden' => 40],
    ];

    public function up(): void
    {
        Schema::create('impuestos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();

            // Mismo tipo que `productos.alicuota_iva`: así el catálogo no puede
            // ofrecer un valor que la columna del producto no pueda guardar.
            $table->decimal('alicuota', 4, 2);

            $table->enum('alcance', ['producto', 'general']);
            $table->enum('comprobante', ['iva', 'otros_nacionales', 'no_se_muestra']);

            // Fija el orden del desglose. No cambia el resultado —el producto de
            // los factores es conmutativo— pero sí el orden en que se lee.
            $table->unsignedSmallInteger('orden')->default(0);

            $table->boolean('activo')->default(true);   // baja lógica
            $table->timestamps();
        });

        $this->precargar();
    }

    public function down(): void
    {
        Schema::dropIfExists('impuestos');
    }

    /** Deja el catálogo usable con un `migrate`, que no corre seeders. */
    private function precargar(): void
    {
        $ahora = now();

        DB::table('impuestos')->insert(array_map(fn (array $alicuota) => [
            ...$alicuota,
            'alcance'     => 'producto',
            'comprobante' => 'iva',
            'activo'      => true,
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ], self::ALICUOTAS));
    }
};