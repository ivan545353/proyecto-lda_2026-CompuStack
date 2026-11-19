<?php

use App\Models\Cliente;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->tiendaRegistro = fn (array $cambios = []) => array_merge([
        'nombre'                => 'Lucía',
        'apellido'              => 'Paredes',
        'email'                 => 'lucia@correo.local',
        'password'              => 'Clave2026',
        'password_confirmation' => 'Clave2026',
        'telefono'              => '297-4001122',
    ], $cambios);
});

/*
|--------------------------------------------------------------------------
| Registro
|--------------------------------------------------------------------------
*/

test('el registro crea la cuenta de tienda con su cliente y devuelve la credencial', function () {
    $respuesta = $this->postJson('/api/v1/registro', ($this->tiendaRegistro)());

    $respuesta->assertStatus(201)
        ->assertJsonPath('datos.tipo', 'Bearer')
        ->assertJsonPath('datos.usuario', ['nombre' => 'Lucía', 'apellido' => 'Paredes', 'email' => 'lucia@correo.local']);
    expect(array_keys($respuesta->json('datos')))->toBe(['credencial', 'tipo', 'vence', 'usuario']);

    $usuario = User::where('email', 'lucia@correo.local')->firstOrFail();
    expect($usuario->rol->nombre)->toBe('Cliente')
        ->and($usuario->activo)->toBeTrue()
        ->and($usuario->esDeGestion())->toBeFalse();

    $cliente = Cliente::where('user_id', $usuario->id)->firstOrFail();
    expect($cliente->razon_social)->toBe('Lucía Paredes')
        ->and($cliente->tipo_doc)->toBe('dni')
        ->and($cliente->nro_doc)->toBeNull()
        ->and($cliente->condicion_iva)->toBe('consumidor_final')
        ->and($cliente->email)->toBe('lucia@correo.local')
        ->and($cliente->telefono)->toBe('297-4001122');
});

test('la credencial del registro abre la sesión de la tienda', function () {
    $credencial = $this->postJson('/api/v1/registro', ($this->tiendaRegistro)())->json('datos.credencial');

    $this->getJson('/api/v1/productos', ['Authorization' => 'Bearer '.$credencial])->assertOk();
});

test('el teléfono es opcional', function () {
    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)(['telefono' => null]))->assertStatus(201);

    expect(Cliente::where('email', 'lucia@correo.local')->value('telefono'))->toBeNull();
});

test('el correo se guarda en minúsculas y sin espacios', function () {
    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)(['email' => '  Lucia@Correo.LOCAL ']))
        ->assertStatus(201)
        ->assertJsonPath('datos.usuario.email', 'lucia@correo.local');
});

test('el registro ignora el rol y el estado que mande el cuerpo', function () {
    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)([
        'rol_id' => \App\Models\Rol::where('nombre', 'Administrador')->value('id'),
        'activo' => false,
    ]))->assertStatus(201);

    $usuario = User::where('email', 'lucia@correo.local')->firstOrFail();
    expect($usuario->rol->nombre)->toBe('Cliente')->and($usuario->activo)->toBeTrue();
});

test('un correo ya registrado es 422, sin importar mayúsculas', function () {
    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)())->assertStatus(201);

    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)(['email' => 'LUCIA@correo.local']))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonPath('errores.email.0', 'Ya hay una cuenta con ese correo.');

    expect(User::count())->toBe(1);
});

