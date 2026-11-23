<?php

use App\Impresion\ListadoVentas;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\ImpresionService;
use App\Services\VentaService;
use App\Support\Importe;
use Database\Seeders\RolPermisoSeeder;
use App\Models\VentaLinea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(VentaService::class);

    // Tramo H: los documentos se piden a la API, que devuelve el enlace firmado (D-22).
    $this->enlace = function (string $url, \App\Models\User $usuario): string {
        $enlace = $this->getJson($url, bearer($usuario))->assertOk()->json('datos.url');
        app('auth')->forgetGuards();

        return $enlace;
    };
});

/** Todas las claves del módulo, para probar los permisos en los dos sentidos. */
const PERMISOS_VENTA = [
    'venta.ver',
    'venta.crear',
    'venta.editar',
    'venta.cobrar',
    'venta.entregar',
    'venta.anular',
    'venta.autorizar_descuento',
];

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| C-2 y C-9 juntos. Se prueban las dos mitades: con todos los permisos del módulo
| MENOS el de la ruta se deniega, y con sólo ése se permite. Sin la primera mitad,
| una ruta protegida por el permiso equivocado pasaría desapercibida — y eso es
| exactamente lo que el original hacía con su `?? "can_update"`.
|
| `recotizar` y `cancelar` comparten permiso con la edición, y es una decisión
| escrita en las rutas: cancelar un presupuesto no mueve stock ni plata, así que el
| vendedor tiene que poder dar de baja lo que él mismo cargó. `venta.anular` queda
| para la devolución de la segunda mitad.
|
*/

dataset('rutas sueltas de venta', [
    'listado'    => ['get', 'ventas.index', 'venta.ver'],
    'formulario' => ['get', 'ventas.create', 'venta.crear'],
]);

dataset('rutas de la venta', [
    'ficha'                 => ['get', 'ventas.show', 'venta.ver'],
    'formulario de edicion' => ['get', 'ventas.edit', 'venta.editar'],

    'formulario de cobro'    => ['get', 'pagos.create', 'venta.cobrar'],
        'formulario de devolucion' => ['get', 'ventas.devolucion', 'venta.anular'],
]);
test('ningun otro permiso del modulo habilita las rutas sueltas', function (string $metodo, string $ruta, string $permiso) {
    $otros = array_values(array_diff(PERMISOS_VENTA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, route($ruta))
        ->assertForbidden();
})->with('rutas sueltas de venta');

test('el permiso de la ruta alcanza para pasar en las rutas sueltas', function (string $metodo, string $ruta, string $permiso) {
    // No se verifica que la petición tenga éxito —el alta sin cuerpo falla la
    // validación— sino que no se deniegue por autorización.
    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, route($ruta));

    expect($respuesta->status())->not->toBe(403);
})->with('rutas sueltas de venta');

test('ningun otro permiso del modulo habilita las rutas de la venta', function (string $metodo, string $ruta, string $permiso) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();
    $otros = array_values(array_diff(PERMISOS_VENTA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, route($ruta, $venta))
        ->assertForbidden();
})->with('rutas de la venta');

test('el permiso de la ruta alcanza para pasar en las rutas de la venta', function (string $metodo, string $ruta, string $permiso) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, route($ruta, $venta));

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de la venta');

// Tramo H: las lecturas de la venta, también por la API. Las tres piden `venta.ver`.
dataset('endpoints de lectura de venta', [
    'listado'    => ['/api/v1/ventas'],
    'vendedores' => ['/api/v1/ventas/vendedores'],
    'ficha'      => ['/api/v1/ventas/{venta}'],
    'enlace del listado impreso'  => ['/api/v1/ventas/impresion'],
    'enlace del documento'        => ['/api/v1/ventas/{venta}/pdf'],
]);

test('ningun otro permiso del modulo habilita las lecturas de la API', function (string $url) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();
    $otros = array_values(array_diff(PERMISOS_VENTA, ['venta.ver']));

    $this->getJson(str_replace('{venta}', (string) $venta->id, $url), bearer(usuarioCon(...$otros)))
        ->assertForbidden();
})->with('endpoints de lectura de venta');

test('venta.ver alcanza para las lecturas de la API', function (string $url) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    $this->getJson(str_replace('{venta}', (string) $venta->id, $url), bearer(usuarioCon('venta.ver')))
        ->assertOk();
})->with('endpoints de lectura de venta');

// Tramo H: las escrituras de la venta pasaron a la API, con el mismo permiso que tenían en la web.
// `{venta}` se reemplaza por una venta de verdad; sin cuerpo, lo que no es 403 es 422 o 200.
dataset('endpoints de escritura de venta', [
    'alta'               => ['post',  '/api/v1/ventas',                   'venta.crear'],
    'guardar la edicion' => ['patch', '/api/v1/ventas/{venta}',           'venta.editar'],
    'recotizar'          => ['post',  '/api/v1/ventas/{venta}/recotizar', 'venta.editar'],
    'cancelar'           => ['post',  '/api/v1/ventas/{venta}/cancelar',  'venta.editar'],
    'registrar el cobro'      => ['post', '/api/v1/ventas/{venta}/pagos',        'venta.cobrar'],
    'marcar como entregada'   => ['post', '/api/v1/ventas/{venta}/entregas',     'venta.entregar'],
    'registrar la devolucion' => ['post', '/api/v1/ventas/{venta}/devoluciones', 'venta.anular'],
]);

test('ningun otro permiso del modulo habilita las escrituras de la API', function (string $metodo, string $url, string $permiso) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();
    $otros = array_values(array_diff(PERMISOS_VENTA, [$permiso]));

    $this->json($metodo, str_replace('{venta}', (string) $venta->id, $url), [], bearer(usuarioCon(...$otros)))
        ->assertForbidden();

    expect($venta->fresh()->estado)->toBe('presupuesto');
})->with('endpoints de escritura de venta');

test('el permiso del endpoint alcanza para pasar en las escrituras', function (string $metodo, string $url, string $permiso) {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    $respuesta = $this->json($metodo, str_replace('{venta}', (string) $venta->id, $url), [], bearer(usuarioCon($permiso)));

    expect($respuesta->status())->not->toBe(403);
})->with('endpoints de escritura de venta');

test('quien solo puede ver no recibe los botones de accion', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    // M-33: lo que no se puede hacer no se ofrece. El control real es la ruta, que ya
    // se probó arriba; esto es la mitad de la interfaz. Desde el tramo H el shell lleva
    // el botón sólo si el @can deja (regla 12), y el JS lo muestra si el estado lo admite.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index'))
        ->assertOk()
        ->assertDontSee(route('ventas.create'))
        ->assertDontSee('data-editar', false);

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-accion="recotizar"', false)
        ->assertDontSee('data-accion="cancelar"', false)
        ->assertDontSee('data-accion="cobrar"', false)
        ->assertDontSee('data-accion="devolver"', false)
        // Y no ve la tarjeta de Acciones vacía: el `@canany` la saca entera.
        ->assertDontSee('Acciones');

    // Y la otra dirección: con los permisos, los botones están en el shell.
    $this->actingAs(usuarioCon('venta.ver', 'venta.crear', 'venta.editar'))
        ->get(route('ventas.index'))
        ->assertSee(route('ventas.create'))
        ->assertSee('data-editar', false);
});

