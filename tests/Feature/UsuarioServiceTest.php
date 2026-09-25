<?php

use App\Models\Cliente;
use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(UsuarioService::class);
});

function datosDeEmpleado(array $sobreescribir = []): array
{
    return array_merge([
        'nombre'   => 'Sofía',
        'apellido' => 'Gutiérrez',
        'email'    => 'sofia@sistema.local',
        'password' => 'Secreta123',
        'rol_id'   => Rol::where('nombre', 'Vendedor')->value('id'),
        'activo'   => true,
        'empleado' => [
            'legajo'        => 'EMP-0031',
            'dni'           => '38123456',
            'telefono'      => '297-4551122',
            'fecha_ingreso' => '2025-03-01',
        ],
    ], $sobreescribir);
}

function datosDeClienteDeTienda(array $sobreescribir = []): array
{
    return array_merge([
        'nombre'   => 'Camila',
        'apellido' => 'Herrera',
        'email'    => 'camila@sistema.local',
        'password' => 'Secreta123',
        'rol_id'   => Rol::where('nombre', 'Cliente')->value('id'),
        'activo'   => true,
        'cliente'  => [
            'razon_social'  => 'Camila Herrera',
            'tipo_doc'      => 'dni',
            'nro_doc'       => '41556778',
            'condicion_iva' => 'consumidor_final',
            'email'         => 'camila@sistema.local',
            'telefono'      => '297-5123456',
        ],
    ], $sobreescribir);
}

/*
|--------------------------------------------------------------------------
| La invariante: un usuario, su satélite, una transacción
|--------------------------------------------------------------------------
*/

test('un rol de gestion crea la fila de empleado y ninguna de cliente', function () {
    $usuario = $this->service->crear(datosDeEmpleado());

    expect($usuario->empleado)->not->toBeNull()
        ->and($usuario->empleado->legajo)->toBe('EMP-0031')
        ->and($usuario->cliente)->toBeNull();
});

test('un rol de tienda crea la fila de cliente y ninguna de empleado', function () {
    $usuario = $this->service->crear(datosDeClienteDeTienda());

    expect($usuario->cliente)->not->toBeNull()
        ->and($usuario->cliente->condicion_iva)->toBe('consumidor_final')
        ->and($usuario->empleado)->toBeNull();
});

test('el satelite lo decide el ambito del rol, no el nombre', function () {
    // M-33: renombrar un rol no puede cambiar qué datos se le piden a su gente.
    Rol::where('nombre', 'Vendedor')->update(['nombre' => 'Asesor comercial']);

    $usuario = $this->service->crear(datosDeEmpleado([
        'rol_id' => Rol::where('nombre', 'Asesor comercial')->value('id'),
    ]));

    expect($usuario->empleado)->not->toBeNull();
});

