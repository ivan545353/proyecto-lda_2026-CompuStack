<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\ProductoProveedorService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/**
 * PERMISOS_COMPRA está declarada en PedidoCompraModuloTest y se reusa acá: Pest
 * carga todos los archivos en el mismo proceso, y declararla dos veces sería un
 * error fatal. `productoDe()` también viene de ahí.
 */

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| C-9 y C-2 juntos: cada acción de estado tiene su permiso declarado en la ruta, y
| se prueban las dos mitades. Con todos los permisos del módulo MENOS el de la
| ruta se deniega, y con sólo ése se permite.
|
| `enviada` y `cancelar` comparten permiso con `aprobar`, y eso hace que el dataset
| de la primera mitad no pueda usar `array_diff` a ciegas: se nombra el permiso de
| cada ruta y se quita ése.
|
*/

dataset('rutas de la orden', [
    'ficha'             => ['get',  'compras.show',      'compra.ver'],
    'aprobar'           => ['post', 'compras.aprobar',   'compra.aprobar'],
    'marcar enviada'    => ['post', 'compras.enviada',   'compra.aprobar'],
    'cerrar incompleta' => ['post', 'compras.cerrar',    'compra.recibir'],
    'cancelar'          => ['post', 'compras.cancelar',  'compra.aprobar'],
    'pantalla de recepcion' => ['get',  'compras.recepcion', 'compra.recibir'],
    'registrar recepcion'   => ['post', 'compras.recibir',   'compra.recibir'],
    'pdf del pedido' => ['get', 'compras.pdf', 'compra.ver'],
]);

test('ningun otro permiso del modulo habilita la ruta de la orden', function (string $metodo, string $ruta, string $permiso) {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 3)->create();

    $otros = array_values(array_diff(PERMISOS_COMPRA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, route($ruta, $orden))
        ->assertForbidden();
})->with('rutas de la orden');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso) {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 3)->create();

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, route($ruta, $orden));

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de la orden');

test('quien solo puede ver no recibe los botones de accion', function () {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 3)->create();

    // M-33: lo que no se puede hacer no se ofrece. El control real es la ruta, que
    // ya se probó arriba.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertDontSee(route('compras.aprobar', $orden))
        ->assertDontSee(route('compras.cancelar', $orden))
        ->assertDontSee(route('compras.edit', $orden));
});

/*
|--------------------------------------------------------------------------
| La ficha
|--------------------------------------------------------------------------
*/

test('la ficha muestra el estado, las lineas y el codigo congelado del proveedor', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral']);
    $producto  = productoDe($proveedor, codigo: 'AUS-77');

    $orden = OrdenCompra::factory()->conLinea($producto, 4, 2500)->create([
        'proveedor_id' => $proveedor->id,
    ]);

    // El código del proveedor se congela en la línea: la ficha lo muestra porque es
    // con lo que el proveedor va a buscar el producto.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertSee($orden->numeroFormateado())
        ->assertSee('Borrador')
        ->assertSee('Distribuidora Austral')
        ->assertSee($producto->nombre)
        ->assertSee('AUS-77');
});

test('la ficha de una orden generada por el sistema lo dice con palabras', function () {
    $orden = OrdenCompra::factory()->delSistema()
        ->conLinea(Producto::factory()->create(), 2)
        ->create();

    // usuario_creo_id null SIGNIFICA «la generó la tarea de reposición». No se
    // muestra una celda vacía.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertSee('por el sistema');
});

test('la ficha dice que sigue segun el canal del proveedor, y solo cuando esta aprobada', function () {
    $proveedor = Proveedor::factory()->porPortal('https://pedidos.austral.test')->create();
    $producto  = productoDe($proveedor);

    $borrador = OrdenCompra::factory()->conLinea($producto, 1)->create(['proveedor_id' => $proveedor->id]);
    $aprobada = OrdenCompra::factory()->aprobada()->conLinea($producto, 1)->create(['proveedor_id' => $proveedor->id]);

    $usuario = usuarioCon('compra.ver');

    // En borrador todavía no hay nada que mandar.
    $this->actingAs($usuario)
        ->get(route('compras.show', $borrador))
        ->assertDontSee('https://pedidos.austral.test');

    $this->actingAs($usuario)
        ->get(route('compras.show', $aprobada))
        ->assertSee('portal del proveedor')
        ->assertSee('https://pedidos.austral.test');
});

