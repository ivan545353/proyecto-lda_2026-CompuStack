<?php

namespace App\Console\Commands;

use App\Services\CarritoService;
use App\Services\IntentoPagoService;
use App\Services\VentaService;
use Illuminate\Console\Command;

/**
 * Suelta las reservas de la tienda que pasaron su plazo: los checkouts (E) y los pedidos sin
 * cobrar, que se cancelan (F, D-1). Antes resuelve lo que está en el aire (G): los intentos
 * pendientes vencidos se consultan una última vez y los aprobados sin acreditar se reintentan.
 */
class VencerReservasTienda extends Command
{
    protected $signature = 'tienda:vencer-reservas';

    protected $description = 'Resuelve los pagos en curso y libera el stock reservado por checkouts y pedidos vencidos';

    public function handle(IntentoPagoService $intentos, CarritoService $carritos, VentaService $ventas): int
    {
        // El orden importa: un pedido con un pago que entró no se cancela por haber mirado tarde.
        $resueltos   = $intentos->verificarVencidos();
        $acreditados = $intentos->reintentarAcreditaciones();
        $checkouts   = $carritos->soltarCheckoutsVencidos();
        $pedidos     = $ventas->cancelarVencidos();

        $this->info(
            "Intentos vencidos resueltos: {$resueltos}. Pagos acreditados: {$acreditados}. "
            ."Checkouts vencidos soltados: {$checkouts}. Pedidos vencidos cancelados: {$pedidos}."
        );

        return self::SUCCESS;
    }
}
