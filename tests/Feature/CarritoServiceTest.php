<?php

use App\Exceptions\CarritoCambioException;
use App\Exceptions\CheckoutVencidoException;
use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Carrito;
use App\Models\CarritoLinea;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Services\CarritoService;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * El carrito en el servidor (tramo E): escritura, resumen y la reserva por StockService.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->carritos = app(CarritoService::class);

    $this->tiendaCuenta = fn () => User::factory()->conRol('Cliente')->create();

    $this->tiendaProducto = fn (int $stock = 10, array $datos = []) => Producto::factory()->conStock($stock)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '1000.00',
        'precio_lista'   => '1200.00',
        ...$datos,
    ]);

    // Un carrito en checkout armado a mano, con su reserva tomada por la única puerta.
    $this->tiendaEnCheckout = function (User $cuenta, Producto $producto, int $cantidad): Carrito {
        $this->carritos->agregar($cuenta, $producto->id, $cantidad);
        $carrito = $this->carritos->delUsuario($cuenta);
        app(StockService::class)->reservar($producto, $cantidad);
        $carrito->estado          = 'en_checkout';
        $carrito->reservado_hasta = now()->addMinutes(20);
        $carrito->save();

        return $carrito;
    };
});

// Confirma el checkout de la cuenta, de contado y con retiro; le crea el cliente si no lo tiene.
beforeEach(function () {
    $this->tiendaConfirmar = function (User $cuenta, array $datos = []): array {
        if ($cuenta->cliente()->doesntExist()) {
            Cliente::factory()->create(['user_id' => $cuenta->id]);
        }

        return $this->carritos->confirmar($cuenta, [
            'carrito_id'   => $this->carritos->paraLeer($cuenta)->id,
            'medio_pago'   => 'contado',
            'modo_entrega' => 'retiro',
            ...$datos,
        ]);
    };
});

// ---------- delUsuario() y paraLeer() ----------

test('el usuario tiene un solo carrito vigente', function () {
    $cuenta = ($this->tiendaCuenta)();

    $primero = $this->carritos->delUsuario($cuenta);
    $segundo = $this->carritos->delUsuario($cuenta);

    expect($segundo->id)->toBe($primero->id)
        ->and(Carrito::where('user_id', $cuenta->id)->count())->toBe(1);
});

test('un carrito convertido no se reusa: el siguiente es nuevo', function () {
    $cuenta  = ($this->tiendaCuenta)();
    $viejo   = $this->carritos->delUsuario($cuenta);
    $viejo->estado = 'convertido';
    $viejo->save();

    expect($this->carritos->delUsuario($cuenta)->id)->not->toBe($viejo->id);
});

test('leer el carrito sin tener uno no crea nada', function () {
    $cuenta = ($this->tiendaCuenta)();

    $resumen = $this->carritos->resumen($this->carritos->paraLeer($cuenta));

    expect($resumen['renglones'])->toBe([])
        ->and($resumen['totales'])->toBe(['contado' => '0.00', 'lista' => '0.00'])
        ->and(Carrito::count())->toBe(0);
});

// ---------- agregar() ----------

test('agregar crea el renglon con las referencias del precio vigente y no reserva', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();

    $linea = $this->carritos->agregar($cuenta, $producto->id, 2);

    expect($linea->cantidad)->toBe(2)
        ->and($linea->contado_referencia)->toBe('1000.00')
        ->and($linea->lista_referencia)->toBe('1200.00')
        ->and($producto->fresh()->stock_reservado)->toBe(0);
});

test('agregar un producto que ya esta suma la cantidad en el mismo renglon', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();

    $this->carritos->agregar($cuenta, $producto->id, 2);
    $this->carritos->agregar($cuenta, $producto->id, 3);

    expect(CarritoLinea::count())->toBe(1)
        ->and(CarritoLinea::first()->cantidad)->toBe(5);
});