test('un dato inválido del registro es 422 con su campo y no crea nada', function (array $cambios, string $campo) {
    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)($cambios))
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonStructure(['errores' => [$campo]]);

    expect(User::count())->toBe(0)->and(Cliente::count())->toBe(0);
})->with([
    'sin nombre'                => [['nombre' => ''], 'nombre'],
    'sin apellido'              => [['apellido' => ''], 'apellido'],
    'nombre de 101'             => [['nombre' => str_repeat('a', 101)], 'nombre'],
    'nombre y apellido sobre 150' => [['nombre' => str_repeat('a', 80), 'apellido' => str_repeat('b', 80)], 'apellido'],
    'sin correo'                => [['email' => ''], 'email'],
    'correo inválido'           => [['email' => 'lucia'], 'email'],
    'contraseña corta'          => [['password' => 'Abc123', 'password_confirmation' => 'Abc123'], 'password'],
    'contraseña sin números'    => [['password' => 'SoloLetras', 'password_confirmation' => 'SoloLetras'], 'password'],
    'contraseña sin letras'     => [['password' => '12345678', 'password_confirmation' => '12345678'], 'password'],
    'confirmación distinta'     => [['password_confirmation' => 'Otra2026x'], 'password'],
    'teléfono de 31'            => [['telefono' => str_repeat('1', 31)], 'telefono'],
]);

test('el registro tiene límite de intentos', function () {
    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/registro', ($this->tiendaRegistro)(['password' => 'corta']))->assertStatus(422);
    }

    $this->postJson('/api/v1/registro', ($this->tiendaRegistro)())
        ->assertStatus(429)
        ->assertJsonPath('codigo', 'demasiados_intentos');

    expect(User::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Sesión
|--------------------------------------------------------------------------
*/

describe('sesión', function () {
    beforeEach(function () {
        $this->tiendaCuenta = app(\App\Services\UsuarioService::class)->crear(datosDeClienteDeTienda());
        $this->tiendaIniciar = fn (array $cambios = []) => $this->postJson('/api/v1/sesion', array_merge([
            'email'    => 'camila@sistema.local',
            'password' => 'Secreta123',
        ], $cambios));
        $this->tiendaBearer = fn (string $credencial) => ['Authorization' => 'Bearer '.$credencial];
    });

    test('iniciar sesión devuelve la credencial con la forma del contrato', function () {
        $respuesta = ($this->tiendaIniciar)();

        $respuesta->assertOk()
            ->assertJsonPath('datos.tipo', 'Bearer')
            ->assertJsonPath('datos.usuario.email', 'camila@sistema.local');
        expect(array_keys($respuesta->json('datos')))->toBe(['credencial', 'tipo', 'vence', 'usuario']);

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/productos', ($this->tiendaBearer)($respuesta->json('datos.credencial')))->assertOk();
    });

    test('el correo no distingue mayúsculas ni espacios', function () {
        ($this->tiendaIniciar)(['email' => ' Camila@Sistema.LOCAL '])->assertOk();
    });

    test('iniciar sesión no abre una sesión web', function () {
        ($this->tiendaIniciar)()->assertOk();

        $this->assertGuest('web');
    });

    test('incorrecta, inexistente, inactiva y de gestión dan el mismo 422', function (string $caso) {
        $datos = match ($caso) {
            'contraseña incorrecta' => ['password' => 'Otra12345'],
            'correo inexistente'    => ['email' => 'nadie@sistema.local'],
            'cuenta inactiva'       => (function () {
                $this->tiendaCuenta->update(['activo' => false]);

                return [];
            })(),
            'cuenta de gestión'     => (function () {
                User::factory()->conRol('Vendedor')->create(['email' => 'vende@sistema.local', 'password' => 'Secreta123']);

                return ['email' => 'vende@sistema.local'];
            })(),
        };

        ($this->tiendaIniciar)($datos)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'validacion')
            ->assertJsonPath('errores', ['email' => ['El correo o la contraseña no son correctos.']]);

        expect(\Laravel\Sanctum\PersonalAccessToken::count())->toBe(0);
    })->with(['contraseña incorrecta', 'correo inexistente', 'cuenta inactiva', 'cuenta de gestión']);

    test('sin correo o sin contraseña es 422 con su campo', function (string $campo) {
        ($this->tiendaIniciar)([$campo => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['errores' => [$campo]]);
    })->with(['email', 'password']);

    test('el inicio de sesión tiene límite de intentos', function () {
        foreach (range(1, 5) as $intento) {
            ($this->tiendaIniciar)(['password' => 'Otra12345'])->assertStatus(422);
        }

        // Ni la contraseña correcta entra mientras dura el límite.
        ($this->tiendaIniciar)()
            ->assertStatus(429)
            ->assertJsonPath('codigo', 'demasiados_intentos');
    });

    test('cerrar sesión revoca: la misma credencial después da 401', function () {
        $credencial = ($this->tiendaIniciar)()->json('datos.credencial');

        app('auth')->forgetGuards();
        $this->deleteJson('/api/v1/sesion', [], ($this->tiendaBearer)($credencial))->assertNoContent();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/productos', ($this->tiendaBearer)($credencial))
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'sesion_invalida');
    });

    test('cerrar sesión no cierra la de otro dispositivo', function () {
        $este = ($this->tiendaIniciar)()->json('datos.credencial');
        $otro = ($this->tiendaIniciar)()->json('datos.credencial');

        app('auth')->forgetGuards();
        $this->deleteJson('/api/v1/sesion', [], ($this->tiendaBearer)($este))->assertNoContent();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/productos', ($this->tiendaBearer)($otro))->assertOk();
    });

    test('cerrar sesión sin credencial es 401 sin_sesion', function () {
        $this->deleteJson('/api/v1/sesion')
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'sin_sesion');
    });

    test('una credencial vencida da 401 sesion_invalida', function () {
        $credencial = ($this->tiendaIniciar)()->json('datos.credencial');

        $this->travel(8)->days();

        app('auth')->forgetGuards();
        $this->deleteJson('/api/v1/sesion', [], ($this->tiendaBearer)($credencial))
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'sesion_invalida');
    });
});