/*
|--------------------------------------------------------------------------
| Las acciones de estado
|--------------------------------------------------------------------------
*/

test('aprobar registra quien y cuando, y lo dice', function () {
    $orden   = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 3, 1000)->create();
    $usuario = usuarioCon('compra.aprobar');

    $this->actingAs($usuario)
        ->post(route('compras.aprobar', $orden))
        ->assertRedirect(route('compras.show', $orden))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'aprobada'));

    $orden->refresh();

    expect($orden->estado)->toBe('aprobada')
        ->and($orden->usuario_aprobo_id)->toBe($usuario->id)
        ->and($orden->fecha_aprobacion)->not->toBeNull();
});

test('marcar enviada guarda la fecha y el mensaje no finge que el sistema lo mando', function () {
    $orden = OrdenCompra::factory()->aprobada()->conLinea(Producto::factory()->create(), 2)->create();

    $this->actingAs(usuarioCon('compra.aprobar'))
        ->post(route('compras.enviada', $orden))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'el sistema sólo lo anota'));

    expect($orden->fresh()->fecha_envio)->not->toBeNull();
});

test('una transicion que la maquina de estados no declara se rechaza con un aviso', function () {
    // Un borrador no se puede marcar como enviado sin aprobar. La
    // TransicionInvalidaException se traduce en bootstrap/app.php, sin try/catch en
    // el controlador (M-30).
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 1)->create();

    $this->actingAs(usuarioCon('compra.aprobar'))
        ->from(route('compras.show', $orden))
        ->post(route('compras.enviada', $orden))
        ->assertRedirect(route('compras.show', $orden))
        ->assertSessionHas('error');

    expect($orden->fresh()->estado)->toBe('borrador');
});

test('cancelar deja la orden cancelada y sin acciones', function () {
    $orden = OrdenCompra::factory()->aprobada()->conLinea(Producto::factory()->create(), 2)->create();

    $this->actingAs(usuarioCon('compra.aprobar'))
        ->post(route('compras.cancelar', $orden))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'cancelada'));

    expect($orden->fresh()->estado)->toBe('cancelada');

    // Un estado final no tiene salidas, y la pantalla no inventa botones.
    $this->actingAs(usuarioCon('compra.aprobar', 'compra.ver', 'compra.recibir'))
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertSee('no admite más cambios de estado')
        ->assertDontSee(route('compras.aprobar', $orden));
});

test('cerrar incompleta se rechaza si no entro nada', function () {
    $orden = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    // Si el proveedor no va a entregar y no entró nada, lo que corresponde es
    // cancelar. El servicio lo explica y la pantalla no ofrece el botón.
    $this->actingAs(usuarioCon('compra.recibir'))
        ->from(route('compras.show', $orden))
        ->post(route('compras.cerrar', $orden))
        ->assertSessionHas('error');

    expect($orden->fresh()->estado)->toBe('enviada');
});

test('la ficha no ofrece cerrar con lo que llego si no llego nada', function () {
    $orden = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    $this->actingAs(usuarioCon('compra.ver', 'compra.recibir'))
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertDontSee(route('compras.cerrar', $orden));
});

