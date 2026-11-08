<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProductoProveedorService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->vinculos = app(ProductoProveedorService::class);
});

/**
 * Los permisos del módulo de compras.
 *
 * Se declara acá porque es el primer test de módulo de compras que existe. Cuando
 * se escriba `CompraModuloTest` —el que cubre el listado, la ficha y la recepción—
 * tiene que REUSAR esta constante: Pest carga todos los archivos en el mismo
 * proceso, y declararla dos veces es un error fatal.
 */
const PERMISOS_COMPRA = ['compra.ver', 'compra.crear', 'compra.editar', 'compra.aprobar', 'compra.recibir'];

/** Un producto que se le puede comprar a ese proveedor, con su costo y su código. */
function productoDe(Proveedor $proveedor, float $costo = 1000, ?string $codigo = null, array $cambios = []): Producto
{
    return Producto::factory()
        ->conProveedor($proveedor, costo: $costo, codigo: $codigo)
        ->create($cambios);
}

/*
|--------------------------------------------------------------------------
| Permisos
|--------------------------------------------------------------------------
*/

dataset('rutas del armador', [
    'pantalla' => ['get',  'compras.pedido'],
    'guardar'  => ['post', 'compras.pedido.store'],
]);

test('ningun otro permiso del modulo habilita el armador', function (string $metodo, string $ruta) {
    $otros = array_values(array_diff(PERMISOS_COMPRA, ['compra.crear']));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, route($ruta), ['lineas' => []])
        ->assertForbidden();
})->with('rutas del armador');

test('compra.crear alcanza para el armador', function (string $metodo, string $ruta) {
    $respuesta = pedirRuta($this->actingAs(usuarioCon('compra.crear')), $metodo, route($ruta), ['lineas' => []]);

    expect($respuesta->status())->not->toBe(403);
})->with('rutas del armador');

test('quien solo puede ver compras no recibe el enlace al armador', function () {
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index'))
        ->assertOk()
        ->assertDontSee(route('compras.pedido'));
});

/*
|--------------------------------------------------------------------------
| La pantalla ofrece sólo lo que se puede pedir
|--------------------------------------------------------------------------
*/

test('solo ofrece productos activos que algun proveedor activo provea', function () {
    $proveedor = Proveedor::factory()->create();
    $deBaja    = Proveedor::factory()->inactivo()->create();

    $pedible      = productoDe($proveedor, cambios: ['nombre' => 'Fuente que se puede pedir']);
    $sinProveedor = Producto::factory()->create(['nombre' => 'Fuente sin proveedor']);
    $inactivo     = Producto::factory()->inactivo()->conProveedor($proveedor)
        ->create(['nombre' => 'Fuente dada de baja']);
    $soloDeBaja   = productoDe($deBaja, cambios: ['nombre' => 'Fuente de un proveedor de baja']);
    $respuesta = $this->actingAs(usuarioCon('compra.crear', 'producto.ver'))
        ->getJson(route('busqueda.productos', ['q' => 'fuente', 'con_proveedor' => 1]))
        ->assertOk();

    expect(array_column($respuesta->json('resultados'), 'id'))->toBe([$pedible->id]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.pedido'))
        ->assertOk();
});


test('la pantalla avisa cuando no hay nada que se pueda pedir', function () {
    Producto::factory()->create();   // sin proveedores

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.pedido'))
        ->assertOk()
        ->assertSee('No hay ningún producto que se le pueda comprar');
});

/*
|--------------------------------------------------------------------------
| La validación rechaza
|--------------------------------------------------------------------------
*/

test('rechaza un pedido sin lineas', function () {
    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => []])
        ->assertSessionHasErrors('lineas');
});

test('rechaza una linea cuyo proveedor no provee el producto', function () {
    $provee   = Proveedor::factory()->create();
    $noProvee = Proveedor::factory()->create();
    $producto = productoDe($provee);

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $noProvee, 3, 1000),
        ]])
        ->assertSessionHasErrors('lineas.0.proveedor_id');

    expect(OrdenCompra::count())->toBe(0);
});

test('rechaza el mismo par dos veces, senalando la linea', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $proveedor, 3, 1000),
            lineaDePedido($producto, $proveedor, 2, 1000),
        ]])
        // El error se cuelga de la línea culpable, no del arreglo entero.
        ->assertSessionHasErrors('lineas.1.producto_id');

    expect(OrdenCompra::count())->toBe(0);
});