test('quien puede cobrar y no editar ve el boton de cobrar', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    // **Este test es el que faltaba, y su ausencia dejaba pasar un bug real.** La
    // tarjeta de Acciones de la ficha estaba envuelta en un solo
    // `@can('venta.editar')`, así que el rol Cajero —que tiene `venta.ver` y
    // `venta.cobrar` y no tiene `venta.editar`— no veía ninguna acción. El test de
    // arriba pasaba igual, porque sólo afirmaba que quien no puede no ve.
    //
    // Un permiso por acción en la ruta exige un permiso por acción en la pantalla, y
    // eso hay que probarlo en las dos direcciones por el mismo motivo por el que el
    // dataset de permisos las prueba: agrupar varios botones detrás del permiso de
    // uno es el `?? "can_update"` del original, en la interfaz.
    $this->actingAs(usuarioCon('venta.ver', 'venta.cobrar'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee(route('pagos.create', $venta))
        ->assertSee('data-accion="cobrar"', false)
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-accion="recotizar"', false)
        ->assertDontSee('data-accion="cancelar"', false);
});

test('quien puede devolver y no cobrar ve solo el boton de devolver', function () {
    $venta = ventaCobrada(productoConPrecios(), 2);

    // El caso del Administrativo, que es el rol al que esta mitad le dio
    // `venta.anular`: ve la devolución y no ve el cobro ni la entrega.
    $this->actingAs(usuarioCon('venta.ver', 'venta.anular'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee(route('ventas.devolucion', $venta))
        ->assertSee('data-accion="devolver"', false)
        ->assertDontSee('data-accion="entregar"', false)
        ->assertDontSee('data-accion="cobrar"', false)
        ->assertDontSee('data-accion="editar"', false);
});

test('los shells del listado y de la ficha no traen datos de negocio', function () {
    // Regla 37: la página llega con su estructura; los datos, por la API.
    $cliente  = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);
    $producto = productoConPrecios(contado: 1234);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)], ['cliente_id' => $cliente->id]), admin());

    foreach ([route('ventas.index'), route('ventas.show', $venta)] as $pantalla) {
        $this->actingAs(usuarioCon('venta.ver', 'venta.editar', 'venta.cobrar'))
            ->get($pantalla)
            ->assertOk()
            ->assertDontSee('Panadería Los Tilos')
            ->assertDontSee($producto->nombre)
            ->assertDontSee($venta->numeroFormateado())
            ->assertDontSee('3.702,00');
    }
});

/*
|--------------------------------------------------------------------------
| Los filtros de la URL llegan a los scopes
|--------------------------------------------------------------------------
*/

test('el filtro de texto llega al scope', function () {
    $tilos = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    $deTilos = Venta::factory()->conLinea(productoConPrecios(), 1)->create(['cliente_id' => $tilos->id]);
    Venta::factory()->count(2)->conLinea(productoConPrecios(), 1)->create();

    $credencial = bearer(usuarioCon('venta.ver'));

    $this->getJson('/api/v1/ventas?q=tilos', $credencial)
        ->assertOk()
        ->assertJsonPath('total', 1);

    // Y por número, que es la otra rama del OR.
    $this->getJson('/api/v1/ventas?q='.urlencode($deTilos->numeroFormateado()), $credencial)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.id', $deTilos->id);
});