test('la ruta de la ficha no se confunde con la del armador', function () {
    // /compras/pedido se declara antes de /compras/{orden} y la ruta de la ficha
    // exige un número: sin eso, «pedido» se resolvería como un id y daría 404.
    $this->actingAs(usuarioCon('compra.ver', 'compra.crear'))
        ->get(route('compras.pedido'))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| La recepción
|--------------------------------------------------------------------------
*/

test('la pantalla de recepcion no se abre si la orden no puede recibir', function () {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 5)->create();

    // Un borrador no recibe: todavía nadie comprometió el gasto. Se redirige con un
    // aviso en lugar de abortar, porque la orden existe.
    $this->actingAs(usuarioCon('compra.recibir'))
        ->get(route('compras.recepcion', $orden))
        ->assertRedirect(route('compras.show', $orden))
        ->assertSessionHas('error');
});

test('la pantalla de recepcion muestra lo pendiente de cada linea', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor, codigo: 'AUS-5');

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 1000)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.recibir'))
        ->get(route('compras.recepcion', $orden))
        ->assertOk()
        ->assertSee($producto->nombre)
        ->assertSee('AUS-5')
        ->assertSee('Registrar la recepción');
});

test('recibir parte de lo pedido ingresa stock, deja la orden parcial y lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor, costo: 900);

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 1200)
        ->create(['proveedor_id' => $proveedor->id]);

    $linea = $orden->lineas->first();

    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $orden), ['cantidades' => [$linea->id => 4]])
        ->assertRedirect(route('compras.show', $orden))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'Quedan 6 pendientes'));

    expect($orden->fresh()->estado)->toBe('recibida_parcial')
        ->and($linea->fresh()->cantidad_recibida)->toBe(4)
        ->and($producto->fresh()->stock)->toBe(4);
});

test('recibir todo lo pendiente deja la orden recibida y el mensaje lo dice', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->aprobada()->conLinea($producto, 3, 1000)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $orden), ['cantidades' => [$orden->lineas->first()->id => 3]])
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'recibida completa'));

    expect($orden->fresh()->estado)->toBe('recibida');
});

test('la recepcion deja al dia el ultimo costo de ese proveedor', function () {
    $proveedor = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    $producto = Producto::factory()
        ->conProveedor($proveedor, costo: 900)
        ->conProveedor($otro, costo: 950)
        ->create();

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 2, 1150)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $orden), ['cantidades' => [$orden->lineas->first()->id => 2]]);

    // El ciclo completo del plan de varios proveedores, visto desde la pantalla: se
    // compara, se le pide a uno, llega la mercadería, y la comparación queda al día.
    $vinculos = $producto->fresh()->proveedores;

    expect((float) $vinculos->firstWhere('id', $proveedor->id)->pivot->costo_ultimo)->toBe(1150.0)
        ->and((float) $vinculos->firstWhere('id', $otro->id)->pivot->costo_ultimo)->toBe(950.0);
});

test('recibir mas de lo pendiente se rechaza con el motivo y no mueve stock', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 5, 1000)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.recibir'))
        ->from(route('compras.recepcion', $orden))
        ->post(route('compras.recibir', $orden), ['cantidades' => [$orden->lineas->first()->id => 9]])
        ->assertRedirect(route('compras.recepcion', $orden))
        ->assertSessionHas('error');

    expect($producto->fresh()->stock)->toBe(0)
        ->and($orden->fresh()->estado)->toBe('enviada');
});

test('una recepcion sin ninguna unidad se rechaza', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 5, 1000)
        ->create(['proveedor_id' => $proveedor->id]);

    // El formulario manda todas las líneas, y las que no llegaron viajan en cero:
    // eso no es un error de campo, es una regla sobre el conjunto, y se traduce a un
    // aviso general.
    $this->actingAs(usuarioCon('compra.recibir'))
        ->from(route('compras.recepcion', $orden))
        ->post(route('compras.recibir', $orden), ['cantidades' => [$orden->lineas->first()->id => 0]])
        ->assertSessionHas('error');

    expect($orden->fresh()->estado)->toBe('enviada');
});

