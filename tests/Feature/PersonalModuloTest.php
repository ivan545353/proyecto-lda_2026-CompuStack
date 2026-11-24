<?php

use App\Models\Empleado;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Cierra C-2 sobre este módulo. El AuthorizationHandlerMiddleware original
| traducía cada acción a una de cuatro banderas CRUD con `?? "can_update"`, así
| que toda acción fuera del CRUD —y cambiar el rol es exactamente eso— heredaba
| el permiso de actualizar.
|
| Se prueban las dos mitades: con todos los permisos del módulo MENOS el de la
| ruta se deniega, y con SÓLO el de la ruta se permite. Sin la primera mitad,
| una ruta protegida por el permiso equivocado pasaría desapercibida.
|
| Desde el tramo I las pantallas siguen en la web y las operaciones están en la
| API: las dos mitades se prueban sobre cada una.
|
*/

// Las pantallas, que siguen en la web.
dataset('rutas de usuarios', [
    'listado'           => ['get', 'personal.index',    'usuario.ver',         false],
    'formulario alta'   => ['get', 'personal.create',   'usuario.crear',       false],
    'formulario edicion'=> ['get', 'personal.edit',     'usuario.editar',      true],
    'pantalla de rol'   => ['get', 'personal.rol.edit', 'usuario.cambiar_rol', true],
]);

// Las operaciones, en la API (tramo I). `{usuario}` se reemplaza por el del test.
dataset('endpoints del personal', [
    'listado'          => ['get',    '/api/v1/personal',                                   'usuario.ver'],
    'ficha'            => ['get',    '/api/v1/personal/{usuario}',                         'usuario.ver'],
    'alta'             => ['post',   '/api/v1/personal',                                   'usuario.crear'],
    'edicion'          => ['patch',  '/api/v1/personal/{usuario}',                         'usuario.editar'],
    'baja'             => ['delete', '/api/v1/personal/{usuario}',                         'usuario.eliminar'],
    'cambio de rol'    => ['put',    '/api/v1/personal/{usuario}/rol',                     'usuario.cambiar_rol'],
    'enlace de acceso' => ['post',   '/api/v1/personal/{usuario}/restablecer-contrasena', 'usuario.resetear_password'],
]);

const PERMISOS_USUARIO = [
    'usuario.ver', 'usuario.crear', 'usuario.editar', 'usuario.eliminar', 'usuario.cambiar_rol','usuario.resetear_password',
];

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conUsuario) {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $url      = $conUsuario ? route($ruta, $objetivo) : route($ruta);

    $otros = array_values(array_diff(PERMISOS_USUARIO, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, datosDeEmpleado())
        ->assertForbidden();
})->with('rutas de usuarios');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conUsuario) {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $url      = $conUsuario ? route($ruta, $objetivo) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, datosDeEmpleado());

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de usuarios');

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    $otros    = array_values(array_diff(PERMISOS_USUARIO, [$permiso]));

    $this->json($metodo, str_replace('{usuario}', (string) $objetivo->id, $url), datosDeEmpleado(), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints del personal');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $respuesta = $this->json($metodo, str_replace('{usuario}', (string) $objetivo->id, $url), datosDeEmpleado(), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->not->toBe(403);
})->with('endpoints del personal');

