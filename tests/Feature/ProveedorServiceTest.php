<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProveedorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ProveedorService::class);
});

/*
|--------------------------------------------------------------------------
| Reglas de negocio de proveedores
|--------------------------------------------------------------------------
|
| El servicio se prueba sin HTTP: recibe datos y devuelve modelos. La validación
| y los permisos se prueban sobre HTTP en ProveedorModuloTest, que es el otro
| nivel del patrón.
|
*/

test('el alta guarda los datos del proveedor', function () {
    $proveedor = $this->service->crear(datosDeProveedor());

    expect($proveedor->razon_social)->toBe('Distribuidora Austral S.A.')
        ->and($proveedor->cuit)->toBe('30712345678')
        ->and($proveedor->canal_pedido)->toBe('manual')
        // El cast integer: sin él, MariaDB devolvería el SMALLINT como string.
        ->and($proveedor->plazo_entrega_dias)->toBe(7)
        ->and($proveedor->activo)->toBeTrue();
});

test('la direccion del portal se guarda cuando el canal es el portal', function () {
    $proveedor = $this->service->crear(datosDeProveedor([
        'canal_pedido' => 'portal_externo',
        'portal_url'   => 'https://pedidos.austral.test',
    ]));

    expect($proveedor->portal_url)->toBe('https://pedidos.austral.test');
});

test('la direccion del portal no sobrevive a un canal que no es el portal', function () {
    // En el alta: aunque la petición la traiga, no se guarda. El Form Request ya
    // la rechaza; esto prueba la segunda barrera, la que protege a la API.
    $alta = $this->service->crear(datosDeProveedor([
        'canal_pedido' => 'email',
        'portal_url'   => 'https://no-corresponde.test',
    ]));

    // Y en la edición: el proveedor que deja de operar por portal se queda sin
    // dirección, en vez de conservar un enlace que la pantalla no debe ofrecer.
    $proveedor = Proveedor::factory()->porPortal()->create();
    $edicion   = $this->service->actualizar($proveedor, datosDeProveedor([
        'cuit'         => $proveedor->cuit,
        'canal_pedido' => 'manual',
        'portal_url'   => 'https://quedo-colgada.test',
    ]));

    expect($alta->portal_url)->toBeNull()
        ->and($edicion->portal_url)->toBeNull();
});

test('desactivar un proveedor no desactiva sus productos', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create(['proveedor_id' => $proveedor->id]);

    $this->service->actualizar($proveedor, datosDeProveedor([
        'cuit'   => $proveedor->cuit,
        'activo' => false,
    ]));

    // Que se te caiga un proveedor no significa que dejes de vender lo que ya
    // tenés en depósito.
    expect($producto->fresh()->activo)->toBeTrue();
});

test('con la opcion, desactivar el proveedor desactiva sus productos', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create(['proveedor_id' => $proveedor->id]);
    $ajeno     = Producto::factory()->create();

    $this->service->actualizar($proveedor, datosDeProveedor([
        'cuit'   => $proveedor->cuit,
        'activo' => false,
    ]), desactivarProductos: true);

    expect($producto->fresh()->activo)->toBeFalse()
        ->and($ajeno->fresh()->activo)->toBeTrue();
});

test('la cascada no se aplica si el proveedor sigue activo', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create(['proveedor_id' => $proveedor->id]);

    // Marcar la opción sin desactivar al proveedor no desactiva nada: desactivar
    // no es un efecto que se dispare por tener el checkbox puesto.
    $this->service->actualizar($proveedor, datosDeProveedor([
        'cuit'   => $proveedor->cuit,
        'activo' => true,
    ]), desactivarProductos: true);

    expect($producto->fresh()->activo)->toBeTrue();
});

test('un proveedor que nadie referencia se elimina', function () {
    $proveedor = Proveedor::factory()->create();

    expect($this->service->eliminar($proveedor))->toBeTrue();

    $this->assertModelMissing($proveedor);
});

test('un proveedor con productos se desactiva en lugar de borrarse', function () {
    $proveedor = Proveedor::factory()->create();
    Producto::factory()->create(['proveedor_id' => $proveedor->id]);

    // productos.proveedor_id es nullOnDelete: sin el chequeo, el producto
    // quedaría sin proveedor en silencio y nadie sabría a quién reponerle.
    expect($this->service->eliminar($proveedor))->toBeFalse();

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});

test('un proveedor con ordenes de compra se desactiva en lugar de borrarse', function () {
    $proveedor = Proveedor::factory()->create();
    OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);

    // ordenes_compra.proveedor_id RESTRINGE: sin el chequeo, el usuario recibiría
    // un error de integridad de MariaDB en vez de un mensaje (M-30).
    expect($this->service->eliminar($proveedor))->toBeFalse();

    $this->assertModelExists($proveedor);
    expect($proveedor->fresh()->activo)->toBeFalse();
});