test('recibir contra la linea de otra orden responde 404', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $propia = OrdenCompra::factory()->enviada()->conLinea($producto, 5)
        ->create(['proveedor_id' => $proveedor->id]);

    $ajena = OrdenCompra::factory()->enviada()->conLinea($producto, 5)
        ->create(['proveedor_id' => $proveedor->id]);

    // La línea se resuelve a través de la relación del padre: con el id suelto se
    // podría recibir mercadería contra la orden de otro proveedor.
    $this->actingAs(usuarioCon('compra.recibir'))
        ->post(route('compras.recibir', $propia), ['cantidades' => [$ajena->lineas->first()->id => 1]])
        ->assertNotFound();
});

test('la ficha ofrece recibir solo cuando la orden puede recibir', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $borrador = OrdenCompra::factory()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);
    $enviada  = OrdenCompra::factory()->enviada()->conLinea($producto, 5)->create(['proveedor_id' => $proveedor->id]);

    $usuario = usuarioCon('compra.ver', 'compra.recibir');

    $this->actingAs($usuario)
        ->get(route('compras.show', $borrador))
        ->assertDontSee(route('compras.recepcion', $borrador));

    $this->actingAs($usuario)
        ->get(route('compras.show', $enviada))
        ->assertSee(route('compras.recepcion', $enviada));
});

test('la ficha muestra lo que entro por la orden, con su fecha y su usuario', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->enviada()->conLinea($producto, 4, 1000)
        ->create(['proveedor_id' => $proveedor->id]);

    $usuario = usuarioCon('compra.ver', 'compra.recibir');

    $this->actingAs($usuario)
        ->post(route('compras.recibir', $orden), ['cantidades' => [$orden->lineas->first()->id => 2]]);

    // Sale del kardex. Por eso no hay tabla de recepciones: sería una copia de esto.
    $this->actingAs($usuario)
        ->get(route('compras.show', $orden))
        ->assertOk()
        ->assertSee('Lo que entró por esta orden')
        ->assertSee($usuario->nombre);
});
/*
|--------------------------------------------------------------------------
| Permisos del listado y de la edición
|--------------------------------------------------------------------------
*/

dataset('rutas del listado y la edicion', [
    'listado'            => ['get', 'compras.index',  'compra.ver',    false],
    'formulario edicion' => ['get', 'compras.edit',   'compra.editar', true],
    'edicion'            => ['put', 'compras.update', 'compra.editar', true],
]);

test('ningun otro permiso del modulo habilita el listado ni la edicion', function (string $metodo, string $ruta, string $permiso, bool $conOrden) {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 2)->create();
    $url   = $conOrden ? route($ruta, $orden) : route($ruta);

    $otros = array_values(array_diff(PERMISOS_COMPRA, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url)
        ->assertForbidden();
})->with('rutas del listado y la edicion');

test('el permiso de la ruta alcanza para el listado y la edicion', function (string $metodo, string $ruta, string $permiso, bool $conOrden) {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 2)->create();
    $url   = $conOrden ? route($ruta, $orden) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url);

    expect($respuesta->status())->not->toBe(403);
})->with('rutas del listado y la edicion');

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en OrdenCompraFiltrosTest; estos
| prueban el CABLEADO entre la URL y el scope, que es exactamente lo que estaba
| roto en el sistema original —el controlador mandaba `categoriaId` y el DAO leía
| `categoria`— y lo que un solo nivel de test no encuentra.
|
*/

test('el parametro q llega al listado y filtra por numero de orden', function () {
    $producto = Producto::factory()->create();

    $buscada = OrdenCompra::factory()->conLinea($producto, 1)->create();
    OrdenCompra::factory()->count(2)->conLinea($producto, 1)->create();

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['q' => $buscada->numeroFormateado()]))
        ->assertOk()
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1 && $o->first()->is($buscada));
});

test('el parametro q llega al listado y filtra por razon social del proveedor', function () {
    $producto = Producto::factory()->create();

    $austral = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral']);
    $otro    = Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia']);

    OrdenCompra::factory()->conLinea($producto, 1)->create(['proveedor_id' => $austral->id]);
    OrdenCompra::factory()->conLinea($producto, 1)->create(['proveedor_id' => $otro->id]);

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['q' => 'austral']))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1);
});

