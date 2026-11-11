<?php

namespace App\Console\Commands;

use App\Services\CarritoService;
use App\Services\VentaService;
use Illuminate\Console\Command;

/**
 * Suelta las reservas de la tienda que pasaron su plazo: los checkouts (E) y los pedidos sin
 * cobrar, que se cancelan (F, D-1). El G excluye los que tienen un intento de pago pendiente.
 */
class VencerReservasTienda extends Command
{
    protected $signature = 'tienda:vencer-reservas';

    protected $description = 'Libera el stock reservado por checkouts y pedidos de la tienda que vencieron';

    public function handle(CarritoService $carritos, VentaService $ventas): int
    {
        $checkouts = $carritos->soltarCheckoutsVencidos();
        $pedidos   = $ventas->cancelarVencidos();

        $this->info("Checkouts vencidos soltados: {$checkouts}. Pedidos vencidos cancelados: {$pedidos}.");

        return self::SUCCESS;
    }
}