test('agregar un producto que no esta en la tienda es no encontrado', function (string $caso) {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = match ($caso) {
        'inactivo'          => ($this->tiendaProducto)(datos: ['activo' => false]),
        'categoria oculta'  => ($this->tiendaProducto)(datos: ['categoria_id' => Categoria::factory()->create(['activo' => false])->id]),
        'inexistente'       => new Producto(['id' => 999999]),
    };

    expect(fn () => $this->carritos->agregar($cuenta, $producto->id ?? 999999, 1))
        ->toThrow(ModelNotFoundException::class);
})->with(['inactivo', 'categoria oculta', 'inexistente']);

test('agregar sin disponible suficiente lanza stock insuficiente y no escribe', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = Producto::factory()->conStock(5, 4)->create(['categoria_id' => Categoria::factory()->create()->id]);

    expect(fn () => $this->carritos->agregar($cuenta, $producto->id, 2))
        ->toThrow(StockInsuficienteException::class);

    expect(CarritoLinea::count())->toBe(0);
});

test('agregar respeta la cantidad maxima por renglon sumando lo que ya estaba', function () {
    config(['tienda.carrito.cantidad_maxima' => 4]);
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();

    $this->carritos->agregar($cuenta, $producto->id, 3);

    expect(fn () => $this->carritos->agregar($cuenta, $producto->id, 2))
        ->toThrow(ReglaDeNegocioException::class, 'hasta 4 unidades');

    expect(CarritoLinea::first()->cantidad)->toBe(3);
});

test('agregar de nuevo actualiza las referencias al precio vigente', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 1);

    $producto->update(['precio_contado' => '1100.00']);
    $this->carritos->agregar($cuenta, $producto->id, 1);

    expect(CarritoLinea::first()->contado_referencia)->toBe('1100.00');
});

// ---------- cambiarCantidad(), quitar(), vaciar() ----------

test('cambiar la cantidad la fija y valida contra el disponible', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(5);
    $linea    = $this->carritos->agregar($cuenta, $producto->id, 1);

    $this->carritos->cambiarCantidad($cuenta, $linea->id, 4);
    expect($linea->fresh()->cantidad)->toBe(4);

    expect(fn () => $this->carritos->cambiarCantidad($cuenta, $linea->id, 6))
        ->toThrow(StockInsuficienteException::class);
    expect($linea->fresh()->cantidad)->toBe(4);
});

test('cambiar la cantidad de un producto que salio de la tienda se rechaza', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $linea    = $this->carritos->agregar($cuenta, $producto->id, 1);
    $producto->update(['activo' => false]);

    expect(fn () => $this->carritos->cambiarCantidad($cuenta, $linea->id, 2))
        ->toThrow(ReglaDeNegocioException::class, 'ya no está en la tienda');
});

test('un renglon de otro usuario es no encontrado para cambiar y quitar', function () {
    $duena    = ($this->tiendaCuenta)();
    $otra     = ($this->tiendaCuenta)();
    $linea    = $this->carritos->agregar($duena, ($this->tiendaProducto)()->id, 2);

    expect(fn () => $this->carritos->cambiarCantidad($otra, $linea->id, 1))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->carritos->quitar($otra, $linea->id))->toThrow(ModelNotFoundException::class);

    expect($linea->fresh()->cantidad)->toBe(2);
});

test('quitar saca el renglon y vaciar saca todos, sin borrar el carrito', function () {
    $cuenta = ($this->tiendaCuenta)();
    $a = $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $this->carritos->quitar($cuenta, $a->id);
    expect(CarritoLinea::count())->toBe(2);

    $this->carritos->vaciar($cuenta);
    expect(CarritoLinea::count())->toBe(0)
        ->and(Carrito::where('user_id', $cuenta->id)->count())->toBe(1);
});