test('el parametro estado llega al listado y filtra', function () {
    $producto = Producto::factory()->create();

    OrdenCompra::factory()->count(2)->conLinea($producto, 1)->create();          // borrador
    OrdenCompra::factory()->aprobada()->conLinea($producto, 1)->create();
    OrdenCompra::factory()->cancelada()->conLinea($producto, 1)->create();

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['estado' => 'aprobada']))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1);
});

test('el parametro proveedor_id llega al listado y filtra', function () {
    $producto = Producto::factory()->create();

    $uno = Proveedor::factory()->create();
    $dos = Proveedor::factory()->create();

    OrdenCompra::factory()->count(2)->conLinea($producto, 1)->create(['proveedor_id' => $uno->id]);
    OrdenCompra::factory()->conLinea($producto, 1)->create(['proveedor_id' => $dos->id]);

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['proveedor_id' => $uno->id]))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 2);
});

test('desde y hasta llegan al listado y recortan por fecha', function () {
    $producto = Producto::factory()->create();

    // Viajar al pasado para la orden vieja
    $this->travelTo(Carbon\Carbon::parse('2026-03-01 10:00'));
    $vieja = OrdenCompra::factory()->conLinea($producto, 1)->create();

    // Viajar al futuro para la orden nueva
    $this->travelTo(Carbon\Carbon::parse('2026-06-15 10:00'));
    $nueva = OrdenCompra::factory()->conLinea($producto, 1)->create();

    // Volver al presente para ejecutar la petición HTTP de forma segura
    $this->travelBack();

    $usuario = usuarioCon('compra.ver');

    $this->actingAs($usuario)
        ->get(route('compras.index', ['desde' => '2026-06-01']))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1 && $o->first()->is($nueva));

    $this->actingAs($usuario)
        ->get(route('compras.index', ['hasta' => '2026-03-31']))
        ->assertViewHas('ordenes', fn ($o) => $o->total() === 1 && $o->first()->is($vieja));
});


test('un estado inexistente vuelve al listado limpio con aviso', function () {
    // El contrato de filtros está declarado en OrdenCompraFiltroRequest: un filtro
    // inválido avisa en lugar de ignorarse, que es lo que hacía el original.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['estado' => 'telepatia']))
        ->assertRedirect(route('compras.index'))
        ->assertSessionHas('error');
});

test('una fecha hasta anterior a desde vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['desde' => '2026-06-01', 'hasta' => '2026-03-01']))
        ->assertRedirect(route('compras.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create();

    OrdenCompra::factory()->count(16)->conLinea($producto, 1)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['estado' => 'borrador']))
        ->assertOk()
        ->assertViewHas('ordenes', fn ($o) => $o->count() === 15 && $o->total() === 16)
        // Sin withQueryString(), pasar a la página 2 perdería el filtro (A-25).
        ->assertSee('estado=borrador', false);
});

/*
|--------------------------------------------------------------------------
| La edición del borrador
|--------------------------------------------------------------------------
*/

test('editar el borrador reemplaza las lineas y recalcula el total', function () {
    $proveedor = Proveedor::factory()->create();

    $uno = productoDe($proveedor);
    $dos = productoDe($proveedor);

    $orden = OrdenCompra::factory()->conLinea($uno, 2, 1000)->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.editar'))
        ->put(route('compras.update', $orden), datosDeOrdenCompra($proveedor, [
            lineaDeOrden($uno, 3, 1000),
            lineaDeOrden($dos, 1, 5000),
        ]))
        ->assertRedirect(route('compras.index'))
        ->assertSessionHas('exito');

    $orden->refresh()->load('lineas');

    // 3 × 1.000 + 1 × 5.000
    expect($orden->lineas)->toHaveCount(2)
        ->and($orden->total_estimado)->toBe('8000.00');
});

