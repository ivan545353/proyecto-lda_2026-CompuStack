<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
| Se prueban las dos mitades: con todos los permisos del módulo MENOS el de la
| ruta se deniega, y con SÓLO el de la ruta se permite. Sin la primera mitad, una
| ruta protegida por el permiso equivocado pasaría desapercibida.
|
*/

// Las pantallas, que siguen en la web.
dataset('rutas de proveedores', [
    'listado'            => ['get', 'proveedores.index',  'proveedor.ver',    false],
    'formulario alta'    => ['get', 'proveedores.create', 'proveedor.crear',  false],
    'formulario edicion' => ['get', 'proveedores.edit',   'proveedor.editar', true],
]);

// Las operaciones, en la API (tramo E de la Etapa 3). `{proveedor}` se reemplaza por el del test.
dataset('endpoints de proveedores', [
    'listado' => ['get',    '/api/v1/proveedores',             'proveedor.ver'],
    'ficha'   => ['get',    '/api/v1/proveedores/{proveedor}', 'proveedor.ver'],
    'alta'    => ['post',   '/api/v1/proveedores',             'proveedor.crear'],
    'edicion' => ['patch',  '/api/v1/proveedores/{proveedor}', 'proveedor.editar'],
    'baja'    => ['delete', '/api/v1/proveedores/{proveedor}', 'proveedor.eliminar'],
]);

