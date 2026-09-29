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
*/

dataset('rutas de usuarios', [
    'listado'           => ['get',    'usuarios.index',      'usuario.ver',         false],
    'formulario alta'   => ['get',    'usuarios.create',     'usuario.crear',       false],
    'alta'              => ['post',   'usuarios.store',      'usuario.crear',       false],
    'formulario edicion'=> ['get',    'usuarios.edit',       'usuario.editar',      true],
    'edicion'           => ['put',    'usuarios.update',     'usuario.editar',      true],
    'baja'              => ['delete', 'usuarios.destroy',    'usuario.eliminar',    true],
    'pantalla de rol'   => ['get',    'usuarios.rol.edit',   'usuario.cambiar_rol', true],
    'cambio de rol'     => ['patch',  'usuarios.rol.update', 'usuario.cambiar_rol', true],
]);

const PERMISOS_USUARIO = [
    'usuario.ver', 'usuario.crear', 'usuario.editar', 'usuario.eliminar', 'usuario.cambiar_rol',
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

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index'))
        ->assertOk()
        ->assertDontSee(route('usuarios.create'))
        ->assertDontSee(route('usuarios.edit', $objetivo));
});

/*
|--------------------------------------------------------------------------
| C-1: el hash de contraseña no sale nunca
|--------------------------------------------------------------------------
|
| UserDao::list() hacía `SELECT u.*` y UserDto::toArray() incluía la clave, así
| que /user/list devolvía el hash bcrypt de todos los usuarios.
|
| $hidden solo actúa al serializar: en una vista Blade `{{ $u->password }}`
| imprimiría el hash igual. Por eso son dos tests y no uno.
|
*/

test('el listado no contiene ningun hash de contrasena', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $respuesta = $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index'))
        ->assertOk();

    $respuesta->assertDontSee($objetivo->password, false);
    // El prefijo de bcrypt, por si algún día cambia la forma de imprimirlo.
    $respuesta->assertDontSee('$2y$', false);
});

test('el listado ni siquiera trae la columna de contrasena desde la base', function () {
    User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index'))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios
            ->every(fn ($usuario) => $usuario->password === null));
});

test('el formulario de edicion tampoco expone el hash', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $objetivo->id]);

    $this->actingAs(usuarioCon('usuario.editar'))
        ->get(route('usuarios.edit', $objetivo))
        ->assertOk()
        ->assertDontSee($objetivo->password, false);
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

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['q' => 'zurbar']))
        ->assertOk()
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === 1
            && $usuarios->first()->apellido === 'Zurbarán');
});

test('el parametro rol_id llega al listado y filtra', function () {
    User::factory()->count(2)->conRol('Cajero')->create();
    User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['rol_id' => Rol::where('nombre', 'Cajero')->value('id')]))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === 2);
});

test('el parametro ambito llega al listado y filtra', function () {
    User::factory()->conRol('Cliente')->create();

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['ambito' => 'tienda']))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === 1);
});

test('el parametro estado llega al listado y filtra', function () {
    User::factory()->conRol('Vendedor')->inactivo()->create();

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['estado' => 'inactivos']))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === 1);
});

test('el parametro situacion llega al listado y filtra', function () {
    $sefue = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $sefue->id, 'fecha_baja' => '2026-03-31']);

    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['situacion' => 'dados_de_baja']))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->total() === 1);
});

test('un filtro inexistente vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('usuario.ver'))
        ->get(route('usuarios.index', ['estado' => 'cualquiera']))
        ->assertRedirect(route('usuarios.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    User::factory()->count(20)->conRol('Vendedor')->create(['apellido' => 'Apellidodeprueba']);

    $usuario = usuarioCon('usuario.ver');

    $this->actingAs($usuario)
        ->get(route('usuarios.index', ['q' => 'apellidodeprueba']))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->count() === 15
            && $usuarios->total() === 20
            // Sin withQueryString(), la página 2 perdería el filtro.
            && str_contains($usuarios->nextPageUrl(), 'q=apellidodeprueba'));

    $this->actingAs($usuario)
        ->get(route('usuarios.index', ['q' => 'apellidodeprueba', 'page' => 2]))
        ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->count() === 5);
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
    $this->actingAs(usuarioCon('usuario.crear'))
        ->from(route('usuarios.create'))
        ->post(route('usuarios.store'), datosDeEmpleado(['email' => 'no-es-un-correo']))
        ->assertRedirect(route('usuarios.create'))
        ->assertSessionHasErrors('email')
        ->assertSessionHasInput('email', 'no-es-un-correo');

    expect(User::where('apellido', 'Gutiérrez')->exists())->toBeFalse();
});

