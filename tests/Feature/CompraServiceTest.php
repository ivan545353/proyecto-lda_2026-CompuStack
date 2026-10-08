<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\TransicionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\CompraService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->service = app(CompraService::class);
});

// ---------- Alta y edición del borrador ----------

test('el alta crea la orden en borrador con sus lineas y el total calculado', function () {
    $proveedor = Proveedor::factory()->create();
    $uno  = Producto::factory()->create();
    $otro = Producto::factory()->create();
    $usuario = admin();

    $orden = $this->service->crearBorrador(datosDeOrdenCompra($proveedor, [
        lineaDeOrden($uno, 4, 25000),
        lineaDeOrden($otro, 2, 10000),
    ]), $usuario);

    expect($orden->estado)->toBe('borrador')
        ->and($orden->lineas)->toHaveCount(2)
        ->and($orden->usuario_creo_id)->toBe($usuario->id)
        // 4 × 25.000 + 2 × 10.000
        ->and($orden->total_estimado)->toBe('120000.00')
        ->and($orden->numeroFormateado())->toBe('OC-'.str_pad((string) $orden->id, 5, '0', STR_PAD_LEFT));
});

test('el alta sin lineas se rechaza', function () {
    $proveedor = Proveedor::factory()->create();

    expect(fn () => $this->service->crearBorrador(datosDeOrdenCompra($proveedor, []), admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect(OrdenCompra::count())->toBe(0);
});

test('el alta con el mismo producto dos veces se rechaza en vez de fusionarse', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create();

    // Fusionar obligaría a descartar uno de los dos costos en silencio, que es
    // M-31 con otra ropa. Y la base lo impide igual con su UNIQUE.
    expect(fn () => $this->service->crearBorrador(datosDeOrdenCompra($proveedor, [
        lineaDeOrden($producto, 4, 25000),
        lineaDeOrden($producto, 2, 30000),
    ]), admin()))->toThrow(ReglaDeNegocioException::class);

    expect(OrdenCompra::count())->toBe(0);
});

test('un alta sin usuario queda marcada como generada por el sistema', function () {
    $proveedor = Proveedor::factory()->create();

    $orden = $this->service->crearBorrador(
        datosDeOrdenCompra($proveedor, [lineaDeOrden(Producto::factory()->create())]),
    );

    // null no es un dato faltante: SIGNIFICA que la generó la tarea de reposición.
    expect($orden->usuario_creo_id)->toBeNull()
        ->and($orden->fueGeneradaPorElSistema())->toBeTrue();
});

test('editar un borrador reemplaza las lineas y recalcula el total', function () {
    $proveedor = Proveedor::factory()->create();
    $viejo = Producto::factory()->create();
    $nuevo = Producto::factory()->create();

    $orden = $this->service->crearBorrador(
        datosDeOrdenCompra($proveedor, [lineaDeOrden($viejo, 4, 25000)]),
        admin(),
    );

    $orden = $this->service->actualizarBorrador($orden, datosDeOrdenCompra($proveedor, [
        lineaDeOrden($nuevo, 3, 5000),
    ]));

    // En borrador ninguna línea tiene cantidad recibida, así que reemplazarlas no
    // pierde nada. Es la misma técnica que el SaleDao original usaba mal.
    expect($orden->lineas)->toHaveCount(1)
        ->and($orden->lineas->first()->producto_id)->toBe($nuevo->id)
        ->and($orden->total_estimado)->toBe('15000.00');
});

test('una orden aprobada ya no se puede editar', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create();
    $orden     = OrdenCompra::factory()->aprobada()->conLinea($producto, 5)->create();

    expect(fn () => $this->service->actualizarBorrador(
        $orden,
        datosDeOrdenCompra($proveedor, [lineaDeOrden($producto, 99)]),
    ))->toThrow(ReglaDeNegocioException::class);

    expect($orden->fresh()->lineas->first()->cantidad_pedida)->toBe(5);
});

// ---------- Aprobación y envío ----------

