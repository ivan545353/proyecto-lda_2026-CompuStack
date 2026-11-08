<?php

namespace Database\Seeders;

use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crea la única cuenta que existe en una instalación limpia.
 *
 * La contraseña no está escrita en el código: se toma de ADMIN_PASSWORD en el
 * .env (que está ignorado) y, si no está definida, se genera una al azar y se
 * imprime una sola vez por consola.
 */
class UsuarioAdministradorSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@sistema.local');

        if (User::where('email', $email)->exists()) {
            $this->command?->warn("El administrador {$email} ya existe. No se modifica.");

            return;
        }

        $password  = env('ADMIN_PASSWORD');
        $generada  = $password === null;
        $password ??= Str::password(16);

        $rol = Rol::where('nombre', 'Administrador')->firstOrFail();

        $usuario = User::create([
            'nombre'   => env('ADMIN_NOMBRE', 'Administrador'),
            'apellido' => env('ADMIN_APELLIDO', 'del Sistema'),
            'email'    => $email,
            'password' => $password,          // el cast 'hashed' lo encripta
            'rol_id'   => $rol->id,
            'activo'   => true,
        ]);

        // Invariante: rol de ámbito gestión ⇒ fila en empleados.
        Empleado::create([
            'user_id'       => $usuario->id,
            'legajo'        => 'ADM-0001',
            'dni'           => '00000000',
            'fecha_ingreso' => now()->toDateString(),
        ]);

        $this->command?->info("Administrador creado: {$email}");

        if ($generada) {
            $this->command?->warn("Contraseña generada: {$password}");
            $this->command?->warn('Se muestra una sola vez. Guardala o definí ADMIN_PASSWORD en el .env.');
        }
    }
}
