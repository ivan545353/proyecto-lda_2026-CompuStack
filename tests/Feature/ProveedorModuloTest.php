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

dataset('rutas de proveedores', [
    'listado'            => ['get',    'proveedores.index',   'proveedor.ver',      false],
    'formulario alta'    => ['get',    'proveedores.create',  'proveedor.crear',    false],
    'alta'               => ['post',   'proveedores.store',   'proveedor.crear',    false],
    'formulario edicion' => ['get',    'proveedores.edit',    'proveedor.editar',   true],
    'edicion'            => ['put',    'proveedores.update',  'proveedor.editar',   true],
    'baja'               => ['delete', 'proveedores.destroy', 'proveedor.eliminar', true],
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

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $proveedor = Proveedor::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertDontSee(route('proveedores.create'))
        ->assertDontSee(route('proveedores.edit', $proveedor));
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

    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index', ['q' => 'austral']))
        ->assertOk()
        ->assertViewHas('proveedores', fn ($p) => $p->total() === 1);
});

test('el parametro canal llega al listado y filtra', function () {
    Proveedor::factory()->porEmail()->create();
    Proveedor::factory()->porPortal()->create();
    Proveedor::factory()->count(2)->create();   // 'manual', el valor por omisión

    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index', ['canal' => 'portal_externo']))
        ->assertViewHas('proveedores', fn ($p) => $p->total() === 1);
});

test('el parametro estado llega al listado y filtra', function () {
    Proveedor::factory()->count(2)->create();
    Proveedor::factory()->inactivo()->create();

    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index', ['estado' => 'inactivos']))
        ->assertViewHas('proveedores', fn ($p) => $p->total() === 1);
});

test('un canal inexistente vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index', ['canal' => 'telepatia']))
        ->assertRedirect(route('proveedores.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    Proveedor::factory()->count(20)
        ->sequence(fn ($s) => ['razon_social' => "Proveedor {$s->index}"])
        ->create();

    $usuario = usuarioCon('proveedor.ver');

    $this->actingAs($usuario)
        ->get(route('proveedores.index', ['q' => 'proveedor']))
        ->assertViewHas('proveedores', fn ($p) => $p->count() === 15
            && $p->total() === 20
            // Sin withQueryString(), la página 2 perdería el filtro.
            && str_contains($p->nextPageUrl(), 'q=proveedor'));

    $this->actingAs($usuario)
        ->get(route('proveedores.index', ['q' => 'proveedor', 'page' => 2]))
        ->assertViewHas('proveedores', fn ($p) => $p->count() === 5);
});

test('el listado muestra el CUIT formateado aunque se guarde sin separadores', function () {
    Proveedor::factory()->create(['cuit' => '30712345678']);

    // Es la contracara de normalizar: una sola forma de guardar, una sola de leer.
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertSee('30-71234567-8');
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
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->from(route('proveedores.create'))
        ->post(route('proveedores.store'), datosDeProveedor([
            'canal_pedido' => 'email',
            'email'        => '',
        ]))
        ->assertRedirect(route('proveedores.create'))
        ->assertSessionHasErrors('email')
        // Un error en un campo no borra los demás.
        ->assertSessionHasInput('razon_social', 'Distribuidora Austral S.A.');

    expect(Proveedor::count())->toBe(0);
});

test('con otro canal, el correo sigue siendo opcional', function () {
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor([
            'canal_pedido' => 'manual',
            'email'        => '',
        ]))
        ->assertSessionHasNoErrors();

    expect(Proveedor::count())->toBe(1);
});

test('si los pedidos se cargan en el portal, hace falta la direccion', function () {
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor([
            'canal_pedido' => 'portal_externo',
            'portal_url'   => '',
        ]))
        ->assertSessionHasErrors('portal_url');

    expect(Proveedor::count())->toBe(0);
});

test('la direccion del portal se rechaza con cualquier otro canal', function () {
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor([
            'canal_pedido' => 'manual',
            'portal_url'   => 'https://no-corresponde.test',
        ]))
        ->assertSessionHasErrors('portal_url');

    // Se rechaza la petición entera: no se guarda el proveedor con la URL
    // vaciada por atrás, que es lo que hacían los setters del original.
    expect(Proveedor::count())->toBe(0);
});

test('un campo opcional vacio se guarda como nulo, no como cadena vacia', function () {
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor([
            'canal_pedido' => 'manual',
            'email'        => '',
            'telefono'     => '',
            'contacto'     => '',
            'portal_url'   => '',
        ]))
        ->assertSessionHasNoErrors();

    // '' y NULL significan lo mismo para una persona y cosas distintas para SQL:
    // un WHERE email IS NULL no encontraría una fila con cadena vacía.
    $proveedor = Proveedor::firstWhere('cuit', '30712345678');

    expect($proveedor->email)->toBeNull()
        ->and($proveedor->telefono)->toBeNull()
        ->and($proveedor->contacto)->toBeNull()
        ->and($proveedor->portal_url)->toBeNull();
});

test('el CUIT se guarda sin separadores aunque se escriba con guiones', function () {
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor(['cuit' => '30-71234567-8']))
        ->assertSessionHasNoErrors();

    expect(Proveedor::first()->cuit)->toBe('30712345678');
});