test('una orden aprobada no se puede editar y la pantalla lo explica', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->aprobada()->conLinea($producto, 2)
        ->create(['proveedor_id' => $proveedor->id]);

    // El listado sólo ofrece «Editar» en los borradores; esto es la red de
    // contención para una URL guardada en favoritos.
    $this->actingAs(usuarioCon('compra.editar'))
        ->get(route('compras.edit', $orden))
        ->assertRedirect(route('compras.index'))
        ->assertSessionHas('error');
});

test('la edicion rechaza un producto que ese proveedor no provee', function () {
    $proveedor = Proveedor::factory()->create();

    $suyo  = productoDe($proveedor);
    $ajeno = Producto::factory()->create();   // sin ningún proveedor

    $orden = OrdenCompra::factory()->conLinea($suyo, 2)->create(['proveedor_id' => $proveedor->id]);

    // La pantalla filtra el selector; esto es la barrera para una petición armada a
    // mano. Es el test que en 5.1 quedó esperando este archivo.
    $this->actingAs(usuarioCon('compra.editar'))
        ->put(route('compras.update', $orden), datosDeOrdenCompra($proveedor, [lineaDeOrden($ajeno, 3)]))
        ->assertSessionHasErrors('lineas.0.producto_id');

    expect($orden->fresh('lineas')->lineas->first()->producto_id)->toBe($suyo->id);
});

test('cambiar el proveedor del borrador revalida las lineas contra el nuevo', function () {
    $viejo = Proveedor::factory()->create();
    $nuevo = Proveedor::factory()->create();

    $soloDelViejo = productoDe($viejo);

    $orden = OrdenCompra::factory()->conLinea($soloDelViejo, 2)->create(['proveedor_id' => $viejo->id]);

    // Si se cambia el proveedor y se dejan las líneas, la orden terminaría
    // pidiéndole a alguien algo que no vende. Se rechaza.
    $this->actingAs(usuarioCon('compra.editar'))
        ->put(route('compras.update', $orden), datosDeOrdenCompra($nuevo, [lineaDeOrden($soloDelViejo, 2)]))
        ->assertSessionHasErrors('lineas.0.producto_id');

    expect($orden->fresh()->proveedor_id)->toBe($viejo->id);
});

test('el producto que ya estaba en la orden no necesita una excepcion', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->conLinea($producto, 2)->create(['proveedor_id' => $proveedor->id]);

    // Éste es el caso que la primera versión de la regla trataba como excepción, y
    // que resultó inalcanzable: desvincular rechaza mientras haya un pedido en curso
    // de ese par, y un borrador —lo único editable— es un pedido en curso.
    expect(fn () => app(ProductoProveedorService::class)->desvincular($producto, $proveedor->id))
        ->toThrow(App\Exceptions\ReglaDeNegocioException::class);

    // Y mientras el vínculo existe, la línea pasa sin ninguna excepción.
    $this->actingAs(usuarioCon('compra.editar'))
        ->put(route('compras.update', $orden), datosDeOrdenCompra($proveedor, [lineaDeOrden($producto, 4)]))
        ->assertSessionHasNoErrors();

    expect($orden->fresh('lineas')->lineas->first()->cantidad_pedida)->toBe(4);
});

/*
|--------------------------------------------------------------------------
| El PDF del pedido
|--------------------------------------------------------------------------
*/

test('un borrador no se puede imprimir', function () {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 3)->create();

    // Un borrador no es un compromiso: ofrecer su PDF invitaría a mandarle al
    // proveedor un pedido que nadie autorizó.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.pdf', $orden))
        ->assertRedirect(route('compras.show', $orden))
        ->assertSessionHas('error');
});

test('una orden aprobada descarga un PDF y no cambia de estado', function () {
    $orden = OrdenCompra::factory()->aprobada()->conLinea(Producto::factory()->create(), 3, 1000)->create();

    $respuesta = $this->actingAs(usuarioCon('compra.ver'))->get(route('compras.pdf', $orden));

    $respuesta->assertOk();

    // Descargar es una lectura: se puede repetir y no marca nada como enviado.
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain($orden->numeroFormateado())
        ->and($orden->fresh()->estado)->toBe('aprobada')
        ->and($orden->fresh()->fecha_envio)->toBeNull();
});