test('acepta el mismo producto a dos proveedores distintos', function () {
    $uno = Proveedor::factory()->create(['razon_social' => 'Austral']);
    $dos = Proveedor::factory()->create(['razon_social' => 'Boreal']);

    $producto = Producto::factory()
        ->conProveedor($uno, costo: 1000)
        ->conProveedor($dos, costo: 900)
        ->create();

    // Es el caso que camino D habilita: partir una compra entre dos proveedores.
    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $uno, 10, 1000),
            lineaDePedido($producto, $dos, 5, 900),
        ]])
        ->assertSessionHasNoErrors();

    expect(OrdenCompra::count())->toBe(2);
});

test('rechaza un producto dado de baja', function () {
    $proveedor = Proveedor::factory()->create();
    $inactivo  = Producto::factory()->inactivo()->conProveedor($proveedor)->create();

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($inactivo, $proveedor, 1, 100),
        ]])
        ->assertSessionHasErrors('lineas.0.producto_id');
});

test('rechaza un proveedor dado de baja', function () {
    $proveedor = Proveedor::factory()->inactivo()->create();
    $producto  = productoDe($proveedor);

    // Una orden es un compromiso de plata: no se compromete con alguien dado de baja.
    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $proveedor, 1, 100),
        ]])
        ->assertSessionHasErrors('lineas.0.proveedor_id');
});

test('el costo en formato argentino se entiende', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoDe($proveedor);

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $proveedor, 2, '25.000,50'),
        ]])
        ->assertSessionHasNoErrors();

    expect(OrdenCompra::first()->lineas->first()->costo_unitario)->toBe('25000.50');
});

/*
|--------------------------------------------------------------------------
| El alta
|--------------------------------------------------------------------------
*/

test('guardar crea un borrador por proveedor y vuelve al listado de borradores', function () {
    $zeta = Proveedor::factory()->create(['razon_social' => 'Zeta Insumos']);
    $alfa = Proveedor::factory()->create(['razon_social' => 'Alfa Distribuciones']);

    $deZeta = productoDe($zeta, codigo: 'ZET-1');
    $deAlfa = productoDe($alfa, codigo: 'ALF-1');
    $otroDeAlfa = productoDe($alfa);

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($deZeta, $zeta, 1, 1000),
            lineaDePedido($deAlfa, $alfa, 2, 2000),
            lineaDePedido($otroDeAlfa, $alfa, 3, 500),
        ]])
        // El usuario cae en el listado filtrado a borradores, donde las nuevas son
        // las primeras filas porque el listado ordena por id descendente.
        ->assertRedirect(route('compras.index', ['estado' => 'borrador']))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, '2 pedidos')
            && str_contains($mensaje, 'Alfa Distribuciones')
            && str_contains($mensaje, 'Zeta Insumos'));

    expect(OrdenCompra::count())->toBe(2);

    $deAlfaEnLaBase = OrdenCompra::where('proveedor_id', $alfa->id)->first();

    expect($deAlfaEnLaBase->lineas)->toHaveCount(2)
        ->and($deAlfaEnLaBase->estado)->toBe('borrador')
        // 2 × 2.000 + 3 × 500
        ->and($deAlfaEnLaBase->total_estimado)->toBe('5500.00')
        // Y el código del proveedor quedó congelado en la línea.
        ->and($deAlfaEnLaBase->lineas->firstWhere('producto_id', $deAlfa->id)->codigo_proveedor)
        ->toBe('ALF-1');
});

