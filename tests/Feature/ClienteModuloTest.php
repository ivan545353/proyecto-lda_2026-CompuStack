<?php

use App\Models\Cliente;
use App\Models\Direccion;
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
| que una acción podía quedar habilitada por un permiso que no era el suyo.
|
| Las cinco rutas de direcciones exigen `cliente.editar` a propósito: una
| dirección no tiene existencia autónoma —el esquema la borra en cascada con su
| cliente— y su baja no va con `cliente.eliminar`, que significa dar de baja al
| cliente. Este dataset es lo que fija esa decisión.
|
*/

// Las pantallas, que siguen en la web.
dataset('rutas de clientes', [
    'listado'            => ['get',    'clientes.index',       'cliente.ver',      'ninguno'],
    'formulario alta'    => ['get',    'clientes.create',      'cliente.crear',    'ninguno'],
    'formulario edicion' => ['get',    'clientes.edit',        'cliente.editar',   'cliente'],
    'alta de direccion'  => ['get',    'direcciones.create',   'cliente.editar',   'cliente'],
    'editar direccion'   => ['get',    'direcciones.edit',     'cliente.editar',   'direccion'],
]);

// Las operaciones, en la API (tramo E de la Etapa 3). `{cliente}` y `{direccion}` se reemplazan por los del test.
dataset('endpoints de clientes', [
    'listado'           => ['get',    '/api/v1/clientes',                              'cliente.ver'],
    'ficha'             => ['get',    '/api/v1/clientes/{cliente}',                    'cliente.ver'],
    'alta'              => ['post',   '/api/v1/clientes',                              'cliente.crear'],
    'edicion'           => ['patch',  '/api/v1/clientes/{cliente}',                    'cliente.editar'],
    'baja'              => ['delete', '/api/v1/clientes/{cliente}',                    'cliente.eliminar'],
    'guardar direccion' => ['post',   '/api/v1/clientes/{cliente}/direcciones',        'cliente.editar'],
    'guardar edicion'   => ['patch',  '/api/v1/clientes/{cliente}/direcciones/{direccion}', 'cliente.editar'],
    'baja de direccion' => ['delete', '/api/v1/clientes/{cliente}/direcciones/{direccion}', 'cliente.editar'],
]);

const PERMISOS_CLIENTE = ['cliente.ver', 'cliente.crear', 'cliente.editar', 'cliente.eliminar'];

/** Arma la URL de la ruta del dataset según qué parámetros lleve. */
function urlDeCliente(string $ruta, string $tipo, Cliente $cliente, Direccion $direccion): string
{
    return match ($tipo) {
        'ninguno'   => route($ruta),
        'cliente'   => route($ruta, $cliente),
        'direccion' => route($ruta, [$cliente, $direccion]),
    };
}

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, string $tipo) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $otros = array_values(array_diff(PERMISOS_CLIENTE, [$permiso]));

    pedirRuta(
        $this->actingAs(usuarioCon(...$otros)),
        $metodo,
        urlDeCliente($ruta, $tipo, $cliente, $direccion),
        array_merge(datosDeCliente(), datosDeDireccion()),
    )->assertForbidden();
})->with('rutas de clientes');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, string $tipo) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $respuesta = pedirRuta(
        $this->actingAs(usuarioCon($permiso)),
        $metodo,
        urlDeCliente($ruta, $tipo, $cliente, $direccion),
        array_merge(datosDeCliente(), datosDeDireccion()),
    );

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de clientes');

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    $otros     = array_values(array_diff(PERMISOS_CLIENTE, [$permiso]));

    $this->json($metodo, str_replace(['{cliente}', '{direccion}'], [$cliente->id, $direccion->id], $url), array_merge(datosDeCliente(), datosDeDireccion()), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de clientes');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $respuesta = $this->json($metodo, str_replace(['{cliente}', '{direccion}'], [$cliente->id, $direccion->id], $url), array_merge(datosDeCliente(), datosDeDireccion()), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de clientes');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $cliente = Cliente::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index'))
        ->assertOk()
        ->assertDontSee(route('clientes.create'))
        ->assertDontSee(route('clientes.edit', $cliente))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    // Desde el tramo E la fila es una plantilla del shell: quien sí tiene los permisos la recibe con los botones.
    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('cliente.ver', 'cliente.crear', 'cliente.editar', 'cliente.eliminar'))
        ->get(route('clientes.index'))
        ->assertSee(route('clientes.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de clientes no traen datos de negocio', function () {
    $cliente = Cliente::factory()->create(['razon_social' => 'Cliente Secreto S.R.L.', 'email' => 'secreto@ejemplo.com']);
    Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id, 'calle' => 'Calle Reservada']);
    $usuario = usuarioCon('cliente.ver', 'cliente.editar');

    $this->actingAs($usuario)->get(route('clientes.index'))
        ->assertOk()
        ->assertDontSee('Cliente Secreto')
        ->assertSee('data-pantalla="clientes"', false);

    $this->actingAs($usuario)->get(route('clientes.edit', $cliente))
        ->assertOk()
        ->assertDontSee('Cliente Secreto')
        ->assertDontSee('secreto@ejemplo.com')
        ->assertDontSee('Calle Reservada')
        ->assertSee('data-cliente="'.$cliente->id.'"', false);
});

