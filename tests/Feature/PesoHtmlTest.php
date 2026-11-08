<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El peso del HTML no crece con el catálogo
|--------------------------------------------------------------------------
|
| Este archivo no existía hasta el tramo E, y no por olvido: `docs/escala.md`
| §3.2 y el pendiente #52 lo dicen con esas palabras —«el peso del HTML no tiene
| todavía un test que lo fije, y es a propósito: hoy crece con el catálogo, así
| que un test que afirmara que no crece sería un test rojo»—. El tramo E lo
| vuelve cierto, así que le toca escribirlo.
|
| Qué fija, y por qué así. Cada test rinde la misma pantalla dos veces —con
| cinco productos y con sesenta— y exige que el HTML pese **exactamente lo
| mismo**. Se compara en lugar de fijar un número de kilobytes, por la misma
| razón que `EscalaTest` compara consultas en lugar de fijarlas: un tope
| absoluto se rompe con cualquier cambio legítimo de la pantalla y entonces se
| sube sin pensar. La propiedad que importa no es «pesa menos de 100 KB»: es
| «pesa lo mismo con cinco productos que con sesenta», y por lo tanto lo mismo
| con veinte mil.
|
| La igualdad exacta es exigente y tiene que serlo: un solo `<option>` por
| producto que quede en alguna de estas pantallas hace fallar el test, que es
| justo lo que se quiere. Para que la única variable sea el catálogo, los
| productos nuevos se le cuelgan **al mismo proveedor**: si cada uno creara el
| suyo, el padrón de proveedores crecería junto con el catálogo y el test
| mediría dos cosas a la vez.
|
| Los números que estas cuatro pantallas tenían antes del tramo, medidos con
| `escala:medir` sobre 20.011 productos (`docs/escala.md` §2):
|
|   Armador de pedido      6.173 KB · 5,8 s · 392 MB de pico
|   Formulario de venta    5.249 KB · 1,2 s · 126 MB
|   Kardex                 3.572 KB · 0,7 s · 104 MB
|   Orden de compra        no medido; rendía sólo los productos del proveedor
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Uno solo para todo el archivo: los productos que se agregan se le cuelgan a
    // él, así el padrón de proveedores no crece con el catálogo.
    $this->proveedor = Proveedor::factory()->create();
});

/**
 * Cuánto pesa el HTML de esa pantalla, en bytes.
 *
 * Vive en este archivo y no en `tests/Pest.php` porque no la comparte nadie: la
 * regla de las ayudas compartidas es para las que se usan desde más de un archivo.
 */
function pesoDelHtml($test, User $usuario, string $url): int
{
    $respuesta = $test->actingAs($usuario)->get($url);

    $respuesta->assertOk();

    return strlen((string) $respuesta->getContent());
}

/** Productos que se le pueden pedir al mismo proveedor. */
function catalogoDe(int $cuantos, Proveedor $proveedor): void
{
    Producto::factory()->count($cuantos)->conProveedor($proveedor)->create();
}

test('el formulario de venta no crece con el catalogo', function () {
    // Los tres permisos que la pantalla usa: el de la ruta y los de los dos
    // buscadores. El peso no depende de ellos, pero medir con el usuario que la
    // pantalla espera evita medir una pantalla recortada.
    $usuario = usuarioCon('venta.crear', 'producto.ver', 'cliente.ver');

    catalogoDe(5, $this->proveedor);

    $pocos = pesoDelHtml($this, $usuario, '/ventas/crear');

    catalogoDe(55, $this->proveedor);

    expect(pesoDelHtml($this, $usuario, '/ventas/crear'))->toBe($pocos);
});

test('el armador de pedido no crece con el catalogo', function () {
    $usuario = usuarioCon('compra.crear', 'producto.ver', 'proveedor.ver');

    catalogoDe(5, $this->proveedor);

    $pocos = pesoDelHtml($this, $usuario, '/compras/pedido');

    catalogoDe(55, $this->proveedor);

    // Es la pantalla que justifica el tramo: 392 MB de pico con 20.011 productos
    // contra un límite por omisión de 512 MB en PHP. No era lenta, era una pantalla
    // que con el doble de catálogo dejaba de funcionar.
    expect(pesoDelHtml($this, $usuario, '/compras/pedido'))->toBe($pocos);
});

test('el kardex no crece con el catalogo', function () {
    $usuario = usuarioCon('stock.ver', 'producto.ver');

    catalogoDe(5, $this->proveedor);

    $pocos = pesoDelHtml($this, $usuario, '/stock');

    catalogoDe(55, $this->proveedor);

    // El pendiente #52: el kardex no mandaba un mapa en JSON como las otras dos,
    // mandaba un selector, y un selector parece inofensivo.
    expect(pesoDelHtml($this, $usuario, '/stock'))->toBe($pocos);
});

test('la orden de compra no crece con el catalogo', function () {
    $usuario = usuarioCon('compra.editar', 'producto.ver', 'proveedor.ver');
    $orden   = OrdenCompra::factory()->create(['proveedor_id' => $this->proveedor->id]);

    catalogoDe(5, $this->proveedor);

    $pocos = pesoDelHtml($this, $usuario, "/compras/{$orden->id}/editar");

    // Todos del mismo proveedor: antes esta pantalla rendía los productos de ese
    // proveedor, así que es con ésos con los que tiene que no crecer.
    catalogoDe(55, $this->proveedor);

    expect(pesoDelHtml($this, $usuario, "/compras/{$orden->id}/editar"))->toBe($pocos);
});