/*
|--------------------------------------------------------------------------
| Perfil
|--------------------------------------------------------------------------
*/

test('el perfil muestra la cuenta de quien tiene la sesión, sin documento hasta que se cargue', function () {
    $credencial = $this->postJson('/api/v1/registro', ($this->tiendaRegistro)())->json('datos.credencial');

    app('auth')->forgetGuards();
    $respuesta = $this->getJson('/api/v1/perfil', ['Authorization' => 'Bearer '.$credencial]);

    // El registro guarda `dni` sin número: sin número, el tipo no viaja (HL-65).
    $respuesta->assertOk()->assertExactJson(['datos' => [
        'nombre'              => 'Lucía',
        'apellido'            => 'Paredes',
        'email'               => 'lucia@correo.local',
        'telefono'            => '297-4001122',
        'razon_social'        => 'Lucía Paredes',
        'tipo_doc'            => null,
        'tipo_doc_texto'      => null,
        'nro_doc'             => null,
        'condicion_iva'       => 'consumidor_final',
        'condicion_iva_texto' => 'Consumidor final',
        'direccion'           => null,
    ]]);
});

test('el perfil propone la direccion predeterminada, no las otras', function () {
    $credencial = $this->postJson('/api/v1/registro', ($this->tiendaRegistro)())->json('datos.credencial');
    $cliente    = \App\Models\Cliente::where('email', 'lucia@correo.local')->sole();
    $cliente->direcciones()->create([...datosDeDireccion(['calle' => 'Rivadavia']), 'es_predeterminada' => false]);
    $cliente->direcciones()->create([...datosDeDireccion(['calle' => 'San Martín', 'piso_depto' => null]), 'es_predeterminada' => true]);

    app('auth')->forgetGuards();
    $direccion = $this->getJson('/api/v1/perfil', ['Authorization' => 'Bearer '.$credencial])
        ->assertOk()->json('datos.direccion');

    expect($direccion['calle'])->toBe('San Martín')
        ->and($direccion['piso_depto'])->toBeNull()
        ->and(array_keys($direccion))->toBe(['calle', 'numero', 'piso_depto', 'codigo_postal', 'localidad', 'provincia']);
});

test('el perfil sin credencial es 401 sin_sesion', function () {
    $this->getJson('/api/v1/perfil')
        ->assertStatus(401)
        ->assertJsonPath('codigo', 'sin_sesion');
});