test('el filtro de estado llega al scope', function () {
    Venta::factory()->count(2)->conLinea(productoConPrecios(), 1)->create();
    Venta::factory()->cancelada()->conLinea(productoConPrecios(), 1)->create();

    $this->getJson('/api/v1/ventas?estado=cancelada', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.estado_texto', 'Cancelada');
});

test('los filtros de cliente y de vendedor llegan al scope', function () {
    $cliente  = Cliente::factory()->create();
    $vendedor = User::factory()->create();

    Venta::factory()->conLinea(productoConPrecios(), 1)->create([
        'cliente_id' => $cliente->id,
        'usuario_id' => $vendedor->id,
    ]);
    Venta::factory()->count(2)->conLinea(productoConPrecios(), 1)->create();

    $credencial = bearer(usuarioCon('venta.ver'));

    // `cliente_id` no tiene selector en el formulario: llega por enlace, desde la
    // ficha del cliente. Que el parámetro funcione es justamente lo que hay que
    // probar, porque ninguna pantalla lo arma con un submit.
    $this->getJson("/api/v1/ventas?cliente_id={$cliente->id}", $credencial)
        ->assertOk()
        ->assertJsonPath('total', 1)
        // Y el chip nombra al cliente en lugar de mostrar un id.
        ->assertJsonPath('criterios.cliente', $cliente->razon_social);

    $this->getJson("/api/v1/ventas?vendedor_id={$vendedor->id}", $credencial)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('criterios.vendedor', $vendedor->nombre_completo);
});

test('el rango de fechas llega al scope', function () {
    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $this->travelTo(Carbon::parse('2026-04-02 08:00'));
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $this->travelBack();

    // El extremo de arriba incluye el día completo: la venta de las 23:40 del 15
    // tiene que entrar en «hasta el 15».
    $this->getJson('/api/v1/ventas?desde=2026-03-01&hasta=2026-03-15', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('un filtro invalido se rechaza con su campo', function () {
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $credencial = bearer(usuarioCon('venta.ver'));

    // «cobrada» era el nombre del estado en el sistema original y no existe en el
    // ENUM nuevo. Desde el tramo H la API lo rechaza (M-31, HL-120): no se corrige
    // a «todas» en silencio.
    $this->getJson('/api/v1/ventas?estado=cobrada', $credencial)
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonValidationErrorFor('estado', 'errores');

    // Y un rango dado vuelta tampoco pasa.
    $this->getJson('/api/v1/ventas?desde=2026-05-01&hasta=2026-01-01', $credencial)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('hasta', 'errores');
});

test('la paginacion arrastra los filtros', function () {
    Venta::factory()->count(18)->cancelada()->conLinea(productoConPrecios(), 1)->create();
    Venta::factory()->count(3)->conLinea(productoConPrecios(), 1)->create();

    $credencial = bearer(usuarioCon('venta.ver'));

    // A-25: la página 2 no pierde el filtro. El servidor devuelve en `criterios` lo que aplicó.
    $this->getJson('/api/v1/ventas?estado=cancelada&por_pagina=15', $credencial)
        ->assertOk()
        ->assertJsonPath('total', 18)
        ->assertJsonCount(15, 'datos')
        ->assertJsonPath('criterios.estado', 'cancelada');

    $this->getJson('/api/v1/ventas?estado=cancelada&por_pagina=15&pagina=2', $credencial)
        ->assertOk()
        ->assertJsonCount(3, 'datos')
        ->assertJsonPath('datos.*.estado', array_fill(0, 3, 'cancelada'));
});

/*
|--------------------------------------------------------------------------
| El alta
|--------------------------------------------------------------------------
*/

test('el alta crea el presupuesto y devuelve la ficha', function () {
    $producto = productoConPrecios(contado: 1000);

    $respuesta = $this->postJson('/api/v1/ventas', datosDeVenta([lineaDeVenta($producto, 3)]), bearer(usuarioCon('venta.crear')))
        ->assertCreated()
        ->assertJsonPath('datos.estado', 'presupuesto');

    $venta = Venta::latest('id')->first();

    expect($respuesta->json('datos.id'))->toBe($venta->id)
        ->and($respuesta->json('mensaje'))->toContain('emitido')
        ->and($venta->estado)->toBe('presupuesto')
        ->and($venta->total)->toBe('3000.00')
        ->and($venta->lineas)->toHaveCount(1);
});

test('el alta rechaza el precio que viene del formulario', function () {
    $producto = productoConPrecios(contado: 1000);

    // El precio lo lee el servidor del catálogo. Hay tres barreras: el servicio no
    // mira esta clave, el modelo no la puede escribir, y el Form Request la declara
    // `prohibited`. Esta es la única de las tres que **informa** en lugar de
    // descartar en silencio, que es la doctrina de M-31 aplicada a la entrada que
    // nadie debería mandar.
    $this->postJson('/api/v1/ventas', datosDeVenta([[
            'producto_id'     => $producto->id,
            'cantidad'        => 1,
            'precio_unitario' => 1,
        ]]), bearer(usuarioCon('venta.crear')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('lineas.0.precio_unitario', 'errores');

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza el estado que viene del formulario', function () {
    $producto = productoConPrecios(contado: 1000);

    // La forma de C-9 que una tabla de transiciones no cubre: el estado no es un
    // campo. El original lo tomaba del body en `updateEstado`.
    $this->postJson('/api/v1/ventas', datosDeVenta([lineaDeVenta($producto, 1)], [
            'estado' => 'pagada',
            'total'  => 1,
        ]), bearer(usuarioCon('venta.crear')))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['estado', 'total'], 'errores');

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza un descuento por encima del tope y conserva lo cargado', function () {
    $producto = productoConPrecios(contado: 1000);
    $cliente  = Cliente::factory()->create();

    $this->postJson('/api/v1/ventas', datosDeVenta([lineaDeVenta($producto, 2)], [
            'cliente_id'           => $cliente->id,
            'descuento_porcentaje' => 20,
            'observaciones'        => 'Pasa el jueves',
        ]), bearer(usuarioCon('venta.crear')))
        // A-12, y de paso M-31: la petición se rechaza entera y el error cae sólo en
        // su campo. Desde el tramo H lo cargado no vuelve de la sesión: la pantalla no
        // se recarga, y los demás campos llegan sin error que los borre.
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('descuento_porcentaje', 'errores')
        ->assertJsonMissingValidationErrors(['observaciones', 'cliente_id'], 'errores');

    expect(Venta::count())->toBe(0);
});

test('el alta sin lineas se rechaza', function () {
    $this->postJson('/api/v1/ventas', datosDeVenta([]), bearer(usuarioCon('venta.crear')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('lineas', 'errores');

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza el mismo producto dos veces, en la linea culpable', function () {
    $producto = productoConPrecios(contado: 1000);

    // El error se cuelga de la línea repetida y no del arreglo entero: es lo que le
    // permite a la pantalla marcar esa fila en lugar de un aviso general.
    $this->postJson('/api/v1/ventas', datosDeVenta([
            lineaDeVenta($producto, 2),
            lineaDeVenta($producto, 3),
        ]), bearer(usuarioCon('venta.crear')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('lineas.1.producto_id', 'errores');

    expect(Venta::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| La edición, recotizar y cancelar, por HTTP
|--------------------------------------------------------------------------
*/

test('la edicion conserva el precio congelado de las lineas que ya estaban', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1800]);

    $this->patchJson("/api/v1/ventas/{$venta->id}", datosDeVenta([lineaDeVenta($producto, 3)]), bearer(usuarioCon('venta.editar')))
        ->assertOk()
        ->assertJsonPath('datos.id', $venta->id)
        ->assertJsonPath('datos.lineas.0.precio_unitario', '1000.00');

    // M-14 de punta a punta: el precio quedó en mil aunque el producto hoy valga mil
    // ochocientos, y los importes se recalcularon con ese precio.
    expect($venta->fresh('lineas')->lineas->first()->precio_unitario)->toBe('1000.00')
        ->and($venta->fresh()->total)->toBe('3000.00');
});

test('el formulario de edicion de una venta cobrada redirige con un aviso', function () {
    $venta = Venta::factory()->pagada()->conLinea(productoConPrecios(), 2)->create();

    // Red de contención para una URL guardada en favoritos: la venta existe, lo que
    // no corresponde es modificarla. Se redirige con un aviso en lugar de abortar.
    $this->actingAs(usuarioCon('venta.editar'))
        ->get(route('ventas.edit', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('error');

    // Y la edición por la API tampoco pasa: lo rechaza el servicio (409).
    $this->patchJson("/api/v1/ventas/{$venta->id}", datosDeVenta([lineaDeVenta(productoConPrecios(), 9)]), bearer(usuarioCon('venta.editar')))
        ->assertStatus(409)
        ->assertJsonStructure(['mensaje', 'codigo']);

    expect($venta->fresh('lineas')->lineas->first()->cantidad)->toBe(2);
});

test('recotizar trae los precios de hoy e informa que cambio', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1500]);

    $this->postJson("/api/v1/ventas/{$venta->id}/recotizar", [], bearer(usuarioCon('venta.editar')))
        ->assertOk()
        ->assertJsonPath('datos.importes.total', '3000.00')
        ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'recotizada'));

    expect($venta->fresh('lineas')->lineas->first()->precio_unitario)->toBe('1500.00')
        ->and($venta->fresh()->total)->toBe('3000.00');
});

test('cancelar deja el presupuesto cancelado sin borrarlo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $this->postJson("/api/v1/ventas/{$venta->id}/cancelar", [], bearer(usuarioCon('venta.editar')))
        ->assertOk()
        ->assertJsonPath('datos.estado', 'cancelada')
        ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'cancelado'));

    // El núcleo de M-16 visto desde la pantalla: queda cancelada, con sus líneas y
    // su total. `SaleDao::delete()` era un DELETE plano con ON DELETE CASCADE.
    $venta = $venta->fresh('lineas');

    expect($venta->estado)->toBe('cancelada')
        ->and($venta->total)->toBe('2000.00')
        ->and($venta->lineas)->toHaveCount(1)
        ->and(Venta::count())->toBe(1);
});

test('cancelar dos veces se rechaza con un aviso', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = Venta::factory()->cancelada()->conLinea($producto, 2)->create();

    // La excepción tipada se traduce en `bootstrap/app.php`, una sola vez para toda la
    // aplicación: por eso no hay un try/catch en el controlador (M-30). En la API, 409.
    $this->postJson("/api/v1/ventas/{$venta->id}/cancelar", [], bearer(usuarioCon('venta.editar')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'transicion_invalida');

    expect($venta->fresh()->estado)->toBe('cancelada');
});

test('un vendedor puede cancelar el presupuesto que cargo', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = User::factory()->conRol('Vendedor')->create();

    $credencial = bearer($vendedor);

    $this->postJson('/api/v1/ventas', datosDeVenta([lineaDeVenta($producto, 1)]), $credencial)
        ->assertCreated();

    $venta = Venta::latest('id')->first();

    // Con el rol real, no con un rol de prueba armado a medida. El Vendedor tiene
    // `venta.crear` y `venta.editar` pero NO `venta.anular`, así que si la ruta de
    // cancelar exigiera ese permiso, no podría dar de baja lo que él mismo cargó.
    // Es la decisión que quedó escrita en las rutas, afirmada contra el seeder.
    $this->postJson("/api/v1/ventas/{$venta->id}/cancelar", [], $credencial)
        ->assertOk();

    expect($venta->fresh()->estado)->toBe('cancelada');
});

/*
|--------------------------------------------------------------------------
| La ficha
|--------------------------------------------------------------------------
*/

test('la ficha muestra el documento con sus importes', function () {
    $producto = productoConPrecios(contado: 1000, alicuota: 21);
    $cliente  = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    $venta = $this->service->crear(
        datosDeVenta([lineaDeVenta($producto, 3)], ['cliente_id' => $cliente->id, 'descuento_porcentaje' => 10]),
        admin(),
    );

    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.numero', $venta->numeroFormateado())
        ->assertJsonPath('datos.estado_texto', 'Presupuesto')
        ->assertJsonPath('datos.cliente.razon_social', 'Panadería Los Tilos')
        ->assertJsonPath('datos.lineas.0.descripcion', $producto->nombre)
        // El neto y el IVA persistidos, que son los importes que se declaran.
        ->assertJsonPath('datos.lineas.0.neto', '2479.34')
        ->assertJsonPath('datos.lineas.0.iva', '520.66')
        // Subtotal 3.000, descuento 300 y total 2.700.
        ->assertJsonPath('datos.importes.subtotal', '3000.00')
        ->assertJsonPath('datos.importes.descuento', '300.00')
        ->assertJsonPath('datos.importes.total', '2700.00');
});

test('la ficha dice que acciones admite el estado, no el permiso', function () {
    $presupuesto = $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 2)]), admin());
    $cobrada     = ventaCobrada(productoConPrecios(), 2);

    // Con sólo `venta.ver`: las acciones las decide el estado (regla 9), el botón lo decide el @can.
    $credencial = bearer(usuarioCon('venta.ver'));

    $this->getJson("/api/v1/ventas/{$presupuesto->id}", $credencial)
        ->assertOk()
        ->assertJsonPath('datos.acciones.editar', true)
        ->assertJsonPath('datos.acciones.cobrar', true)
        ->assertJsonPath('datos.acciones.cancelar', true)
        ->assertJsonPath('datos.acciones.devolver', false)
        ->assertJsonPath('datos.cobro.principal', 'efectivo')
        ->assertJsonPath('datos.devolucion', null);

    $this->getJson("/api/v1/ventas/{$cobrada->id}", $credencial)
        ->assertOk()
        ->assertJsonPath('datos.acciones.editar', false)
        ->assertJsonPath('datos.acciones.cobrar', false)
        ->assertJsonPath('datos.acciones.cancelar', false)
        ->assertJsonPath('datos.acciones.devolver', true)
        ->assertJsonPath('datos.cobro', null)
        ->assertJsonPath('datos.devolucion.sugerido', 'efectivo');
});