test('una sola linea crea un solo pedido y el mensaje lo dice en singular', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral']);
    $producto  = productoDe($proveedor);

    $this->actingAs(usuarioCon('compra.crear'))
        ->post(route('compras.pedido.store'), ['lineas' => [
            lineaDePedido($producto, $proveedor, 4, 1000),
        ]])
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'Se creó el pedido')
            && str_contains($mensaje, 'Austral'));

    expect(OrdenCompra::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Los sugeridos de reposición
|--------------------------------------------------------------------------
*/

test('solo compra.crear abre la pantalla de sugeridos', function () {
    $otros = array_values(array_diff(PERMISOS_COMPRA, ['compra.crear']));

    $this->actingAs(usuarioCon(...$otros))->get(route('compras.sugeridos'))->assertForbidden();
    $this->actingAs(usuarioCon('compra.crear'))->get(route('compras.sugeridos'))->assertOk();
});

test('los sugeridos son lo que hay que reponer y nada mas', function () {
    $proveedor = Proveedor::factory()->create();

    $aPedir   = productoDe($proveedor, cambios: ['stock_minimo' => 5]);
    $enCamino = productoDe($proveedor, cambios: ['stock_minimo' => 5]);

    Producto::factory()->conStock(50)->conProveedor($proveedor)->create(['stock_minimo' => 5]);
    Producto::factory()->conStock(0)->conProveedor($proveedor)->create(['stock_minimo' => 0]);
    Producto::factory()->conStock(0)->create(['stock_minimo' => 5]);   // sin proveedor

    OrdenCompra::factory()->conLinea($enCamino, 10)->create(['proveedor_id' => $proveedor->id]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertViewHas('sugeridos', fn ($sugeridos) => $sugeridos->pluck('id')->all() === [$aPedir->id]);
});

test('el aviso dice cuantos no se pueden pedir y enlaza al catalogo filtrado', function () {
    productoDe(Proveedor::factory()->create(), cambios: ['stock_minimo' => 5]);

    Producto::factory()->count(3)->conStock(0)->create(['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('compra.crear', 'producto.ver'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertSee('data-sin-proveedor="3"', false)
        ->assertSee(route('productos.index', ['stock' => 'sin_proveedor']), false);
});

test('sin huerfanos el aviso no existe', function () {
    productoDe(Proveedor::factory()->create(), cambios: ['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertDontSee('avisoSinProveedor', false);
});

test('la pantalla pagina y no rinde todo lo que hay que reponer', function () {
    Producto::factory()->count(21)->conProveedor(Proveedor::factory()->create())
        ->create(['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertViewHas('sugeridos', fn ($sugeridos) => $sugeridos->count() === 20
            && $sugeridos->total() === 21);
});

test('el ultimo costo no se muestra sin el permiso de verlo', function () {
    productoDe(Proveedor::factory()->create(), costo: 12000, cambios: ['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertDontSee('Último costo');

    $this->actingAs(usuarioCon('compra.crear', 'precio.ver_costo'))
        ->get(route('compras.sugeridos'))
        ->assertOk()
        ->assertSee('Último costo');
});

test('los sugeridos elegidos llegan al armador como renglones', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral']);
    $producto  = productoDe($proveedor, costo: 12000, cambios: [
        'codigo'              => 'FUE-001',
        'stock_minimo'        => 5,
        'cantidad_reposicion' => 7,
    ]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.pedido', ['productos' => [$producto->id]]))
        ->assertOk()
        // El proveedor, la cantidad y el costo los pone el servidor: la pantalla
        // mandó un id y nada más.
        ->assertViewHas('lineas', fn ($lineas) => count($lineas) === 1
            && $lineas[0]['producto_id'] === $producto->id
            && $lineas[0]['proveedor_id'] === $proveedor->id
            && $lineas[0]['cantidad_pedida'] === 7
            && (float) $lineas[0]['costo_unitario'] === 12000.0)
        ->assertSee('FUE-001');
});

test('un sugerido que ya no hace falta no entra y el armador lo dice', function () {
    $proveedor = Proveedor::factory()->create();

    $sigue   = productoDe($proveedor, cambios: ['stock_minimo' => 5]);
    $cargado = Producto::factory()->conStock(50)->conProveedor($proveedor)->create(['stock_minimo' => 5]);

    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.pedido', ['productos' => [$sigue->id, $cargado->id]]))
        ->assertOk()
        ->assertViewHas('lineas', fn ($lineas) => count($lineas) === 1)
        // Rechaza, no corrige: el renglón no se agrega y se dice por qué (M-31).
        ->assertSee('ya no hace falta');
});

test('una lista de productos invalida devuelve el armador limpio con un error', function () {
    $this->actingAs(usuarioCon('compra.crear'))
        ->get(route('compras.pedido', ['productos' => [99999]]))
        ->assertRedirect(route('compras.pedido'))
        ->assertSessionHas('error');
});

test('la insignia del menu cuenta los borradores para quien puede aprobar', function () {
    OrdenCompra::factory()->count(2)->create(['proveedor_id' => Proveedor::factory()->create()->id]);

    $this->actingAs(usuarioCon('compra.ver', 'compra.aprobar'))
        ->get(route('compras.index'))
        ->assertOk()
        ->assertSee('data-borradores="2"', false)
        ->assertSee('esperando aprobación');

    // Para quien no puede aprobar no sería una llamada a la acción: no se rinde, y
    // tampoco se paga la consulta.
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index'))
        ->assertOk()
        ->assertDontSee('data-borradores', false);
});