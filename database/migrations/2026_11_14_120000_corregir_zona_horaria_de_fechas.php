<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corre tres horas para atrás las fechas guardadas mientras PHP estuvo en UTC (D-24, HL-86).
 *
 * `config/app.php` fijaba 'UTC' a mano: Laravel escribía la hora UTC y MariaDB, con la sesión en
 * `SYSTEM` (Buenos Aires), la guardaba como si fuera local. No cambia ningún hecho: cambia cómo
 * está escrita la hora del mismo hecho, y `down()` lo deshace. Argentina no tiene horario de
 * verano desde 2009, así que el corrimiento es exacto.
 *
 * La lista está escrita y no se descubre al correr, para que el diff diga qué se toca. Queda
 * afuera `failed_jobs.failed_at`: es del framework y la llena la base, ya en hora local.
 * `pagos.fecha` e `intentos_pago.vence_at` tienen valor por omisión de la base, pero todas sus
 * filas las escribió Laravel (los 10.150 pagos tenían `fecha` igual a `created_at`, HL-91).
 */
return new class extends Migration
{
    private const COLUMNAS = [
        'carritos'               => ['reservado_hasta', 'created_at', 'updated_at'],
        'carrito_lineas'         => ['created_at', 'updated_at'],
        'categorias'             => ['created_at', 'updated_at'],
        'clientes'               => ['created_at', 'updated_at'],
        'direcciones'            => ['created_at', 'updated_at'],
        'empleados'              => ['created_at', 'updated_at'],
        'entregas'               => ['created_at'],
        'factores_costo'         => ['created_at', 'updated_at'],
        'historial_precios'      => ['created_at'],
        'impuestos'              => ['created_at', 'updated_at'],
        'intentos_pago'          => ['vence_at', 'resuelto_at', 'acreditado_at', 'created_at', 'updated_at'],
        'lotes_ajuste'           => ['created_at'],
        'marcas'                 => ['created_at', 'updated_at'],
        'movimientos_stock'      => ['created_at', 'updated_at'],
        'ordenes_compra'         => ['fecha_aprobacion', 'fecha_envio', 'created_at', 'updated_at'],
        'orden_compra_lineas'    => ['created_at', 'updated_at'],
        'pagos'                  => ['fecha', 'created_at', 'updated_at'],
        'password_reset_tokens'  => ['created_at'],
        'permisos'               => ['created_at', 'updated_at'],
        'personal_access_tokens' => ['last_used_at', 'expires_at', 'created_at', 'updated_at'],
        'politica_precios'       => ['created_at', 'updated_at'],
        'productos'              => ['created_at', 'updated_at'],
        'producto_proveedor'     => ['created_at', 'updated_at'],
        'proveedores'            => ['created_at', 'updated_at'],
        'roles'                  => ['created_at', 'updated_at'],
        'users'                  => ['email_verified_at', 'created_at', 'updated_at'],
        'ventas'                 => ['reserva_vence', 'created_at', 'updated_at'],
        'venta_lineas'           => ['created_at', 'updated_at'],
        'venta_linea_impuestos'  => ['created_at'],
    ];

    public function up(): void
    {
        $this->correr('-');
    }

    public function down(): void
    {
        $this->correr('+');
    }

    /** Una sentencia por tabla, todas en una transacción: o se corren todas o ninguna. */
    private function correr(string $signo): void
    {
        DB::transaction(function () use ($signo) {
            foreach (self::COLUMNAS as $tabla => $columnas) {
                // El constructor de consultas no tiene aritmética de fechas: el fragmento es la
                // única forma de hacerlo en la base sin traer cada fila a PHP. Un NULL sigue NULL.
                DB::table($tabla)->update(array_combine(
                    $columnas,
                    array_map(fn (string $c) => DB::raw("`{$c}` {$signo} INTERVAL 3 HOUR"), $columnas),
                ));
            }
        });
    }
};