/*
|--------------------------------------------------------------------------
| El anidamiento: una dirección no se abre desde la ficha equivocada
|--------------------------------------------------------------------------
|
| Laravel resuelve cada parámetro de la ruta por separado y no verifica que
| estén relacionados. El controlador busca la dirección DENTRO de las del
| cliente; sin eso, /clientes/1/direcciones/99 editaría una dirección del
| cliente 2 desde la ficha del 1.
|
| No se usa scopeBindings(), que sería la forma automática, porque deduce el
| nombre de la relación pluralizando en inglés y Str::plural('direccion') no
| devuelve 'direcciones'.
|
*/

test('la direccion de otro cliente da 404', function () {
    $propietario = Cliente::factory()->create();
    $ajeno       = Cliente::factory()->create();

    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $propietario->id]);

    $usuario = usuarioCon('cliente.editar');

    // La página de edición también la busca dentro del cliente: una dirección ajena no se abre desde otra ficha.
    $this->actingAs($usuario)
        ->get(route('direcciones.edit', [$ajeno, $direccion]))
        ->assertNotFound();

    $encabezado = bearer($usuario);

    $this->patchJson("/api/v1/clientes/{$ajeno->id}/direcciones/{$direccion->id}", datosDeDireccion(), $encabezado)
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrado');

    $this->deleteJson("/api/v1/clientes/{$ajeno->id}/direcciones/{$direccion->id}", [], $encabezado)
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrado');

    // Y no se tocó nada de la dirección real.
    $this->assertModelExists($direccion);
    expect($direccion->fresh()->cliente_id)->toBe($propietario->id);
});

