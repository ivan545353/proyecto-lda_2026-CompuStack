<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sin seed de roles y permisos
|--------------------------------------------------------------------------
|
| El comando no pasa por el Gate: no hay sesión ni usuario. Sembrar los roles
| sólo haría más lenta la corrida sin cubrir nada.
|
*/

/**
 * Un producto que la reposición tiene que levantar: activo, con mínimo
 * declarado y con el disponible en cero.
 *
 * El proveedor es opcional porque dos tests necesitan justamente un producto
 * sin nadie a quien pedirle.
 */
function productoBajoMinimo(
    ?Proveedor $proveedor = null,
    ?float $costo = 15000,
    ?string $codigo = null,
    array $sobreescribir = [],
): Producto {
    $factory = Producto::factory()->conStock(0);

    if ($proveedor !== null) {
        $factory = $factory->conProveedor($proveedor, $costo, $codigo);
    }

    return $factory->create(array_merge([
        'stock_minimo'        => 5,
        'cantidad_reposicion' => 12,
    ], $sobreescribir));
}

// ---------- Lo que genera ----------

test('genera una orden en borrador por proveedor con los productos criticos de cada uno', function () {
    $austral = Proveedor::factory()->create(['razon_social' => 'Austral S.A.']);
    $boreal  = Proveedor::factory()->create(['razon_social' => 'Boreal S.R.L.']);

    $uno = productoBajoMinimo($austral, 15000, codigo: 'AUS-1');
    productoBajoMinimo($austral, 8000, codigo: 'AUS-2');
    productoBajoMinimo($boreal, 30000);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(2);

    $deAustral = OrdenCompra::where('proveedor_id', $austral->id)->firstOrFail();
    $linea     = $deAustral->lineas->firstWhere('producto_id', $uno->id);

    expect($deAustral->estado)->toBe('borrador')
        ->and($deAustral->lineas)->toHaveCount(2)
        // La cantidad es la que el producto declara, no una inventada.
        ->and($linea->cantidad_pedida)->toBe(12)
        ->and($linea->costo_unitario)->toBe('15000.00')
        ->and(OrdenCompra::where('proveedor_id', $boreal->id)->firstOrFail()->lineas)->toHaveCount(1);
});

test('la orden generada no la creo ninguna persona y lo dice', function () {
    productoBajoMinimo(Proveedor::factory()->create());

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    $orden = OrdenCompra::firstOrFail();

    expect($orden->usuario_creo_id)->toBeNull()
        ->and($orden->fueGeneradaPorElSistema())->toBeTrue()
        ->and($orden->observaciones)->toContain('Generada automáticamente');
});

test('las ordenes se numeran en el orden alfabetico de los proveedores', function () {
    // Zeta se crea primero, así que tiene el id más chico y el nombre más grande:
    // si el comando agrupara por id, el orden saldría al revés.
    $zeta = Proveedor::factory()->create(['razon_social' => 'Zeta S.A.']);
    $alfa = Proveedor::factory()->create(['razon_social' => 'Alfa S.A.']);

    productoBajoMinimo($zeta);
    productoBajoMinimo($alfa);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::with('proveedor')->orderBy('id')->get()
        ->map(fn (OrdenCompra $orden) => $orden->proveedor->razon_social)->all())
        ->toBe(['Alfa S.A.', 'Zeta S.A.']);
});

// ---------- A quién se le pide y a qué precio ----------

test('se le pide al preferido aunque no sea el mas barato', function () {
    $barato  = Proveedor::factory()->create(['razon_social' => 'Barato S.A.']);
    $elegido = Proveedor::factory()->create(['razon_social' => 'Elegido S.A.']);

    Producto::factory()
        ->conStock(0)
        ->conProveedor($barato, 8000)
        ->conProveedor($elegido, 20000, preferido: true)
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 12]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    $orden = OrdenCompra::with('lineas')->firstOrFail();

    // Y el costo es el del vínculo de ESE proveedor, no el más barato de la tabla.
    expect($orden->proveedor_id)->toBe($elegido->id)
        ->and($orden->lineas->first()->costo_unitario)->toBe('20000.00');
});

test('el costo sale del vinculo y no de costo_promedio', function () {
    $proveedor = Proveedor::factory()->create();

    Producto::factory()
        ->conStockYCosto(0, 99000)          // promedio de todas las compras, a todos
        ->conProveedor($proveedor, 15000)   // lo último que cobró ESTE proveedor
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 12]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::firstOrFail()->lineas->first()->costo_unitario)->toBe('15000.00');
});

test('si el vinculo no tiene costo cargado la linea va en cero', function () {
    productoBajoMinimo(Proveedor::factory()->create(), costo: null);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    $orden = OrdenCompra::with('lineas')->firstOrFail();

    // Un borrador con el precio en cero se nota y se completa antes de aprobar.
    // Completarlo con el promedio produciría un número que parece acordado y no lo
    // es, que es M-31 con otra ropa.
    expect($orden->lineas->first()->costo_unitario)->toBe('0.00')
        ->and($orden->total_estimado)->toBe('0.00');
});