test('los vendedores del filtro son los que cargaron ventas', function () {
    $vendedor = User::factory()->create(['nombre' => 'Lucía', 'apellido' => 'Paz']);
    User::factory()->create();   // sin ventas: no se ofrece

    Venta::factory()->conLinea(productoConPrecios(), 1)->create(['usuario_id' => $vendedor->id]);

    $this->getJson('/api/v1/ventas/vendedores', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.id', $vendedor->id)
        ->assertJsonPath('datos.0.nombre', $vendedor->nombre_completo);
});

test('la ficha dice sin identificar con palabras', function () {
    $venta = $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 1)]), admin());

    // `cliente_id` en null SIGNIFICA sin identificar: no es un dato que falte, y la
    // pantalla no muestra una tarjeta vacía.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.cliente', null)
        ->assertJsonPath('datos.cliente_texto', 'Sin identificar');
});

/*
|--------------------------------------------------------------------------
| El cobro, por la ruta
|--------------------------------------------------------------------------
|
| El segundo nivel que `patron-modulo.md` exige, y acá tuvo su demostración: los
| treinta casos de `PagoServiceTest` pasaban todos y el formulario estaba roto,
| porque el Form Request descartaba las «filas vacías» y ninguna fila del cobro es
| nunca vacía —`metodo` es un hidden con valor fijo—. Un test que postea el cuerpo
| real es lo único que lo encuentra.
|
| Es A-24 otra vez con otra ropa: la pieza funcionaba y el cableado no.
|
*/

test('el cobro acepta el cuerpo que manda el formulario, con los medios sin usar vacios', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 4)]), admin());

    // Exactamente lo que postea la pantalla: una fila por medio ofrecido, con monto
    // sólo en el que se usó, y el monto escrito en formato argentino.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => '4.000,00'],
                ['metodo' => 'transferencia', 'monto' => ''],
                ['metodo' => 'qr',            'monto' => ''],
            ],
        ], bearer(usuarioCon('venta.cobrar')))
        ->assertCreated()
        ->assertJsonPath('datos.estado', 'pagada')
        ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'cobrada'));

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->pagos()->count())->toBe(1)
        ->and($venta->fresh()->pagado())->toBe(4000.0)
        ->and($producto->fresh()->stock)->toBe(46);
});

test('un cobro donde todos los medios llegan vacios vuelve con un aviso general', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Que venga al menos un monto es una regla sobre el conjunto, así que el error no
    // va en un campo: vuelve como aviso (en la API, 409 con su mensaje y sin errores
    // por campo). Mismo criterio que la recepción con todas las cantidades en cero.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => ''],
                ['metodo' => 'transferencia', 'monto' => ''],
                ['metodo' => 'qr',            'monto' => ''],
            ],
        ], bearer(usuarioCon('venta.cobrar')))
        ->assertStatus(409)
        ->assertJsonPath('errores', []);

    expect($venta->fresh()->estado)->toBe('presupuesto');
});

test('un cobro por un monto que no coincide vuelve con aviso y conserva lo cargado', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // El saldo NO se valida en el Form Request —depende de los pagos ya registrados,
    // que otra petición puede estar moviendo— así que el error llega como aviso y no
    // en el campo: 409 con su mensaje. En la web `withInput()` devolvía lo cargado;
    // desde el tramo H la pantalla no se recarga y lo cargado no se pierde.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", [
            'pagos' => [['metodo' => 'efectivo', 'monto' => '1500']],
        ], bearer(usuarioCon('venta.cobrar')))
        ->assertStatus(409)
        ->assertJsonPath('errores', [])
        ->assertJsonStructure(['mensaje']);

    expect($venta->fresh()->pagos()->count())->toBe(0);
});

test('el cobro rechaza las columnas que acredita el webhook', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // La pantalla del mostrador no es el webhook: el servicio acepta
    // `mp_payment_id` como clave de idempotencia, y este formulario lo rechaza. Se
    // rechaza en lugar de ignorarse, porque descartar en silencio un dato que alguien
    // mandó es la doctrina de M-31 al revés.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", [
            'pagos' => [[
                'metodo'          => 'efectivo',
                'monto'           => '2000',
                'mp_payment_id'   => 'mp-inventado',
                'neto_acreditado' => '1900',
                'usuario_id'      => 1,
            ]],
        ], bearer(usuarioCon('venta.cobrar')))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'pagos.0.mp_payment_id',
            'pagos.0.neto_acreditado',
            'pagos.0.usuario_id',
        ], 'errores');

    expect($venta->fresh()->pagos()->count())->toBe(0);
});

test('un campo prohibido en un medio sin monto se rechaza igual', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Éste es el caso que el arreglo del Form Request mejoró. La primera versión
    // descartaba las filas «vacías» antes de validarlas, así que un campo prohibido
    // en una fila sin monto habría desaparecido sin que nadie se enterara. Ahora no
    // se descarta nada acá —las filas sin monto las filtra el servicio— y el campo se
    // rechaza con su mensaje.
    $this->postJson("/api/v1/ventas/{$venta->id}/pagos", [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => '2000'],
                ['metodo' => 'transferencia', 'monto' => '', 'mp_payment_id' => 'mp-inventado'],
            ],
        ], bearer(usuarioCon('venta.cobrar')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('pagos.1.mp_payment_id', 'errores');

    expect($venta->fresh()->pagos()->count())->toBe(0)
        ->and($venta->fresh()->estado)->toBe('presupuesto');
});

test('el formulario de cobro de una venta ya cobrada redirige con un aviso', function () {
    $venta = ventaCobrada(productoConPrecios(contado: 1000), 2);

    // La ficha ofrece el botón sólo cuando la transición existe; esto es la red para
    // una URL guardada en favoritos. Se redirige con aviso en lugar de abortar: la
    // venta existe y lo que no corresponde es cobrarla.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('error');
});

test('la ficha muestra los movimientos de dinero despues de cobrar', function () {
    $venta = ventaCobrada(productoConPrecios(contado: 1000), 2);

    // Tramo H: los movimientos, de la API; la tarjeta, del shell (nace oculta y el JS la muestra).
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.pagos.0.metodo_texto', 'Efectivo')
        ->assertJsonPath('datos.importes.pagado', '2000.00');

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Movimientos de dinero')
        ->assertSee('Quedó cobrado');
});

/*
|--------------------------------------------------------------------------
| La devolución, por la ruta — A-11
|--------------------------------------------------------------------------
*/

test('la devolucion por la API repone stock, revierte el pago y deriva el estado', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5);
    $linea    = $venta->lineas()->firstOrFail();

    expect($producto->fresh()->stock)->toBe(45);

    $this->postJson("/api/v1/ventas/{$venta->id}/devoluciones", [
            'cantidades' => [$linea->id => 2],
            'metodo'     => 'qr',
            'motivo'     => 'Vino fallado',
        ], bearer(usuarioCon('venta.anular')))
        ->assertCreated()
        ->assertJsonPath('datos.estado', 'devuelta_parcial')
        ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'Devolución parcial'));

    expect($venta->fresh()->estado)->toBe('devuelta_parcial')
        ->and($linea->fresh()->cantidad_devuelta)->toBe(2)
        ->and($producto->fresh()->stock)->toBe(47)
        ->and($venta->fresh()->pagado())->toBe(3000.0)
        ->and($venta->fresh()->pagos()->count())->toBe(2)
        // El medio del contra-asiento es el que eligió quien devolvió, no el del
        // cobro: la venta se cobró en efectivo y la plata volvió por QR.
        ->and($venta->fresh()->pagos()->orderByDesc('id')->first()->metodo)->toBe('qr');
});

