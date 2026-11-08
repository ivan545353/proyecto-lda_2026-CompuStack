<?php

namespace Database\Seeders;

use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personas a escala: los operadores que firman las operaciones y los clientes.
 *
 * Los operadores se crean con Eloquent y no con DB::table() porque son ocho filas
 * y el cast `hashed` de User tiene que correr sobre la contraseña; una inserción
 * masiva guardaría el texto plano. Los clientes, que son miles, van por inserción
 * masiva en tandas.
 *
 * Invariante rol↔satélite: un rol de ámbito gestión exige fila en `empleados`, y
 * cada operador la lleva, creada en la misma corrida.
 */
class PersonasVolumenSeeder extends Seeder
{
    /**
     * Rol => cuántos operadores.
     *
     * Seis vendedores y dos cajeros, y no uno de cada uno, porque el ranking de
     * vendedores del panel necesita varios para ordenar algo.
     */
    private const OPERADORES = ['Administrativo' => 2, 'Vendedor' => 6, 'Cajero' => 2];

    private const NOMBRES = [
        'Sofía', 'Martín', 'Lucía', 'Diego', 'Camila', 'Nicolás', 'Valentina',
        'Julián', 'Agustina', 'Facundo', 'Micaela', 'Gonzalo', 'Rocío',
        'Emiliano', 'Brenda', 'Leandro', 'Carla', 'Matías', 'Daniela', 'Rodrigo',
    ];

    private const APELLIDOS = [
        'Gutiérrez', 'Ojeda', 'Quiroga', 'Colque', 'Ramírez', 'Sosa', 'Benítez',
        'Acuña', 'Vera', 'Molina', 'Figueroa', 'Cárdenas', 'Luna', 'Peralta',
        'Ibarra', 'Maldonado', 'Villalba', 'Zárate', 'Aguirre', 'Correa',
        'Bustos', 'Navarro', 'Herrera', 'Cáceres', 'Riquelme',
    ];

    public function run(array $cantidades): void
    {
        $this->operadores();
        $this->clientes($cantidades['clientes']);
        $this->resumen();
    }

    /**
     * Los vendedores y cajeros a los que se atribuyen las ventas y los cobros.
     *
     * Quedan con `activo = false` y contraseña aleatoria: son identidades de
     * atribución, no cuentas de trabajo, y ninguna de las dos cosas hace falta para
     * que una venta tenga vendedor. Un empleado inactivo conserva su historial y
     * sigue apareciendo en los reportes, que es la razón por la que `activo` y
     * `fecha_baja` son columnas distintas.
     */
    private function operadores(): void
    {
        $legajo = 0;

        foreach (self::OPERADORES as $rolNombre => $cuantos) {
            $rolId = Rol::where('nombre', $rolNombre)->value('id');

            for ($n = 1; $n <= $cuantos; $n++) {
                $legajo++;

                $usuario = User::create([
                    'nombre'   => self::NOMBRES[($legajo * 5) % count(self::NOMBRES)],
                    'apellido' => self::APELLIDOS[($legajo * 3) % count(self::APELLIDOS)],
                    'email'    => mb_strtolower($rolNombre).$n.'@volumen.local',
                    'password' => Str::random(40),
                    'rol_id'   => $rolId,
                    'activo'   => false,
                ]);

                Empleado::create([
                    'user_id'       => $usuario->id,
                    'legajo'        => VolumenSeeder::PREFIJO.str_pad((string) $legajo, 4, '0', STR_PAD_LEFT),
                    'dni'           => (string) (90100000 + $legajo),
                    'telefono'      => '297-4'.str_pad((string) $legajo, 6, '0', STR_PAD_LEFT),
                    'fecha_ingreso' => now()->subMonths(VolumenSeeder::MESES + 2)->toDateString(),
                ]);
            }
        }
    }

    private function clientes(int $cantidad): void
    {
        $ahora = now()->toDateTimeString();

        foreach (array_chunk(range(1, $cantidad), VolumenSeeder::TANDA) as $tanda) {
            $filas = [];

            foreach ($tanda as $i) {
                $filas[] = $this->cliente($i, $ahora);
            }

            DB::table('clientes')->insert($filas);
        }
    }

    /**
     * Una fila de cliente, derivada del índice.
     *
     * Uno de cada veinte es responsable inscripto y uno de cada treinta y siete
     * monotributista; los dos llevan CUIT y son los que deciden Factura A en la
     * Etapa 2. El resto es consumidor final con DNI, que es el caso de mostrador.
     * El orden del match importa: un índice múltiplo de 20 y de 37 entra por la
     * primera rama y no genera dos números distintos.
     *
     * Los documentos van en rangos reservados —DNI 9000xxxx, CUIT 339… y 209…—
     * para no chocar con el UNIQUE(tipo_doc, nro_doc) de la demostración, cuyos
     * clientes tienen DNI de ocho dígitos sorteados y un CUIT cargado a mano.
     */
    private function cliente(int $i, string $ahora): array
    {
        $apellido = self::APELLIDOS[$i % count(self::APELLIDOS)];
        $nombre   = self::NOMBRES[($i * 7) % count(self::NOMBRES)];

        [$condicion, $tipoDoc, $nroDoc, $razonSocial] = match (true) {
            $i % 20 === 0 => [
                'responsable_inscripto',
                'cuit',
                '339'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                "{$apellido} y Asociados S.R.L.",
            ],
            $i % 37 === 0 => [
                'monotributo',
                'cuit',
                '209'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                "{$apellido}, {$nombre}",
            ],
            default => [
                'consumidor_final',
                'dni',
                (string) (90000000 + $i),
                "{$apellido}, {$nombre}",
            ],
        };

        return [
            // Cliente de mostrador: sin cuenta de acceso. Una cuenta por cliente
            // serían 5.000 filas más en `users` con rol de ámbito tienda, y la tienda
            // es de la Etapa 2.
            'user_id'       => null,
            'razon_social'  => $razonSocial,
            'tipo_doc'      => $tipoDoc,
            'nro_doc'       => $nroDoc,
            'condicion_iva' => $condicion,

            // Uno de cada tres sin correo ni teléfono: es la ficha incompleta del
            // mostrador, y el listado tiene que poder mostrarla.
            'email'      => $i % 3 === 0 ? null : "cliente{$i}@volumen.local",
            'telefono'   => $i % 3 === 0 ? null : '297-5'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    private function resumen(): void
    {
        $this->command?->info(sprintf(
            'Personas: %s clientes (%s con CUIT) y %d operadores con su fila en empleados.',
            number_format(DB::table('clientes')->count(), 0, ',', '.'),
            number_format(DB::table('clientes')->where('tipo_doc', 'cuit')->count(), 0, ',', '.'),
            array_sum(self::OPERADORES),
        ));
    }
}