test('un correo repetido se rechaza con un mensaje que lo explica', function () {
    User::factory()->conRol('Vendedor')->create(['email' => 'sofia@sistema.local']);

    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeEmpleado())
        ->assertSessionHasErrors(['email' => 'Ya hay una cuenta con ese correo.']);
});

test('las contrasenas que no coinciden se rechazan', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeEmpleado([
            'password'              => 'Secreta123',
            'password_confirmation' => 'Otra456789',
        ]))
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'sofia@sistema.local')->exists())->toBeFalse();
});

test('un rol de gestion exige los datos laborales', function () {
    $datos = datosDeEmpleado();
    unset($datos['empleado']);

    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), $datos)
        ->assertSessionHasErrors('empleado');
});

test('un rol de tienda exige los datos de facturacion, no los laborales', function () {
    $datos = datosDeClienteDeTienda();
    unset($datos['cliente']);

    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), $datos)
        ->assertSessionHasErrors('cliente')
        // Qué se exige lo decide roles.ambito, no el nombre del rol (M-33).
        ->assertSessionDoesntHaveErrors('empleado');
});

test('quien factura A necesita numero de documento', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeClienteDeTienda([
            'cliente' => [
                'razon_social'  => 'Estudio Austral S.R.L.',
                'tipo_doc'      => 'cuit',
                'nro_doc'       => '',
                'condicion_iva' => 'responsable_inscripto',
            ],
        ]))
        ->assertSessionHasErrors('cliente.nro_doc');
});

test('un documento mal escrito se rechaza por lo que es, no por estar vacio', function () {
    // M-31 en su forma exacta: el setter original vaciaba el valor y el mensaje
    // resultante decía "es obligatorio", que manda al usuario a completar un
    // campo que sí completó.
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeClienteDeTienda([
            'cliente' => [
                'razon_social'  => 'Estudio Austral S.R.L.',
                'tipo_doc'      => 'cuit',
                'nro_doc'       => '30-ABC-1',
                'condicion_iva' => 'responsable_inscripto',
            ],
        ]))
        ->assertSessionHasErrors(['cliente.nro_doc' => 'El CUIT/CUIL tiene 11 dígitos.']);
});

test('la fecha de ingreso no puede ser futura', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeEmpleado([
            'empleado' => [
                'legajo'        => 'EMP-0031',
                'dni'           => '38123456',
                'fecha_ingreso' => now()->addWeek()->toDateString(),
            ],
        ]))
        ->assertSessionHasErrors('empleado.fecha_ingreso');
});

/*
|--------------------------------------------------------------------------
| Alta: el usuario y su satélite, en la misma transacción
|--------------------------------------------------------------------------
*/

test('el alta de personal crea la cuenta y su legajo', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeEmpleado())
        ->assertRedirect(route('usuarios.index'))
        ->assertSessionHas('exito');

    $creado = User::firstWhere('email', 'sofia@sistema.local');

    expect($creado->empleado->legajo)->toBe('EMP-0031')
        ->and($creado->cliente)->toBeNull();
});

test('el alta de una cuenta de tienda crea la ficha de cliente', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeClienteDeTienda())
        ->assertSessionHas('exito');

    $creado = User::firstWhere('email', 'camila@sistema.local');

    expect($creado->cliente->condicion_iva)->toBe('consumidor_final')
        ->and($creado->empleado)->toBeNull();
});

test('el cuit se guarda normalizado aunque se escriba con guiones', function () {
    $this->actingAs(usuarioCon('usuario.crear'))
        ->post(route('usuarios.store'), datosDeClienteDeTienda([
            'cliente' => [
                'razon_social'  => 'Estudio Austral S.R.L.',
                'tipo_doc'      => 'cuit',
                'nro_doc'       => '30-71555888-1',
                'condicion_iva' => 'responsable_inscripto',
            ],
        ]))
        ->assertSessionHas('exito');

    expect(User::firstWhere('email', 'camila@sistema.local')->cliente->nro_doc)
        ->toBe('30715558881');
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

    $this->actingAs(usuarioCon('usuario.editar'))
        ->put(route('usuarios.update', $objetivo), datosDeEdicionDe($objetivo->fresh(), [
            'rol_id' => Rol::where('nombre', 'Administrador')->value('id'),
        ]))
        ->assertSessionHasErrors('rol_id');

    expect($objetivo->fresh()->rol->nombre)->toBe('Vendedor');
});

