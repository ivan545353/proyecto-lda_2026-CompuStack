<?php

use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Los filtros por rol y por ámbito necesitan los roles del sistema.
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Filtros del listado de usuarios
|--------------------------------------------------------------------------
|
| Cubre A-24 sobre el módulo donde el desajuste era peor: el UserController
| original mandaba `nombres` y el UserDao esperaba `perfil_id` y `estado`, así
| que el filtro por nombre nunca se aplicó. Convivió con la versión final del
| sistema porque no existía este archivo.
|
*/

function usuarioLlamado(string $nombre, string $apellido, string $email): User
{
    return User::factory()->conRol('Vendedor')->create([
        'nombre'   => $nombre,
        'apellido' => $apellido,
        'email'    => $email,
    ]);
}

test('buscar encuentra por nombre, por apellido y por correo', function () {
    usuarioLlamado('Sofía', 'Gutiérrez', 'sofia@sistema.local');
    usuarioLlamado('Martín', 'Ojeda', 'martin@sistema.local');

    expect(User::buscar('sofí')->count())->toBe(1)
        ->and(User::buscar('ojeda')->count())->toBe(1)
        ->and(User::buscar('martin@')->count())->toBe(1);
});

test('buscar encuentra por legajo, que vive en la tabla de empleados', function () {
    $sofia  = usuarioLlamado('Sofía', 'Gutiérrez', 'sofia@sistema.local');
    $martin = usuarioLlamado('Martín', 'Ojeda', 'martin@sistema.local');

    Empleado::factory()->create(['user_id' => $sofia->id,  'legajo' => 'EMP-0031']);
    Empleado::factory()->create(['user_id' => $martin->id, 'legajo' => 'EMP-0099']);

    $resultado = User::buscar('EMP-0031')->get();

    expect($resultado)->toHaveCount(1)
        ->and($resultado->first()->id)->toBe($sofia->id);
});

test('buscar sin texto no filtra nada', function () {
    User::factory()->count(3)->conRol('Vendedor')->create();

    expect(User::buscar(null)->count())->toBe(3)
        ->and(User::buscar('')->count())->toBe(3)
        ->and(User::buscar('   ')->count())->toBe(3);
});

test('buscar trata el comodin de LIKE como texto literal', function () {
    usuarioLlamado('Ana', 'Pérez', 'ana100%@sistema.local');
    usuarioLlamado('Luis', 'Sosa', 'luis@sistema.local');

    // Sin escapar, '%' devolvería el padrón completo a quien busque un
    // porcentaje: un buscador que ignora lo que le pedís.
    expect(User::buscar('100%')->count())->toBe(1);
});

test('deRol devuelve solo los usuarios de ese rol', function () {
    $vendedor = Rol::where('nombre', 'Vendedor')->value('id');

    User::factory()->count(2)->conRol('Vendedor')->create();
    User::factory()->conRol('Cajero')->create();

    expect(User::deRol($vendedor)->count())->toBe(2);
});

test('deRol sin valor o con un valor no numerico no filtra', function () {
    User::factory()->count(2)->conRol('Vendedor')->create();
    User::factory()->conRol('Cajero')->create();

    expect(User::deRol(null)->count())->toBe(3)
        ->and(User::deRol('')->count())->toBe(3)
        ->and(User::deRol('cualquier-cosa')->count())->toBe(3);
});

test('deAmbito separa el personal de las cuentas de tienda', function () {
    User::factory()->count(2)->conRol('Vendedor')->create();
    User::factory()->conRol('Cliente')->create();

    expect(User::deAmbito('gestion')->count())->toBe(2)
        ->and(User::deAmbito('tienda')->count())->toBe(1);
});

test('deAmbito no compara el nombre del rol', function () {
    // Renombrar un rol no puede cambiar a qué aplicación entra su gente:
    // ese acoplamiento es el hallazgo M-33.
    User::factory()->conRol('Cliente')->create();
    Rol::where('nombre', 'Cliente')->update(['nombre' => 'Comprador']);

    expect(User::deAmbito('tienda')->count())->toBe(1);
});

test('deAmbito sin valor o con un valor desconocido no filtra', function () {
    User::factory()->conRol('Vendedor')->create();
    User::factory()->conRol('Cliente')->create();

    expect(User::deAmbito(null)->count())->toBe(2)
        ->and(User::deAmbito('')->count())->toBe(2)
        ->and(User::deAmbito('oficina')->count())->toBe(2);
});

test('conEstado separa quien puede entrar de quien no', function () {
    User::factory()->count(2)->conRol('Vendedor')->create();
    User::factory()->conRol('Vendedor')->inactivo()->create();

    expect(User::conEstado('activos')->count())->toBe(2)
        ->and(User::conEstado('inactivos')->count())->toBe(1);
});

test('conEstado sin valor o con un valor desconocido no filtra', function () {
    User::factory()->count(2)->conRol('Vendedor')->create();
    User::factory()->conRol('Vendedor')->inactivo()->create();

    expect(User::conEstado(null)->count())->toBe(3)
        ->and(User::conEstado('')->count())->toBe(3)
        ->and(User::conEstado('habilitados')->count())->toBe(3);
});

test('conSituacion separa a quien sigue trabajando de quien se fue', function () {
    $sigue = User::factory()->conRol('Vendedor')->create();
    $sefue = User::factory()->conRol('Vendedor')->create();

    Empleado::factory()->create(['user_id' => $sigue->id, 'fecha_baja' => null]);
    Empleado::factory()->create(['user_id' => $sefue->id, 'fecha_baja' => '2026-03-31']);

    expect(User::conSituacion('en_actividad')->count())->toBe(1)
        ->and(User::conSituacion('dados_de_baja')->count())->toBe(1);
});

test('la baja laboral es independiente del acceso al sistema', function () {
    // Son dos cosas distintas y los filtros tienen que poder decirlo. Un
    // empleado dado de baja que todavía tiene la cuenta abierta aparece en
    // 'activos' Y en 'dados_de_baja'.
    $usuario = User::factory()->conRol('Vendedor')->create(['activo' => true]);
    Empleado::factory()->create(['user_id' => $usuario->id, 'fecha_baja' => '2026-03-31']);

    expect(User::conEstado('activos')->conSituacion('dados_de_baja')->count())->toBe(1)
        ->and(User::conEstado('inactivos')->count())->toBe(0);
});

test('conSituacion deja fuera a las cuentas de tienda', function () {
    // No tienen vínculo laboral: no son ni una cosa ni la otra.
    User::factory()->conRol('Cliente')->create();

    expect(User::conSituacion('en_actividad')->count())->toBe(0)
        ->and(User::conSituacion('dados_de_baja')->count())->toBe(0);
});

test('los filtros se combinan entre si', function () {
    usuarioLlamado('Sofía', 'Gutiérrez', 'sofia@sistema.local');

    $inactiva = User::factory()->conRol('Vendedor')->inactivo()->create([
        'nombre' => 'Sofía', 'apellido' => 'Sosa', 'email' => 'sofia.sosa@sistema.local',
    ]);

    User::factory()->conRol('Cajero')->create([
        'nombre' => 'Sofía', 'apellido' => 'Ruiz', 'email' => 'sofia.ruiz@sistema.local',
    ]);

    $resultado = User::buscar('sofía')
        ->conEstado('activos')
        ->deRol(Rol::where('nombre', 'Vendedor')->value('id'))
        ->pluck('apellido')
        ->all();

    expect($resultado)->toBe(['Gutiérrez']);
});