test('escribir en un carrito en checkout libera la reserva y vuelve a activo', function (string $operacion) {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $carrito  = ($this->tiendaEnCheckout)($cuenta, $producto, 3);
    $linea    = $carrito->lineas()->first();
    expect($producto->fresh()->stock_reservado)->toBe(3);

    match ($operacion) {
        'agregar' => $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1),
        'cambiar' => $this->carritos->cambiarCantidad($cuenta, $linea->id, 2),
        'quitar'  => $this->carritos->quitar($cuenta, $linea->id),
        'vaciar'  => $this->carritos->vaciar($cuenta),
    };

    $carrito->refresh();
    expect($producto->fresh()->stock_reservado)->toBe(0)
        ->and($carrito->estado)->toBe('activo')
        ->and($carrito->reservado_hasta)->toBeNull();
})->with(['agregar', 'cambiar', 'quitar', 'vaciar']);

// ---------- resumen() ----------

test('el resumen trae los dos importes y los totales con los precios vigentes', function () {
    $cuenta = ($this->tiendaCuenta)();
    $a = ($this->tiendaProducto)(datos: ['precio_contado' => '1000.50', 'precio_lista' => '1200.10']);
    $b = ($this->tiendaProducto)(datos: ['precio_contado' => '250.00', 'precio_lista' => '300.00']);
    $this->carritos->agregar($cuenta, $a->id, 3);
    $this->carritos->agregar($cuenta, $b->id, 1);

    // El precio sube después de agregar: el resumen usa el vigente, no la referencia.
    $b->update(['precio_contado' => '260.00']);

    $resumen = $this->carritos->resumen($this->carritos->paraLeer($cuenta));

    expect($resumen['renglones'][0]['unitario'])->toBe(['contado' => '1000.50', 'lista' => '1200.10'])
        ->and($resumen['renglones'][0]['subtotal'])->toBe(['contado' => '3001.50', 'lista' => '3600.30'])
        ->and($resumen['renglones'][1]['subtotal']['contado'])->toBe('260.00')
        ->and($resumen['totales'])->toBe(['contado' => '3261.50', 'lista' => '3900.30']);
});

test('el resumen avisa precio cambiado, producto fuera de la tienda y cantidad mayor que el disponible', function () {
    $cuenta  = ($this->tiendaCuenta)();
    $precio  = ($this->tiendaProducto)();
    $fuera   = ($this->tiendaProducto)();
    $escaso  = ($this->tiendaProducto)(5);
    $this->carritos->agregar($cuenta, $precio->id, 1);
    $this->carritos->agregar($cuenta, $fuera->id, 1);
    $this->carritos->agregar($cuenta, $escaso->id, 4);

    $precio->update(['precio_lista' => '1300.00']);
    $fuera->update(['activo' => false]);
    app(StockService::class)->ajustar($escaso, 2, 'Rotura en depósito', admin());

    $resumen = $this->carritos->resumen($this->carritos->paraLeer($cuenta));
    $avisos  = collect($resumen['avisos'])->keyBy('tipo');

    expect($avisos['precio'])->toBe([
        'producto_id' => $precio->id, 'descripcion' => $precio->nombre, 'tipo' => 'precio',
        'lista' => 'lista', 'anterior' => '1200.00', 'actual' => '1300.00',
    ])
        ->and($avisos['inactivo'])->toBe(['producto_id' => $fuera->id, 'descripcion' => $fuera->nombre, 'tipo' => 'inactivo'])
        ->and($avisos['disponibilidad'])->toMatchArray(['producto_id' => $escaso->id, 'pedida' => 4, 'disponibilidad' => 'ultimas_unidades'])
        // Lo que salió de la tienda no suma: sólo el de precio (1000) y el escaso (4 × 1000).
        ->and($resumen['totales']['contado'])->toBe('5000.00');
});

test('en checkout, lo reservado por el propio carrito no cuenta como faltante', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(3);
    $carrito  = ($this->tiendaEnCheckout)($cuenta, $producto, 3);

    expect($this->carritos->resumen($carrito->fresh())['avisos'])->toBe([]);
});

