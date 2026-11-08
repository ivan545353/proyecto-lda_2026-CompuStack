<?php

namespace App\Console\Commands;

use Database\Seeders\VolumenSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Carga los datos a escala para poder medir el sistema.
 *
 */
class CargarDatosVolumen extends Command
{
    protected $signature = 'datos:volumen
        {--productos=   : Cuántos productos cargar (por omisión 20.000)}
        {--proveedores= : Cuántos proveedores cargar (por omisión 300)}
        {--clientes=    : Cuántos clientes cargar (por omisión 5.000)}
        {--compras=     : Cuántas órdenes de compra cargar (por omisión 2.000)}
        {--ventas=      : Cuántas ventas cargar (por omisión 10.000)}';

    protected $description = 'Carga datos a escala para medir el sistema. No es la demostración: no corre en producción ni en migrate:fresh --seed';

    public function handle(): int
    {
        try {
            $cantidades = $this->cantidades();
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Se van a cargar %s productos, %s proveedores, %s clientes, %s órdenes de compra '
            .'y %s ventas, con sus líneas, cobros y asientos de kardex.',
            number_format($cantidades['productos'], 0, ',', '.'),
            number_format($cantidades['proveedores'], 0, ',', '.'),
            number_format($cantidades['clientes'], 0, ',', '.'),
            number_format($cantidades['compras'], 0, ',', '.'),
            number_format($cantidades['ventas'], 0, ',', '.'),
        ));


        if (! $this->confirm('¿Sigo?', true)) {
            $this->components->warn('No se cargó nada.');

            return self::SUCCESS;
        }

        $arranque = microtime(true);

        try {
            app(VolumenSeeder::class)->setCommand($this)->run($cantidades);
        } catch (RuntimeException $error) {

            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Datos de volumen cargados en %s segundos, con %s MB de memoria pico.',
            number_format(microtime(true) - $arranque, 1, ',', '.'),
            number_format(memory_get_peak_usage(true) / 1024 / 1024, 0, ',', '.'),
        ));

        $this->line('El próximo paso es medir: php artisan escala:medir');

        return self::SUCCESS;
    }

    /**
     * Las cantidades, con las del plan como omisión.
     *
     * **Rechaza, no corrige** (M-31): `--productos=0` o `--productos=muchos` no se
     * convierten en el valor por omisión en silencio, se informan. Es la misma regla
     * que siguen los Form Requests del sistema, y vale igual acá: un operador que
     * escribió mal una cantidad tiene que enterarse, no recibir otra cosa.
     *
     * @return array<string, int>
     */
    private function cantidades(): array
    {
        $cantidades = VolumenSeeder::CANTIDADES;

        foreach (array_keys($cantidades) as $clave) {
            $valor = $this->option($clave);

            if ($valor === null) {
                continue;
            }

            if (! ctype_digit((string) $valor) || (int) $valor < 1) {
                throw new RuntimeException(
                    "--{$clave} tiene que ser un número entero mayor que cero, y llegó «{$valor}»."
                );
            }

            $cantidades[$clave] = (int) $valor;
        }

        return $cantidades;
    }
}