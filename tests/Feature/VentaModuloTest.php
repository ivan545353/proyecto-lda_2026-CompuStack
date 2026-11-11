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
    'alta'       => ['post', 'ventas.store', 'venta.crear'],
    'exportar'   => ['get', 'ventas.exportar', 'venta.ver'],
]);

dataset('rutas de la venta', [
    'ficha'                 => ['get', 'ventas.show', 'venta.ver'],
    'formulario de edicion' => ['get', 'ventas.edit', 'venta.editar'],
    'guardar la edicion'    => ['put', 'ventas.update', 'venta.editar'],
    'recotizar'             => ['post', 'ventas.recotizar', 'venta.editar'],
    'cancelar'              => ['post', 'ventas.cancelar', 'venta.editar'],

    'formulario de cobro'    => ['get', 'pagos.create', 'venta.cobrar'],
    'registrar el cobro'     => ['post', 'pagos.store', 'venta.cobrar'],
    'marcar como entregada'  => ['post', 'ventas.entregar', 'venta.entregar'],
        'formulario de devolucion' => ['get', 'ventas.devolucion', 'venta.anular'],
    'registrar la devolucion'  => ['post', 'ventas.devolver', 'venta.anular'],
    'descargar el documento'   => ['get', 'ventas.pdf', 'venta.ver'],
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

test('quien solo puede ver no recibe los botones de accion', function () {
    $venta = Venta::factory()->conLinea(productoConPrecios(), 2)->create();

    // M-33: lo que no se puede hacer no se ofrece. El control real es la ruta, que ya
    // se probó arriba; esto es la mitad de la interfaz.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index'))
        ->assertOk()
        ->assertDontSee(route('ventas.create'))
        ->assertDontSee(route('ventas.edit', $venta));

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertDontSee(route('ventas.edit', $venta))
        ->assertDontSee(route('ventas.recotizar', $venta))
        ->assertDontSee(route('ventas.cancelar', $venta))
        ->assertDontSee(route('pagos.create', $venta))
        ->assertDontSee(route('ventas.devolucion', $venta))
        // Y no ve la tarjeta de Acciones vacía: el `@canany` la saca entera.
        ->assertDontSee('Acciones');
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
        ->assertDontSee(route('ventas.edit', $venta))
        ->assertDontSee(route('ventas.recotizar', $venta))
        ->assertDontSee(route('ventas.cancelar', $venta));
});

test('quien puede devolver y no cobrar ve solo el boton de devolver', function () {
    $venta = ventaCobrada(productoConPrecios(), 2);

    // El caso del Administrativo, que es el rol al que esta mitad le dio
    // `venta.anular`: ve la devolución y no ve el cobro ni la entrega.
    $this->actingAs(usuarioCon('venta.ver', 'venta.anular'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee(route('ventas.devolucion', $venta))
        ->assertDontSee(route('ventas.entregar', $venta))
        ->assertDontSee(route('ventas.edit', $venta));
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

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['q' => 'tilos']))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1);

    // Y por número, que es la otra rama del OR.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['q' => $deTilos->numeroFormateado()]))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1);
});

test('el filtro de estado llega al scope', function () {
    Venta::factory()->count(2)->conLinea(productoConPrecios(), 1)->create();
    Venta::factory()->cancelada()->conLinea(productoConPrecios(), 1)->create();

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['estado' => 'cancelada']))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1);
});

test('los filtros de cliente y de vendedor llegan al scope', function () {
    $cliente  = Cliente::factory()->create();
    $vendedor = User::factory()->create();

    Venta::factory()->conLinea(productoConPrecios(), 1)->create([
        'cliente_id' => $cliente->id,
        'usuario_id' => $vendedor->id,
    ]);
    Venta::factory()->count(2)->conLinea(productoConPrecios(), 1)->create();

    // `cliente_id` no tiene selector en el formulario: llega por enlace, desde la
    // ficha del cliente. Que el parámetro funcione es justamente lo que hay que
    // probar, porque ninguna pantalla lo arma con un submit.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['cliente_id' => $cliente->id]))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1)
        // Y el chip nombra al cliente en lugar de mostrar un id.
        ->assertSee($cliente->razon_social);

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['vendedor_id' => $vendedor->id]))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1);
});