test('el resumen no escribe nada', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 1);
    $producto->update(['precio_contado' => '1500.00']);

    $consultas = consultasSobre('carrito_lineas', fn () => $this->carritos->resumen($this->carritos->paraLeer($cuenta)));

    // Una lectura de los renglones; ninguna escritura: la referencia sigue siendo la vieja.
    expect($consultas)->toBe(1)
        ->and(CarritoLinea::first()->contado_referencia)->toBe('1000.00');
});

test('dos usuarios no ven el carrito del otro', function () {
    $una  = ($this->tiendaCuenta)();
    $otra = ($this->tiendaCuenta)();
    $this->carritos->agregar($una, ($this->tiendaProducto)()->id, 2);

    expect($this->carritos->resumen($this->carritos->paraLeer($otra))['renglones'])->toBe([]);
});

// ---------- iniciarCheckout() ----------

test('iniciar el checkout reserva cada renglon, pasa a en checkout y fija el plazo', function () {
    $cuenta = ($this->tiendaCuenta)();
    $a = ($this->tiendaProducto)(10);
    $b = ($this->tiendaProducto)(10);
    $this->carritos->agregar($cuenta, $a->id, 2);
    $this->carritos->agregar($cuenta, $b->id, 3);

    $resumen = $this->carritos->iniciarCheckout($cuenta);

    $carrito = $resumen['carrito'];
    expect($carrito->estado)->toBe('en_checkout')
        ->and(abs($carrito->reservado_hasta->diffInSeconds(now()->addMinutes(20))))->toBeLessThan(5)
        ->and($a->fresh()->stock_reservado)->toBe(2)
        ->and($b->fresh()->stock_reservado)->toBe(3)
        ->and($a->fresh()->stock)->toBe(10)
        ->and($resumen['totales']['contado'])->toBe('5000.00');
});

test('el checkout reserva todo o nada', function () {
    $cuenta = ($this->tiendaCuenta)();
    $hay    = ($this->tiendaProducto)(10);
    $escaso = ($this->tiendaProducto)(5);
    $this->carritos->agregar($cuenta, $hay->id, 2);
    $this->carritos->agregar($cuenta, $escaso->id, 4);

    // Después de agregar, otro checkout se lleva el disponible del segundo.
    app(StockService::class)->reservar($escaso, 3);

    expect(fn () => $this->carritos->iniciarCheckout($cuenta))->toThrow(StockInsuficienteException::class);

    expect($hay->fresh()->stock_reservado)->toBe(0)
        ->and($escaso->fresh()->stock_reservado)->toBe(3)
        ->and($this->carritos->paraLeer($cuenta)->estado)->toBe('activo');
});

test('con el precio cambiado da los cambios, guarda la referencia y el segundo intento pasa', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 2);
    $producto->update(['precio_contado' => '1100.00']);

    try {
        $this->carritos->iniciarCheckout($cuenta);
        $this->fail('Tenía que avisar el cambio de precio.');
    } catch (CarritoCambioException $e) {
        expect($e->cambios)->toBe([[
            'producto_id' => $producto->id, 'descripcion' => $producto->nombre, 'tipo' => 'precio',
            'lista' => 'contado', 'anterior' => '1000.00', 'actual' => '1100.00',
        ]]);
    }

    // La referencia quedó guardada aunque la excepción salió: no hubo rollback.
    expect(CarritoLinea::first()->contado_referencia)->toBe('1100.00')
        ->and($producto->fresh()->stock_reservado)->toBe(0);

    $resumen = $this->carritos->iniciarCheckout($cuenta);

    expect($resumen['carrito']->estado)->toBe('en_checkout')
        ->and($producto->fresh()->stock_reservado)->toBe(2);
});

