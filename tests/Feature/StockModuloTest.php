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
});

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Son dos permisos y tres rutas: ver el kardex y ajustar el inventario son
| responsabilidades distintas. El administrativo tiene las dos; un rol que sólo
| audite tendría `stock.ver` y no podría tocar nada.
|
*/

dataset('rutas de stock', [
    'kardex'              => ['get',  'stock.index',         'stock.ver',     false],
    'formulario ajuste'   => ['get',  'stock.ajuste.create', 'stock.ajustar', true],
    'registrar ajuste'    => ['post', 'stock.ajuste.store',  'stock.ajustar', true],
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

test('quien solo puede ver el kardex no recibe el boton de ajustar', function () {
    $producto = Producto::factory()->conStock(5)->create();

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['producto_id' => $producto->id]))
        ->assertOk()
        ->assertDontSee(route('stock.ajuste.create', $producto));
});

test('el listado de productos ofrece el acceso al stock solo con permiso', function () {
    $producto = Producto::factory()->conStock(5)->create();
    $enlace   = route('stock.index', ['producto_id' => $producto->id]);

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

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['producto_id' => $uno->id]))
        ->assertOk()
        ->assertViewHas('movimientos', fn ($m) => $m->total() === 1)
        // Y el panel del producto aparece, con su stock y el acceso al ajuste
        ->assertViewHas('producto', fn ($p) => $p->id === $uno->id);
});

test('el parametro tipo llega al kardex y filtra', function () {
    $producto  = Producto::factory()->conStockYCosto(50, 100)->create();
    $usuario   = admin();

    $this->service->ajustar($producto->fresh(), 45, 'Inventario', $usuario);
    $this->service->recibirCompra($producto->fresh(), 10, 120, Marca::factory()->create(), $usuario);

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['tipo' => 'compra']))
        ->assertViewHas('movimientos', fn ($m) => $m->total() === 1);
});

test('el parametro usuario_id llega al kardex y filtra', function () {
    $producto = Producto::factory()->conStock(50)->create();
    $una  = admin();
    $otra = admin();

    $this->service->ajustar($producto->fresh(), 45, 'Inventario', $una);
    $this->service->ajustar($producto->fresh(), 40, 'Inventario', $otra);

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['usuario_id' => $una->id]))
        ->assertViewHas('movimientos', fn ($m) => $m->total() === 1);
});

test('el rango de fechas llega al kardex e incluye el dia completo', function () {
    $producto = Producto::factory()->conStock(100)->create();
    $usuario  = admin();

    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    $this->service->ajustar($producto->fresh(), 90, 'Marzo a la noche', $usuario);

    $this->travelTo(Carbon::parse('2026-04-01 08:00'));
    $this->service->ajustar($producto->fresh(), 80, 'Abril', $usuario);

    $this->travelBack();

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index', ['desde' => '2026-03-01', 'hasta' => '2026-03-15']))
        ->assertViewHas('movimientos', fn ($m) => $m->total() === 1);
});

test('un filtro invalido vuelve al kardex limpio con aviso', function () {
    $usuario = usuarioCon('stock.ver');

    $this->actingAs($usuario)
        ->get(route('stock.index', ['tipo' => 'telepatia']))
        ->assertRedirect(route('stock.index'))
        ->assertSessionHas('error');

    // Un rango invertido no devuelve nada y no es lo que nadie quiso pedir.
    $this->actingAs($usuario)
        ->get(route('stock.index', ['desde' => '2026-05-01', 'hasta' => '2026-04-01']))
        ->assertRedirect(route('stock.index'))
        ->assertSessionHas('error');
});

test('el kardex pagina de a 15, del mas nuevo al mas viejo', function () {
    $producto = Producto::factory()->conStock(1000)->create();
    $usuario  = admin();

    foreach (range(1, 20) as $i) {
        $this->service->ajustar($producto->fresh(), 1000 - $i, "Ajuste numero {$i}", $usuario);
    }

    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index'))
        ->assertViewHas('movimientos', fn ($m) => $m->count() === 15
            && $m->total() === 20
            // El más nuevo primero: es el orden en que se lee un kardex.
            && $m->first()->motivo === 'Ajuste numero 20');
});

