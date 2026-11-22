<?php

use App\Models\Marca;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(StockService::class);

    // El kardex por la API, con quien sólo puede verlo. Closure y no función global (tests.md).
    $this->kardex = fn (array $filtros = []) => $this->getJson(
        '/api/v1/stock/movimientos?'.http_build_query($filtros),
        bearer(usuarioCon('stock.ver')),
    );
});

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Son dos permisos: ver el kardex y ajustar el inventario son responsabilidades
| distintas. El administrativo tiene las dos; un rol que sólo audite tendría
| `stock.ver` y no podría tocar nada.
|
| Desde el tramo F de la Etapa 3 el kardex y el ajuste son endpoints: los tests
| migraron conservando cada aserción.
|
*/

// Las pantallas. Registrar el ajuste salió de la web en el tramo F: su fila es la del dataset de
// endpoints, con el mismo permiso.
dataset('rutas de stock', [
    'kardex'              => ['get',  'stock.index',         'stock.ver',     false],
    'formulario ajuste'   => ['get',  'stock.ajuste.create', 'stock.ajustar', true],
]);

// `{producto}` se reemplaza por un producto con 5 unidades.
dataset('endpoints de stock', [
    'kardex'               => ['get',  '/api/v1/stock/movimientos',               'stock.ver'],
    'imprimir el kardex'   => ['get',  '/api/v1/stock/movimientos/impresion',     'stock.ver'],
    'quienes registraron'  => ['get',  '/api/v1/stock/usuarios',                  'stock.ver'],
    'stock de un producto' => ['get',  '/api/v1/productos/{producto}/stock',      'stock.ver'],
    'registrar ajuste'     => ['post', '/api/v1/productos/{producto}/ajustes-stock', 'stock.ajustar'],
]);

const PERMISOS_STOCK = ['stock.ver', 'stock.ajustar'];

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, bool $conProducto) {
    $producto = Producto::factory()->conStock(5)->create();
    $url      = $conProducto ? route($ruta, $producto) : route($ruta);

    $otros = array_values(array_diff(PERMISOS_STOCK, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, $url, datosDeAjuste(7, 5))
        ->assertForbidden();
})->with('rutas de stock');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, bool $conProducto) {
    $producto = Producto::factory()->conStock(5)->create();
    $url      = $conProducto ? route($ruta, $producto) : route($ruta);

    $respuesta = pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, $url, datosDeAjuste(7, 5));

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de stock');

