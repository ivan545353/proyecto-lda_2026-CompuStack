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
                // Descripciones, fotos, destacados y fechas de alta: necesita el catálogo.
                TiendaDemoSeeder::class,
                PersonasDemoSeeder::class,
                // Va último: necesita el catálogo, los proveedores y las personas.
                OperacionesDemoSeeder::class,
            ]);
        }
    }
}