test('la devolucion rechaza el monto y el estado que vengan del formulario', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);
    $linea    = $venta->lineas()->firstOrFail();

    // Los dos campos que definen el hallazgo: cuánta plata vuelve lo calcula el
    // servidor, y que la devolución sea parcial o total se deriva de las líneas. Si
    // el operador pudiera escribir el monto, podría devolver más de lo que entró.
    $this->postJson("/api/v1/ventas/{$venta->id}/devoluciones", [
            'cantidades' => [$linea->id => 1],
            'metodo'     => 'efectivo',
            'monto'      => '999999',
            'estado'     => 'devuelta',
        ], bearer(usuarioCon('venta.anular')))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['monto', 'estado'], 'errores');

    expect($linea->fresh()->cantidad_devuelta)->toBe(0)
        ->and($venta->fresh()->pagos()->count())->toBe(1);
});

test('el formulario de devolucion de un presupuesto redirige con un aviso', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    $this->actingAs(usuarioCon('venta.anular'))
        ->get(route('ventas.devolucion', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('error');
});

test('entregar por la API deja la venta entregada sin mover stock', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);

    $stockPrevio = $producto->fresh()->stock;

    $this->postJson("/api/v1/ventas/{$venta->id}/entregas", [], bearer(usuarioCon('venta.entregar')))
        ->assertCreated()
        ->assertJsonPath('datos.estado', 'entregada')
        ->assertJsonCount(1, 'datos.entregas');

    expect($venta->fresh()->estado)->toBe('entregada')
        ->and($producto->fresh()->stock)->toBe($stockPrevio);
});

/*
|--------------------------------------------------------------------------
| El documento: presupuesto o comprobante de venta
|--------------------------------------------------------------------------
*/

test('descargar el documento es una lectura y no cambia nada', function () {
    $venta = ventaCobrada(productoConPrecios(contado: 1000), 3);

    $respuesta = $this->get(($this->enlace)("/api/v1/ventas/{$venta->id}/pdf", usuarioCon('venta.ver')));

    $respuesta->assertOk();

    // Es el mismo test que fija el PDF del pedido en la Fase 5: se puede repetir y no
    // marca nada. La venta sigue donde estaba.
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain($venta->numeroFormateado())
        ->and($venta->fresh()->estado)->toBe('pagada');
});

test('el presupuesto tambien se imprime por su enlace, y no por el de la tienda', function () {
    $presupuesto = Venta::factory()->conLinea(productoConPrecios(contado: 1000), 2)->create();

    // HL-129: la ruta firmada de la tienda exige lo cobrado; la de la administración, no.
    $respuesta = $this->get(($this->enlace)("/api/v1/ventas/{$presupuesto->id}/pdf", usuarioCon('venta.ver')));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-disposition'))->toContain('presupuesto-');
});

test('el enlace del documento deja de servir si quien lo pidio pierde el permiso', function () {
    $venta   = ventaCobrada(productoConPrecios(contado: 1000), 1);
    $usuario = usuarioCon('venta.ver');
    $enlace  = ($this->enlace)("/api/v1/ventas/{$venta->id}/pdf", $usuario);

    $usuario->forceFill(['activo' => false])->save();

    $this->get($enlace)->assertForbidden();
    // Y tocado, tampoco: la firma cubre quién lo pidió.
    $this->get(str_replace('pedido_por=', 'pedido_por=9', $enlace))->assertForbidden();
});

test('una venta cancelada no tiene documento que entregar', function () {
    $venta = Venta::factory()->cancelada()->conLinea(productoConPrecios(contado: 1000), 2)->create();

    $this->getJson("/api/v1/ventas/{$venta->id}/pdf", bearer(usuarioCon('venta.ver')))
        ->assertStatus(409)
        ->assertJsonPath('mensaje', fn (string $mensaje) => str_contains($mensaje, 'no hay documento'));
});

test('la ficha ofrece descargar el documento salvo que este cancelada', function () {
    $producto = productoConPrecios(contado: 1000);

    $viva   = Venta::factory()->conLinea($producto, 2)->create();
    $muerta = Venta::factory()->cancelada()->conLinea($producto, 2)->create();

    $credencial = bearer(usuarioCon('venta.ver'));

    $this->getJson("/api/v1/ventas/{$viva->id}", $credencial)->assertJsonPath('datos.acciones.imprimir', true);
    $this->getJson("/api/v1/ventas/{$muerta->id}", $credencial)->assertJsonPath('datos.acciones.imprimir', false);
});

test('el presupuesto declara su validez y el comprobante de venta no', function () {
    $producto = productoConPrecios(contado: 1000);

    $presupuesto = Venta::factory()->conLinea($producto, 2)->create();
    $cobrada     = ventaCobrada($producto, 2);

    $comoHtml = fn (Venta $venta) => view('ventas.pdf', [
        'venta'       => $venta->fresh(['lineas.producto', 'cliente', 'usuario']),
        'validoHasta' => $venta->created_at->copy()->addDays(15),
    ])->render();

    expect($comoHtml($presupuesto))->toContain('Presupuesto')
        ->and($comoHtml($presupuesto))->toContain('Válido hasta')
        ->and($comoHtml($cobrada))->toContain('Comprobante de venta')
        ->and($comoHtml($cobrada))->not->toContain('Válido hasta');
});

test('el documento imprime lo congelado y no el estado ni los pagos ni lo devuelto', function () {
    $venta = ventaCobrada(productoConPrecios(contado: 1000), 3);

    $this->service->devolver($venta, [
        'cantidades' => [$venta->lineas->first()->id => 1],
        'metodo'     => 'efectivo',
    ], admin());

    $html = view('ventas.pdf', [
        'venta'       => $venta->fresh(['lineas.producto', 'cliente', 'usuario']),
        'validoHasta' => $venta->created_at->copy()->addDays(15),
    ])->render();

    // Regenerarlo dentro de un año tiene que dar el mismo papel, así que no imprime
    // nada que cambie: imprime las 3 unidades vendidas y los 3000 del documento, no
    // las 2 que quedaron ni los 2000 que siguen cobrados. Es la misma decisión que el
    // PDF del pedido, que imprime `cantidad_pedida` y nunca `cantidad_recibida`.
    expect($html)->toContain($venta->numeroFormateado())
        ->and($html)->toContain('3.000,00')
        ->and($html)->toContain('no es una factura')
        ->and($html)->not->toContain('Devuelta parcial')
        ->and($html)->not->toContain('2.000,00');
});

/*
|--------------------------------------------------------------------------
| La exportación del listado
|--------------------------------------------------------------------------
|
| Que el filtrado funcione ya está cubierto: `index()` y `exportar()` comparten
| `filtradas()`, así que los tests de filtros de la pantalla prueban la misma cadena.
| Eso es justamente lo que se compra al extraer el método. Lo que falta probar es lo
| propio del documento: que declare su alcance y que diga cuando recortó.
|
*/

test('exportar el listado devuelve un PDF y no cambia nada', function () {
    $venta = ventaCobrada(productoConPrecios(contado: 1000), 3);

    $respuesta = $this->get(($this->enlace)('/api/v1/ventas/impresion', usuarioCon('venta.ver')));

    $respuesta->assertOk();

    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('ventas-')
        ->and($venta->fresh()->estado)->toBe('pagada');
});

test('el listado exportado declara los filtros aplicados', function () {
    $usuario  = usuarioCon('venta.ver');
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoVentas();

    $html = view('impresion.listado-pdf', $servicio->datosDelPdf(
        $listado,
        Venta::query()->orderByDesc('id'),
        $servicio->opciones($listado, [], $usuario),
        $usuario,
        ['Estado' => 'Pagada', 'Desde' => '01/03/2026'],
    ))->render();

    expect($html)->toContain('Estado')
        ->and($html)->toContain('Pagada')
        ->and($html)->toContain('01/03/2026')
        ->and($html)->not->toContain('Sin ningún filtro aplicado');
});

test('el listado sin filtros lo dice en lugar de no decir nada', function () {
    $usuario  = usuarioCon('venta.ver');
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoVentas();

    $html = view('impresion.listado-pdf', $servicio->datosDelPdf(
        $listado,
        Venta::query()->orderByDesc('id'),
        $servicio->opciones($listado, [], $usuario),
        $usuario,
        [],
    ))->render();

    expect($html)->toContain('Sin ningún filtro aplicado');
});

