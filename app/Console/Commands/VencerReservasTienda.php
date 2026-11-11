<?php

namespace App\Console\Commands;

use App\Services\CarritoService;
use Illuminate\Console\Command;

/**
 * Suelta las reservas de la tienda que pasaron su plazo. En el tramo E, los checkouts; el F y el
 * G le suman los pedidos de contado y de pasarela vencidos.
 */
class VencerReservasTienda extends Command
{
    protected $signature = 'tienda:vencer-reservas';

    protected $description = 'Libera el stock reservado por checkouts de la tienda que vencieron';

    public function handle(CarritoService $carritos): int
    {
        $checkouts = $carritos->soltarCheckoutsVencidos();

        $this->info("Checkouts vencidos soltados: {$checkouts}.");

        return self::SUCCESS;
    }
}