test('el kardex no muestra nombres de clase en la columna de origen', function () {
    $producto = Producto::factory()->conStockYCosto(10, 100)->create();

    $this->service->recibirCompra($producto, 5, 120, Marca::factory()->create(), admin());

    // Lenguaje del negocio, nunca del programador.
    $this->actingAs(usuarioCon('stock.ver'))
        ->get(route('stock.index'))
        ->assertOk()
        ->assertDontSee('App\\Models')
        ->assertSee('Documento interno');
});

/*
|--------------------------------------------------------------------------
| El ajuste, desde la pantalla
|--------------------------------------------------------------------------
*/

test('el ajuste mueve el stock y el mensaje dice de cuanto a cuanto', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $this->actingAs(usuarioCon('stock.ajustar'))
        ->post(route('stock.ajuste.store', $producto), datosDeAjuste(7, 10))
        ->assertRedirect(route('stock.index', ['producto_id' => $producto->id]))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'de 10 a 7')
            && str_contains($mensaje, '-3'));

    expect($producto->fresh()->stock)->toBe(7);

    $movimiento = MovimientoStock::sole();

    expect($movimiento->tipo)->toBe('ajuste')
        ->and($movimiento->cantidad)->toBe(-3)
        ->and($movimiento->motivo)->toBe('Faltante detectado en inventario');
});

test('el ajuste sin motivo se rechaza y no mueve nada', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $this->actingAs(usuarioCon('stock.ajustar'))
        ->from(route('stock.ajuste.create', $producto))
        ->post(route('stock.ajuste.store', $producto), datosDeAjuste(7, 10, ['motivo' => '']))
        ->assertRedirect(route('stock.ajuste.create', $producto))
        ->assertSessionHasErrors('motivo')
        ->assertSessionHasInput('stock_contado', 7);

    expect($producto->fresh()->stock)->toBe(10)
        ->and(MovimientoStock::count())->toBe(0);
});

test('el ajuste con un conteo negativo se rechaza', function () {
    $producto = Producto::factory()->conStock(10)->create();

    $this->actingAs(usuarioCon('stock.ajustar'))
        ->post(route('stock.ajuste.store', $producto), datosDeAjuste(-1, 10))
        ->assertSessionHasErrors('stock_contado');

    expect($producto->fresh()->stock)->toBe(10);
});

test('el ajuste rechaza el producto y el tipo enviados en el cuerpo', function () {
    $producto = Producto::factory()->conStock(5)->create();
    $otro     = Producto::factory()->conStock(5)->create();

    $this->actingAs(usuarioCon('stock.ajustar'))
        ->post(route('stock.ajuste.store', $producto), datosDeAjuste(7, 5, [
            'producto_id' => $otro->id,
            'tipo'        => 'compra',
        ]))
        ->assertSessionHasErrors(['producto_id', 'tipo']);

    // Prohibir en vez de omitir: el intento de registrar un ajuste como si fuera
    // una compra rompe en la cara de quien lo haga, no desaparece en silencio.
    expect($producto->fresh()->stock)->toBe(5)
        ->and($otro->fresh()->stock)->toBe(5)
        ->and(MovimientoStock::count())->toBe(0);
});

test('un ajuste sobre un stock que cambio vuelve al formulario con el aviso', function () {
    $producto = Producto::factory()->conStock(10)->create();

    // Entre que se abrió la pantalla y se guardó, entró una devolución.
    $this->service->reponer($producto, 2, Marca::factory()->create(), admin());

    $this->actingAs(usuarioCon('stock.ajustar'))
        ->from(route('stock.ajuste.create', $producto))
        ->post(route('stock.ajuste.store', $producto), datosDeAjuste(9, 10))
        ->assertRedirect(route('stock.ajuste.create', $producto))
        ->assertSessionHas('error')
        // ReglaDeNegocioException vuelve con withInput(): no se pierde lo escrito.
        ->assertSessionHasInput('motivo');

    // Y la devolución sigue en pie: el ajuste no la pisó.
    expect($producto->fresh()->stock)->toBe(12)
        ->and(MovimientoStock::where('tipo', 'ajuste')->count())->toBe(0);
});