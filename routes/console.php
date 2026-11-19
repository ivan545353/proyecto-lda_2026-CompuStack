<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| El sistema original no tenía nada parecido: la reposición se hacía mirando el
| listado de productos a ojo.
|
| A las siete de la mañana, antes de que abra el negocio: el administrativo
| llega y encuentra los borradores esperando aprobación, con el día entero por
| delante para llamar al proveedor. A una hora de movimiento, el stock cambia
| mientras el comando lo lee.
|
| `--isolated` toma un lock antes de correr: si por cualquier motivo hay dos
| ejecuciones a la vez, la segunda no hace nada en lugar de duplicar los
| pedidos.
|
| En el servidor hace falta UNA sola entrada de cron, la de Laravel:
|   * * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
|
*/
Schedule::command('compras:generar-reposicion --isolated')->dailyAt('07:00');

/*
| Cada minuto, las reservas vencidas de la tienda (tramo E: los checkouts). La reserva de un
| checkout dura 20 minutos y baja el disponible que ve el cajero: barrer cada hora dejaría ese
| stock trabado hasta tres veces su plazo. Un minuto es lo más fino que da el cron, y la
| consulta usa el índice (`estado`, `reservado_hasta`): sin nada vencido, no lee ninguna fila.
| `withoutOverlapping()`: si una pasada tarda más de un minuto, la siguiente no se le encima.
*/
Schedule::command('tienda:vencer-reservas')->everyMinute()->withoutOverlapping();