test('en la API, ningun otro permiso del modulo habilita el endpoint de stock', function (string $metodo, string $url, string $permiso) {
    $producto = Producto::factory()->conStock(5)->create();
    $otros    = array_values(array_diff(PERMISOS_STOCK, [$permiso]));

    $this->json($metodo, str_replace('{producto}', (string) $producto->id, $url), datosDeAjuste(7, 5), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de stock');

test('en la API, el permiso del endpoint de stock alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $producto = Producto::factory()->conStock(5)->create();

    $respuesta = $this->json($metodo, str_replace('{producto}', (string) $producto->id, $url), datosDeAjuste(7, 5), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de stock');

test('quien solo puede ver el kardex no recibe el boton de ajustar', function () {
    $producto = Producto::factory()->conStock(5)->create();

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertDontSee(route('stock.ajuste.create', $producto));

    // La otra dirección: con el permiso, el enlace está en el shell.
    $this->actingAs(usuarioCon('stock.ver', 'stock.ajustar'))
        ->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertSee(route('stock.ajuste.create', $producto));
});

test('las rutas web de escritura del stock ya no existen', function () {
    // El ajuste y la impresión son de la API (tramo F): la web sólo entrega las pantallas.
    $producto = Producto::factory()->conStock(5)->create();
    $usuario  = usuarioCon(...PERMISOS_STOCK);

    expect($this->actingAs($usuario)->post("/stock/{$producto->id}/ajuste", datosDeAjuste(7, 5))->status())->toBeIn([404, 405])
        ->and($producto->fresh()->stock)->toBe(5);

    // `/stock/imprimir` ya no es una ruta: cae en `/stock/{producto}/ajuste`, que no coincide, y es 404.
    $this->actingAs($usuario)->get('/stock/imprimir')->assertNotFound();
});

test('las pantallas de stock no llevan datos de negocio', function () {
    // Regla 37: el shell trae la estructura; los movimientos y el stock los pide el JS.
    $producto = Producto::factory()->conStock(37)->create(['codigo' => 'CODSHELL', 'nombre' => 'ProductoDelShell']);
    $this->service->ajustar($producto, 41, 'MotivoDelShell', admin());
    $usuario = usuarioCon(...PERMISOS_STOCK);

    $this->actingAs($usuario)->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertSee('data-pantalla="kardex"', false)
        ->assertDontSee('CODSHELL')
        ->assertDontSee('ProductoDelShell')
        ->assertDontSee('MotivoDelShell');

    $this->actingAs($usuario)->get(route('stock.ajuste.create', $producto))
        ->assertOk()
        ->assertSee('data-formulario-ajuste-stock', false)
        ->assertDontSee('ProductoDelShell')
        ->assertDontSee('value="41"', false);
});

// Desde el tramo D de la Etapa 3 la fila del listado es una plantilla del shell (regla 37): el enlace
// lleva `__ID__`, que la pantalla reemplaza por el id de cada producto que trae la API.
test('el listado de productos ofrece el acceso al stock solo con permiso', function () {
    Producto::factory()->conStock(5)->create();
    $enlace = route('stock.index', ['producto_id' => '__ID__']);

    $this->actingAs(usuarioCon('producto.ver', 'stock.ver'))
        ->get(route('productos.index'))
        ->assertSee($enlace, escape: false);

    $this->actingAs(usuarioCon('producto.ver'))
        ->get(route('productos.index'))
        ->assertDontSee($enlace, escape: false);
});

/*
|--------------------------------------------------------------------------
| Filtros sobre HTTP
|--------------------------------------------------------------------------
|
| Los scopes ya tienen su test en MovimientoFiltrosTest; estos prueban el
| cableado entre la URL y el scope, que es lo que estaba roto en A-24.
|
*/

test('el parametro producto_id llega al kardex y filtra', function () {
    $uno  = Producto::factory()->conStock(50)->create();
    $otro = Producto::factory()->conStock(50)->create();
    $usuario = admin();

    $this->service->ajustar($uno, 45, 'Inventario', $usuario);
    $this->service->ajustar($otro, 40, 'Inventario', $usuario);

    ($this->kardex)(['producto_id' => $uno->id])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('criterios.producto_id', $uno->id);

    app('auth')->forgetGuards();

    // Y el panel del producto, con su stock, viene de su propio endpoint.
    $this->getJson("/api/v1/productos/{$uno->id}/stock", bearer(usuarioCon('stock.ver')))
        ->assertOk()
        ->assertJsonPath('datos.id', $uno->id)
        ->assertJsonPath('datos.stock', 45);
});

test('el parametro tipo llega al kardex y filtra', function () {
    $producto  = Producto::factory()->conStockYCosto(50, 100)->create();
    $usuario   = admin();

    $this->service->ajustar($producto->fresh(), 45, 'Inventario', $usuario);
    $this->service->recibirCompra($producto->fresh(), 10, 120, Marca::factory()->create(), $usuario);

    ($this->kardex)(['tipo' => 'compra'])->assertOk()->assertJsonPath('total', 1);
});

test('el parametro usuario_id llega al kardex y filtra', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $una  = admin();
    $otra = admin();

    $this->service->ajustar($producto->fresh(), 45, 'Inventario', $una);
    $this->service->ajustar($producto->fresh(), 40, 'Inventario', $otra);

    ($this->kardex)(['usuario_id' => $una->id])->assertOk()->assertJsonPath('total', 1);
});

test('el filtro de quien registro ofrece solo a quienes tienen movimientos', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $una      = admin();
    admin();    // Otra cuenta, sin movimientos: no se ofrece.

    $this->service->ajustar($producto, 45, 'Inventario', $una);

    $this->getJson('/api/v1/stock/usuarios', bearer(usuarioCon('stock.ver')))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('datos.0.id', $una->id)
        ->assertJsonPath('datos.0.texto', "{$una->apellido}, {$una->nombre}");
});

test('el rango de fechas llega al kardex e incluye el dia completo', function () {
    $producto = Producto::factory()->conStock(100)->create();
    $usuario  = admin();

    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    $this->service->ajustar($producto->fresh(), 90, 'Marzo a la noche', $usuario);

    $this->travelTo(Carbon::parse('2026-04-01 08:00'));
    $this->service->ajustar($producto->fresh(), 80, 'Abril', $usuario);

    $this->travelBack();

    ($this->kardex)(['desde' => '2026-03-01', 'hasta' => '2026-03-15'])->assertOk()->assertJsonPath('total', 1);
});

test('un filtro invalido se rechaza con su campo', function () {
    // En la web volvía al kardex limpio con un aviso; la API rechaza, no corrige (M-31, HL-120).
    ($this->kardex)(['tipo' => 'telepatia'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tipo', 'errores');

    app('auth')->forgetGuards();

    // Un rango invertido no devuelve nada y no es lo que nadie quiso pedir.
    ($this->kardex)(['desde' => '2026-05-01', 'hasta' => '2026-04-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta', 'errores');
});

test('el kardex pagina de a 20, del mas nuevo al mas viejo', function () {
    // Eran 15 en la web; la API pagina de a 20 como todos sus listados (HL-119).
    $producto = Producto::factory()->conStock(1000)->create();
    $usuario  = admin();

    foreach (range(1, 25) as $i) {
        $this->service->ajustar($producto->fresh(), 1000 - $i, "Ajuste numero {$i}", $usuario);
    }

    $respuesta = ($this->kardex)()->assertOk();

    expect($respuesta->json('datos'))->toHaveCount(20)
        ->and($respuesta->json('total'))->toBe(25)
        // El más nuevo primero: es el orden en que se lee un kardex.
        ->and($respuesta->json('datos.0.motivo'))->toBe('Ajuste numero 25');
});

test('el kardex no muestra nombres de clase en la columna de origen', function () {
    $producto = Producto::factory()->conStockYCosto(10, 100)->create();

    $this->service->recibirCompra($producto, 5, 120, Marca::factory()->create(), admin());

    // Lenguaje del negocio, nunca del programador.
    $respuesta = ($this->kardex)()->assertOk()->assertJsonPath('datos.0.origen_texto', 'Documento interno');

    expect($respuesta->getContent())->not->toContain('Models');
});

/*
|--------------------------------------------------------------------------
| El ajuste, por la API
|--------------------------------------------------------------------------
*/

test('el ajuste mueve el stock y el mensaje dice de cuanto a cuanto', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $mensaje = $this->postJson("/api/v1/productos/{$producto->id}/ajustes-stock", datosDeAjuste(7, 10), bearer(usuarioCon('stock.ajustar')))
        ->assertCreated()
        ->assertJsonPath('datos.cantidad', -3)
        ->json('mensaje');

    expect($mensaje)->toContain('de 10 a 7')->toContain('-3')
        ->and($producto->fresh()->stock)->toBe(7);

    $movimiento = MovimientoStock::sole();

    expect($movimiento->tipo)->toBe('ajuste')
        ->and($movimiento->cantidad)->toBe(-3)
        ->and($movimiento->motivo)->toBe('Faltante detectado en inventario');
});

test('el ajuste sin motivo se rechaza y no mueve nada', function () {
    // Lo escrito no se pierde porque la pantalla no se va: el formulario sigue ahí con el 422.
    $producto = Producto::factory()->conStock(10)->create();

    $this->postJson("/api/v1/productos/{$producto->id}/ajustes-stock", datosDeAjuste(7, 10, ['motivo' => '']), bearer(usuarioCon('stock.ajustar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo', 'errores');

    expect($producto->fresh()->stock)->toBe(10)
        ->and(MovimientoStock::count())->toBe(0);
});

test('el ajuste con un conteo negativo se rechaza', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $this->postJson("/api/v1/productos/{$producto->id}/ajustes-stock", datosDeAjuste(-1, 10), bearer(usuarioCon('stock.ajustar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stock_contado', 'errores');

    expect($producto->fresh()->stock)->toBe(10);
});

test('el ajuste rechaza el producto y el tipo enviados en el cuerpo', function () {
    $producto = Producto::factory()->conStock(5)->create();
    $otro     = Producto::factory()->conStock(5)->create();

    $this->postJson("/api/v1/productos/{$producto->id}/ajustes-stock", datosDeAjuste(7, 5, [
        'producto_id' => $otro->id,
        'tipo'        => 'compra',
    ]), bearer(usuarioCon('stock.ajustar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['producto_id', 'tipo'], 'errores');

    // Prohibir en vez de omitir: el intento de registrar un ajuste como si fuera
    // una compra rompe en la cara de quien lo haga, no desaparece en silencio.
    expect($producto->fresh()->stock)->toBe(5)
        ->and($otro->fresh()->stock)->toBe(5)
        ->and(MovimientoStock::count())->toBe(0);
});

test('un ajuste sobre un stock que cambio se rechaza con el aviso', function () {
    $producto = Producto::factory()->conStock(10)->create();

    // Entre que se abrió la pantalla y se guardó, entró una devolución.
    $this->service->reponer($producto, 2, Marca::factory()->create(), admin());

    $this->postJson("/api/v1/productos/{$producto->id}/ajustes-stock", datosDeAjuste(9, 10), bearer(usuarioCon('stock.ajustar')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    // Y la devolución sigue en pie: el ajuste no la pisó.
    expect($producto->fresh()->stock)->toBe(12)
        ->and(MovimientoStock::where('tipo', 'ajuste')->count())->toBe(0);
});
