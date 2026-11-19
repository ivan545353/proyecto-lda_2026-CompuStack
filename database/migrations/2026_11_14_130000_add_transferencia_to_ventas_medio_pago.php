<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La transferencia como tercer medio de la tienda (D-27, HL-90). Agregar un valor al ENUM no toca
 * filas: los pedidos de contado y de pasarela siguen como estaban.
 */
return new class extends Migration
{
    private const TRES = ['contado', 'transferencia', 'pasarela'];

    private const DOS = ['contado', 'pasarela'];

    public function up(): void
    {
        $this->declarar(self::TRES);
    }

    // Con pedidos por transferencia escritos, volver atrás los dejaría sin medio: no se hace.
    public function down(): void
    {
        if (DB::table('ventas')->where('medio_pago', 'transferencia')->exists()) {
            throw new \RuntimeException('Hay pedidos por transferencia: quitar el valor los dejaría sin medio.');
        }

        $this->declarar(self::DOS);
    }

    /** @param  array<int, string>  $valores */
    private function declarar(array $valores): void
    {
        $lista = collect($valores)->map(fn (string $valor) => "'{$valor}'")->implode(', ');

        DB::statement("ALTER TABLE ventas MODIFY COLUMN medio_pago ENUM({$lista}) NULL");
    }
};