test('no se puede mudar una direccion a otro cliente enviando su id', function () {
    $cliente   = Cliente::factory()->create();
    $ajeno     = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    // El cliente lo determina la ruta, no el cuerpo de la petición. Está
    // declarado prohibido para que el intento se vea en vez de ignorarse:
    // mismo criterio con el que se cerró C-3.
    $this->patchJson("/api/v1/clientes/{$cliente->id}/direcciones/{$direccion->id}", datosDeDireccion([
        'cliente_id' => $ajeno->id,
    ]), bearer(usuarioCon('cliente.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cliente_id', 'errores');

    expect($direccion->fresh()->cliente_id)->toBe($cliente->id);
});

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en ClienteFiltrosTest; estos prueban
| el cableado entre la URL y el scope, que es lo que estaba roto en el original.
|
*/

test('el parametro q llega al listado y filtra', function () {
    Cliente::factory()->create(['razon_social' => 'Estudio Austral S.R.L.']);
    Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    $this->getJson('/api/v1/clientes?q=austral', bearer(usuarioCon('cliente.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.razon_social', 'Estudio Austral S.R.L.')
        ->assertJsonPath('criterios.q', 'austral');
});

test('se puede buscar un cuit pegado con guiones', function () {
    // Se lee de una factura como 30-71555888-1 y se guarda sin separadores.
    Cliente::factory()->responsableInscripto()->create(['nro_doc' => '30715558881']);
    Cliente::factory()->create(['nro_doc' => '41556778']);

    $this->getJson('/api/v1/clientes?q=30-71555888-1', bearer(usuarioCon('cliente.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('el parametro condicion_iva llega al listado y filtra', function () {
    Cliente::factory()->count(2)->create(['condicion_iva' => 'consumidor_final']);
    Cliente::factory()->responsableInscripto()->create();

    $this->getJson('/api/v1/clientes?condicion_iva=responsable_inscripto', bearer(usuarioCon('cliente.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('criterios.condicion_iva', 'responsable_inscripto');
});

test('el parametro cuenta llega al listado y filtra', function () {
    Cliente::factory()->count(2)->create();
    Cliente::factory()->conCuenta()->create();

    $this->getJson('/api/v1/clientes?cuenta=con_cuenta', bearer(usuarioCon('cliente.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.con_cuenta', true)
        ->assertJsonPath('criterios.cuenta', 'con_cuenta');
});

// En la API, el aviso es el 422: el listado no muestra otra cosa que la pedida.
test('un filtro inexistente se rechaza con aviso', function () {
    $this->getJson('/api/v1/clientes?condicion_iva=cualquiera', bearer(usuarioCon('cliente.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('condicion_iva', 'errores');
});

test('el listado pagina de a 20 y criterios conserva el filtro', function () {
    Cliente::factory()->count(25)
        ->sequence(fn ($s) => ['razon_social' => "Comercio Austral {$s->index}"])
        ->create();

    $encabezado = bearer(usuarioCon('cliente.ver'));

    // `criterios` es lo que la pantalla repite al pedir la página siguiente: sin él, perdería el filtro.
    $this->getJson('/api/v1/clientes?q=austral', $encabezado)
        ->assertJsonCount(20, 'datos')
        ->assertJsonPath('total', 25)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.q', 'austral');

    $this->getJson('/api/v1/clientes?q=austral&pagina=2', $encabezado)
        ->assertJsonCount(5, 'datos');
});

test('el listado trae lo que la pantalla muestra: condicion, comprobante, cuenta y direcciones', function () {
    $mostrador = Cliente::factory()->responsableInscripto()->create(['razon_social' => 'Estudio Austral S.R.L.']);
    Direccion::factory()->count(2)->create(['cliente_id' => $mostrador->id]);

    $this->getJson('/api/v1/clientes', bearer(usuarioCon('cliente.ver')))
        ->assertOk()
        ->assertJsonPath('datos.0.condicion_iva', 'responsable_inscripto')
        ->assertJsonPath('datos.0.condicion_iva_texto', 'Responsable inscripto')
        ->assertJsonPath('datos.0.tipo_comprobante', 'factura_a')
        ->assertJsonPath('datos.0.tipo_comprobante_texto', 'Factura A')
        ->assertJsonPath('datos.0.con_cuenta', false)
        ->assertJsonPath('datos.0.cuenta_email', null)
        ->assertJsonPath('datos.0.cantidad_direcciones', 2);
});

test('la ficha trae las direcciones del cliente con la predeterminada primero, y el listado no las trae', function () {
    $cliente = Cliente::factory()->create();
    $vieja   = Direccion::factory()->create(['cliente_id' => $cliente->id]);
    $actual  = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    Direccion::factory()->create();   // de otro cliente

    $encabezado = bearer(usuarioCon('cliente.ver'));

    $this->getJson("/api/v1/clientes/{$cliente->id}", $encabezado)
        ->assertOk()
        ->assertJsonCount(2, 'datos.direcciones')
        ->assertJsonPath('datos.direcciones.0.id', $actual->id)
        ->assertJsonPath('datos.direcciones.0.es_predeterminada', true)
        ->assertJsonPath('datos.direcciones.1.id', $vieja->id)
        ->assertJsonPath('datos.cantidad_direcciones', 2);

    // La lista sólo viaja en la ficha; el listado cuenta y nada más.
    $this->getJson('/api/v1/clientes', $encabezado)
        ->assertOk()
        ->assertJsonMissingPath('datos.0.direcciones');
});

test('la ficha de un cliente que no existe es 404', function () {
    $this->getJson('/api/v1/clientes/999999', bearer(usuarioCon('cliente.ver')))
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrado');
});

/*
|--------------------------------------------------------------------------
| Validación fiscal: rechaza, no vacía
|--------------------------------------------------------------------------
|
| M-31. Los setters originales convertían en cadena vacía lo que no validaba y
| el dato se guardaba igual; el mensaje resultante decía "es obligatorio", que
| manda al usuario a completar un campo que sí completó.
|
| Estas tres pruebas venían del módulo de personal, donde vivían las reglas
| fiscales antes de extraerse a App\Support\ReglasFiscales. Ahora la clase la
| usa ClienteRequest y es acá donde corresponde probarla sobre HTTP.
|
*/

test('quien factura A necesita numero de documento', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente([
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '',
        'condicion_iva' => 'responsable_inscripto',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'Para esa condición frente al IVA hace falta el número de documento.');

    expect(Cliente::count())->toBe(0);
});

test('el consumidor final puede no estar identificado', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente(['nro_doc' => '']), bearer(usuarioCon('cliente.crear')))
        ->assertCreated();

    // Nulo, no cadena vacía: sólo NULL no colisiona consigo mismo en el
    // UNIQUE(tipo_doc, nro_doc), que es lo que permite dos de mostrador sin
    // identificar.
    expect(Cliente::first()->nro_doc)->toBeNull();
});

test('un documento mal escrito se rechaza por lo que es, no por estar vacio', function () {
    // La web además conservaba lo escrito en el campo; en la API no hay sesión que lo guarde:
    // el formulario no se limpia porque el 422 no toca la pantalla.
    $this->postJson('/api/v1/clientes', datosDeCliente([
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '30-ABC-1',
        'condicion_iva' => 'responsable_inscripto',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'El CUIT/CUIL tiene 11 dígitos.');

    expect(Cliente::count())->toBe(0);
});

test('el largo del documento depende del tipo', function () {
    $encabezado = bearer(usuarioCon('cliente.crear'));

    // Un DNI de 11 dígitos no es un DNI.
    $this->postJson('/api/v1/clientes', datosDeCliente(['tipo_doc' => 'dni', 'nro_doc' => '30715558881']), $encabezado)
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.');

    // Y un CUIT de 8 tampoco es un CUIT.
    $this->postJson('/api/v1/clientes', datosDeCliente(['tipo_doc' => 'cuit', 'nro_doc' => '41556778']), $encabezado)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nro_doc', 'errores');
});

test('el cuit se guarda normalizado aunque se escriba con guiones', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente([
        'razon_social'  => 'Estudio Austral S.R.L.',
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '30-71555888-1',
        'condicion_iva' => 'responsable_inscripto',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertCreated()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'Se creó el cliente'));

    expect(Cliente::first()->nro_doc)->toBe('30715558881');
});

test('un documento repetido del mismo tipo se rechaza con un mensaje que lo explica', function () {
    Cliente::factory()->responsableInscripto()->create(['tipo_doc' => 'cuit', 'nro_doc' => '30715558881']);

    $this->postJson('/api/v1/clientes', datosDeCliente([
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '30715558881',
        'condicion_iva' => 'responsable_inscripto',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'Ya hay un cliente registrado con ese documento.');
});

test('el mismo numero con otro tipo de documento si se acepta', function () {
    // La base tiene UNIQUE(tipo_doc, nro_doc), no UNIQUE(nro_doc): la regla dice
    // lo mismo antes, para que el usuario reciba un mensaje y no un error de
    // integridad.
    Cliente::factory()->responsableInscripto()->create(['tipo_doc' => 'cuit', 'nro_doc' => '30715558881']);

    $this->postJson('/api/v1/clientes', datosDeCliente([
        'razon_social'  => 'Otra empresa',
        'tipo_doc'      => 'cuil',
        'nro_doc'       => '30715558881',
        'condicion_iva' => 'monotributo',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertCreated();

    expect(Cliente::count())->toBe(2);
});

test('la cuenta de acceso no se puede asignar desde este modulo', function () {
    // La invariante rol↔satélite sólo la sabe mantener el módulo de personal, y
    // en la Etapa 1 ninguna pantalla crea cuentas de tienda.
    $ajeno = User::factory()->conRol('Cliente')->create();

    $this->postJson('/api/v1/clientes', datosDeCliente(['user_id' => $ajeno->id]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id', 'errores');

    expect(Cliente::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta devuelve 201 con el cliente, para cargarle direcciones', function () {
    // La redirección a la ficha es de la pantalla (JS); acá se afirma lo que la hace posible:
    // el id del cliente nuevo y el aviso que ofrece cargarle direcciones.
    $this->postJson('/api/v1/clientes', datosDeCliente(), bearer(usuarioCon('cliente.crear')))
        ->assertCreated()
        ->assertJsonPath('datos.id', Cliente::first()->id)
        ->assertJsonPath('mensaje', 'Se creó el cliente «Panadería Los Tilos». Ya podés agregarle direcciones.');
});

test('la edicion guarda los datos fiscales', function () {
    $cliente = Cliente::factory()->create();

    $this->patchJson("/api/v1/clientes/{$cliente->id}", datosDeCliente([
        'razon_social'  => 'Estudio Austral S.R.L.',
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '30715558881',
        'condicion_iva' => 'responsable_inscripto',
    ]), bearer(usuarioCon('cliente.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', 'Se guardaron los datos de «Estudio Austral S.R.L.».')
        ->assertJsonPath('datos.tipo_comprobante', 'factura_a');

    $cliente = $cliente->fresh();

    expect($cliente->razon_social)->toBe('Estudio Austral S.R.L.')
        ->and($cliente->tipoComprobante())->toBe('factura_a');
});

test('el cliente que se edita no choca con su propio documento', function () {
    $cliente = Cliente::factory()->create(['tipo_doc' => 'dni', 'nro_doc' => '41556778']);

    $this->patchJson("/api/v1/clientes/{$cliente->id}", datosDeCliente(['razon_social' => 'Otro nombre']), bearer(usuarioCon('cliente.editar')))
        ->assertOk();

    expect($cliente->fresh()->razon_social)->toBe('Otro nombre');
});

/*
|--------------------------------------------------------------------------
| Direcciones sobre HTTP
|--------------------------------------------------------------------------
|
| La invariante ya tiene su test en DireccionServiceTest; estos prueban que la
| pantalla no diga una cosa y el sistema haga otra.
|
*/

test('la primera direccion queda predeterminada y el mensaje lo dice', function () {
    $cliente = Cliente::factory()->create();

    $this->postJson("/api/v1/clientes/{$cliente->id}/direcciones", datosDeDireccion(), bearer(usuarioCon('cliente.editar')))
        ->assertCreated()
        ->assertJsonPath('datos.es_predeterminada', true)
        ->assertJsonPath('mensaje', 'Se agregó la dirección y quedó como predeterminada.');

    expect(predeterminadasDe($cliente))->toBe(1);
});

test('el formulario de la primera direccion llega con el checkbox marcado', function () {
    // Si llegara destildado, el sistema la marcaría igual y la pantalla habría
    // dicho una cosa distinta del resultado. Desde el tramo E la casilla la marca pantallas/direcciones.js
    // con la ficha del cliente: lo que se prueba es lo que la decide (las direcciones que ya tiene).
    $cliente = Cliente::factory()->create();
    $lector  = bearer(usuarioCon('cliente.ver', 'cliente.editar'));

    $this->actingAs(usuarioCon('cliente.editar'))
        ->get(route('direcciones.create', $cliente))
        ->assertOk()
        ->assertSee('data-formulario-direccion', false)
        ->assertSee('data-cliente="'.$cliente->id.'"', false)
        ->assertSee('name="es_predeterminada"', false);

    $this->getJson("/api/v1/clientes/{$cliente->id}", $lector)
        ->assertJsonPath('datos.direcciones', []);

    Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->getJson("/api/v1/clientes/{$cliente->id}", $lector)
        ->assertJsonCount(1, 'datos.direcciones');
});

test('el shell de la direccion no trae los datos del cliente ni de la direccion', function () {
    $cliente   = Cliente::factory()->create(['razon_social' => 'Cliente Reservado S.A.']);
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id, 'calle' => 'Calle Reservada']);

    $this->actingAs(usuarioCon('cliente.editar'))
        ->get(route('direcciones.edit', [$cliente, $direccion]))
        ->assertOk()
        ->assertDontSee('Cliente Reservado')
        ->assertDontSee('Calle Reservada')
        ->assertSee('data-direccion="'.$direccion->id.'"', false);
});

test('marcar una direccion desmarca la anterior', function () {
    $cliente = Cliente::factory()->create();
    $primera = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    $segunda = Direccion::factory()->create(['cliente_id' => $cliente->id]);

    $this->patchJson("/api/v1/clientes/{$cliente->id}/direcciones/{$segunda->id}", datosDeDireccion([
        'es_predeterminada' => true,
    ]), bearer(usuarioCon('cliente.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', 'Se guardó la dirección.');

    expect($primera->fresh()->es_predeterminada)->toBeFalse()
        ->and($segunda->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($cliente))->toBe(1);
});

test('destildar la predeterminada guarda el resto y lo avisa', function () {
    $cliente = Cliente::factory()->create();
    $primera = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    Direccion::factory()->create(['cliente_id' => $cliente->id]);

    $this->patchJson("/api/v1/clientes/{$cliente->id}/direcciones/{$primera->id}", datosDeDireccion([
        'numero'            => '1460',
        'es_predeterminada' => false,
    ]), bearer(usuarioCon('cliente.editar')))
        // El mensaje dice lo que pasó de verdad: guardar algo distinto de lo que
        // el usuario marcó sin avisarle es mentirle sobre el estado del sistema.
        ->assertOk()
        ->assertJsonPath('datos.es_predeterminada', true)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'marcá otra'));

    expect($primera->fresh()->es_predeterminada)->toBeTrue()
        // Y la corrección sí se guardó: no pierde lo que vino a hacer.
        ->and($primera->fresh()->numero)->toBe('1460');
});

test('borrar la predeterminada asciende otra y el mensaje lo anuncia', function () {
    $cliente = Cliente::factory()->create();
    $vieja   = Direccion::factory()->create(['cliente_id' => $cliente->id]);
    $actual  = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->deleteJson("/api/v1/clientes/{$cliente->id}/direcciones/{$actual->id}", [], bearer(usuarioCon('cliente.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'más antigua'));

    expect($vieja->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($cliente))->toBe(1);
});

test('una provincia fuera de la lista se rechaza', function () {
    // Lista cerrada: con texto libre "Santa Cruz", "Sta Cruz" y "SANTA CRUZ"
    // serían tres provincias, y la cotización de envío de la Etapa 2 necesita
    // una sola.
    $cliente = Cliente::factory()->create();

    $this->postJson("/api/v1/clientes/{$cliente->id}/direcciones", datosDeDireccion(['provincia' => 'Sta Cruz']), bearer(usuarioCon('cliente.editar')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.provincia.0', 'Elegí una provincia de la lista.');

    expect($cliente->direcciones()->count())->toBe(0);
});

test('el codigo postal acepta los dos formatos del pais y rechaza cualquier otro', function () {
    $cliente    = Cliente::factory()->create();
    $encabezado = bearer(usuarioCon('cliente.editar'));
    $url        = "/api/v1/clientes/{$cliente->id}/direcciones";

    // El de cuatro dígitos y el CPA. Rechazar el viejo dejaría afuera a quien
    // copia la dirección de una factura.
    foreach (['9011', 'Z9011XAA'] as $valido) {
        $this->postJson($url, datosDeDireccion(['codigo_postal' => $valido]), $encabezado)
            ->assertCreated();
    }

    $this->postJson($url, datosDeDireccion(['codigo_postal' => '90']), $encabezado)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('codigo_postal', 'errores');

    expect($cliente->direcciones()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| La baja: se rechaza, no se desactiva
|--------------------------------------------------------------------------
|
| Única excepción del proyecto a "lo que está referenciado se desactiva".
| `clientes` no tiene columna `activo` y no hace falta: un cliente no se elige
| de un desplegable, se busca. Rechazar la baja conserva el dato igual y además
| lo explica.
|
*/

test('un cliente sin ventas ni cuenta se elimina con sus direcciones', function () {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->deleteJson("/api/v1/clientes/{$cliente->id}", [], bearer(usuarioCon('cliente.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'Se eliminó'));

    $this->assertModelMissing($cliente);
    // Cascada del esquema: una dirección sin cliente no significa nada.
    $this->assertModelMissing($direccion);
});

test('un cliente con ventas no se elimina y el mensaje explica por que', function () {
    $cliente = Cliente::factory()->create();

    // La tabla existe desde la Fase 1; el modelo Venta llega en la Fase 6.
    DB::table('ventas')->insert([
        'cliente_id' => $cliente->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La ReglaDeNegocioException se traduce una sola vez para toda la API (409 regla_de_negocio),
    // como en la web se traducía a mensaje (M-30, donde toda excepción volvía como un 400 genérico).
    $this->deleteJson("/api/v1/clientes/{$cliente->id}", [], bearer(usuarioCon('cliente.eliminar')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio')
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'operaciones registradas'));

    $this->assertModelExists($cliente);
});

test('un cliente con cuenta de acceso no se elimina desde este modulo', function () {
    // Borrarlo dejaría un usuario de ámbito tienda sin satélite: rompería la
    // invariante que sostiene toda la fase.
    $usuario = User::factory()->conRol('Cliente')->create();
    $cliente = Cliente::factory()->create(['user_id' => $usuario->id]);

    $this->deleteJson("/api/v1/clientes/{$cliente->id}", [], bearer(usuarioCon('cliente.eliminar')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio')
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'cuenta de acceso'));

    $this->assertModelExists($cliente);
});