test('cuando hay mas ventas que el tope el documento lo dice y el total es de todas', function () {
    $producto = productoConPrecios(contado: 1000);
    $primera  = ventaCobrada($producto, 1);
    $segunda  = ventaCobrada($producto, 2);
    $tercera  = ventaCobrada($producto, 3);

    $usuario  = usuarioCon('venta.ver');
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoVentas();

    $html = view('impresion.listado-pdf', $servicio->datosDelPdf(
        $listado,
        Venta::query()->orderByDesc('id'),
        $servicio->opciones($listado, [], $usuario),
        $usuario,
        [],
        tope: 2,
    ))->render();

    // El resultado dice lo que pasó de verdad: que recortó, y que el total es de las
    // tres coincidencias y no de las dos impresas, que son las más nuevas.
    expect($html)->toContain('data-recorte')
        ->and($html)->toContain($tercera->numeroFormateado())
        ->and($html)->toContain($segunda->numeroFormateado())
        ->and($html)->not->toContain($primera->numeroFormateado())
        ->and($html)->toMatch('/Total:\s*3 ventas/')
        ->and($html)->toContain(Importe::pesos(Venta::sum('total')));
});
/*
|--------------------------------------------------------------------------
| A quién se le vendió: «Sin identificar»
|--------------------------------------------------------------------------
|
| Punto 13 del revisor, primera mitad. Se renombran dos cosas y hay una tercera que
| NO se renombra, y los cuatro tests van juntos porque son una sola decisión: el
| rótulo de la pantalla cambió, la categoría fiscal de ARCA no.
|
*/

test('una venta sin cliente dice sin identificar', function () {
    $venta = Venta::factory()->create(['cliente_id' => null]);

    expect($venta->clienteTexto())->toBe('Sin identificar');
});

test('una venta con cliente dice su razon social', function () {
    $cliente = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);
    $venta   = Venta::factory()->create(['cliente_id' => $cliente->id]);

    expect($venta->clienteTexto())->toBe('Panadería Los Tilos');
});

test('la ficha de la venta muestra sin identificar y no consumidor final', function () {
    $venta = Venta::factory()->create(['cliente_id' => null]);

    $respuesta = $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.cliente_texto', 'Sin identificar');

    expect($respuesta->getContent())->not->toContain('Consumidor final');
});

test('la condicion fiscal consumidor_final no se renombro', function () {
    // La mitad que el plan dice NO tocar. `consumidor_final` es una de las cuatro
    // categorías de ARCA y decide si va factura A o B en la Etapa 2; el test existe
    // para que un «renombrar todo» futuro no se la lleve junto con el rótulo.
    expect(Cliente::CONDICIONES_IVA)->toHaveKey('consumidor_final')
        ->and(Cliente::CONDICIONES_IVA['consumidor_final'])->toBe('Consumidor final');
});

/*
|--------------------------------------------------------------------------
| Quién supera el disponible: una definición, dos lenguajes
|--------------------------------------------------------------------------
|
| El punto 2 del revisor pide avisar del faltante al cargar el presupuesto, y el
| plan lo reparte en tres momentos que no bloquean más uno que sí —el cobro, que
| es el de la Fase 6 y no se toca—. Antes de las pantallas hace falta que la
| pregunta «¿qué renglón pide más de lo que hay?» tenga una sola respuesta.
|
| La tiene `Venta::faltantesDeStock()`, que lee el accesor `stock_disponible`, y
| la repite en SQL `VentaLinea::scopeSuperanElDisponible()`, que reusa la constante
| `Producto::DISPONIBLE` para que la resta siga viviendo en un solo lugar
| (invariante 6). Son dos escrituras de la misma regla porque el listado no puede
| contar en PHP sin un N+1, y lo único que las ata es el primer test de acá.
|
| Cotizar lo que todavía no llegó es legítimo, así que nada de esto impide nada.
|
*/

/** Un presupuesto que pide más unidades de las que hay disponibles. */
function presupuestoSinStock(int $cantidad = 5, int $stock = 2): Venta
{
    $producto = Producto::factory()->conStock($stock)->create([
        'codigo'         => 'ESC-00001',
        'nombre'         => 'Teclado escaso',
        'precio_contado' => '1000.00',
    ]);

    return app(VentaService::class)->crear(
        datosDeVenta([lineaDeVenta($producto, $cantidad)]),
        admin(),
    );
}

test('el conteo en SQL y el accesor en PHP dan el mismo numero', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    $enSql = VentaLinea::query()->where('venta_id', $venta->id)->superanElDisponible()->count();
    $enPhp = $venta->load('lineas.producto')->faltantesDeStock()->count();

    expect($enSql)->toBe(1)->and($enPhp)->toBe(1);
});

test('un presupuesto con stock de sobra no tiene ningun faltante', function () {
    $venta = presupuestoSinStock(cantidad: 2, stock: 10);

    $enSql = VentaLinea::query()->where('venta_id', $venta->id)->superanElDisponible()->count();

    expect($enSql)->toBe(0)
        ->and($venta->load('lineas.producto')->faltantesDeStock())->toBeEmpty();
});

test('el disponible descuenta lo reservado y no sólo el stock', function () {
    // El disponible es `stock - stock_reservado` en todos lados (invariante 6): con
    // ocho en el estante y seis comprometidos, una venta de cuatro ya no entra.
    $producto = Producto::factory()->conStock(8, 6)->create(['precio_contado' => '1000.00']);

    $venta = app(VentaService::class)->crear(
        datosDeVenta([lineaDeVenta($producto, 4)]),
        admin(),
    );

    expect(VentaLinea::query()->where('venta_id', $venta->id)->superanElDisponible()->count())->toBe(1)
        ->and($venta->load('lineas.producto')->faltantesDeStock())->toHaveCount(1);
});

test('la pantalla de cobro avisa del faltante con el conteo del modelo', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    // El texto es el de la Fase 6 y no cambia: lo que cambió es de dónde sale el
    // número. Desde el tramo H llega en la ficha y el JS lo pone en el aviso.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonCount(1, 'datos.cobro.problemas')
        ->assertJsonPath('datos.cobro.problemas.0', fn (string $texto) => str_contains($texto, 'y hay 2 disponible(s)'));
});

test('el aviso del cobro prefiere la baja del producto antes que el faltante', function () {
    $venta    = presupuestoSinStock(cantidad: 5, stock: 2);
    $producto = $venta->lineas->first()->producto;

    $producto->update(['activo' => false]);

    // Un renglón, un mensaje. Los dos problemas se arreglan en pantallas distintas
    // —el catálogo y el depósito— y el de la baja hace fallar el cobro igual.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonCount(1, 'datos.cobro.problemas')
        ->assertJsonPath('datos.cobro.problemas.0', fn (string $texto) => str_contains($texto, 'dado de baja en el cat')
            && ! str_contains($texto, 'y hay 2 disponible(s)'));
});

/*
|--------------------------------------------------------------------------
| Los tres momentos que avisan, y el que bloquea
|--------------------------------------------------------------------------
|
| Al guardar, en la ficha y en el listado se avisa; en el cobro se bloquea, y eso
| es de la Fase 6 y no se toca. Los tres primeros no impiden nada: cotizar algo que
| todavía no llegó es un caso de negocio legítimo, y lo que el sistema tiene que
| hacer no es prohibir, es no dejar llegar al cobro sin saberlo.
|
| Las dos insignias se prueban por un `id` puesto a propósito y no por su frase:
| `assertSee` compara contra el HTML en bruto y el formateador de Blade parte las
| líneas largas.
|
*/

test('el alta de un presupuesto que supera el disponible avisa en azul y no en verde', function () {
    $producto = Producto::factory()->conStock(2)->create(['precio_contado' => '1000.00']);

    // Se emite igual. Lo que no puede pasar es que un verde afirme que quedó todo
    // listo: lo que quedó es un presupuesto que hoy no se puede cobrar.
    // Desde el tramo H el tono lo decide la pantalla con `sin_stock` de la ficha.
    $this->postJson('/api/v1/ventas', datosDeVenta([lineaDeVenta($producto, 5)]), bearer(usuarioCon('venta.crear')))
        ->assertCreated()
        ->assertJsonPath('datos.sin_stock', true)
        ->assertJsonPath('mensaje', fn (string $aviso) => str_contains($aviso, 'Un renglón supera el disponible'));

    expect(Venta::latest('id')->first()->estado)->toBe('presupuesto');
});

