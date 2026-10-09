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