test('un CUIT con letras se rechaza en vez de limpiarse', function () {
    // M-31 en su forma exacta: el setter original convertía "30-ABC" en "30" y lo
    // guardaba. normalizar() saca separadores, no letras, así que la regla lo
    // rechaza con un mensaje que describe el problema real.
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor(['cuit' => '30-ABCDEFG-8']))
        ->assertSessionHasErrors('cuit');

    expect(Proveedor::count())->toBe(0);
});

test('un CUIT repetido se rechaza con un mensaje que lo explica', function () {
    Proveedor::factory()->create(['cuit' => '30712345678']);

    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor())
        ->assertSessionHasErrors(['cuit' => 'Ya hay un proveedor registrado con ese CUIT.']);
});

test('el proveedor que se edita no choca con su propio CUIT', function () {
    $proveedor = Proveedor::factory()->create(['cuit' => '30712345678']);

    $this->actingAs(usuarioCon('proveedor.editar'))
        ->put(route('proveedores.update', $proveedor), datosDeProveedor([
            'razon_social' => 'Distribuidora Austral S.R.L.',
        ]))
        ->assertSessionHasNoErrors();

    expect($proveedor->fresh()->razon_social)->toBe('Distribuidora Austral S.R.L.');
});

test('un plazo de entrega negativo se rechaza', function () {
    // La columna es unsignedSmallInteger: sin la regla, MariaDB devolvería un
    // error de integridad en la cara del usuario en lugar de un mensaje (M-30).
    $this->actingAs(usuarioCon('proveedor.crear'))
        ->post(route('proveedores.store'), datosDeProveedor(['plazo_entrega_dias' => -5]))
        ->assertSessionHasErrors('plazo_entrega_dias');

    expect(Proveedor::count())->toBe(0);
});

test('el checkbox desmarcado desactiva el proveedor', function () {
    $proveedor = Proveedor::factory()->create();

    // El checkbox sin marcar no viaja en el POST. Sin la normalización del Form
    // Request, desactivar un proveedor desde el formulario sería imposible.
    $datos = datosDeProveedor(['cuit' => $proveedor->cuit]);
    unset($datos['activo']);

    $this->actingAs(usuarioCon('proveedor.editar'))
        ->put(route('proveedores.update', $proveedor), $datos);

    expect($proveedor->fresh()->activo)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| La cascada, desde la pantalla
|--------------------------------------------------------------------------
|
| Se prueba que la casilla EXISTE en el formulario, no sólo que el servicio la
| obedece. En marcas la cascada vive en el Form Request y en el controlador pero
| nunca se renderizó, y el test del módulo pasa igual porque postea el campo
| directo: la pieza funciona y el cableado no existe. Es A-24 con otra ropa, y
| está anotado en los pendientes de usabilidad.
|
*/

test('la casilla de cascada solo aparece si el proveedor tiene productos activos', function () {
    $sinProductos = Proveedor::factory()->create();
    $conProductos = Proveedor::factory()->create();
    Producto::factory()->create(['proveedor_id' => $conProductos->id]);

    $usuario = usuarioCon('proveedor.editar');

    // No ofrecer una acción que no tendría efecto.
    $this->actingAs($usuario)
        ->get(route('proveedores.edit', $sinProductos))
        ->assertOk()
        ->assertDontSee('desactivar_productos');

    $this->actingAs($usuario)
        ->get(route('proveedores.edit', $conProductos))
        ->assertOk()
        ->assertSee('desactivar_productos');
});

test('desactivar desde la pantalla no desactiva los productos, y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create(['proveedor_id' => $proveedor->id]);

    $datos = datosDeProveedor(['cuit' => $proveedor->cuit]);
    unset($datos['activo']);

    $this->actingAs(usuarioCon('proveedor.editar'))
        ->put(route('proveedores.update', $proveedor), $datos)
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'siguen activos'));

    expect($producto->fresh()->activo)->toBeTrue();
});

test('con la casilla, desactivar el proveedor desactiva sus productos', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create(['proveedor_id' => $proveedor->id]);
    $ajeno     = Producto::factory()->create();

    $datos = datosDeProveedor(['cuit' => $proveedor->cuit, 'desactivar_productos' => 1]);
    unset($datos['activo']);

    $this->actingAs(usuarioCon('proveedor.editar'))
        ->put(route('proveedores.update', $proveedor), $datos)
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'junto con'));

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

    $this->actingAs(usuarioCon('proveedor.eliminar'))
        ->delete(route('proveedores.destroy', $proveedor))
        ->assertRedirect(route('proveedores.index'))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'eliminado'));

    $this->assertModelMissing($proveedor);
});

test('un proveedor con productos se desactiva y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    Producto::factory()->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('proveedor.eliminar'))
        ->delete(route('proveedores.destroy', $proveedor))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('un proveedor con ordenes de compra se desactiva y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('proveedor.eliminar'))
        ->delete(route('proveedores.destroy', $proveedor))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'se desactivó'));

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('el listado avisa cuando el proveedor tiene ordenes de compra', function () {
    $proveedor = Proveedor::factory()->create();
    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $proveedor->id]);

    // Sin esto el confirm() diría «¿Eliminar?» sobre un proveedor que en realidad
    // se va a desactivar: el sistema anunciaría una cosa y haría otra.
    $this->actingAs(usuarioCon('proveedor.ver'))
        ->get(route('proveedores.index'))
        ->assertOk()
        ->assertSee('2 orden(es)');
});