test('la linea generada congela el codigo del proveedor', function () {
    productoBajoMinimo(Proveedor::factory()->create(), 15000, codigo: 'AUS-9');

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::firstOrFail()->lineas->first()->codigo_proveedor)->toBe('AUS-9');
});

// ---------- Qué productos entran ----------

test('lo reservado cuenta: el critico se mide sobre el disponible', function () {
    // 10 en depósito, 8 comprometidos: disponible 2, por debajo del mínimo 5.
    Producto::factory()
        ->conStock(10, 8)
        ->conProveedor(Proveedor::factory()->create(), 15000)
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 12]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(1);
});

test('un producto con stock por encima del minimo no se pide', function () {
    Producto::factory()
        ->conStock(20)
        ->conProveedor(Proveedor::factory()->create(), 15000)
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 12]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(0);
});

test('un producto inactivo no se pide', function () {
    productoBajoMinimo(Proveedor::factory()->create(), sobreescribir: ['activo' => false]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(0);
});

test('un producto sin minimo declarado no se pide aunque este en cero', function () {
    // Mínimo cero significa «este producto no se repone solo». stockCritico() por sí
    // solo lo daría por crítico, porque 0 <= 0: es la razón de reponibles().
    productoBajoMinimo(
        Proveedor::factory()->create(),
        sobreescribir: ['stock_minimo' => 0, 'cantidad_reposicion' => 0],
    );

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(0);
});

// ---------- Los que no se le pueden pedir a nadie ----------

test('un producto sin ningun proveedor no se pide y se informa', function () {
    productoBajoMinimo(sobreescribir: ['codigo' => 'SIN-001']);

    $this->artisan('compras:generar-reposicion')
        ->expectsOutputToContain('SIN-001')
        ->assertSuccessful();

    expect(OrdenCompra::count())->toBe(0);
});

test('un producto con todos sus proveedores inactivos no se pide y se informa', function () {
    productoBajoMinimo(
        Proveedor::factory()->inactivo()->create(),
        sobreescribir: ['codigo' => 'BAJA-001'],
    );

    $this->artisan('compras:generar-reposicion')
        ->expectsOutputToContain('BAJA-001')
        ->assertSuccessful();

    // A un proveedor dado de baja no se le manda un pedido automático.
    expect(OrdenCompra::count())->toBe(0);
});

// ---------- No pedir dos veces lo mismo ----------

test('un producto que ya esta en una orden abierta no se vuelve a pedir', function (string $estado) {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoBajoMinimo($proveedor);

    // El dataset ES la constante del modelo: si mañana se agrega un estado abierto,
    // este test lo cubre solo, y el match falla ruidosamente si nadie le enseñó a
    // construirlo.
    $factory = match ($estado) {
        'borrador'         => OrdenCompra::factory(),
        'aprobada'         => OrdenCompra::factory()->aprobada(),
        'enviada'          => OrdenCompra::factory()->enviada(),
        'recibida_parcial' => OrdenCompra::factory()->recibidaParcial(),
    };

    $factory->conLinea($producto, 10)->create(['proveedor_id' => $proveedor->id]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    // La que ya existía, y ninguna nueva.
    expect(OrdenCompra::count())->toBe(1);
})->with(OrdenCompra::ESTADOS_ABIERTOS);

test('un producto que estuvo en una orden cerrada se vuelve a pedir', function (string $estado) {
    $proveedor = Proveedor::factory()->create();
    $producto  = productoBajoMinimo($proveedor);

    $factory = match ($estado) {
        'recibida'  => OrdenCompra::factory()->recibida(),
        'cancelada' => OrdenCompra::factory()->cancelada(),
    };

    $factory->conLinea($producto, 10)->create(['proveedor_id' => $proveedor->id]);

    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    // Una compra terminada no bloquea la próxima: si no, un producto comprado una
    // vez nunca se repondría de nuevo.
    expect(OrdenCompra::count())->toBe(2)
        ->and(OrdenCompra::where('estado', 'borrador')->firstOrFail()->lineas)->toHaveCount(1);
})->with(['recibida', 'cancelada']);

test('dos corridas seguidas no duplican el pedido', function () {
    productoBajoMinimo(Proveedor::factory()->create());

    $this->artisan('compras:generar-reposicion')->assertSuccessful();
    $this->artisan('compras:generar-reposicion')->assertSuccessful();

    expect(OrdenCompra::count())->toBe(1);
});

test('sin productos bajo el minimo no genera nada y lo dice', function () {
    Producto::factory()
        ->conStock(50)
        ->conProveedor(Proveedor::factory()->create(), 15000)
        ->create(['stock_minimo' => 5, 'cantidad_reposicion' => 12]);

    $this->artisan('compras:generar-reposicion')
        ->expectsOutputToContain('No hay productos')
        ->assertSuccessful();

    expect(OrdenCompra::count())->toBe(0);
});