<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Un usuario de gestión que tiene exactamente el permiso indicado, y nada más.
 *
 * Crea un rol descartable en vez de reutilizar los de sistema: así el test
 * verifica el permiso que nombra y no hereda otros por accidente.
 */
function usuarioCon(string ...$claves): \App\Models\User
{
    $rol = \App\Models\Rol::create([
        'nombre'     => 'Prueba '.\Illuminate\Support\Str::random(8),
        'ambito'     => 'gestion',
        'es_sistema' => false,
    ]);

    $rol->permisos()->sync(
        \App\Models\Permiso::whereIn('clave', $claves)->pluck('id')
    );

    return \App\Models\User::factory()->create(['rol_id' => $rol->id]);
}

/**
 * Un usuario con rol de Administrador para pruebas que requieren privilegios completos.
 */
function admin(): \App\Models\User
{
    return \App\Models\User::factory()->conRol('Administrador')->create();
}

/**
 * Hace la petición con el verbo indicado. Los GET van sin cuerpo; el resto
 * lleva $datos. La usan los datasets de permisos de cada módulo, que recorren
 * todas las rutas con el mismo test.
 */
function pedirRuta($test, string $metodo, string $url, array $datos = [])
{
    return $metodo === 'get'
        ? $test->get($url)
        : $test->{$metodo}($url, $datos);
}

/**
 * Datos válidos de alta de una persona del personal (rol de ámbito gestión).
 *
 * Vive acá y no en un archivo de test porque la usan el test del servicio y el
 * del módulo, y Pest carga todos los archivos en el mismo proceso: declararla
 * dos veces es un error fatal.
 */
function datosDeEmpleado(array $sobreescribir = []): array
{
    return array_merge([
        'nombre'                => 'Sofía',
        'apellido'              => 'Gutiérrez',
        'email'                 => 'sofia@sistema.local',
        'password'              => 'Secreta123',
        'password_confirmation' => 'Secreta123',
        'rol_id'                => \App\Models\Rol::where('nombre', 'Vendedor')->value('id'),
        'activo'                => true,
        'empleado'              => [
            'legajo'        => 'EMP-0031',
            'dni'           => '38123456',
            'telefono'      => '297-4551122',
            'fecha_ingreso' => '2025-03-01',
        ],
    ], $sobreescribir);
}

/** Datos válidos de alta de una cuenta de tienda (rol de ámbito tienda). */
function datosDeClienteDeTienda(array $sobreescribir = []): array
{
    return array_merge([
        'nombre'                => 'Camila',
        'apellido'              => 'Herrera',
        'email'                 => 'camila@sistema.local',
        'password'              => 'Secreta123',
        'password_confirmation' => 'Secreta123',
        'rol_id'                => \App\Models\Rol::where('nombre', 'Cliente')->value('id'),
        'activo'                => true,
        'cliente'               => [
            'razon_social'  => 'Camila Herrera',
            'tipo_doc'      => 'dni',
            'nro_doc'       => '41556778',
            'condicion_iva' => 'consumidor_final',
            'email'         => 'camila@sistema.local',
            'telefono'      => '297-5123456',
        ],
    ], $sobreescribir);
}

/**
 * Datos válidos para EDITAR a una persona ya existente.
 *
 * Sin `password` ni `rol_id`: los dos están prohibidos en la edición (C-3), así
 * que un payload de edición que los incluyera no sería un payload válido.
 */
function datosDeEdicionDe(\App\Models\User $usuario, array $sobreescribir = []): array
{
    $datos = [
        'nombre'   => $usuario->nombre,
        'apellido' => $usuario->apellido,
        'email'    => $usuario->email,
        'activo'   => 1,
    ];

    if ($usuario->empleado) {
        $datos['empleado'] = array_filter([
            'legajo'        => $usuario->empleado->legajo,
            'dni'           => $usuario->empleado->dni,
            'telefono'      => $usuario->empleado->telefono,
            'fecha_ingreso' => $usuario->empleado->fecha_ingreso->toDateString(),
            'fecha_baja'    => $usuario->empleado->fecha_baja?->toDateString(),
        ], fn ($valor) => $valor !== null);
    }

    if ($usuario->cliente) {
        $datos['cliente'] = $usuario->cliente->only([
            'razon_social', 'tipo_doc', 'nro_doc', 'condicion_iva', 'email', 'telefono',
        ]);
    }

    return array_merge($datos, $sobreescribir);
}

/** El token que viaja en un enlace de restablecimiento. */
function tokenDelEnlace(string $enlace): string
{
    return basename(parse_url($enlace, PHP_URL_PATH));
}

/** Datos fiscales válidos de un cliente de mostrador. */
function datosDeCliente(array $sobreescribir = []): array
{
    return array_merge([
        'razon_social'  => 'Panadería Los Tilos',
        'tipo_doc'      => 'dni',
        'nro_doc'       => '41556778',
        'condicion_iva' => 'consumidor_final',
        'email'         => 'tilos@ejemplo.com',
        'telefono'      => '297-5123456',
    ], $sobreescribir);
}

/** Datos válidos de una dirección. */
function datosDeDireccion(array $sobreescribir = []): array
{
    return array_merge([
        'calle'             => 'Av. Eva Perón',
        'numero'            => '1450',
        'piso_depto'        => null,
        'codigo_postal'     => '9011',
        'localidad'         => 'Caleta Olivia',
        'provincia'         => 'Santa Cruz',
        'es_predeterminada' => false,
    ], $sobreescribir);
}

/**
 * Cuántas direcciones del cliente están marcadas como predeterminadas.
 *
 * La invariante dice que si tiene al menos una, este número es exactamente 1.
 */
function predeterminadasDe(\App\Models\Cliente $cliente): int
{
    return $cliente->direcciones()->where('es_predeterminada', true)->count();
}

/** Datos válidos de un proveedor. */
function datosDeProveedor(array $sobreescribir = []): array
{
    return array_merge([
        'razon_social'       => 'Distribuidora Austral S.A.',
        'cuit'               => '30712345678',
        'email'              => 'ventas@austral.test',
        'telefono'           => '297-4551122',
        'contacto'           => 'Mesa de pedidos',
        'canal_pedido'       => 'manual',
        'portal_url'         => null,
        'plazo_entrega_dias' => 7,
        'activo'             => true,
    ], $sobreescribir);
}