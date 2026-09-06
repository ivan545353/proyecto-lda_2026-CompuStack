<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Direccion;
use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Un usuario por rol de gestión, más clientes de los tres tipos que hay que
 * poder demostrar: mostrador sin cuenta, responsable inscripto y cuenta de
 * tienda.
 *
 * Todos comparten la contraseña de demostración 'demo1234'. No es un secreto:
 * son cuentas de datos ficticios que sólo existen fuera de producción, y el
 * DatabaseSeeder no ejecuta este seeder si el entorno es production.
 */
class PersonasDemoSeeder extends Seeder
{
    private const CLAVE_DEMO = 'demo1234';

    private const EMPLEADOS = [
        ['Administrativo', 'Colque',    'Pedro Valentín', 'administrativo@sistema.local', 'EMP-0002'],
        ['Vendedor',       'Gutiérrez', 'Sofía',          'vendedor@sistema.local',       'EMP-0003'],
        ['Vendedor',       'Ojeda',     'Martín',         'vendedor2@sistema.local',      'EMP-0004'],
        ['Cajero',         'Quiroga',   'Lucía',          'cajero@sistema.local',         'EMP-0005'],
    ];

    public function run(): void
    {
        $this->crearEmpleados();
        $this->crearClientes();
    }

    private function crearEmpleados(): void
    {
        foreach (self::EMPLEADOS as $i => [$rolNombre, $apellido, $nombre, $email, $legajo]) {
            $rol = Rol::where('nombre', $rolNombre)->firstOrFail();

            $usuario = User::create([
                'nombre'   => $nombre,
                'apellido' => $apellido,
                'email'    => $email,
                'password' => self::CLAVE_DEMO,
                'rol_id'   => $rol->id,
                'activo'   => true,
            ]);

            // Invariante: rol de ámbito gestión ⇒ fila en empleados.
            Empleado::create([
                'user_id'       => $usuario->id,
                'legajo'        => $legajo,
                'dni'           => (string) (30000000 + $i),
                'telefono'      => '297-4'.(500000 + $i),
                'fecha_ingreso' => now()->subMonths(6 + $i)->toDateString(),
            ]);
        }
    }

    private function crearClientes(): void
    {
        // 1. Mostrador, sin cuenta de acceso.
        Cliente::factory()->count(6)->create();

        // 2. Empresa con CUIT: es la que dispara Factura A.
        Cliente::factory()->responsableInscripto()->create([
            'razon_social' => 'Estudio Contable Austral S.R.L.',
            'nro_doc'      => '30715558881',
            'email'        => 'compras@estudioaustral.com.ar',
        ]);

        // 3. Cliente con cuenta de tienda. Su rol es de ámbito 'tienda' y no
        //    tiene ningún permiso de gestión: en la Etapa 1 no hay tienda todavía.
        $rolCliente = Rol::where('nombre', 'Cliente')->firstOrFail();

        $usuario = User::create([
            'nombre'   => 'Camila',
            'apellido' => 'Herrera',
            'email'    => 'cliente@sistema.local',
            'password' => self::CLAVE_DEMO,
            'rol_id'   => $rolCliente->id,
            'activo'   => true,
        ]);

        $cliente = Cliente::create([
            'user_id'       => $usuario->id,
            'razon_social'  => 'Camila Herrera',
            'tipo_doc'      => 'dni',
            'nro_doc'       => '41556778',
            'condicion_iva' => 'consumidor_final',
            'email'         => $usuario->email,
            'telefono'      => '297-5123456',
        ]);

        Direccion::create([
            'cliente_id'        => $cliente->id,
            'calle'             => 'Av. Eva Perón',
            'numero'            => '1450',
            'codigo_postal'     => '9011',
            'localidad'         => 'Caleta Olivia',
            'provincia'         => 'Santa Cruz',
            'es_predeterminada' => true,
        ]);
    }
}
