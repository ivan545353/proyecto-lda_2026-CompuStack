<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega `simulador` a `pagos.metodo` (tramo G, D-13).
 *
 * Al final del ENUM y no en el medio: con la tabla cargada, agregar al final es lo único que
 * MariaDB hace sin reescribir cada fila. `MODIFY COLUMN` a mano, como la migración de
 * `entregada_parcial`, para que la definición completa (`NOT NULL`, sin `DEFAULT`) quede a la vista.
 */
return new class extends Migration
{
    private const CINCO = ['efectivo', 'transferencia', 'qr', 'mercadopago', 'simulador'];

    private const CUATRO = ['efectivo', 'transferencia', 'qr', 'mercadopago'];

    public function up(): void
    {
        $this->declarar(self::CINCO);
    }

    // Con pagos del simulador escritos, volver atrás los dejaría sin método: no se hace.
    public function down(): void
    {
        if (DB::table('pagos')->where('metodo', 'simulador')->exists()) {
            throw new \RuntimeException('Hay pagos del simulador: quitar el valor los dejaría sin método.');
        }

        $this->declarar(self::CUATRO);
    }

    /** @param  array<int, string>  $valores */
    private function declarar(array $valores): void
    {
        $lista = collect($valores)->map(fn (string $valor) => "'{$valor}'")->implode(', ');

        DB::statement("ALTER TABLE pagos MODIFY COLUMN metodo ENUM({$lista}) NOT NULL");
    }
};