test('aprobar registra quien y cuando, y recalcula el total', function () {
    $producto = Producto::factory()->create();
    $orden    = OrdenCompra::factory()->conLinea($producto, 4, 25000)->create();
    $usuario  = admin();

    $orden = $this->service->aprobar($orden, $usuario);

    expect($orden->estado)->toBe('aprobada')
        ->and($orden->usuario_aprobo_id)->toBe($usuario->id)
        ->and($orden->fecha_aprobacion)->not->toBeNull()
        ->and($orden->total_estimado)->toBe('100000.00');
});

test('aprobar una orden sin productos se rechaza', function () {
    $orden = OrdenCompra::factory()->create();

    expect(fn () => $this->service->aprobar($orden, admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($orden->fresh()->estado)->toBe('borrador');
});

test('puede aprobar quien creo la orden, y las dos identidades quedan grabadas', function () {
    $usuario  = admin();
    $producto = Producto::factory()->create();

    $orden = $this->service->crearBorrador(
        datosDeOrdenCompra(Proveedor::factory()->create(), [lineaDeOrden($producto)]),
        $usuario,
    );

    $orden = $this->service->aprobar($orden, $usuario);

    // No se prohíbe: en un negocio con un solo administrativo es el único camino.
    // El control es que se puede auditar, porque las dos columnas están grabadas.
    expect($orden->usuario_creo_id)->toBe($usuario->id)
        ->and($orden->usuario_aprobo_id)->toBe($usuario->id);
});

test('un borrador no se puede marcar como enviado sin aprobar', function () {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 5)->create();

    expect(fn () => $this->service->marcarEnviada($orden))
        ->toThrow(TransicionInvalidaException::class);

    expect($orden->fresh()->estado)->toBe('borrador')
        ->and($orden->fresh()->fecha_envio)->toBeNull();
});

test('marcar enviada guarda la fecha', function () {
    $orden = OrdenCompra::factory()->aprobada()->conLinea(Producto::factory()->create(), 5)->create();

    $orden = $this->service->marcarEnviada($orden);

    expect($orden->estado)->toBe('enviada')
        ->and($orden->fecha_envio)->not->toBeNull();
});

// ---------- Recepción ----------

test('recibir parte de lo pedido ingresa stock, deja el kardex y la orden parcial', function () {
    $producto = Producto::factory()->conStockYCosto(10, 100)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 200)->create();
    $linea    = $orden->lineas->first();
    $usuario  = admin();

    $orden = $this->service->recibir($orden, [$linea->id => 4], $usuario);

    expect($orden->estado)->toBe('recibida_parcial')
        ->and($orden->lineas->first()->cantidad_recibida)->toBe(4)
        ->and($producto->fresh()->stock)->toBe(14);

    // Toda variación de stock deja movimiento, y su origen es esta orden: es lo
    // que permite responder «¿de dónde salieron estas 4 unidades?».
    $movimiento = MovimientoStock::sole();

    expect($movimiento->tipo)->toBe('compra')
        ->and($movimiento->cantidad)->toBe(4)
        ->and($movimiento->origen_type)->toBe($orden->getMorphClass())
        ->and($movimiento->origen_id)->toBe($orden->id)
        ->and($movimiento->usuario_id)->toBe($usuario->id);
});

test('recibir todo lo pendiente deja la orden recibida', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 6, 500)->create();
    $linea    = $orden->lineas->first();

    $orden = $this->service->recibir($orden, [$linea->id => 6], admin());

    expect($orden->estado)->toBe('recibida')
        ->and($orden->tienePendientes())->toBeFalse();
});

test('la recepcion recalcula el costo promedio ponderado del producto', function () {
    // 10 unidades a $100 más 10 a $200 = 20 a $150.
    $producto = Producto::factory()->conStockYCosto(10, 100)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 200)->create();

    $this->service->recibir($orden, [$orden->lineas->first()->id => 10], admin());

    expect($producto->fresh()->costo_promedio)->toBe('150.00');
});