test('las opciones de rol se piden con ver o con crear, y con ningun otro permiso', function () {
    // El filtro del listado y el alta las usan: quien puede dar de alta tiene que poder elegir el rol.
    $otros = array_values(array_diff(PERMISOS_USUARIO, ['usuario.ver', 'usuario.crear']));

    $this->getJson('/api/v1/personal/roles', bearer(usuarioCon(...$otros)))->assertForbidden();

    foreach (['usuario.ver', 'usuario.crear'] as $permiso) {
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/personal/roles', bearer(usuarioCon($permiso)))->assertOk();
    }
});

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('personal.index'))
        ->assertOk()
        ->assertDontSee(route('personal.create'))
        ->assertDontSee(route('personal.edit', $objetivo))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    // Desde el tramo I la fila es una plantilla del shell: quien sí tiene los permisos la recibe con los botones.
    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('usuario.ver', 'usuario.crear', 'usuario.editar', 'usuario.eliminar'))
        ->get(route('personal.index'))
        ->assertSee(route('personal.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

/*
|--------------------------------------------------------------------------
| C-1: el hash de contraseña no sale nunca
|--------------------------------------------------------------------------
|
| UserDao::list() hacía `SELECT u.*` y UserDto::toArray() incluía la clave, así
| que /user/list devolvía el hash bcrypt de todos los personal.
|
| $hidden solo actúa al serializar: un Resource que nombrara la columna la
| mandaría igual. Por eso son dos tests y no uno.
|
*/

/** El JSON tal cual se ve, sin las barras escapadas: un hash bcrypt tiene `/`. */
function jsonDelPersonal($respuesta): string
{
    return json_encode($respuesta->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

test('el listado no contiene ningun hash de contrasena', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $respuesta = $this->getJson('/api/v1/personal', bearer(usuarioCon('usuario.ver')))->assertOk();

    expect(jsonDelPersonal($respuesta))->not->toContain($objetivo->password)
        // El prefijo de bcrypt, por si algún día cambia la forma de imprimirlo.
        ->not->toContain('$2y$')
        ->and($respuesta->json('datos.0'))->not->toHaveKey('password');
});

test('el listado ni siquiera trae la columna de contrasena desde la base', function () {
    User::factory()->conRol('Vendedor')->create();
    $cabeceras = bearer(usuarioCon('usuario.ver'));

    // La consulta del listado es la que ordena por apellido; la de la credencial no cuenta. Se mira
    // sólo lo que trae de `users`: las subconsultas `exists (select * from roles…)` de los filtros no.
    $columnas = [];
    DB::listen(function ($consulta) use (&$columnas) {
        if (str_contains($consulta->sql, 'order by `apellido`')
            && preg_match('/^select (.*?) from `users`/', $consulta->sql, $partes)) {
            $columnas[] = $partes[1];
        }
    });

    $this->getJson('/api/v1/personal', $cabeceras)->assertOk();

    expect($columnas)->not->toBeEmpty()
        ->and(implode(' ', $columnas))->not->toContain('password')
        ->not->toContain('*');
});

test('el formulario de edicion tampoco expone el hash', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $objetivo->id]);
    $usuario = usuarioCon('usuario.ver', 'usuario.editar');

    $this->actingAs($usuario)
        ->get(route('personal.edit', $objetivo))
        ->assertOk()
        ->assertDontSee($objetivo->password, false);

    // Y la ficha que el formulario pide a la API, tampoco.
    $ficha = $this->getJson("/api/v1/personal/{$objetivo->id}", bearer($usuario))->assertOk();

    expect(jsonDelPersonal($ficha))->not->toContain($objetivo->password)
        ->and($ficha->json('datos'))->not->toHaveKey('password');
});

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en UsuarioFiltrosTest; estos
| prueban el cableado entre la URL y el scope, que es exactamente lo que estaba
| roto: el UserController mandaba `nombres` y el UserDao leía `perfil_id`.
|
*/

test('el parametro q llega al listado y filtra', function () {
    User::factory()->conRol('Vendedor')->create(['apellido' => 'Zurbarán']);
    User::factory()->conRol('Vendedor')->create(['apellido' => 'Medina']);

    $this->getJson('/api/v1/personal?q=zurbar', bearer(usuarioCon('usuario.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.apellido', 'Zurbarán');
});

test('el parametro rol_id llega al listado y filtra', function () {
    User::factory()->count(2)->conRol('Cajero')->create();
    User::factory()->conRol('Vendedor')->create();

    $this->getJson('/api/v1/personal?rol_id='.Rol::where('nombre', 'Cajero')->value('id'), bearer(usuarioCon('usuario.ver')))
        ->assertJsonPath('total', 2);
});


test('el parametro estado llega al listado y filtra', function () {
    User::factory()->conRol('Vendedor')->inactivo()->create();

    $this->getJson('/api/v1/personal?estado=inactivos', bearer(usuarioCon('usuario.ver')))
        ->assertJsonPath('total', 1);
});

test('el parametro situacion llega al listado y filtra', function () {
    $sefue = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $sefue->id, 'fecha_baja' => '2026-03-31']);

    $this->getJson('/api/v1/personal?situacion=dados_de_baja', bearer(usuarioCon('usuario.ver')))
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.situacion_texto', 'Baja el 31/03/2026');
});

