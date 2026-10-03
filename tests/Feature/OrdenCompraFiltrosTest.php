<?php

use App\Models\OrdenCompra;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de órdenes de compra
|--------------------------------------------------------------------------
|
| Primer nivel de los dos: acá se prueba que los scopes filtran. Que el parámetro
| de la URL llegue al scope se prueba en CompraModuloTest.
|
*/

test('buscar encuentra la orden por su numero escrito de varias formas', function () {
    // Razón social sin dígitos: así la rama del nombre no interfiere con la del número.
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral']);

    $orden = OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);
    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $proveedor->id]);

    // El número se lee de un mail o se copia de la pantalla: las tres formas valen.
    expect(OrdenCompra::buscar($orden->numeroFormateado())->count())->toBe(1)
        ->and(OrdenCompra::buscar('OC-'.$orden->id)->count())->toBe(1)
        ->and(OrdenCompra::buscar((string) $orden->id)->count())->toBe(1);
});

test('buscar encuentra las ordenes por la razon social del proveedor', function () {
    $austral = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral']);
    $sur     = Proveedor::factory()->create(['razon_social' => 'Insumos del Sur']);

    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $austral->id]);
    OrdenCompra::factory()->create(['proveedor_id' => $sur->id]);

    expect(OrdenCompra::buscar('austral')->count())->toBe(2);
});

test('buscar sin texto no filtra nada', function () {
    OrdenCompra::factory()->count(3)->create();

    expect(OrdenCompra::buscar(null)->count())->toBe(3)
        ->and(OrdenCompra::buscar('')->count())->toBe(3)
        ->and(OrdenCompra::buscar('   ')->count())->toBe(3);
});

test('conEstado filtra por estado', function () {
    OrdenCompra::factory()->count(2)->create();           // borrador
    OrdenCompra::factory()->aprobada()->create();
    OrdenCompra::factory()->recibida()->create();

    expect(OrdenCompra::conEstado('borrador')->count())->toBe(2)
        ->and(OrdenCompra::conEstado('aprobada')->count())->toBe(1)
        ->and(OrdenCompra::conEstado('recibida')->count())->toBe(1);
});

test('conEstado sin valor o con un estado inexistente no filtra', function () {
    OrdenCompra::factory()->count(3)->create();

    expect(OrdenCompra::conEstado(null)->count())->toBe(3)
        ->and(OrdenCompra::conEstado('')->count())->toBe(3)
        ->and(OrdenCompra::conEstado('entregada')->count())->toBe(3);
});

test('deProveedor filtra por proveedor', function () {
    $uno  = Proveedor::factory()->create();
    $otro = Proveedor::factory()->create();

    OrdenCompra::factory()->count(2)->create(['proveedor_id' => $uno->id]);
    OrdenCompra::factory()->create(['proveedor_id' => $otro->id]);

    expect(OrdenCompra::deProveedor($uno->id)->count())->toBe(2)
        ->and(OrdenCompra::deProveedor(null)->count())->toBe(3)
        ->and(OrdenCompra::deProveedor('abc')->count())->toBe(3);
});

test('abiertas excluye las recibidas y las canceladas', function () {
    OrdenCompra::factory()->create();                      // borrador
    OrdenCompra::factory()->aprobada()->create();
    OrdenCompra::factory()->enviada()->create();
    OrdenCompra::factory()->recibidaParcial()->create();
    OrdenCompra::factory()->recibida()->create();
    OrdenCompra::factory()->cancelada()->create();

    // Es la definición que usa la reposición automática para no pedir dos veces
    // el mismo producto, y la que el panel va a usar para contar pendientes.
    expect(OrdenCompra::abiertas()->count())->toBe(4);
});

test('desde y hasta recortan por fecha e incluyen el dia completo', function () {
    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    OrdenCompra::factory()->create();

    $this->travelTo(Carbon::parse('2026-04-02 08:00'));
    OrdenCompra::factory()->create();

    $this->travelBack();

    expect(OrdenCompra::desde('2026-03-01')->hasta('2026-03-31')->count())->toBe(1)
        // Comparar created_at <= la fecha dejaría afuera la de las 23:40.
        ->and(OrdenCompra::hasta('2026-03-15')->count())->toBe(1)
        ->and(OrdenCompra::desde('2026-04-01')->count())->toBe(1);
});

test('el OR de buscar no se lleva puesto el filtro de estado', function () {
    // La razón social lleva un número a propósito: así el texto buscado activa
    // las dos ramas del OR y el agrupamiento importa de verdad.
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral 24']);

    OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);              // borrador
    OrdenCompra::factory()->cancelada()->create(['proveedor_id' => $proveedor->id]);

    // Sin el closure, la condición quedaría
    // `proveedor LIKE … OR (id = 24 AND estado = 'borrador')`, y la rama del LIKE
    // traería la cancelada sin pasar por el filtro de estado.
    expect(OrdenCompra::buscar('Austral 24')->conEstado('borrador')->count())->toBe(1);
});

test('la base impide dos lineas del mismo producto en una orden', function () {
    $producto = App\Models\Producto::factory()->create();
    $orden    = OrdenCompra::factory()->conLinea($producto, 5)->create();

    // UNIQUE (orden_compra_id, producto_id). El formulario tiene que fusionar o
    // rechazar el repetido antes de llegar acá, y el paso del servicio lo hace.
    expect(fn () => $orden->lineas()->create([
        'producto_id'     => $producto->id,
        'cantidad_pedida' => 3,
        'costo_unitario'  => 500,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});