test('un producto que salio de la tienda frena el checkout hasta que se quite', function () {
    $cuenta = ($this->tiendaCuenta)();
    $sigue  = ($this->tiendaProducto)();
    $fuera  = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $sigue->id, 1);
    $linea = $this->carritos->agregar($cuenta, $fuera->id, 1);
    $fuera->update(['activo' => false]);

    expect(fn () => $this->carritos->iniciarCheckout($cuenta))->toThrow(CarritoCambioException::class)
        ->and(fn () => $this->carritos->iniciarCheckout($cuenta))->toThrow(CarritoCambioException::class);

    $this->carritos->quitar($cuenta, $linea->id);

    expect($this->carritos->iniciarCheckout($cuenta)['carrito']->estado)->toBe('en_checkout');
});

test('iniciar de nuevo con la reserva en plazo no reserva dos veces', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 3);

    $primero = $this->carritos->iniciarCheckout($cuenta);
    $segundo = $this->carritos->iniciarCheckout($cuenta);

    expect($producto->fresh()->stock_reservado)->toBe(3)
        ->and($segundo['carrito']->reservado_hasta->equalTo($primero['carrito']->reservado_hasta))->toBeTrue();
});

test('iniciar con la reserva vencida y sin barrer la suelta y reserva de nuevo', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 3);
    $this->carritos->iniciarCheckout($cuenta);

    $this->travel(21)->minutes();
    $resumen = $this->carritos->iniciarCheckout($cuenta);

    expect($producto->fresh()->stock_reservado)->toBe(3)
        ->and($resumen['carrito']->reservado_hasta->isFuture())->toBeTrue();
});

test('el checkout de un carrito vacio se rechaza', function () {
    expect(fn () => $this->carritos->iniciarCheckout(($this->tiendaCuenta)()))
        ->toThrow(ReglaDeNegocioException::class, 'vacío');
});

// ---------- vencimiento ----------

test('el comando suelta los checkouts vencidos y deja los que estan en plazo', function () {
    $una  = ($this->tiendaCuenta)();
    $otra = ($this->tiendaCuenta)();
    $a = ($this->tiendaProducto)();
    $b = ($this->tiendaProducto)();
    $this->carritos->agregar($una, $a->id, 2);
    $this->carritos->iniciarCheckout($una);

    $this->travel(15)->minutes();
    $this->carritos->agregar($otra, $b->id, 1);
    $this->carritos->iniciarCheckout($otra);

    $this->travel(6)->minutes();   // la primera ya venció (21 min); la segunda, no (6 min)
    $this->artisan('tienda:vencer-reservas')
        ->expectsOutputToContain('Checkouts vencidos soltados: 1.')
        ->assertSuccessful();

    expect($a->fresh()->stock_reservado)->toBe(0)
        ->and($this->carritos->paraLeer($una)->estado)->toBe('activo')
        ->and($b->fresh()->stock_reservado)->toBe(1)
        ->and($this->carritos->paraLeer($otra)->estado)->toBe('en_checkout');
});

test('soltar dos veces el mismo checkout no libera dos veces', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)();
    $this->carritos->agregar($cuenta, $producto->id, 2);
    app(StockService::class)->reservar($producto, 5);   // de otro carrito
    $this->carritos->iniciarCheckout($cuenta);

    $this->travel(21)->minutes();
    expect($this->carritos->soltarCheckoutsVencidos())->toBe(1)
        ->and($this->carritos->soltarCheckoutsVencidos())->toBe(0)
        ->and($producto->fresh()->stock_reservado)->toBe(5);
});