test('recibir mas de lo pendiente se rechaza y no mueve el stock', function () {
    $producto = Producto::factory()->conStockYCosto(5, 100)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 200)->create();
    $linea    = $orden->lineas->first();

    // El proveedor mandó 12 de las 10 pedidas: la diferencia es una decisión
    // humana, no algo que el sistema acepte en silencio.
    expect(fn () => $this->service->recibir($orden, [$linea->id => 12], admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(5)
        ->and(MovimientoStock::count())->toBe(0)
        ->and($orden->fresh()->estado)->toBe('enviada');
});

test('recibir contra la linea de otra orden responde 404', function () {
    $ajena = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();
    $propia = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    // La línea existe, pero no en esta orden. Buscarla por su id suelto permitiría
    // recibir mercadería contra la orden de otro proveedor.
    expect(fn () => $this->service->recibir($propia, [$ajena->lineas->first()->id => 1], admin()))
        ->toThrow(ModelNotFoundException::class);
});

test('se puede recibir una orden aprobada que nadie marco como enviada', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->aprobada()->conLinea($producto, 5, 300)->create();

    // La mercadería está en la puerta: bloquear la recepción por un clic que faltó
    // sería negarse a registrar un hecho. Que nunca se marcó queda en fecha_envio.
    $orden = $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin());

    expect($orden->estado)->toBe('recibida')
        ->and($orden->fecha_envio)->toBeNull()
        ->and($producto->fresh()->stock)->toBe(5);
});

test('una orden cancelada no puede recibir mercaderia', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->cancelada()->conLinea($producto, 5)->create();

    expect(fn () => $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin()))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock)->toBe(0);
});

test('una recepcion sin ninguna unidad se rechaza', function () {
    $orden = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    expect(fn () => $this->service->recibir($orden, [$orden->lineas->first()->id => 0], admin()))
        ->toThrow(ReglaDeNegocioException::class);
});

// ---------- Cierre y cancelación ----------

test('cerrar una orden incompleta la deja recibida con su saldo a la vista', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 500)->create();

    $orden = $this->service->recibir($orden, [$orden->lineas->first()->id => 3], admin());
    $orden = $this->service->cerrarIncompleta($orden);

    // «recibida» significa cerrada; cuánto llegó lo dicen las líneas.
    expect($orden->estado)->toBe('recibida')
        ->and($orden->lineas->first()->cantidad_recibida)->toBe(3)
        ->and($orden->lineas->first()->cantidadPendiente())->toBe(7);
});

test('no se cierra como incompleta una orden donde no entro nada', function () {
    $orden = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    // Si no llegó nada, lo que corresponde es cancelarla, y el mensaje lo dice.
    expect(fn () => $this->service->cerrarIncompleta($orden))
        ->toThrow(ReglaDeNegocioException::class);

    expect($orden->fresh()->estado)->toBe('enviada');
});

test('no se cierra como incompleta una orden que ya llego completa', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 5, 100)->create();

    $orden = $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin());

    expect(fn () => $this->service->cerrarIncompleta($orden))
        ->toThrow(ReglaDeNegocioException::class);
});

test('se puede cancelar desde borrador y desde enviada', function () {
    $borrador = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 5)->create();
    $enviada  = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 5)->create();

    expect($this->service->cancelar($borrador)->estado)->toBe('cancelada')
        ->and($this->service->cancelar($enviada)->estado)->toBe('cancelada');
});

test('no se puede cancelar una orden con mercaderia recibida', function () {
    $producto = Producto::factory()->conStockYCosto(0, 0)->create();
    $orden    = OrdenCompra::factory()->enviada()->conLinea($producto, 10, 100)->create();

    $orden = $this->service->recibir($orden, [$orden->lineas->first()->id => 3], admin());

    // No hay ningún `if` que lo prohíba: la tabla de transiciones ya no lo declara.
    expect(fn () => $this->service->cancelar($orden))
        ->toThrow(TransicionInvalidaException::class);

    expect($orden->fresh()->estado)->toBe('recibida_parcial')
        ->and($producto->fresh()->stock)->toBe(3);
});