test('el rango de fechas llega al scope', function () {
    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $this->travelTo(Carbon::parse('2026-04-02 08:00'));
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    $this->travelBack();

    // El extremo de arriba incluye el día completo: la venta de las 23:40 del 15
    // tiene que entrar en «hasta el 15».
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-15']))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1);
});

test('un filtro invalido redirige al listado limpio con un aviso', function () {
    Venta::factory()->conLinea(productoConPrecios(), 1)->create();

    // «cobrada» era el nombre del estado en el sistema original y no existe en el
    // ENUM nuevo. Un filtro inválido no rebota con una pantalla de errores sobre un
    // formulario que el usuario no completó: redirige y avisa.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['estado' => 'cobrada']))
        ->assertRedirect(route('ventas.index'))
        ->assertSessionHas('error');

    // Y un rango dado vuelta tampoco pasa.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['desde' => '2026-05-01', 'hasta' => '2026-01-01']))
        ->assertRedirect(route('ventas.index'))
        ->assertSessionHas('error');
});

test('la paginacion arrastra los filtros', function () {
    Venta::factory()->count(18)->cancelada()->conLinea(productoConPrecios(), 1)->create();
    Venta::factory()->count(3)->conLinea(productoConPrecios(), 1)->create();

    // A-25: `paginate(15)->withQueryString()`. Sin el `withQueryString()` la página
    // 2 pierde el filtro y muestra cualquier cosa.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['estado' => 'cancelada']))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 18 && $ventas->count() === 15)
        ->assertSee('estado=cancelada', escape: false);
});

/*
|--------------------------------------------------------------------------
| El alta
|--------------------------------------------------------------------------
*/

test('el alta crea el presupuesto y redirige a la ficha', function () {
    $producto = productoConPrecios(contado: 1000);

    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([lineaDeVenta($producto, 3)]))
        ->assertRedirect(route('ventas.show', Venta::latest('id')->first()))
        ->assertSessionHas('exito');

    $venta = Venta::latest('id')->first();

    expect($venta->estado)->toBe('presupuesto')
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
    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([[
            'producto_id'     => $producto->id,
            'cantidad'        => 1,
            'precio_unitario' => 1,
        ]]))
        ->assertSessionHasErrors('lineas.0.precio_unitario');

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza el estado que viene del formulario', function () {
    $producto = productoConPrecios(contado: 1000);

    // La forma de C-9 que una tabla de transiciones no cubre: el estado no es un
    // campo. El original lo tomaba del body en `updateEstado`.
    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([lineaDeVenta($producto, 1)], [
            'estado' => 'pagada',
            'total'  => 1,
        ]))
        ->assertSessionHasErrors(['estado', 'total']);

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza un descuento por encima del tope y conserva lo cargado', function () {
    $producto = productoConPrecios(contado: 1000);
    $cliente  = Cliente::factory()->create();

    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([lineaDeVenta($producto, 2)], [
            'cliente_id'           => $cliente->id,
            'descuento_porcentaje' => 20,
            'observaciones'        => 'Pasa el jueves',
        ]))
        ->assertSessionHasErrors('descuento_porcentaje')
        // A-12, y de paso M-31: la petición se rechaza entera y lo que el usuario
        // escribió vuelve con ella. Un error en un campo no le borra los demás.
        ->assertSessionHasInput('observaciones', 'Pasa el jueves')
        ->assertSessionHasInput('cliente_id', (string) $cliente->id);

    expect(Venta::count())->toBe(0);
});

test('el alta sin lineas se rechaza', function () {
    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([]))
        ->assertSessionHasErrors('lineas');

    expect(Venta::count())->toBe(0);
});

test('el alta rechaza el mismo producto dos veces, en la linea culpable', function () {
    $producto = productoConPrecios(contado: 1000);

    // El error se cuelga de la línea repetida y no del arreglo entero: es lo que le
    // permite a la pantalla marcar esa fila en lugar de un aviso general.
    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([
            lineaDeVenta($producto, 2),
            lineaDeVenta($producto, 3),
        ]))
        ->assertSessionHasErrors('lineas.1.producto_id');

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

    $this->actingAs(usuarioCon('venta.editar'))
        ->put(route('ventas.update', $venta), datosDeVenta([lineaDeVenta($producto, 3)]))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('exito');

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

    // Y el PUT armado a mano tampoco pasa: lo rechaza el servicio.
    $this->actingAs(usuarioCon('venta.editar'))
        ->put(route('ventas.update', $venta), datosDeVenta([lineaDeVenta(productoConPrecios(), 9)]))
        ->assertSessionHas('error');

    expect($venta->fresh('lineas')->lineas->first()->cantidad)->toBe(2);
});