test('si falla el satelite no queda el usuario', function () {
    // El legajo ya está tomado: la segunda inserción viola el UNIQUE.
    Empleado::factory()->create(['legajo' => 'EMP-0031']);

    expect(fn () => $this->service->crear(datosDeEmpleado()))
        ->toThrow(QueryException::class);

    // Una cuenta sin legajo es una fila que nadie sabe qué es. La transacción
    // la revierte: o están las dos filas, o no está ninguna.
    expect(User::where('email', 'sofia@sistema.local')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| C-1: la contraseña
|--------------------------------------------------------------------------
*/

test('la contrasena se guarda hasheada y no aparece al serializar', function () {
    $usuario = $this->service->crear(datosDeEmpleado());

    expect($usuario->password)->not->toBe('Secreta123')
        ->and(Hash::check('Secreta123', $usuario->password))->toBeTrue()
        // $hidden: el UserDto original devolvía la clave en toArray() y
        // /user/list exponía el hash de todos los usuarios.
        ->and($usuario->toArray())->not->toHaveKey('password')
        ->and($usuario->fresh()->toArray())->not->toHaveKey('password');
});

/*
|--------------------------------------------------------------------------
| C-3: la edición no toca el rol ni la contraseña
|--------------------------------------------------------------------------
*/

test('actualizar no cambia el rol aunque venga en los datos', function () {
    $usuario = $this->service->crear(datosDeEmpleado());
    $rolOriginal = $usuario->rol_id;

    $this->service->actualizar($usuario, [
        'nombre'   => 'Sofía',
        'apellido' => 'Gutiérrez',
        'email'    => 'sofia@sistema.local',
        'activo'   => true,
        'rol_id'   => Rol::where('nombre', 'Administrador')->value('id'),
        'empleado' => $usuario->empleado->only(['legajo', 'dni', 'telefono', 'fecha_ingreso']),
    ]);

    // El Form Request ya lo rechaza; esto prueba la segunda barrera. La API de
    // la Etapa 3 va a llamar a este mismo método, y no puede depender de que
    // del otro lado alguien se haya acordado de filtrar el arreglo.
    expect($usuario->fresh()->rol_id)->toBe($rolOriginal);
});

test('actualizar no cambia la contrasena aunque venga en los datos', function () {
    $usuario = $this->service->crear(datosDeEmpleado());

    $this->service->actualizar($usuario, [
        'nombre'   => 'Sofía',
        'apellido' => 'Gutiérrez',
        'email'    => 'sofia@sistema.local',
        'activo'   => true,
        'password' => 'OtraClave456',
        'empleado' => $usuario->empleado->only(['legajo', 'dni', 'telefono', 'fecha_ingreso']),
    ]);

    expect(Hash::check('Secreta123', $usuario->fresh()->password))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| La baja laboral no es la baja de acceso
|--------------------------------------------------------------------------
*/

test('cargar la fecha de baja no desactiva la cuenta', function () {
    $usuario = $this->service->crear(datosDeEmpleado());

    $this->service->actualizar($usuario, [
        'nombre'   => 'Sofía',
        'apellido' => 'Gutiérrez',
        'email'    => 'sofia@sistema.local',
        'activo'   => true,
        'empleado' => array_merge(
            $usuario->empleado->only(['legajo', 'dni', 'telefono', 'fecha_ingreso']),
            ['fecha_baja' => '2026-08-31'],
        ),
    ]);

    $usuario = $usuario->fresh();

    // Son datos distintos: activo gobierna el login, fecha_baja es el vínculo
    // laboral. Quien se va el 31 puede necesitar entrar el 1 a cerrar cosas.
    expect($usuario->empleado->fecha_baja->toDateString())->toBe('2026-08-31')
        ->and($usuario->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| M-16: lo que está referenciado no se borra
|--------------------------------------------------------------------------
*/

test('un usuario sin historial se borra, y su legajo se va con el', function () {
    $usuario  = $this->service->crear(datosDeEmpleado());
    $empleado = $usuario->empleado;

    expect($this->service->eliminar($usuario))->toBeTrue();

    $this->assertModelMissing($usuario);
    $this->assertModelMissing($empleado);
});

test('un usuario con ventas no se borra: se desactiva', function () {
    $usuario = $this->service->crear(datosDeEmpleado());

    // La tabla existe desde la Fase 1; el modelo Venta llega en la Fase 6.
    DB::table('ventas')->insert([
        'usuario_id' => $usuario->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->service->eliminar($usuario))->toBeFalse();

    $this->assertModelExists($usuario);
    expect($usuario->fresh()->activo)->toBeFalse();
});

test('un usuario que movio stock no se borra: el kardex perderia quien fue', function () {
    $usuario  = $this->service->crear(datosDeEmpleado());
    $producto = \App\Models\Producto::factory()->create();

    // movimientos_stock.usuario_id es nullOnDelete: borrar la cuenta no daría
    // error, pondría la columna en null y el movimiento quedaría sin autor.
    DB::table('movimientos_stock')->insert([
        'producto_id'      => $producto->id,
        'tipo'             => 'ajuste',
        'cantidad'         => -2,
        'stock_resultante' => 0,
        'usuario_id'       => $usuario->id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect($this->service->eliminar($usuario))->toBeFalse();
    expect($usuario->fresh()->activo)->toBeFalse();
});

test('borrar la cuenta de un cliente de tienda lo deja como cliente de mostrador', function () {
    $usuario = $this->service->crear(datosDeClienteDeTienda());
    $cliente = $usuario->cliente;

    expect($this->service->eliminar($usuario))->toBeTrue();

    // clientes.user_id es nullOnDelete, y está bien que sea distinto de
    // empleados: la persona que compró sigue existiendo aunque cierre su
    // cuenta, y sus ventas la siguen apuntando.
    $this->assertModelExists($cliente);
    expect($cliente->fresh()->user_id)->toBeNull();
});