// ---------- La columna que no se escribe en masa ----------

test('el estado no se puede escribir por asignacion masiva', function () {
    $orden = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 5)->create();

    // `estado` está fuera de $fillable, así que un update() lo descarta EN
    // SILENCIO. Por eso el servicio lo asigna por propiedad y siempre a través de
    // cambiarEstado(), que valida la transición primero.
    $orden->update(['estado' => 'recibida', 'total_estimado' => 999]);

    expect($orden->fresh()->estado)->toBe('borrador')
        ->and($orden->fresh()->total_estimado)->not->toBe('999.00');
});

// ---------- La reposición automática ----------

test('el borrador automatico toma el ultimo costo de ESE proveedor', function () {
    $barato = Proveedor::factory()->create();
    $caro   = Proveedor::factory()->create();

    $producto = Producto::factory()
        ->conProveedor($barato, costo: 10000)
        ->conProveedor($caro, costo: 12000)
        ->create(['cantidad_reposicion' => 7]);

    $orden = $this->service->generarBorrador($caro->id, [$producto]);

    // 12.000: lo que cobra el proveedor al que se le está pidiendo. Ni los 10.000
    // del otro, ni el promedio ponderado del producto.
    expect($orden->lineas)->toHaveCount(1)
        ->and($orden->lineas->first()->costo_unitario)->toBe('12000.00')
        ->and($orden->lineas->first()->cantidad_pedida)->toBe(7)
        ->and($orden->proveedor_id)->toBe($caro->id)
        ->and($orden->fueGeneradaPorElSistema())->toBeTrue();
});

test('el borrador automatico deja el costo en cero si ese proveedor no tiene costo cargado', function () {
    $otro     = Proveedor::factory()->create();
    $sinCosto = Proveedor::factory()->create();

    // Con costo promedio distinto de cero, para que el cero del resultado no pueda
    // venir de ahí por casualidad.
    $producto = Producto::factory()
        ->conStockYCosto(5, 9999)
        ->conProveedor($otro, costo: 10000)
        ->conProveedor($sinCosto, costo: null)
        ->create(['cantidad_reposicion' => 3]);

    $orden = $this->service->generarBorrador($sinCosto->id, [$producto]);

    // Cero y no 9.999 ni 10.000: un número que parece un precio acordado y no lo
    // es se aprueba sin mirar. El cero se nota antes de aprobar.
    expect($orden->lineas->first()->costo_unitario)->toBe('0.00');
});

// ---------- La recepción mantiene la comparación de precios ----------

test('la recepcion graba el costo en el vinculo con el proveedor de la orden', function () {
    $proveedor = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    $producto = Producto::factory()
        ->conProveedor($proveedor, costo: 10000)
        ->conProveedor($otro, costo: 11000)
        ->create();

    $orden = OrdenCompra::factory()->aprobada()
        ->conLinea($producto, 5, 12500)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->service->recibir($orden, [$orden->lineas->first()->id => 5], admin());

    $vinculos = $producto->fresh()->proveedores;

    // Se actualiza SÓLO el del proveedor de la orden. El vínculo del otro no
    // participó de esta compra y su costo sigue siendo el que era.
    expect((float) $vinculos->firstWhere('id', $proveedor->id)->pivot->costo_ultimo)->toBe(12500.0)
        ->and((float) $vinculos->firstWhere('id', $otro->id)->pivot->costo_ultimo)->toBe(11000.0);
});

test('la recepcion no crea el vinculo si el proveedor ya no provee el producto', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->create();   // sin ningún vínculo

    $orden = OrdenCompra::factory()->aprobada()
        ->conLinea($producto, 2, 8000)
        ->create(['proveedor_id' => $proveedor->id]);

    $this->service->recibir($orden, [$orden->lineas->first()->id => 2], admin());

    // Se desvinculó después de hacer la orden, que es un caso posible. La
    // mercadería entra igual: el kardex no depende de la pivote.
    expect($producto->fresh()->proveedores()->count())->toBe(0)
        ->and($producto->fresh()->stock)->toBe(2);
});