test('recotizar trae los precios de hoy e informa que cambio', function () {
    $producto = productoConPrecios(contado: 1000, lista: 2500);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $producto->update(['precio_contado' => 1500]);

    $this->actingAs(usuarioCon('venta.editar'))
        ->post(route('ventas.recotizar', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('exito');

    expect($venta->fresh('lineas')->lineas->first()->precio_unitario)->toBe('1500.00')
        ->and($venta->fresh()->total)->toBe('3000.00');
});

test('cancelar deja el presupuesto cancelado sin borrarlo', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    $this->actingAs(usuarioCon('venta.editar'))
        ->post(route('ventas.cancelar', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('exito');

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

    // La excepción tipada se traduce a un aviso en `bootstrap/app.php`, una sola vez
    // para toda la aplicación: por eso no hay un try/catch en el controlador (M-30).
    $this->actingAs(usuarioCon('venta.editar'))
        ->post(route('ventas.cancelar', $venta))
        ->assertSessionHas('error');

    expect($venta->fresh()->estado)->toBe('cancelada');
});

test('un vendedor puede cancelar el presupuesto que cargo', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = User::factory()->conRol('Vendedor')->create();

    $this->actingAs($vendedor)
        ->post(route('ventas.store'), datosDeVenta([lineaDeVenta($producto, 1)]));

    $venta = Venta::latest('id')->first();

    // Con el rol real, no con un rol de prueba armado a medida. El Vendedor tiene
    // `venta.crear` y `venta.editar` pero NO `venta.anular`, así que si la ruta de
    // cancelar exigiera ese permiso, no podría dar de baja lo que él mismo cargó.
    // Es la decisión que quedó escrita en las rutas, afirmada contra el seeder.
    $this->actingAs($vendedor)
        ->post(route('ventas.cancelar', $venta))
        ->assertSessionHas('exito');

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

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee($venta->numeroFormateado())
        ->assertSee('Presupuesto')
        ->assertSee('Panadería Los Tilos')
        ->assertSee($producto->nombre)
        // El neto y el IVA persistidos, que son los importes que se declaran.
        ->assertSee('2.479,34')
        ->assertSee('520,66')
        // Subtotal 3.000, descuento 300 y total 2.700.
        ->assertSee('300,00')
        ->assertSee('2.700,00');
});

test('la ficha dice sin identificar con palabras', function () {
    $venta = $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 1)]), admin());

    // `cliente_id` en null SIGNIFICA sin identificar: no es un dato que falte, y la
    // pantalla no muestra una tarjeta vacía.
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Sin identificar');
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
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => '4.000,00'],
                ['metodo' => 'transferencia', 'monto' => ''],
                ['metodo' => 'qr',            'monto' => ''],
            ],
        ])
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito');

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->pagos()->count())->toBe(1)
        ->and($venta->fresh()->pagado())->toBe(4000.0)
        ->and($producto->fresh()->stock)->toBe(46);
});

test('un cobro donde todos los medios llegan vacios vuelve con un aviso general', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // Que venga al menos un monto es una regla sobre el conjunto, así que el error no
    // va en un campo: vuelve como aviso. Mismo criterio que la recepción de
    // mercadería con todas las cantidades en cero.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => ''],
                ['metodo' => 'transferencia', 'monto' => ''],
                ['metodo' => 'qr',            'monto' => ''],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('error');

    expect($venta->fresh()->estado)->toBe('presupuesto');
});

test('un cobro por un monto que no coincide vuelve con aviso y conserva lo cargado', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // El saldo NO se valida en el Form Request —depende de los pagos ya registrados,
    // que otra petición puede estar moviendo— así que el error llega como aviso y no
    // en el campo. El precio que se paga por eso es éste, y `withInput()` lo
    // compensa: lo que el usuario escribió vuelve a la pantalla.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), [
            'pagos' => [['metodo' => 'efectivo', 'monto' => '1500']],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('error')
        ->assertSessionHasInput('pagos.0.monto', '1500');

    expect($venta->fresh()->pagos()->count())->toBe(0);
});

