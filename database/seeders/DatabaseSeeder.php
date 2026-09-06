<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Indispensables: sin roles ni permisos el sistema no arranca, y sin
        // administrador no hay forma de entrar.
        $this->call([
            RolPermisoSeeder::class,
            UsuarioAdministradorSeeder::class,
        ]);

        // Datos ficticios para la demostración. Nunca en producción: son cuentas
        // con contraseña conocida.
        if (! app()->environment('production')) {
            $this->call([
                CatalogoDemoSeeder::class,
                PersonasDemoSeeder::class,
            ]);
        }
    }
}