test('una orden ya recibida se sigue pudiendo imprimir', function () {
    // El administrativo necesita el papel para archivar, después de todo el ciclo.
    $orden = OrdenCompra::factory()->recibida()->conLinea(Producto::factory()->create(), 2)->create();

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.pdf', $orden))
        ->assertOk();
});

test('una cancelada que alcanzo a aprobarse se imprime, y una cancelada en borrador no', function () {
    $producto = Producto::factory()->create();

    $salio = OrdenCompra::factory()->aprobada()->conLinea($producto, 2)->create();
    app(App\Services\CompraService::class)->cancelar($salio);

    $nuncaSalio = OrdenCompra::factory()->cancelada()->conLinea($producto, 2)->create();

    $usuario = usuarioCon('compra.ver');

    // La condición es `fecha_aprobacion`, no el estado: una orden que tuvo
    // aprobación pudo haber salido, y el papel de lo que se pidió sigue valiendo.
    $this->actingAs($usuario)->get(route('compras.pdf', $salio))->assertOk();
    $this->actingAs($usuario)->get(route('compras.pdf', $nuncaSalio))->assertRedirect(route('compras.show', $nuncaSalio));
});

test('el pedido imprime lo pedido, el codigo del proveedor y la fecha de aprobacion', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral']);
    $producto  = productoDe($proveedor, codigo: 'AUS-77');

    $orden = OrdenCompra::factory()->aprobada()->conLinea($producto, 10, 1250)
        ->create(['proveedor_id' => $proveedor->id]);

    $orden->load(['proveedor', 'lineas.producto', 'usuarioAprobo']);

    // Se prueba el HTML que dompdf consume, no los bytes del PDF: lo que importa es
    // qué dice el documento, y parsear un PDF para afirmarlo no agrega nada.
    $html = view('compras.pdf', ['orden' => $orden])->render();

    expect($html)->toContain($orden->numeroFormateado())
        ->toContain('Distribuidora Austral')
        ->toContain('AUS-77')
        ->toContain($producto->nombre)
        ->toContain($orden->fecha_aprobacion->format('d/m/Y'))
        // Nunca la cantidad recibida: lo que llegó es información interna, y si el
        // documento la imprimiera, regenerarlo después de una recepción daría otro
        // papel. Es lo que permite no archivar el archivo.
        ->not->toContain('Recibidas')
        ->not->toContain('recibida');
});

test('recibir mercaderia no cambia el pedido impreso', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $orden = OrdenCompra::factory()->aprobada()->conLinea($producto, 10, 1250)
        ->create(['proveedor_id' => $proveedor->id]);

    $antes = view('compras.pdf', ['orden' => $orden->load(['proveedor', 'lineas.producto', 'usuarioAprobo'])])->render();

    app(App\Services\CompraService::class)->recibir(
        $orden,
        [$orden->lineas->first()->id => 4],
        admin(),
    );

    $despues = view('compras.pdf', ['orden' => $orden->fresh()->load(['proveedor', 'lineas.producto', 'usuarioAprobo'])])->render();

    // Idéntico. Es la afirmación que sostiene la decisión de no archivar el PDF.
    expect($despues)->toBe($antes);
});

test('la ficha ofrece el PDF solo cuando la orden se puede imprimir', function () {
    $producto = Producto::factory()->create();

    $borrador = OrdenCompra::factory()->conLinea($producto, 2)->create();
    $aprobada = OrdenCompra::factory()->aprobada()->conLinea($producto, 2)->create();

    $usuario = usuarioCon('compra.ver');

    $this->actingAs($usuario)
        ->get(route('compras.show', $borrador))
        ->assertDontSee(route('compras.pdf', $borrador));

    $this->actingAs($usuario)
        ->get(route('compras.show', $aprobada))
        ->assertSee(route('compras.pdf', $aprobada));
});