test('el cobro rechaza las columnas que acredita el webhook', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 2)]), admin());

    // La pantalla del mostrador no es el webhook: el servicio acepta
    // `mp_payment_id` como clave de idempotencia, y este formulario lo rechaza. Se
    // rechaza en lugar de ignorarse, porque descartar en silencio un dato que alguien
    // mandó es la doctrina de M-31 al revés.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), [
            'pagos' => [[
                'metodo'          => 'efectivo',
                'monto'           => '2000',
                'mp_payment_id'   => 'mp-inventado',
                'neto_acreditado' => '1900',
                'usuario_id'      => 1,
            ]],
        ])
        ->assertSessionHasErrors([
            'pagos.0.mp_payment_id',
            'pagos.0.neto_acreditado',
            'pagos.0.usuario_id',
        ]);

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
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->post(route('pagos.store', $venta), [
            'pagos' => [
                ['metodo' => 'efectivo',      'monto' => '2000'],
                ['metodo' => 'transferencia', 'monto' => '', 'mp_payment_id' => 'mp-inventado'],
            ],
        ])
        ->assertSessionHasErrors('pagos.1.mp_payment_id');

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

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Movimientos de dinero')
        ->assertSee('Quedó cobrado')
        ->assertSee('Efectivo');
});

/*
|--------------------------------------------------------------------------
| La devolución, por la ruta — A-11
|--------------------------------------------------------------------------
*/

test('la devolucion por la ruta repone stock, revierte el pago y deriva el estado', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 5);
    $linea    = $venta->lineas()->firstOrFail();

    expect($producto->fresh()->stock)->toBe(45);

    $this->actingAs(usuarioCon('venta.anular'))
        ->post(route('ventas.devolver', $venta), [
            'cantidades' => [$linea->id => 2],
            'metodo'     => 'qr',
            'motivo'     => 'Vino fallado',
        ])
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito');

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
    $this->actingAs(usuarioCon('venta.anular'))
        ->post(route('ventas.devolver', $venta), [
            'cantidades' => [$linea->id => 1],
            'metodo'     => 'efectivo',
            'monto'      => '999999',
            'estado'     => 'devuelta',
        ])
        ->assertSessionHasErrors(['monto', 'estado']);

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

test('entregar por la ruta deja la venta entregada sin mover stock', function () {
    $producto = productoConPrecios(contado: 1000);
    $venta    = ventaCobrada($producto, 2);

    $stockPrevio = $producto->fresh()->stock;

    $this->actingAs(usuarioCon('venta.entregar'))
        ->post(route('ventas.entregar', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('exito');

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

    $respuesta = $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.pdf', $venta));

    $respuesta->assertOk();

    // Es el mismo test que fija el PDF del pedido en la Fase 5: se puede repetir y no
    // marca nada. La venta sigue donde estaba.
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain($venta->numeroFormateado())
        ->and($venta->fresh()->estado)->toBe('pagada');
});

test('una venta cancelada no tiene documento que entregar', function () {
    $venta = Venta::factory()->cancelada()->conLinea(productoConPrecios(contado: 1000), 2)->create();

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.pdf', $venta))
        ->assertRedirect(route('ventas.show', $venta))
        ->assertSessionHas('error');
});

test('la ficha ofrece descargar el documento salvo que este cancelada', function () {
    $producto = productoConPrecios(contado: 1000);

    $viva   = Venta::factory()->conLinea($producto, 2)->create();
    $muerta = Venta::factory()->cancelada()->conLinea($producto, 2)->create();

    $usuario = usuarioCon('venta.ver');

    $this->actingAs($usuario)->get(route('ventas.show', $viva))
        ->assertSee(route('ventas.pdf', $viva));

    $this->actingAs($usuario)->get(route('ventas.show', $muerta))
        ->assertDontSee(route('ventas.pdf', $muerta));
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

    $respuesta = $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.exportar'));

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

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('Sin identificar')
        ->assertDontSee('Consumidor final');
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
    // número. Cadena corta a propósito, que el formateador de Blade no parte.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('y hay 2 disponible(s)');
});

test('el aviso del cobro prefiere la baja del producto antes que el faltante', function () {
    $venta    = presupuestoSinStock(cantidad: 5, stock: 2);
    $producto = $venta->lineas->first()->producto;

    $producto->update(['activo' => false]);

    // Un renglón, un mensaje. Los dos problemas se arreglan en pantallas distintas
    // —el catálogo y el depósito— y el de la baja hace fallar el cobro igual.
    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('dado de baja en el cat')
        ->assertDontSee('y hay 2 disponible(s)');
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
    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([lineaDeVenta($producto, 5)]))
        ->assertSessionMissing('exito')
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'Un renglón supera el disponible'));

    expect(Venta::latest('id')->first()->estado)->toBe('presupuesto');
});

