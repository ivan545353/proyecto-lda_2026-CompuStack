<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Marca;
use App\Models\MovimientoStock;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Escala: la cantidad de consultas no depende de la cantidad de datos
|--------------------------------------------------------------------------
|
| Un N+1 no rompe ningún test de los otros archivos: la pantalla sigue
| devolviendo 200 y mostrando lo correcto. Sólo hace más consultas, y eso no se
| nota hasta que hay datos. Estos tests son los únicos que lo ven.
|
| Cada uno mide dos veces el mismo listado —con pocas filas y con muchas— y exige
| que el número de consultas sea el MISMO. Se compara en lugar de fijar un número
| porque un número exacto se rompe con cualquier cambio legítimo —un filtro nuevo,
| una relación más en el `with`— y entonces se actualiza sin pensar. La propiedad
| que importa no es «once consultas»: es «las mismas con cinco filas que con
| cincuenta».
|
| Lo que detecta, además del N+1 clásico, es el caso que apareció al medir la Fase
| 7.5: una consulta por `@can` por fila. Ese no crece con el catálogo, crece con el
| tamaño de la página, y por eso el segundo tramo de cada test carga más filas que
| las que entran en una página.
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // La caché va a la BASE, como en producción, y no en memoria.
    //
    // Es la línea más importante del archivo. `phpunit.xml` fija
    // CACHE_STORE=array, y con la caché en memoria el defecto que estos tests
    // vigilan NO EXISTE: `Cache::remember()` no consulta nada, así que un `@can`
    // por fila sale gratis y el conteo da igual con cinco filas que con cincuenta.
    // Con CACHE_STORE=database —lo que dice el .env— cada lectura de la caché es un
    // SELECT. Medido con 20.000 productos, el listado del catálogo hacía 106
    // consultas y 97 eran ésa. Un test que no reproduzca la configuración de
    // producción habría seguido pasando con el defecto puesto.
    config(['cache.default' => 'database']);
});

/*
| url, modelo con el que se crean las filas, y los permisos de la pantalla.
|
| Los permisos son TODOS los del listado, no sólo el de la ruta: cada `@can` que el
| usuario aprueba es un botón que se renderiza, y lo que se mide es el peor caso.
| Con un usuario que sólo puede ver, los botones de la fila no se dibujan y el
| defecto que estos tests buscan no se manifiesta.
*/
dataset('listados', [
    'catálogo'    => ['/productos',    Producto::class,    ['producto.ver', 'producto.crear', 'producto.editar', 'producto.eliminar', 'stock.ver']],
    'clientes'    => ['/clientes',     Cliente::class,     ['cliente.ver', 'cliente.crear', 'cliente.editar', 'cliente.eliminar']],
    'proveedores' => ['/proveedores',  Proveedor::class,   ['proveedor.ver', 'proveedor.crear', 'proveedor.editar', 'proveedor.eliminar']],
    'categorías'  => ['/categorias',   Categoria::class,   ['categoria.ver', 'categoria.crear', 'categoria.editar', 'categoria.eliminar']],
    'marcas'      => ['/marcas',       Marca::class,       ['marca.ver', 'marca.crear', 'marca.editar', 'marca.eliminar']],
    'ventas'      => ['/ventas',       Venta::class,       ['venta.ver', 'venta.crear', 'venta.editar']],
    'compras'     => ['/compras',      OrdenCompra::class, ['compra.ver', 'compra.crear', 'compra.editar']],
]);

test('el listado no hace mas consultas con mas filas', function (string $url, string $modelo, array $permisos) {
    $usuario = usuarioCon(...$permisos);

    $modelo::factory()->count(5)->create();

    // Calentamiento sin medir: la primera petición compila la vista y llena la
    // caché de permisos del rol. Lo que se compara es el efecto de las filas, no
    // el del primer uso.
    $this->actingAs($usuario)->get($url)->assertOk();

    $pocas = contarConsultas(fn () => $this->actingAs($usuario)->get($url)->assertOk());

    // Cincuenta en total: más de las que entran en una página, para que la segunda
    // medición renderice una página completa y no cinco filas.
    $modelo::factory()->count(45)->create();

    $muchas = contarConsultas(fn () => $this->actingAs($usuario)->get($url)->assertOk());

    expect($muchas)->toBe($pocas);
})->with('listados');

test('el kardex no hace mas consultas con mas movimientos', function () {
    $usuario  = usuarioCon('stock.ver', 'stock.ajustar');
    $producto = Producto::factory()->create();

    // Los asientos se crean llamando al servicio, no con MovimientoStock::create():
    // el modelo declara que no tiene factory a propósito, porque un movimiento sin su
    // cambio de stock sería una fila que afirma algo que no pasó. Cada ajuste cuenta
    // una unidad más que el anterior, así que ninguno queda en diferencia cero, que el
    // servicio rechaza.
    $proximo = 1;

    $asentar = function (int $cuantos) use ($producto, $usuario, &$proximo) {
        foreach (range(1, $cuantos) as $i) {
            app(StockService::class)->ajustar(
                $producto,
                $proximo++,
                'Asiento de prueba de escala.',
                $usuario,
            );
        }
    };

    $asentar(5);

    $this->actingAs($usuario)->get('/stock')->assertOk();

    $pocas = contarConsultas(fn () => $this->actingAs($usuario)->get('/stock')->assertOk());

    $asentar(45);

    $muchas = contarConsultas(fn () => $this->actingAs($usuario)->get('/stock')->assertOk());

    expect($muchas)->toBe($pocas);
});

test('una pantalla con un can por fila lee los permisos una sola vez', function () {
    $usuario = usuarioCon('producto.ver', 'producto.crear', 'producto.editar', 'producto.eliminar', 'stock.ver');

    Producto::factory()->count(30)->create();

    $consultas = consultasSobre('cache', fn () => $this->actingAs($usuario)->get('/productos')->assertOk());

    // Una lectura de la caché y, si la entrada no estaba, una escritura. Nunca una
    // por botón: el catálogo dibuja cuatro por fila y veinte filas por página.
    expect($consultas)->toBeLessThanOrEqual(2);
});