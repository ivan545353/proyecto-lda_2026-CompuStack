<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El guion de la defensa (tramo K), tal como lo recorre la colección
|--------------------------------------------------------------------------
|
| docs/demostracion/api-etapa-3.http, bloque «Tramo K»: la compra completa por la API y la
| operación que el servidor rechaza cuando el rol pierde el permiso. Si este archivo pasa, el
| guion se puede hacer sin tocar código. El checkout de Mercado Pago de prueba (D-23) lo
| reemplaza acá el simulador, que resuelve el intento por el mismo camino (D-13).
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    config(['tienda.pagos.simulador' => true]);

    $this->producto = Producto::factory()->conStockYCosto(10, 30000)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'codigo'         => 'G505-GUION',
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        'alicuota_iva'   => 21,
    ]);

    $this->cliente = User::factory()->create(['rol_id' => Rol::where('nombre', 'Cliente')->value('id')]);
    Cliente::factory()->create(['user_id' => $this->cliente->id]);

    // Cada petición con otra credencial empieza sin guard en memoria, como en producción.
    $this->como = function (array $bearer): array {
        app('auth')->forgetGuards();

        return $bearer;
    };
    $this->tienda = bearer($this->cliente);
});

test('la venta completa de mostrador: cada paso lo hace la cuenta que tiene el permiso', function () {
    $vendedor       = bearer(User::factory()->conRol('Vendedor')->create());
    $cajero         = bearer(User::factory()->conRol('Cajero')->create());
    $administrativo = bearer(User::factory()->conRol('Administrativo')->create());
    $cliente        = Cliente::factory()->create(['razon_social' => 'Estudio Contable Guion S.R.L.']);

    $fila = $this->getJson('/api/v1/productos?forma=gestion&q=G505-GUION', ($this->como)($vendedor))
        ->assertOk()->assertJsonPath('datos.0.id', $this->producto->id)->json('datos.0');
    expect($fila)->not->toHaveKey('costo_promedio')->not->toHaveKey('stock');
    expect($this->getJson("/api/v1/productos/{$this->producto->id}?forma=gestion", ($this->como)($administrativo))->json('datos'))
        ->toHaveKey('costo_promedio')->toHaveKey('stock');

    $this->getJson('/api/v1/clientes?q=Estudio Contable Guion', ($this->como)($vendedor))
        ->assertOk()->assertJsonPath('datos.0.id', $cliente->id);

    $venta = $this->postJson('/api/v1/ventas', [
        'cliente_id' => $cliente->id, 'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
    ], ($this->como)($vendedor))->assertCreated()->assertJsonPath('datos.estado', 'presupuesto')->json('datos');
    $pago = ['pagos' => [['metodo' => 'efectivo', 'monto' => $venta['importes']['saldo']]]];

    $this->postJson("/api/v1/ventas/{$venta['id']}/pagos", $pago, ($this->como)($vendedor))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    $this->postJson("/api/v1/ventas/{$venta['id']}/pagos", $pago, ($this->como)($cajero))
        ->assertCreated()->assertJsonPath('datos.estado', 'pagada')->assertJsonPath('datos.importes.saldo', '0.00');
    $this->postJson("/api/v1/ventas/{$venta['id']}/entregas", [], ($this->como)($cajero))
        ->assertCreated()->assertJsonPath('datos.estado', 'entregada');

    $url = $this->getJson("/api/v1/ventas/{$venta['id']}/pdf", ($this->como)($cajero))->assertOk()->json('datos.url');
    app('auth')->forgetGuards();
    expect($this->get($url)->assertOk()->headers->get('Content-Type'))->toContain('application/pdf');
    $this->get($url.'0')->assertForbidden();

    $this->getJson("/api/v1/stock/movimientos?producto_id={$this->producto->id}", ($this->como)($administrativo))
        ->assertOk()->assertJsonPath('datos.0.tipo', 'venta')->assertJsonPath('datos.0.cantidad', -1);
    expect($this->producto->fresh()->stock)->toBe(9);
});

test('la compra completa por la API: carrito, checkout, pedido y pago con el simulador', function () {
    $tienda = ($this->como)($this->tienda);

    $this->deleteJson('/api/v1/carrito', [], $tienda)->assertOk();
    $this->getJson('/api/v1/productos?q=G505-GUION', $tienda)
        ->assertOk()
        ->assertJsonPath('datos.0.id', $this->producto->id);

    $this->postJson('/api/v1/carrito/lineas', ['producto_id' => $this->producto->id, 'cantidad' => 1], $tienda)
        ->assertSuccessful();
    $carritoId = $this->postJson('/api/v1/carrito/checkout', [], $tienda)->assertOk()->json('datos.id');

    $pedido = $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $carritoId, 'medio_pago' => 'pasarela', 'modo_entrega' => 'retiro',
    ], $tienda)->assertCreated()->assertJsonPath('datos.estado', 'pendiente_pago')->json('datos.id');

    $intento = $this->postJson("/api/v1/pedidos/{$pedido}/pagos", ['pasarela' => 'simulador'], $tienda)
        ->assertCreated()->json('datos.id');
    $this->postJson("/api/v1/simulador/intentos/{$intento}/aprobar", [], $tienda)->assertOk();

    $this->getJson("/api/v1/pedidos/{$pedido}", $tienda)->assertOk()->assertJsonPath('datos.estado', 'pagada');
    expect($this->producto->fresh()->stock)->toBe(9);

    // La administración ve la misma venta por su endpoint y con su permiso.
    $this->getJson("/api/v1/ventas/{$pedido}", ($this->como)(bearer(usuarioCon('venta.ver'))))
        ->assertOk()
        ->assertJsonPath('datos.canal', 'online')
        ->assertJsonPath('datos.estado', 'pagada');
});

test('quitado tienda.comprar al rol Cliente, la misma credencial recibe 403 y vuelve a comprar al devolverlo', function () {
    $rol         = Rol::where('nombre', 'Cliente')->firstOrFail();
    $descripcion = $rol->descripcion;
    $todos       = $rol->permisos()->pluck('permisos.id')->all();
    $comprar     = Permiso::where('clave', 'tienda.comprar')->value('id');
    $admin       = bearer(admin());

    $this->getJson('/api/v1/carrito', ($this->como)($this->tienda))->assertOk();

    $this->patchJson("/api/v1/roles/{$rol->id}", [
        'descripcion' => $descripcion, 'permisos' => array_values(array_diff($todos, [$comprar])),
    ], ($this->como)($admin))->assertOk();

    $this->getJson('/api/v1/carrito', ($this->como)($this->tienda))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
    $this->getJson('/api/v1/pedidos', ($this->como)($this->tienda))->assertOk();

    $this->patchJson("/api/v1/roles/{$rol->id}", ['descripcion' => $descripcion, 'permisos' => $todos], ($this->como)($admin))
        ->assertOk();

    $this->getJson('/api/v1/carrito', ($this->como)($this->tienda))->assertOk();
    expect($rol->fresh()->descripcion)->toBe($descripcion);
});