test('invariante: lo reservado es la suma de los checkouts vigentes y los pedidos pendientes', function () {
    // HL-4. Dos dueños posibles: el carrito en checkout y, desde el F, el pedido sin cobrar.
    $cuentas   = collect(range(1, 4))->map(fn () => ($this->tiendaCuenta)());
    $productos = collect(range(1, 3))->map(fn () => ($this->tiendaProducto)(20));

    $verificar = function () use ($productos) {
        foreach ($productos as $producto) {
            $esperado = (int) CarritoLinea::where('producto_id', $producto->id)
                ->whereHas('carrito', fn ($c) => $c->where('estado', 'en_checkout')->where('reservado_hasta', '>', now()))
                ->sum('cantidad');
            $esperado += (int) VentaLinea::where('producto_id', $producto->id)
                ->whereHas('venta', fn ($v) => $v->where('estado', 'pendiente_pago')->whereNotNull('reserva_vence'))
                ->sum('cantidad');

            expect($producto->fresh()->stock_reservado)->toBe($esperado, "Producto {$producto->id}");
        }
    };

    [$c1, $c2, $c3, $c4] = $cuentas->all();
    [$p1, $p2, $p3] = $productos->all();

    $this->carritos->agregar($c1, $p1->id, 3);
    $this->carritos->agregar($c1, $p2->id, 2);
    $this->carritos->agregar($c2, $p1->id, 4);
    $this->carritos->agregar($c3, $p3->id, 5);
    $this->carritos->agregar($c4, $p2->id, 1);
    $verificar();

    $this->carritos->iniciarCheckout($c1);
    $this->carritos->iniciarCheckout($c2);
    $this->carritos->iniciarCheckout($c3);
    $verificar();

    // c1 confirma de contado: su reserva pasa al pedido sin moverse.
    ($this->tiendaConfirmar)($c1);
    $verificar();

    $this->carritos->agregar($c2, $p3->id, 1);   // escribir suelta el checkout de c2
    $this->carritos->cambiarCantidad($c3, $this->carritos->paraLeer($c3)->lineas()->first()->id, 2);
    $verificar();

    $this->carritos->iniciarCheckout($c3);
    $this->travel(10)->minutes();
    $this->carritos->iniciarCheckout($c4);
    $this->carritos->iniciarCheckout($c2);
    $verificar();

    $this->travel(11)->minutes();   // c3 vence; c4 y c2, no; c1 ya es un pedido de 48 horas
    $this->artisan('tienda:vencer-reservas')->assertSuccessful();
    $verificar();

    $this->carritos->vaciar($c4);
    $this->travel(21)->minutes();
    $this->artisan('tienda:vencer-reservas')->assertSuccessful();
    $verificar();

    $this->travel(48)->hours();   // el pedido de c1 vence sin cobrarse
    $this->artisan('tienda:vencer-reservas')->assertSuccessful();
    $verificar();

    expect($productos->sum(fn ($p) => $p->fresh()->stock_reservado))->toBe(0);
});

// ---------- confirmar() ----------

test('confirmar convierte el carrito en un pedido que se queda con la reserva', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(10);
    $carrito  = ($this->tiendaEnCheckout)($cuenta, $producto, 3);

    ['venta' => $venta, 'creada' => $creada] = ($this->tiendaConfirmar)($cuenta);

    expect($creada)->toBeTrue()
        ->and($venta->carrito_id)->toBe($carrito->id)
        ->and($venta->cliente_id)->toBe($cuenta->cliente->id)
        ->and($carrito->fresh()->estado)->toBe('convertido')
        ->and($carrito->fresh()->reservado_hasta)->toBeNull()
        ->and($carrito->fresh()->venta->id)->toBe($venta->id)
        ->and($producto->fresh()->stock_reservado)->toBe(3);
});

test('dos confirmaciones del mismo carrito dan un solo pedido', function () {
    $cuenta = ($this->tiendaCuenta)();
    $carrito = ($this->tiendaEnCheckout)($cuenta, ($this->tiendaProducto)(), 2);

    $primera = ($this->tiendaConfirmar)($cuenta, ['carrito_id' => $carrito->id]);
    $segunda = ($this->tiendaConfirmar)($cuenta, ['carrito_id' => $carrito->id, 'medio_pago' => 'pasarela']);

    expect($segunda['creada'])->toBeFalse()
        ->and($segunda['venta']->id)->toBe($primera['venta']->id)
        ->and($segunda['venta']->medio_pago)->toBe('contado')
        ->and(Venta::count())->toBe(1);
});