test('el aviso del alta cuenta los renglones en plural', function () {
    $uno = Producto::factory()->conStock(1)->create(['precio_contado' => '1000.00']);
    $dos = Producto::factory()->conStock(1)->create(['precio_contado' => '2000.00']);

    $this->actingAs(usuarioCon('venta.crear'))
        ->post(route('ventas.store'), datosDeVenta([
            lineaDeVenta($uno, 4),
            lineaDeVenta($dos, 4),
        ]))
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, '2 renglones superan el disponible'));
});

test('la edicion que deja un renglon sin stock avisa en azul', function () {
    $venta    = presupuestoSinStock(cantidad: 1, stock: 2);
    $producto = $venta->lineas->first()->producto;

    // El alta de este presupuesto no avisó nada: entraba en el disponible. Lo que
    // avisa es la edición que lo saca de rango, y el mensaje de la edición conserva
    // lo que decía sobre los precios congelados.
    $this->actingAs(usuarioCon('venta.editar'))
        ->put(route('ventas.update', $venta), datosDeVenta([lineaDeVenta($producto, 9)]))
        ->assertSessionMissing('exito')
        ->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'conservan el precio')
            && str_contains($aviso, 'Un renglón supera el disponible'));
});

test('la ficha lo dice con la insignia y marca el renglon', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertSee('insigniaSinStock')
        ->assertSee('Hay 2 disponible(s)');
});

test('la ficha de una venta cobrada no muestra la insignia aunque el stock ya no alcance', function () {
    $producto = Producto::factory()->conStockYCosto(5, 500)->create(['precio_contado' => '1000.00']);
    $venta    = ventaCobrada($producto, 5);

    // El cobro dejó el disponible en cero, así que la comparación daría verdadero.
    // Que no se muestre no es cosmético: esa mercadería ya salió del depósito, y
    // avisar de un faltante ahí sería avisar de un problema que no existe.
    expect($producto->fresh()->stock_disponible)->toBe(0);

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.show', $venta))
        ->assertOk()
        ->assertDontSee('insigniaSinStock');
});

test('el listado marca el presupuesto sin stock y no la venta cobrada', function () {
    $presupuesto = presupuestoSinStock(cantidad: 5, stock: 2);

    $cobrado = Producto::factory()->conStockYCosto(5, 500)->create(['precio_contado' => '1000.00']);
    ventaCobrada($cobrado, 5);

    $respuesta = $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index'))
        ->assertOk()
        ->assertSee('Sin stock suficiente');

    $ventas = $respuesta->viewData('ventas');

    // Las dos tienen un renglón que supera el disponible de hoy; sólo una es un
    // presupuesto. El conteo lo trae la subconsulta para las dos y la pantalla
    // decide a cuál le corresponde la insignia.
    expect((int) $ventas->firstWhere('id', $presupuesto->id)->faltantes_count)->toBe(1)
        ->and($ventas->filter(fn (Venta $venta) => $venta->esPresupuesto()))->toHaveCount(1);
});