test('el aviso del alta cuenta los renglones en plural', function () {
    $uno = Producto::factory()->conStock(1)->create(['precio_contado' => '1000.00']);
    $dos = Producto::factory()->conStock(1)->create(['precio_contado' => '2000.00']);

    $this->postJson('/api/v1/ventas', datosDeVenta([
            lineaDeVenta($uno, 4),
            lineaDeVenta($dos, 4),
        ]), bearer(usuarioCon('venta.crear')))
        ->assertCreated()
        ->assertJsonPath('mensaje', fn (string $aviso) => str_contains($aviso, '2 renglones superan el disponible'));
});

test('la edicion que deja un renglon sin stock avisa en azul', function () {
    $venta    = presupuestoSinStock(cantidad: 1, stock: 2);
    $producto = $venta->lineas->first()->producto;

    // El alta de este presupuesto no avisó nada: entraba en el disponible. Lo que
    // avisa es la edición que lo saca de rango, y el mensaje de la edición conserva
    // lo que decía sobre los precios congelados.
    $this->patchJson("/api/v1/ventas/{$venta->id}", datosDeVenta([lineaDeVenta($producto, 9)]), bearer(usuarioCon('venta.editar')))
        ->assertOk()
        ->assertJsonPath('datos.sin_stock', true)
        ->assertJsonPath('mensaje', fn (string $aviso) => str_contains($aviso, 'conservan el precio')
            && str_contains($aviso, 'Un renglón supera el disponible'));
});

test('la ficha lo dice con la insignia y marca el renglon', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.sin_stock', true)
        ->assertJsonPath('datos.lineas.0.faltante.disponible', 2);
});

test('la ficha de una venta cobrada no muestra la insignia aunque el stock ya no alcance', function () {
    $producto = Producto::factory()->conStockYCosto(5, 500)->create(['precio_contado' => '1000.00']);
    $venta    = ventaCobrada($producto, 5);

    // El cobro dejó el disponible en cero, así que la comparación daría verdadero.
    // Que no se muestre no es cosmético: esa mercadería ya salió del depósito, y
    // avisar de un faltante ahí sería avisar de un problema que no existe.
    expect($producto->fresh()->stock_disponible)->toBe(0);

    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.sin_stock', false)
        ->assertJsonPath('datos.lineas.0.faltante', null);
});

test('el listado marca el presupuesto sin stock y no la venta cobrada', function () {
    $presupuesto = presupuestoSinStock(cantidad: 5, stock: 2);

    $cobrado = Producto::factory()->conStockYCosto(5, 500)->create(['precio_contado' => '1000.00']);
    $venta   = ventaCobrada($cobrado, 5);

    $filas = collect($this->getJson('/api/v1/ventas', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->json('datos'))->keyBy('id');

    // Las dos tienen un renglón que supera el disponible de hoy; sólo una es un
    // presupuesto. El conteo lo trae la subconsulta para las dos y el servidor
    // decide a cuál le corresponde la insignia.
    expect($filas[$presupuesto->id]['renglones_sin_stock'])->toBe(1)
        ->and($filas[$presupuesto->id]['sin_stock'])->toBeTrue()
        ->and($filas[$venta->id]['sin_stock'])->toBeFalse()
        ->and($filas->where('sin_stock', true))->toHaveCount(1);
});

test('el conteo del listado llega a la pantalla con el mismo numero que la ficha', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    // El segundo nivel de A-24: que el scope cuente bien ya lo fija el test de la
    // sección anterior; esto fija que el `withCount` esté cableado y que su alias
    // llegue a la respuesta.
    $contadoPorLaBase = collect($this->getJson('/api/v1/ventas', bearer(usuarioCon('venta.ver')))->json('datos'))
        ->firstWhere('id', $venta->id)['renglones_sin_stock'];

    expect($contadoPorLaBase)->toBe($venta->load('lineas.producto')->faltantesDeStock()->count());
});

/*
|--------------------------------------------------------------------------
| El gesto de cargar un renglón
|--------------------------------------------------------------------------
|
| Un solo buscador arriba de la tabla, y el renglón nace con el producto puesto.
| Lo que se puede probar desde el servidor es lo que el servidor rinde: que el
| buscador esté, que el renglón lleve su producto en un campo oculto y su nombre
| como texto. Desde el tramo H el formulario es un shell: los renglones guardados,
| su rótulo, su precio de hoy y su disponible llegan en la ficha de la API, y el
| JS los pone en la plantilla y en `data-buscable-excluir` al cargar.
|
| El pendiente #30 deja de existir acá, y no por una validación nueva: no hay
| selector que mantener, así que no hay dónde elegir dos veces lo mismo. El
| `distinct` de `VentaRequest` se queda igual, porque una petición armada a mano
| sigue siendo posible.
|
| El peso del HTML lo sigue fijando `PesoHtmlTest`, que no se toca: el `<select>`
| por renglón desapareció, así que esta pantalla sólo puede haber adelgazado.
|
*/

test('el formulario de alta abre con el buscador y sin ningun renglon', function () {
    Producto::factory()->create();

    $this->actingAs(usuarioCon('venta.crear', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.create'))
        ->assertOk()
        ->assertSee('data-buscador-producto', false)
        // Sin filas, no hay nada que excluir todavía.
        ->assertSee('data-buscable-excluir=""', false)
        // Ya no se abre con una fila vacía: el renglón nace con su producto.
        ->assertDontSee('name="lineas[0][producto_id]"', false)
        ->assertSee('Buscalo arriba por c');
});

test('el formulario de edicion rinde el producto como texto y lo excluye del buscador', function () {
    $venta    = presupuestoSinStock(cantidad: 5, stock: 2);
    $producto = $venta->lineas->first()->producto;

    // El id y el rótulo del renglón, de la API: el JS los pone como campo oculto y como texto, y el id
    // va a `data-buscable-excluir`.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.lineas.0.producto.id', $producto->id)
        ->assertJsonPath('datos.lineas.0.producto.rotulo', 'ESC-00001 — Teclado escaso');

    app('auth')->forgetGuards();

    // El renglón se quita, no se cambia: la plantilla lleva el producto en un campo oculto, no un selector.
    $this->actingAs(usuarioCon('venta.editar', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.edit', $venta))
        ->assertOk()
        ->assertSee('type="hidden" value="" data-producto data-campo-linea="producto_id"', false)
        ->assertSee('data-buscable-excluir=""', false)
        ->assertDontSee('Teclado escaso');
});

test('el renglon publica el precio de hoy y el disponible que conto el servidor', function () {
    $producto = Producto::factory()->conStock(8, 6)->create(['precio_contado' => '1234.00']);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    // Es lo que reemplaza al objeto de la opción de Tom Select: con el producto en
    // texto fijo ya no hay opción donde colgar el precio y el disponible, y el
    // JavaScript los lee de la fila. El disponible es 8 − 6, no 8. Desde el tramo H
    // los cuenta la API y el JS los escribe en los data- de la plantilla.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.lineas.0.cotizacion_hoy.precio', '1234.00')
        ->assertJsonPath('datos.lineas.0.cotizacion_hoy.disponible', 2);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.editar', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.edit', $venta))
        ->assertOk()
        ->assertSee('data-precio-hoy="" data-disponible=""', false);
});

test('cobrada, la ficha ya no trae la cotizacion de hoy', function () {
    // Lo de hoy sirve para editar, y sólo un presupuesto se edita.
    $venta = ventaCobrada(productoConPrecios(), 1);

    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.lineas.0.cotizacion_hoy', null);
});

test('un error de validacion devuelve los renglones con su producto legible', function () {
    $producto = Producto::factory()->conStock(10)->create([
        'codigo'         => 'CBL-00009',
        'nombre'         => 'Cable corto',
        'precio_contado' => '1000.00',
    ]);

    $usuario = usuarioCon('venta.crear', 'producto.ver', 'cliente.ver');

    // Tramo H: el 422 marca sólo la cantidad del renglón y no su producto, así que la fila sigue con
    // su rótulo: la pantalla no se recarga, el JS conserva lo cargado y pinta el error en esa fila.
    // Antes la fila volvía de `old()` y el servidor tenía que volver a resolver el rótulo.
    $this->postJson('/api/v1/ventas', datosDeVenta([
        ['producto_id' => $producto->id, 'cantidad' => 0],
    ]), bearer($usuario))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('lineas.0.cantidad', 'errores')
        ->assertJsonMissingValidationErrors(['lineas.0.producto_id'], 'errores');

    expect(Venta::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El pedido de la tienda en la gestión (tramo F)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    // Un pedido de verdad: carrito, checkout con reserva y confirmación por el servicio.
    $this->tiendaPedido = function (\App\Models\Producto $producto, int $cantidad): Venta {
        $cuenta = \App\Models\User::factory()->conRol('Cliente')->create();
        \App\Models\Cliente::factory()->create(['user_id' => $cuenta->id]);
        $carritos = app(\App\Services\CarritoService::class);
        $carritos->agregar($cuenta, $producto->id, $cantidad);
        $carritos->iniciarCheckout($cuenta);

        return $carritos->confirmar($cuenta, [
            'carrito_id'   => $carritos->paraLeer($cuenta)->id,
            'medio_pago'   => 'contado',
            'modo_entrega' => 'retiro',
        ])['venta'];
    };
});

test('el filtro por canal llega al scope y el listado muestra el canal', function () {
    $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 1)]), admin());
    $pedido = ($this->tiendaPedido)(productoConPrecios(), 1);

    $credencial = bearer(usuarioCon('venta.ver'));

    $this->getJson('/api/v1/ventas?canal=online', $credencial)
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.id', $pedido->id)
        ->assertJsonPath('datos.0.canal', 'online')
        ->assertJsonPath('datos.0.canal_texto', 'Tienda online')
        ->assertJsonPath('criterios.canal', 'online');

    $this->getJson('/api/v1/ventas?canal=mostrador', $credencial)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.canal', 'mostrador');
});