test('un filtro inexistente se rechaza con el error en su campo', function () {
    // Antes volvía al listado limpio con un aviso; en la API un filtro inválido es 422 en su campo (HL-120).
    $this->getJson('/api/v1/personal?estado=cualquiera', bearer(usuarioCon('usuario.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estado', 'errores');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    User::factory()->count(20)->conRol('Vendedor')->create(['apellido' => 'Apellidodeprueba']);

    $cabeceras = bearer(usuarioCon('usuario.ver'));

    // Los enlaces de página los arma la pantalla con la consulta de la URL; la API devuelve el filtro aplicado.
    $this->getJson('/api/v1/personal?q=apellidodeprueba', $cabeceras)
        ->assertJsonCount(15, 'datos')
        ->assertJsonPath('por_pagina', 15)
        ->assertJsonPath('total', 20)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.q', 'apellidodeprueba');

    $this->getJson('/api/v1/personal?q=apellidodeprueba&pagina=2', $cabeceras)
        ->assertJsonCount(5, 'datos');
});

/*
|--------------------------------------------------------------------------
| Validación: rechaza, no vacía
|--------------------------------------------------------------------------
|
| M-31. setNombre() convertía en cadena vacía todo nombre de más de 100
| caracteres y setCorreo() hacía lo mismo con un mail inválido; el servicio
| informaba después "el correo es obligatorio", que no describe el problema.
|
*/

test('un correo invalido se rechaza, no se guarda y conserva lo escrito', function () {
    // Lo escrito se conserva porque la pantalla ya no se recarga: el 422 marca el campo y el formulario queda.
    $this->postJson('/api/v1/personal', datosDeEmpleado(['email' => 'no-es-un-correo']), bearer(usuarioCon('usuario.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email', 'errores');

    expect(User::where('apellido', 'Gutiérrez')->exists())->toBeFalse();
});

test('un correo repetido se rechaza con un mensaje que lo explica', function () {
    User::factory()->conRol('Vendedor')->create(['email' => 'sofia@sistema.local']);

    $this->postJson('/api/v1/personal', datosDeEmpleado(), bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors(['email' => 'Ya hay una cuenta con ese correo.'], 'errores');
});

test('las contrasenas que no coinciden se rechazan', function () {
    $this->postJson('/api/v1/personal', datosDeEmpleado([
        'password'              => 'Secreta123',
        'password_confirmation' => 'Otra456789',
    ]), bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors('password', 'errores');

    expect(User::where('email', 'sofia@sistema.local')->exists())->toBeFalse();
});

test('un rol de gestion exige los datos laborales', function () {
    $datos = datosDeEmpleado();
    unset($datos['empleado']);

    $this->postJson('/api/v1/personal', $datos, bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors('empleado', 'errores');
});


test('la fecha de ingreso no puede ser futura', function () {
    $this->postJson('/api/v1/personal', datosDeEmpleado([
        'empleado' => [
            'legajo'        => 'EMP-0031',
            'dni'           => '38123456',
            'fecha_ingreso' => now()->addWeek()->toDateString(),
        ],
    ]), bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors('empleado.fecha_ingreso', 'errores');
});

test('el acceso se envia siempre: si falta, se rechaza', function () {
    // M-31 en la API: ya no hay casilla que, desmarcada, no se envíe. Antes `activo` faltante se leía como `false`.
    $datos = datosDeEmpleado();
    unset($datos['activo']);

    $this->postJson('/api/v1/personal', $datos, bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors('activo', 'errores');

    expect(User::where('email', 'sofia@sistema.local')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Alta: el usuario y su satélite, en la misma transacción
|--------------------------------------------------------------------------
*/

test('el alta de personal crea la cuenta y su legajo', function () {
    $this->postJson('/api/v1/personal', datosDeEmpleado(), bearer(usuarioCon('usuario.crear')))
        ->assertCreated()
        ->assertJsonPath('mensaje', 'Se creó la cuenta de Gutiérrez, Sofía.')
        ->assertJsonPath('datos.empleado.legajo', 'EMP-0031');

    $creado = User::firstWhere('email', 'sofia@sistema.local');

    expect($creado->empleado->legajo)->toBe('EMP-0031')
        ->and($creado->cliente)->toBeNull();
});



/*
|--------------------------------------------------------------------------
| C-3: escalada de privilegios
|--------------------------------------------------------------------------
|
| UserController::update() armaba el DTO desde el body con `perfil_id` incluido
| y nunca comparaba el id del body contra el del token: cualquiera con permiso
| de edición se asignaba el perfil Administrador.
|
*/

test('enviar rol_id en la edicion se rechaza en vez de ignorarse', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $objetivo->id]);

    $this->patchJson("/api/v1/personal/{$objetivo->id}", datosDeEdicionDe($objetivo->fresh(), [
        'rol_id' => Rol::where('nombre', 'Administrador')->value('id'),
    ]), bearer(usuarioCon('usuario.editar')))
        ->assertJsonValidationErrors('rol_id', 'errores');

    expect($objetivo->fresh()->rol->nombre)->toBe('Vendedor');
});

test('nadie se cambia el rol a si mismo', function () {
    $usuario = usuarioCon('usuario.cambiar_rol');

    $this->putJson("/api/v1/personal/{$usuario->id}/rol", [
        'rol_id' => Rol::where('nombre', 'Administrador')->value('id'),
    ], bearer($usuario))
        ->assertJsonValidationErrors('rol_id', 'errores');

    expect($usuario->fresh()->rol->nombre)->not->toBe('Administrador');
});

test('no se asigna un rol de otro ambito', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->putJson("/api/v1/personal/{$objetivo->id}/rol", [
        'rol_id' => Rol::where('nombre', 'Cliente')->value('id'),
    ], bearer(usuarioCon('usuario.cambiar_rol')))
        ->assertJsonValidationErrors('rol_id', 'errores');

    expect($objetivo->fresh()->rol->nombre)->toBe('Vendedor');
});

test('el cambio de rol dentro del mismo ambito funciona', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->putJson("/api/v1/personal/{$objetivo->id}/rol", [
        'rol_id' => Rol::where('nombre', 'Cajero')->value('id'),
    ], bearer(usuarioCon('usuario.cambiar_rol')))
        ->assertOk()
        ->assertJsonPath('datos.rol.nombre', 'Cajero')
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'pasó a tener el rol Cajero'));

    expect($objetivo->fresh()->rol->nombre)->toBe('Cajero');
});

test('el permiso nuevo rige de inmediato, sin esperar a que expire nada', function () {
    // A-5: en el sistema original el perfil viajaba dentro del JWT, así que un
    // cambio de rol tardaba hasta una hora en surtir efecto. Acá Gate consulta
    // el rol en cada request, también con la credencial que ya tenía.
    $objetivo  = usuarioCon('usuario.ver');
    $suyo      = bearer($objetivo);

    $this->putJson("/api/v1/personal/{$objetivo->id}/rol", [
        'rol_id' => Rol::where('nombre', 'Cajero')->value('id'),
    ], bearer(usuarioCon('usuario.cambiar_rol')))->assertOk();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/personal', $suyo)->assertForbidden();

    app('auth')->forgetGuards();

    $this->actingAs($objetivo->fresh())
        ->get(route('personal.index'))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No quedarse afuera del sistema
|--------------------------------------------------------------------------
*/

test('nadie se quita el acceso a si mismo', function () {
    $usuario = usuarioCon('usuario.editar');

    $this->patchJson("/api/v1/personal/{$usuario->id}", datosDeEdicionDe($usuario, ['activo' => 0]), bearer($usuario))
        ->assertJsonValidationErrors('activo', 'errores');

    expect($usuario->fresh()->activo)->toBeTrue();
});

test('nadie elimina su propia cuenta', function () {
    $usuario = usuarioCon('usuario.eliminar');

    $this->deleteJson("/api/v1/personal/{$usuario->id}", [], bearer($usuario))
        ->assertConflict()
        ->assertJsonPath('codigo', 'regla_de_negocio')
        ->assertJsonPath('mensaje', 'No podés eliminar tu propia cuenta.');

    $this->assertModelExists($usuario);
});

test('no se desactiva la ultima cuenta que puede administrar usuarios', function () {
    // El que pide tiene 'usuario.editar', así que si desactivara al último OTRO
    // que lo tiene, seguiría habiendo uno. Se protege la capacidad, no el rol
    // llamado "Administrador" (M-33): acá ni siquiera existe ese rol en juego.
    $quienPide = usuarioCon('usuario.editar');
    $objetivo  = usuarioCon('usuario.editar');

    // Primero se va el que pide de la lista de capaces: queda sólo el objetivo.
    $quienPide->rol->permisos()->detach();
    \Illuminate\Support\Facades\Cache::forget(User::claveCache($quienPide->rol_id));

    // Con el permiso quitado ya no puede entrar a la ruta, así que el escenario
    // real es al revés: el objetivo es el último, y lo intenta desactivar quien
    // sí conserva el permiso.
    $ultimo    = usuarioCon('usuario.editar');
    $otroAdmin = usuarioCon('usuario.editar', 'usuario.ver');

    $otroAdmin->update(['activo' => false]);

    $this->patchJson("/api/v1/personal/{$ultimo->id}", datosDeEdicionDe($ultimo, ['activo' => 0]), bearer($ultimo))
        ->assertJsonValidationErrors('activo', 'errores');

    expect($ultimo->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| M-16: lo que está referenciado no se borra
|--------------------------------------------------------------------------
*/

test('una cuenta sin historial se elimina', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->deleteJson("/api/v1/personal/{$objetivo->id}", [], bearer(usuarioCon('usuario.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'Se eliminó'));

    $this->assertModelMissing($objetivo);
});

test('una cuenta con ventas se desactiva y el mensaje lo dice', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    DB::table('ventas')->insert([
        'usuario_id' => $objetivo->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson("/api/v1/personal/{$objetivo->id}", [], bearer(usuarioCon('usuario.eliminar')))
        // El mensaje dice lo que pasó de verdad: informar "cuenta eliminada"
        // cuando en realidad quedó desactivada es mentirle al usuario sobre el
        // estado del sistema.
        ->assertJsonPath('datos.eliminado', false)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'historial'));

    $this->assertModelExists($objetivo);
    expect($objetivo->fresh()->activo)->toBeFalse();
});

test('cargar la fecha de baja no quita el acceso', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create([
        'user_id'       => $objetivo->id,
        'fecha_ingreso' => '2025-01-01',
        'fecha_baja'    => null,
    ]);

    $this->patchJson("/api/v1/personal/{$objetivo->id}", datosDeEdicionDe($objetivo->fresh(), [
        'empleado' => array_merge(
            $objetivo->empleado->only(['legajo', 'dni', 'telefono']),
            [
                'fecha_ingreso' => $objetivo->empleado->fecha_ingreso->toDateString(),
                'fecha_baja'    => '2026-08-31',
            ],
        ),
    ]), bearer(usuarioCon('usuario.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', "Se guardaron los datos de {$objetivo->nombre_completo}.");

    $objetivo = $objetivo->fresh();

    expect($objetivo->empleado->fecha_baja->toDateString())->toBe('2026-08-31')
        ->and($objetivo->activo)->toBeTrue();
});

test('quitarle el acceso a otra persona lo dice en el mensaje', function () {
    // Que alguien pierda el acceso es un efecto que se nombra, no se esconde en un «se guardó».
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $objetivo->id]);

    $this->patchJson("/api/v1/personal/{$objetivo->id}", datosDeEdicionDe($objetivo->fresh(), ['activo' => false]), bearer(usuarioCon('usuario.editar')))
        ->assertOk()
        ->assertJsonPath('datos.activo', false)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'se le quitó el acceso al sistema'));
});

test('el listado no muestra las cuentas de clientes', function () {
    // El problema que este paso corrige. Antes el ámbito era un filtro
    // opcional, así que el cajero y un cliente de la tienda aparecían en la
    // misma lista sin que nada lo dijera.
    $empleado = User::factory()->conRol('Vendedor')->create(['apellido' => 'Gutiérrez']);
    $cliente  = User::factory()->conRol('Cliente')->create(['apellido' => 'Herrera']);

    $respuesta = $this->getJson('/api/v1/personal', bearer(usuarioCon('usuario.ver')))->assertOk();

    expect(collect($respuesta->json('datos'))->pluck('id'))->toContain($empleado->id)
        ->not->toContain($cliente->id)
        ->and(jsonDelPersonal($respuesta))->not->toContain('Herrera')
        ->and($cliente->esDeGestion())->toBeFalse();
});

test('el filtro de rol solo ofrece roles del personal', function () {
    $ids = collect($this->getJson('/api/v1/personal/roles', bearer(usuarioCon('usuario.ver')))->assertOk()->json('datos'))
        ->pluck('id');

    expect($ids)->not->toBeEmpty()
        ->and(Rol::whereIn('id', $ids)->get()->every(fn ($rol) => $rol->ambito === 'gestion'))->toBeTrue()
        ->and($ids)->not->toContain(Rol::where('nombre', 'Cliente')->value('id'));
});

test('el alta rechaza un rol de tienda', function () {
    // La capacidad de crear una cuenta de tienda existe en UsuarioService y
    // está probada, pero ninguna pantalla de la Etapa 1 la ofrece: en la Etapa
    // 1 no hay tienda.
    $this->postJson('/api/v1/personal', datosDeEmpleado([
        'rol_id' => Rol::where('nombre', 'Cliente')->value('id'),
    ]), bearer(usuarioCon('usuario.crear')))
        ->assertJsonValidationErrors('rol_id', 'errores');

    expect(User::where('email', 'sofia@sistema.local')->exists())->toBeFalse();
});

test('una cuenta de cliente no se abre desde la pantalla de personal', function () {
    // El binding implícito resuelve cualquier fila de `users`. Sin el guardián
    // del controlador, la ficha de un cliente de la tienda se editaría desde
    // acá. Es el mismo descuido que el de las direcciones anidadas.
    $cliente = User::factory()->conRol('Cliente')->create();

    $usuario   = usuarioCon('usuario.ver', 'usuario.editar', 'usuario.eliminar', 'usuario.cambiar_rol', 'usuario.resetear_password');
    $cabeceras = bearer($usuario);

    $this->getJson("/api/v1/personal/{$cliente->id}", $cabeceras)->assertNotFound();
    $this->patchJson("/api/v1/personal/{$cliente->id}", datosDeEmpleado(), $cabeceras)->assertNotFound();
    $this->deleteJson("/api/v1/personal/{$cliente->id}", [], $cabeceras)->assertNotFound();
    $this->putJson("/api/v1/personal/{$cliente->id}/rol", ['rol_id' => Rol::where('nombre', 'Cliente')->value('id')], $cabeceras)->assertNotFound();
    $this->postJson("/api/v1/personal/{$cliente->id}/restablecer-contrasena", [], $cabeceras)->assertNotFound();

    app('auth')->forgetGuards();

    $this->actingAs($usuario)->get(route('personal.edit', $cliente))->assertNotFound();
    $this->actingAs($usuario)->get(route('personal.rol.edit', $cliente))->assertNotFound();

    $this->assertModelExists($cliente);
});

/*
|--------------------------------------------------------------------------
| La ficha, representación por permiso (regla 36)
|--------------------------------------------------------------------------
|
| Lo que la pantalla de cambio de rol necesita —lo que el rol actual permite y los
| roles asignables— viaja sólo con `usuario.cambiar_rol`. Sin él, la clave no está.
|
*/

test('sin el permiso de cambiar el rol, la ficha no trae el rol en detalle ni los asignables', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->getJson("/api/v1/personal/{$objetivo->id}", bearer(usuarioCon('usuario.ver', 'usuario.editar')))
        ->assertOk()
        ->assertJsonPath('datos.rol.nombre', 'Vendedor')
        ->assertJsonMissingPath('datos.rol.permisos')
        ->assertJsonMissingPath('datos.rol.descripcion')
        ->assertJsonMissingPath('datos.roles_asignables');
});

test('con el permiso de cambiar el rol, la ficha trae lo que hoy puede y los roles de su ambito', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $ficha = $this->getJson("/api/v1/personal/{$objetivo->id}", bearer(usuarioCon('usuario.ver', 'usuario.cambiar_rol')))
        ->assertOk()
        ->assertJsonPath('datos.es_la_propia', false);

    $asignables = collect($ficha->json('datos.roles_asignables'));

    expect($ficha->json('datos.rol.permisos'))->not->toBeEmpty()
        ->and($asignables->firstWhere('actual', true)['nombre'])->toBe('Vendedor')
        ->and($asignables->pluck('nombre'))->not->toContain('Cliente');
});

/*
|--------------------------------------------------------------------------
| Las rutas web retiradas y los shells (tramo I)
|--------------------------------------------------------------------------
|
| La web sólo entrega las pantallas: el alta, la edición, la baja, el cambio de
| rol y el enlace van por `/api/v1/personal`. Cada URL vieja se prueba literal,
| con todos los permisos del módulo, para que no la salve un 403.
|
*/

dataset('rutas web retiradas del personal', [
    'alta'             => ['post',   '/personal'],
    'guardar edicion'  => ['put',    '/personal/{usuario}'],
    'baja'             => ['delete', '/personal/{usuario}'],
    'cambio de rol'    => ['patch',  '/personal/{usuario}/rol'],
    'enlace de acceso' => ['post',   '/personal/{usuario}/restablecer-contrasena'],
]);

test('la ruta web retirada del personal ya no existe', function (string $metodo, string $url) {
    $objetivo = User::factory()->conRol('Vendedor')->create(['nombre' => 'Original']);
    $quien    = usuarioCon(...PERMISOS_USUARIO);
    $antes    = User::count();

    $respuesta = pedirRuta(
        $this->actingAs($quien),
        $metodo,
        str_replace('{usuario}', (string) $objetivo->id, $url),
        datosDeEmpleado(['nombre' => 'Cambiado', 'rol_id' => Rol::where('nombre', 'Cajero')->value('id')]),
    );

    $objetivo = $objetivo->fresh();

    expect($respuesta->status())->toBeIn([404, 405])
        ->and($objetivo)->not->toBeNull()
        ->and($objetivo->nombre)->toBe('Original')
        ->and($objetivo->rol->nombre)->toBe('Vendedor')
        ->and(User::count())->toBe($antes)
        ->and(DB::table('password_reset_tokens')->where('email', $objetivo->email)->exists())->toBeFalse();
})->with('rutas web retiradas del personal');

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells del personal no traen datos de negocio', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create(['nombre' => 'Persona', 'apellido' => 'Reservadísima', 'email' => 'reservada@sistema.local']);
    Empleado::factory()->create(['user_id' => $objetivo->id, 'legajo' => 'LEG-SECRETO']);
    $usuario = usuarioCon('usuario.ver', 'usuario.crear', 'usuario.editar', 'usuario.cambiar_rol', 'usuario.resetear_password');

    $this->actingAs($usuario)->get(route('personal.index'))
        ->assertOk()
        ->assertDontSee('Reservadísima')
        ->assertDontSee('Vendedor')
        ->assertSee('data-pantalla="personal"', false);

    $this->actingAs($usuario)->get(route('personal.create'))
        ->assertOk()
        ->assertDontSee('Cajero')
        ->assertSee('data-formulario-personal', false);

    $this->actingAs($usuario)->get(route('personal.edit', $objetivo))
        ->assertOk()
        ->assertDontSee('Reservadísima')
        ->assertDontSee('reservada@sistema.local')
        ->assertDontSee('LEG-SECRETO')
        ->assertSee('data-usuario="'.$objetivo->id.'"', false);

    $this->actingAs($usuario)->get(route('personal.rol.edit', $objetivo))
        ->assertOk()
        ->assertDontSee('Reservadísima')
        ->assertDontSee('Cajero')
        ->assertSee('data-formulario-rol', false);
});