test('el carrito de otro usuario no se encuentra', function () {
    $duena = ($this->tiendaCuenta)();
    $carrito = ($this->tiendaEnCheckout)($duena, ($this->tiendaProducto)(), 1);

    expect(fn () => ($this->tiendaConfirmar)(($this->tiendaCuenta)(), ['carrito_id' => $carrito->id]))
        ->toThrow(ModelNotFoundException::class);

    expect($carrito->fresh()->estado)->toBe('en_checkout');
});

test('un checkout vencido se suelta y no se confirma', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(10);
    $carrito  = ($this->tiendaEnCheckout)($cuenta, $producto, 2);
    $this->travel(21)->minutes();

    expect(fn () => ($this->tiendaConfirmar)($cuenta))->toThrow(CheckoutVencidoException::class);

    expect($carrito->fresh()->estado)->toBe('activo')
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and(Venta::count())->toBe(0);
});

test('un carrito que no paso por el checkout no se confirma', function () {
    $cuenta = ($this->tiendaCuenta)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    expect(fn () => ($this->tiendaConfirmar)($cuenta))->toThrow(CheckoutVencidoException::class);

    expect(Venta::count())->toBe(0);
});

test('un precio cambiado no confirma: suelta la reserva y deja el precio nuevo a la vista', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(10);
    $carrito  = ($this->tiendaEnCheckout)($cuenta, $producto, 2);
    $producto->update(['precio_contado' => '1100.00']);

    expect(fn () => ($this->tiendaConfirmar)($cuenta))->toThrow(CarritoCambioException::class);

    expect(Venta::count())->toBe(0)
        ->and($carrito->fresh()->estado)->toBe('activo')
        ->and($producto->fresh()->stock_reservado)->toBe(0)
        ->and($carrito->lineas()->first()->contado_referencia)->toBe('1100.00');
});

test('sin registros parciales: si falla el ultimo renglon no queda nada', function () {
    $cuenta  = ($this->tiendaCuenta)();
    $p1      = ($this->tiendaProducto)(10);
    $p2      = ($this->tiendaProducto)(10);
    $carrito = ($this->tiendaEnCheckout)($cuenta, $p1, 2);
    $this->carritos->agregar($cuenta, $p2->id, 1);
    app(StockService::class)->reservar($p2, 1);
    // agregar() soltó el checkout: se vuelve a armar a mano, con las dos reservas tomadas.
    $carrito->refresh();
    $carrito->estado          = 'en_checkout';
    $carrito->reservado_hasta = now()->addMinutes(20);
    $carrito->save();
    app(StockService::class)->reservar($p1, 2);

    $renglones = 0;
    DB::beforeExecuting(function (string $sql) use (&$renglones) {
        if (str_starts_with(strtolower($sql), 'insert into `venta_lineas`') && ++$renglones === 2) {
            throw new RuntimeException('Falla inyectada en el último renglón.');
        }
    });

    expect(fn () => ($this->tiendaConfirmar)($cuenta))->toThrow(RuntimeException::class);

    expect(Venta::count())->toBe(0)
        ->and(VentaLinea::count())->toBe(0)
        ->and($carrito->fresh()->estado)->toBe('en_checkout')
        ->and($p1->fresh()->stock_reservado)->toBe(2)
        ->and($p2->fresh()->stock_reservado)->toBe(1);
});

test('el comando cancela el pedido de contado vencido y libera su reserva', function () {
    $cuenta   = ($this->tiendaCuenta)();
    $producto = ($this->tiendaProducto)(10);
    ($this->tiendaEnCheckout)($cuenta, $producto, 4);
    ['venta' => $venta] = ($this->tiendaConfirmar)($cuenta);
    $this->travel(49)->hours();

    $this->artisan('tienda:vencer-reservas')
        ->expectsOutputToContain('Pedidos vencidos cancelados: 1')
        ->assertSuccessful();

    expect($venta->fresh()->estado)->toBe('cancelada')
        ->and($producto->fresh()->stock_reservado)->toBe(0);
});