test('un canal desconocido se rechaza con su campo', function () {
    $this->getJson('/api/v1/ventas?canal=telefono', bearer(usuarioCon('venta.ver')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('canal', 'errores');
});

test('el selector de estado ofrece pendiente de pago y filtra los pedidos', function () {
    $pedido = ($this->tiendaPedido)(productoConPrecios(), 1);
    $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 1)]), admin());

    // El selector es estructura del shell; el filtro, del endpoint.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['estado' => 'pendiente_pago']))
        ->assertOk()
        ->assertSee('<option value="pendiente_pago" selected', false);

    $this->getJson('/api/v1/ventas?estado=pendiente_pago', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.id', $pedido->id);
});

test('la ficha del pedido ofrece cancelar el pedido y avisa que libera la reserva', function () {
    $pedido = ($this->tiendaPedido)(productoConPrecios(), 1);

    // Tramo H: que es un pedido y que se puede cancelar lo dice la API; el texto del botón
    // («Cancelar el pedido») y la confirmación que avisa que vuelve al disponible los arma el JS con eso.
    $this->getJson("/api/v1/ventas/{$pedido->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.es_online', true)
        ->assertJsonPath('datos.acciones.cancelar', true);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.ver', 'venta.editar'))
        ->get(route('ventas.show', $pedido))
        ->assertOk()
        ->assertSee('data-accion="cancelar"', false);
});

test('cancelar el pedido desde la gestion libera la reserva y lo dice', function () {
    $producto = productoConPrecios();   // 50 en stock
    $pedido   = ($this->tiendaPedido)($producto, 3);

    expect($producto->fresh()->stock_reservado)->toBe(3);

    $this->postJson("/api/v1/ventas/{$pedido->id}/cancelar", [], bearer(usuarioCon('venta.editar')))
        ->assertOk()
        ->assertJsonPath('mensaje', fn (string $texto) => str_contains($texto, 'Pedido')
            && str_contains($texto, 'volvieron al disponible'));

    expect($pedido->fresh()->estado)->toBe('cancelada')
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and($producto->fresh()->stock)->toBe(50);
});

test('la pantalla de cobro no avisa faltantes falsos para un pedido reservado', function () {
    // Todo el stock está reservado por el pedido: el disponible es cero, pero es suyo.
    $producto = \App\Models\Producto::factory()->conStockYCosto(2, 600)->create([
        'precio_contado' => 1000, 'precio_lista' => 1200, 'alicuota_iva' => 21,
    ]);
    $pedido = ($this->tiendaPedido)($producto, 2);

    $this->getJson("/api/v1/ventas/{$pedido->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.cobro.problemas', []);
});

/*
|--------------------------------------------------------------------------
| Las rutas web retiradas (tramo H)
|--------------------------------------------------------------------------
|
| La web sólo entrega las pantallas: emitir, editar, recotizar, cancelar, cobrar,
| entregar, devolver y las cuatro descargas van por `/api/v1/ventas`. Cada URL vieja se
| prueba literal, con todos los permisos del módulo, para que no la salve un 403.
|
*/

dataset('rutas web retiradas de ventas', [
    'emitir'                    => ['post', '/ventas'],
    'guardar la edicion'        => ['put',  '/ventas/{venta}'],
    'recotizar'                 => ['post', '/ventas/{venta}/recotizar'],
    'cancelar'                  => ['post', '/ventas/{venta}/cancelar'],
    'registrar el cobro'        => ['post', '/ventas/{venta}/cobro'],
    'entregar'                  => ['post', '/ventas/{venta}/entregar'],
    'registrar la devolucion'   => ['post', '/ventas/{venta}/devolucion'],
    'exportar el listado'       => ['get',  '/ventas/exportar'],
    'documento de la venta'     => ['get',  '/ventas/{venta}/pdf'],
    'comprobante de entrega'    => ['get',  '/ventas/{venta}/entregas/1/pdf'],
    'comprobante transferencia' => ['get',  '/ventas/{venta}/comprobantes-transferencia/1'],
]);

test('la ruta web retirada de ventas ya no existe', function (string $metodo, string $url) {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());
    $antes    = Venta::count();

    $respuesta = pedirRuta(
        $this->actingAs(usuarioCon(...permisosDelModulo('venta'))),
        $metodo,
        str_replace('{venta}', (string) $venta->id, $url),
        ['pagos' => [['metodo' => 'efectivo', 'monto' => '2000,00']]] + datosDeVenta([lineaDeVenta($producto, 1)]),
    );

    expect($respuesta->status())->toBeIn([404, 405])
        ->and($venta->fresh()->estado)->toBe('presupuesto')
        ->and($venta->fresh()->total)->toBe($venta->total)
        ->and($venta->pagos()->count())->toBe(0)
        ->and(Venta::count())->toBe($antes);
})->with('rutas web retiradas de ventas');

test('los shells de cobro, entrega y devolucion no traen datos de negocio', function () {
    // Regla 37: la página llega con su estructura; los renglones, los importes y el cliente, por la API.
    $cliente  = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);
    $producto = productoConPrecios(contado: 1234);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 3)], ['cliente_id' => $cliente->id]), admin());

    $pantallas = [route('pagos.create', $venta)];

    $cobrada    = ventaCobrada($producto, 3);
    $pantallas[] = route('ventas.entrega', $cobrada);
    $pantallas[] = route('ventas.devolucion', $cobrada);

    foreach ($pantallas as $pantalla) {
        $this->actingAs(usuarioCon('venta.ver', 'venta.cobrar', 'venta.entregar', 'venta.anular'))
            ->get($pantalla)
            ->assertOk()
            ->assertDontSee('Panadería Los Tilos')
            ->assertDontSee($producto->nombre)
            ->assertDontSee($venta->numeroFormateado())
            ->assertDontSee($cobrada->numeroFormateado())
            ->assertDontSee('3.702,00');
    }
});