test('el conteo del listado llega a la pantalla con el mismo numero que la ficha', function () {
    $venta = presupuestoSinStock(cantidad: 5, stock: 2);

    // El segundo nivel de A-24: que el scope cuente bien ya lo fija el test de la
    // sección anterior; esto fija que el `withCount` esté cableado y que su alias
    // llegue a la vista con ese nombre.
    $contadoPorLaBase = (int) $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index'))
        ->viewData('ventas')
        ->firstWhere('id', $venta->id)
        ->faltantes_count;

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
| como texto, y que los ya cargados salgan en `data-buscable-excluir` desde la
| primera carga y no cuando el JavaScript corra.
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

    $this->actingAs(usuarioCon('venta.editar', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.edit', $venta))
        ->assertOk()
        // El id en un campo oculto y el nombre como texto: el renglón se quita, no se
        // cambia, así que no queda selector que mantener.
        ->assertSee('name="lineas[0][producto_id]"', false)
        ->assertSee('ESC-00001 — Teclado escaso')
        ->assertSee('data-buscable-excluir="'.$producto->id.'"', false);
});

test('el renglon publica el precio de hoy y el disponible que conto el servidor', function () {
    $producto = Producto::factory()->conStock(8, 6)->create(['precio_contado' => '1234.00']);

    $venta = $this->service->crear(datosDeVenta([lineaDeVenta($producto, 1)]), admin());

    // Es lo que reemplaza al objeto de la opción de Tom Select: con el producto en
    // texto fijo ya no hay opción donde colgar el precio y el disponible, y el
    // JavaScript los lee de la fila. El disponible es 8 − 6, no 8.
    $this->actingAs(usuarioCon('venta.editar', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.edit', $venta))
        ->assertOk()
        ->assertSee('data-precio-hoy="1234.00"', false)
        ->assertSee('data-disponible="2"', false);
});

test('un error de validacion devuelve los renglones con su producto legible', function () {
    $producto = Producto::factory()->conStock(10)->create([
        'codigo'         => 'CBL-00009',
        'nombre'         => 'Cable corto',
        'precio_contado' => '1000.00',
    ]);

    $usuario = usuarioCon('venta.crear', 'producto.ver', 'cliente.ver');

    $this->actingAs($usuario)
        ->from(route('ventas.create'))
        ->post(route('ventas.store'), datosDeVenta([
            ['producto_id' => $producto->id, 'cantidad' => 0],
        ]))
        ->assertRedirect(route('ventas.create'))
        ->assertSessionHasErrors('lineas.0.cantidad');

    // La fila vuelve de `old()`, y con el producto en texto el servidor tiene que
    // volver a resolver su rótulo: si no, el usuario vería un renglón sin nombre y
    // sin forma de saber cuál era.
    $this->actingAs($usuario)
        ->get(route('ventas.create'))
        ->assertOk()
        ->assertSee('CBL-00009 — Cable corto')
        ->assertSee('data-buscable-excluir="'.$producto->id.'"', false);
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

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['canal' => 'online']))
        ->assertOk()
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1 && $ventas->first()->id === $pedido->id)
        ->assertSee('data-canal="online"', false)
        ->assertSee('Canal: Tienda online');

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['canal' => 'mostrador']))
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1 && $ventas->first()->id !== $pedido->id);
});

test('un canal desconocido vuelve al listado limpio con un aviso', function () {
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['canal' => 'telefono']))
        ->assertRedirect(route('ventas.index'))
        ->assertSessionHas('error');
});

test('el selector de estado ofrece pendiente de pago y filtra los pedidos', function () {
    $pedido = ($this->tiendaPedido)(productoConPrecios(), 1);
    $this->service->crear(datosDeVenta([lineaDeVenta(productoConPrecios(), 1)]), admin());

    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('ventas.index', ['estado' => 'pendiente_pago']))
        ->assertOk()
        ->assertSee('<option value="pendiente_pago" selected', false)
        ->assertViewHas('ventas', fn ($ventas) => $ventas->total() === 1 && $ventas->first()->id === $pedido->id);
});

test('la ficha del pedido ofrece cancelar el pedido y avisa que libera la reserva', function () {
    $pedido = ($this->tiendaPedido)(productoConPrecios(), 1);

    $this->actingAs(usuarioCon('venta.ver', 'venta.editar'))
        ->get(route('ventas.show', $pedido))
        ->assertOk()
        ->assertSee('Cancelar el pedido')
        ->assertDontSee('Cancelar el presupuesto')
        ->assertSee('vuelven al disponible');
});

test('cancelar el pedido en la web libera la reserva y lo dice', function () {
    $producto = productoConPrecios();   // 50 en stock
    $pedido   = ($this->tiendaPedido)($producto, 3);

    expect($producto->fresh()->stock_reservado)->toBe(3);

    $this->actingAs(usuarioCon('venta.editar'))
        ->post(route('ventas.cancelar', $pedido))
        ->assertRedirect(route('ventas.show', $pedido))
        ->assertSessionHas('exito', fn (string $texto) => str_contains($texto, 'Pedido')
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

    $this->actingAs(usuarioCon('venta.cobrar'))
        ->get(route('pagos.create', $pedido))
        ->assertOk()
        ->assertViewHas('problemas', []);
});