const PERMISOS_PROVEEDOR = ['proveedor.ver', 'proveedor.crear', 'proveedor.editar', 'proveedor.eliminar'];

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conProveedor) {
    $proveedor = Proveedor::factory()->create();
    $url       = $conProveedor ? route($ruta, $proveedor) : route($ruta);

    $otros = array_values(array_diff(PERMISOS_PROVEEDOR, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, datosDeProveedor())
        ->assertForbidden();
})->with('rutas de proveedores');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conProveedor) {
    $proveedor = Proveedor::factory()->create();
    $url       = $conProveedor ? route($ruta, $proveedor) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, datosDeProveedor());

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de proveedores');

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $proveedor = Proveedor::factory()->create();
    $otros     = array_values(array_diff(PERMISOS_PROVEEDOR, [$permiso]));

    $this->json($metodo, str_replace('{proveedor}', $proveedor->id, $url), datosDeProveedor(), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de proveedores');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $proveedor = Proveedor::factory()->create();

    $respuesta = $this->json($metodo, str_replace('{proveedor}', $proveedor->id, $url), datosDeProveedor(), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de proveedores');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $proveedor = Proveedor::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    // Desde el tramo E la fila es una plantilla del shell: lo que se afirma es que la plantilla
    // no trae el botón. Y en la otra dirección, que sí lo trae para quien tiene el permiso.
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertDontSee(route('proveedores.create'))
        ->assertDontSee(route('proveedores.edit', $proveedor))
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-listado-eliminar', false);

    $this->actingAs(usuarioCon('proveedor.ver', 'proveedor.crear', 'proveedor.editar', 'proveedor.eliminar'))
        ->get(route('proveedores.index'))
        ->assertSee(route('proveedores.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-listado-eliminar', false);
});

test('los numeros de la fila se enlazan sólo a los listados que quien mira puede ver', function () {
    // Un enlace a un listado sin permiso devolvería un 403: la plantilla no lo trae.
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertDontSee('proveedor_id=__ID__', false);

    $html = $this->actingAs(usuarioCon('proveedor.ver', 'producto.ver', 'compra.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('productos.index', ['proveedor_id' => '__ID__']))
        ->and($html)->toContain(route('compras.index', ['proveedor_id' => '__ID__']));
});

// Regla 37: la página llega con su estructura, y los datos por la API.
test('los shells de proveedores no traen datos de negocio', function () {
    // Un CUIT distinto del de ejemplo que lleva el formulario como placeholder.
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Proveedora Secreta S.A.', 'cuit' => '30998877665']);
    $usuario   = usuarioCon('proveedor.ver', 'proveedor.editar');

    $this->actingAs($usuario)->get(route('proveedores.index'))
        ->assertOk()
        ->assertDontSee('Proveedora Secreta')
        ->assertDontSee('30-99887766-5')
        ->assertSee('data-pantalla="proveedores"', false);

    $this->actingAs($usuario)->get(route('proveedores.edit', $proveedor))
        ->assertOk()
        ->assertDontSee('Proveedora Secreta')
        ->assertDontSee('30-99887766-5')
        ->assertSee('data-proveedor="'.$proveedor->id.'"', false);
});

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en ProveedorFiltrosTest; estos
| prueban el cableado entre la URL y el scope, que es exactamente lo que estaba
| roto en el sistema original y lo que un solo nivel de test no encuentra.
|
*/

test('el parametro q llega al listado y filtra', function () {
    Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral S.A.']);
    Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia']);

    $this->getJson('/api/v1/proveedores?q=austral', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('criterios.q', 'austral');
});

test('el parametro canal llega al listado y filtra', function () {
    Proveedor::factory()->porEmail()->create();
    Proveedor::factory()->porPortal()->create();
    Proveedor::factory()->count(2)->create();   // 'manual', el valor por omisión

    $this->getJson('/api/v1/proveedores?canal=portal_externo', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('criterios.canal', 'portal_externo');
});

test('el parametro estado llega al listado y filtra', function () {
    Proveedor::factory()->count(2)->create();
    Proveedor::factory()->inactivo()->create();

    $this->getJson('/api/v1/proveedores?estado=inactivos', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

// En la API, el aviso es el 422: el listado no muestra otra cosa que la pedida.
test('un canal inexistente se rechaza con aviso', function () {
    $this->getJson('/api/v1/proveedores?canal=telepatia', bearer(usuarioCon('proveedor.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrors('canal', 'errores');
});

test('el listado pagina de a 20 y criterios conserva el filtro', function () {
    Proveedor::factory()->count(25)
        ->sequence(fn ($s) => ['razon_social' => "Proveedor {$s->index}"])
        ->create();

    $encabezado = bearer(usuarioCon('proveedor.ver'));

    // `criterios` es lo que la pantalla repite al pedir la página siguiente: sin él, perdería el filtro.
    $this->getJson('/api/v1/proveedores?q=proveedor', $encabezado)
        ->assertJsonCount(20, 'datos')
        ->assertJsonPath('total', 25)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios.q', 'proveedor');

    $this->getJson('/api/v1/proveedores?q=proveedor&pagina=2', $encabezado)
        ->assertJsonCount(5, 'datos');
});

test('el listado trae el CUIT formateado aunque se guarde sin separadores', function () {
    Proveedor::factory()->create(['cuit' => '30712345678']);

    // Es la contracara de normalizar: una sola forma de guardar, una sola de leer.
    $this->getJson('/api/v1/proveedores', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()
        ->assertJsonPath('datos.0.cuit', '30712345678')
        ->assertJsonPath('datos.0.cuit_formateado', '30-71234567-8');
});

test('el listado cuenta los productos y las ordenes de cada proveedor, y la ficha los productos activos', function () {
    $proveedor = Proveedor::factory()->create();
    Producto::factory()->conProveedor($proveedor)->create();
    Producto::factory()->conProveedor($proveedor)->create(['activo' => false]);
    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $proveedor->id]);

    $encabezado = bearer(usuarioCon('proveedor.ver'));

    // Sin esto el confirm() diría «¿Eliminar?» sobre un proveedor que en realidad
    // se va a desactivar: el sistema anunciaría una cosa y haría otra.
    $this->getJson('/api/v1/proveedores', $encabezado)
        ->assertJsonPath('datos.0.productos', 2)
        ->assertJsonPath('datos.0.ordenes_compra', 2)
        ->assertJsonMissingPath('datos.0.productos_activos');

    $this->getJson("/api/v1/proveedores/{$proveedor->id}", $encabezado)
        ->assertOk()
        ->assertJsonPath('datos.productos_activos', 1)
        ->assertJsonPath('datos.canal_pedido_texto', 'Pedido manual');
});

test('la ficha de un proveedor que no existe es 404', function () {
    $this->getJson('/api/v1/proveedores/999999', bearer(usuarioCon('proveedor.ver')))
        ->assertNotFound()
        ->assertJsonPath('codigo', 'no_encontrado');
});

/*
|--------------------------------------------------------------------------
| Validación condicional
|--------------------------------------------------------------------------
|
| Las dos reglas que son propias de este módulo. El correo hace falta sólo si el
| pedido se manda por correo, y la dirección del portal sólo corresponde a ese
| canal: con cualquier otro se RECHAZA, porque vaciarla en silencio sería
| corregir en lugar de rechazar (M-31).
|
*/

test('si los pedidos se mandan por correo, el correo es obligatorio', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor([
        'canal_pedido' => 'email',
        'email'        => '',
    ]), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email', 'errores')
        // Un error en un campo no marca los demás.
        ->assertJsonMissingValidationErrors('razon_social', 'errores');

    expect(Proveedor::count())->toBe(0);
});

test('con otro canal, el correo sigue siendo opcional', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor([
        'canal_pedido' => 'manual',
        'email'        => '',
    ]), bearer(usuarioCon('proveedor.crear')))
        ->assertCreated();

    expect(Proveedor::count())->toBe(1);
});

test('si los pedidos se cargan en el portal, hace falta la direccion', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor([
        'canal_pedido' => 'portal_externo',
        'portal_url'   => '',
    ]), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('portal_url', 'errores');

    expect(Proveedor::count())->toBe(0);
});

test('la direccion del portal se rechaza con cualquier otro canal', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor([
        'canal_pedido' => 'manual',
        'portal_url'   => 'https://no-corresponde.test',
    ]), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('portal_url', 'errores');

    // Se rechaza la petición entera: no se guarda el proveedor con la URL
    // vaciada por atrás, que es lo que hacían los setters del original.
    expect(Proveedor::count())->toBe(0);
});

test('un campo opcional vacio se guarda como nulo, no como cadena vacia', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor([
        'canal_pedido' => 'manual',
        'email'        => '',
        'telefono'     => '',
        'contacto'     => '',
        'portal_url'   => '',
    ]), bearer(usuarioCon('proveedor.crear')))
        ->assertCreated();

    // '' y NULL significan lo mismo para una persona y cosas distintas para SQL:
    // un WHERE email IS NULL no encontraría una fila con cadena vacía.
    $proveedor = Proveedor::firstWhere('cuit', '30712345678');

    expect($proveedor->email)->toBeNull()
        ->and($proveedor->telefono)->toBeNull()
        ->and($proveedor->contacto)->toBeNull()
        ->and($proveedor->portal_url)->toBeNull();
});

test('el CUIT se guarda sin separadores aunque se escriba con guiones', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor(['cuit' => '30-71234567-8']), bearer(usuarioCon('proveedor.crear')))
        ->assertCreated()
        ->assertJsonPath('datos.cuit', '30712345678');

    expect(Proveedor::first()->cuit)->toBe('30712345678');
});

test('un CUIT con letras se rechaza en vez de limpiarse', function () {
    // M-31 en su forma exacta: el setter original convertía "30-ABC" en "30" y lo
    // guardaba. normalizar() saca separadores, no letras, así que la regla lo
    // rechaza con un mensaje que describe el problema real.
    $this->postJson('/api/v1/proveedores', datosDeProveedor(['cuit' => '30-ABCDEFG-8']), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuit', 'errores');

    expect(Proveedor::count())->toBe(0);
});

test('un CUIT repetido se rechaza con un mensaje que lo explica', function () {
    Proveedor::factory()->create(['cuit' => '30712345678']);

    $this->postJson('/api/v1/proveedores', datosDeProveedor(), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.cuit.0', 'Ya hay un proveedor registrado con ese CUIT.');
});

test('el proveedor que se edita no choca con su propio CUIT', function () {
    $proveedor = Proveedor::factory()->create(['cuit' => '30712345678']);

    $this->patchJson("/api/v1/proveedores/{$proveedor->id}", datosDeProveedor([
        'razon_social' => 'Distribuidora Austral S.R.L.',
    ]), bearer(usuarioCon('proveedor.editar')))
        ->assertOk();

    expect($proveedor->fresh()->razon_social)->toBe('Distribuidora Austral S.R.L.');
});

test('un plazo de entrega negativo se rechaza', function () {
    // La columna es unsignedSmallInteger: sin la regla, MariaDB devolvería un
    // error de integridad en la cara del usuario en lugar de un mensaje (M-30).
    $this->postJson('/api/v1/proveedores', datosDeProveedor(['plazo_entrega_dias' => -5]), bearer(usuarioCon('proveedor.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plazo_entrega_dias', 'errores');

    expect(Proveedor::count())->toBe(0);
});

test('el alta devuelve 201 con el proveedor y el mensaje', function () {
    $this->postJson('/api/v1/proveedores', datosDeProveedor(), bearer(usuarioCon('proveedor.crear')))
        ->assertCreated()
        ->assertJsonPath('mensaje', 'Proveedor «Distribuidora Austral S.A.» creado.')
        ->assertJsonPath('datos.razon_social', 'Distribuidora Austral S.A.')
        ->assertJsonPath('datos.activo', true);
});

test('desactivar con activo en falso desactiva el proveedor', function () {
    $proveedor = Proveedor::factory()->create();

    // En la web el checkbox sin marcar no viajaba y el Form Request lo leía como «no». La API no
    // adivina: `activo` viaja siempre, y su falta se rechaza.
    $this->patchJson("/api/v1/proveedores/{$proveedor->id}", datosDeProveedor(['cuit' => $proveedor->cuit, 'activo' => false]), bearer(usuarioCon('proveedor.editar')))
        ->assertOk()
        ->assertJsonPath('datos.activo', false);

    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('editar sin decir el estado se rechaza en vez de adivinarlo', function () {
    $proveedor = Proveedor::factory()->create();

    $datos = datosDeProveedor(['cuit' => $proveedor->cuit]);
    unset($datos['activo']);

    $this->patchJson("/api/v1/proveedores/{$proveedor->id}", $datos, bearer(usuarioCon('proveedor.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('activo', 'errores');

    expect($proveedor->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| La cascada
|--------------------------------------------------------------------------
|
| La casilla EXISTE en el formulario (se prueba abajo, sobre la pantalla), y el
| endpoint la obedece. En marcas la cascada vivía en el Form Request y en el
| controlador pero nunca se renderizó: la pieza funciona y el cableado no
| existe. Es A-24 con otra ropa, y está anotado en los pendientes de usabilidad.
|
*/

test('la casilla de cascada solo aparece si el proveedor tiene productos activos', function () {
    $sinProductos = Proveedor::factory()->create();
    $conProductos = Proveedor::factory()->create();
    Producto::factory()->conProveedor($conProductos)->create();

    $usuario = usuarioCon('proveedor.editar');

    // La casilla está en el formulario de edición, y no en el de alta: la cascada no tiene sentido
    // sin un proveedor que desactivar. Nace oculta (`hidden`): no se ofrece una acción sin efecto.
    $html = $this->actingAs($usuario)->get(route('proveedores.edit', $sinProductos))->assertOk()->getContent();
    expect($html)->toMatch('/<div[^>]*data-aviso-productos[^>]*\shidden[\s>]/')
        ->and($html)->toContain('name="desactivar_productos"');

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('proveedor.crear'))
        ->get(route('proveedores.create'))
        ->assertOk()
        ->assertDontSee('desactivar_productos');

    // Y la decide la ficha de la API: con productos activos trae el número, y la pantalla la muestra.
    $encabezado = bearer(usuarioCon('proveedor.ver'));

    $this->getJson("/api/v1/proveedores/{$sinProductos->id}", $encabezado)
        ->assertJsonPath('datos.productos_activos', 0);
    $this->getJson("/api/v1/proveedores/{$conProductos->id}", $encabezado)
        ->assertJsonPath('datos.productos_activos', 1);
});

test('desactivar desde la pantalla no desactiva los productos, y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->conProveedor($proveedor)->create();

    $this->patchJson("/api/v1/proveedores/{$proveedor->id}", datosDeProveedor(['cuit' => $proveedor->cuit, 'activo' => false]), bearer(usuarioCon('proveedor.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'siguen activos'));

    expect($producto->fresh()->activo)->toBeTrue();
});

test('con la casilla, desactivar el proveedor desactiva sus productos', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->conProveedor($proveedor)->create();
    $ajeno     = Producto::factory()->create();

    $this->patchJson("/api/v1/proveedores/{$proveedor->id}", datosDeProveedor([
        'cuit'                 => $proveedor->cuit,
        'activo'               => false,
        'desactivar_productos' => true,
    ]), bearer(usuarioCon('proveedor.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'junto con'));

    expect($producto->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Bajas
|--------------------------------------------------------------------------
|
| M-16. El original borraba con un DELETE plano y el ON DELETE CASCADE se llevaba
| el historial. Lo que está referenciado no se borra.
|
*/

test('un proveedor que nadie referencia se elimina y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();

    $this->deleteJson("/api/v1/proveedores/{$proveedor->id}", [], bearer(usuarioCon('proveedor.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'eliminado'));

    $this->assertModelMissing($proveedor);
});

test('un proveedor con productos se desactiva y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    Producto::factory()->conProveedor($proveedor)->create();

    $this->deleteJson("/api/v1/proveedores/{$proveedor->id}", [], bearer(usuarioCon('proveedor.eliminar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', false)
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('un proveedor con ordenes de compra se desactiva y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);

    $this->deleteJson("/api/v1/proveedores/{$proveedor->id}", [], bearer(usuarioCon('proveedor.eliminar')))
        ->assertOk()
        ->assertJsonPath('mensaje', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('el listado avisa cuando el proveedor tiene ordenes de compra', function () {
    $proveedor = Proveedor::factory()->create();
    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $proveedor->id]);

    // Sin esto el confirm() diría «¿Eliminar?» sobre un proveedor que en realidad
    // se va a desactivar: el sistema anunciaría una cosa y haría otra. El texto del aviso lo arma
    // la pantalla (pantallas/proveedores.js) con lo que trae el listado: las órdenes y los
    // productos de cada fila, aunque el proveedor no tenga ningún producto.
    $this->getJson('/api/v1/proveedores', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()
        ->assertJsonPath('datos.0.productos', 0)
        ->assertJsonPath('datos.0.ordenes_compra', 2);

    // Y la baja, en efecto, no lo elimina: lo desactiva (ver «Bajas»).
    $this->actingAs(usuarioCon('proveedor.eliminar', 'proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertSee('data-listado-eliminar', false);
});