test('nadie se cambia el rol a si mismo', function () {
    $usuario = usuarioCon('usuario.cambiar_rol');

    $this->actingAs($usuario)
        ->patch(route('usuarios.rol.update', $usuario), [
            'rol_id' => Rol::where('nombre', 'Administrador')->value('id'),
        ])
        ->assertSessionHasErrors('rol_id');

    expect($usuario->fresh()->rol->nombre)->not->toBe('Administrador');
});

test('no se asigna un rol de otro ambito', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.cambiar_rol'))
        ->patch(route('usuarios.rol.update', $objetivo), [
            'rol_id' => Rol::where('nombre', 'Cliente')->value('id'),
        ])
        ->assertSessionHasErrors('rol_id');

    expect($objetivo->fresh()->rol->nombre)->toBe('Vendedor');
});

test('el cambio de rol dentro del mismo ambito funciona', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.cambiar_rol'))
        ->patch(route('usuarios.rol.update', $objetivo), [
            'rol_id' => Rol::where('nombre', 'Cajero')->value('id'),
        ])
        ->assertRedirect(route('usuarios.edit', $objetivo))
        ->assertSessionHas('exito');

    expect($objetivo->fresh()->rol->nombre)->toBe('Cajero');
});

test('el permiso nuevo rige de inmediato, sin esperar a que expire nada', function () {
    // A-5: en el sistema original el perfil viajaba dentro del JWT, así que un
    // cambio de rol tardaba hasta una hora en surtir efecto. Acá Gate consulta
    // el rol en cada request.
    $objetivo = usuarioCon('usuario.ver');

    $this->actingAs(usuarioCon('usuario.cambiar_rol'))
        ->patch(route('usuarios.rol.update', $objetivo), [
            'rol_id' => Rol::where('nombre', 'Cajero')->value('id'),
        ]);

    $this->actingAs($objetivo->fresh())
        ->get(route('usuarios.index'))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No quedarse afuera del sistema
|--------------------------------------------------------------------------
*/

test('nadie se quita el acceso a si mismo', function () {
    $usuario = usuarioCon('usuario.editar');

    $this->actingAs($usuario)
        ->put(route('usuarios.update', $usuario), datosDeEdicionDe($usuario, ['activo' => 0]))
        ->assertSessionHasErrors('activo');

    expect($usuario->fresh()->activo)->toBeTrue();
});

test('nadie elimina su propia cuenta', function () {
    $usuario = usuarioCon('usuario.eliminar');

    $this->actingAs($usuario)
        ->delete(route('usuarios.destroy', $usuario))
        ->assertSessionHas('error');

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

    $this->actingAs($ultimo)
        ->put(route('usuarios.update', $ultimo), datosDeEdicionDe($ultimo, ['activo' => 0]))
        ->assertSessionHasErrors('activo');

    expect($ultimo->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| M-16: lo que está referenciado no se borra
|--------------------------------------------------------------------------
*/

test('una cuenta sin historial se elimina', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    $this->actingAs(usuarioCon('usuario.eliminar'))
        ->delete(route('usuarios.destroy', $objetivo))
        ->assertRedirect(route('usuarios.index'))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'Se eliminó'));

    $this->assertModelMissing($objetivo);
});

test('una cuenta con ventas se desactiva y el mensaje lo dice', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();

    DB::table('ventas')->insert([
        'usuario_id' => $objetivo->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(usuarioCon('usuario.eliminar'))
        ->delete(route('usuarios.destroy', $objetivo))
        // El mensaje dice lo que pasó de verdad: informar "cuenta eliminada"
        // cuando en realidad quedó desactivada es mentirle al usuario sobre el
        // estado del sistema.
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'historial'));

    $this->assertModelExists($objetivo);
    expect($objetivo->fresh()->activo)->toBeFalse();
});

test('cargar la fecha de baja no quita el acceso', function () {
    $objetivo = User::factory()->conRol('Vendedor')->create();
    Empleado::factory()->create(['user_id' => $objetivo->id, 'fecha_baja' => null]);

    $this->actingAs(usuarioCon('usuario.editar'))
        ->put(route('usuarios.update', $objetivo), datosDeEdicionDe($objetivo->fresh(), [
            'empleado' => array_merge(
                $objetivo->empleado->only(['legajo', 'dni', 'telefono']),
                [
                    'fecha_ingreso' => $objetivo->empleado->fecha_ingreso->toDateString(),
                    'fecha_baja'    => '2026-08-31',
                ],
            ),
        ]))
        ->assertSessionHas('exito');

    $objetivo = $objetivo->fresh();

    expect($objetivo->empleado->fecha_baja->toDateString())->toBe('2026-08-31')
        ->and($objetivo->activo)->toBeTrue();
});