<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\CredencialService;
use App\Services\VentaService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * La consulta de compras (tramo H): el cliente ve sus pedidos online, y sólo los suyos. Lo ajeno
 * no se encuentra: 404, igual que lo que no existe.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->carritos = app(CarritoService::class);

    $this->tiendaProducto = fn () => Producto::factory()->conStockYCosto(20, 30000)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        'alicuota_iva'   => 21,
    ]);

    $this->tiendaCuentaCon = function (?array $claves = null): array {
        $rol = Rol::where('nombre', 'Cliente')->firstOrFail();

        if ($claves !== null) {
            $rol = Rol::create(['nombre' => 'Tienda '.uniqid(), 'ambito' => 'tienda', 'es_sistema' => false]);
            $rol->permisos()->sync(Permiso::whereIn('clave', $claves)->pluck('id'));
        }

        $cuenta = User::factory()->create(['rol_id' => $rol->id]);
        Cliente::factory()->create(['user_id' => $cuenta->id]);
        app('auth')->forgetGuards();

        return [$cuenta, ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($cuenta)['credencial']]];
    };

    // Un pedido confirmado por el servicio, con su reserva.
    $this->tiendaPedido = function (User $cuenta, string $medio = 'pasarela', ?Producto $producto = null, int $cantidad = 2): Venta {
        $this->carritos->agregar($cuenta, ($producto ?? ($this->tiendaProducto)())->id, $cantidad);
        $this->carritos->iniciarCheckout($cuenta);
        $carrito = $this->carritos->paraLeer($cuenta);

        return $this->carritos->confirmar($cuenta, [
            'carrito_id' => $carrito->id, 'medio_pago' => $medio, 'modo_entrega' => 'retiro',
        ])['venta'];
    };

    $this->intento = fn (Venta $venta, string $estado, bool $acreditado = false) => IntentoPago::forceCreate([
        'venta_id'      => $venta->id,
        'pasarela'      => 'mercadopago',
        'estado'        => $estado,
        'importe'       => $venta->total,
        'referencia'    => (string) Str::uuid(),
        'vence_at'      => now()->addMinutes(20),
        'resuelto_at'   => $estado === 'pendiente' ? null : now(),
        'acreditado_at' => $acreditado ? now() : null,
    ]);
});

// ---------- acceso ----------

test('leer los pedidos pide sesion y tienda.ver_pedidos', function () {
    $this->getJson('/api/v1/pedidos')->assertStatus(401)->assertJsonPath('codigo', 'sin_sesion');
    $this->getJson('/api/v1/pedidos/1')->assertStatus(401);

    [, $soloComprar] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $this->getJson('/api/v1/pedidos', $soloComprar)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
    $this->getJson('/api/v1/pedidos/1', $soloComprar)->assertStatus(403);

    // La otra dirección: con sólo `tienda.ver_pedidos` se lee, aunque no se pueda comprar.
    [$cuenta, $soloLeer] = ($this->tiendaCuentaCon)(['tienda.ver_pedidos', 'tienda.comprar']);
    $venta = ($this->tiendaPedido)($cuenta);
    $cuenta->rol->permisos()->sync(Permiso::where('clave', 'tienda.ver_pedidos')->pluck('id'));
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/pedidos', $soloLeer)->assertOk()->assertJsonPath('total', 1);
    $this->getJson("/api/v1/pedidos/{$venta->id}", $soloLeer)->assertOk();
});

test('un cliente pide el pedido de otro y recibe 404, igual que un numero que no existe', function () {
    [$duena] = ($this->tiendaCuentaCon)();
    $ajeno   = ($this->tiendaPedido)($duena);
    [, $otro] = ($this->tiendaCuentaCon)();

    $deOtro     = $this->getJson("/api/v1/pedidos/{$ajeno->id}", $otro)->assertStatus(404);
    $inexistente = $this->getJson('/api/v1/pedidos/999999', $otro)->assertStatus(404);

    expect($deOtro->json())->toBe($inexistente->json())
        ->and($deOtro->json('codigo'))->toBe('no_encontrado');
});

test('una compra de mostrador del mismo cliente no es un pedido de la app', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $mostrador = Venta::factory()->pagada()->create(['cliente_id' => $cuenta->cliente->id]);

    $this->getJson("/api/v1/pedidos/{$mostrador->id}", $bearer)->assertStatus(404);
    $this->getJson('/api/v1/pedidos', $bearer)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('datos', []);
});

// ---------- listado ----------

test('el listado trae solo los propios, del mas nuevo al mas viejo, paginado', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();

    $primero = ($this->tiendaPedido)($cuenta, 'pasarela', $producto, 1);
    $segundo = ($this->tiendaPedido)($cuenta, 'pasarela', $producto, 2);
    $tercero = ($this->tiendaPedido)($cuenta, 'pasarela', $producto, 3);
    // El mismo instante: desempata el id.
    Venta::whereKey([$primero->id, $segundo->id, $tercero->id])->update(['created_at' => now()->subHour()]);

    [$otra] = ($this->tiendaCuentaCon)();
    ($this->tiendaPedido)($otra, 'pasarela', $producto);

    $pagina = $this->getJson('/api/v1/pedidos?por_pagina=2', $bearer)->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('ultima_pagina', 2)
        ->assertJsonPath('criterios', []);

    expect(array_column($pagina->json('datos'), 'id'))->toBe([$tercero->id, $segundo->id]);
    expect($pagina->json('datos.0'))->toBe([
        'id'                => $tercero->id,
        'numero'            => $tercero->numeroFormateado(),
        'creado'            => $tercero->fresh()->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        'estado'            => 'pendiente_pago',
        'estado_texto'      => 'Esperando el pago',
        'estado_pago'       => 'pendiente',
        'estado_pago_texto' => 'Falta pagar',
        'unidades'          => 3,
        'importes'          => ['total' => $tercero->fresh()->total],
    ]);

    $this->getJson('/api/v1/pedidos?pagina=2&por_pagina=2', $bearer)->assertOk()
        ->assertJsonPath('datos.0.id', $primero->id);
    $this->getJson('/api/v1/pedidos?pagina=9', $bearer)->assertOk()->assertJsonPath('datos', []);
});

test('sin tienda.ver_precios los importes no viajan, ni en el listado ni en el detalle', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)(['tienda.ver_pedidos', 'tienda.comprar']);
    $venta = ($this->tiendaPedido)($cuenta);

    $listado = $this->getJson('/api/v1/pedidos', $bearer)->assertOk();
    $detalle = $this->getJson("/api/v1/pedidos/{$venta->id}", $bearer)->assertOk();

    expect($listado->json('datos.0'))->not->toHaveKey('importes')
        ->and($detalle->json('datos'))->not->toHaveKey('importes')
        ->and($detalle->json('datos.lineas.0'))->not->toHaveKeys(['precio_unitario', 'total']);
});

// ---------- detalle ----------

test('el detalle muestra el precio congelado aunque el precio del producto haya cambiado', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $producto = ($this->tiendaProducto)();
    $venta    = ($this->tiendaPedido)($cuenta, 'pasarela', $producto, 2);

    $producto->forceFill(['precio_lista' => '99999.00', 'precio_contado' => '88888.00'])->save();

    $this->getJson("/api/v1/pedidos/{$venta->id}", $bearer)->assertOk()
        ->assertJsonPath('datos.lineas.0.precio_unitario', '60500.00')
        ->assertJsonPath('datos.lineas.0.total', '121000.00')
        ->assertJsonPath('datos.lineas.0.descripcion', $venta->lineas->first()->descripcion)
        ->assertJsonPath('datos.importes.total', $venta->fresh()->total);
});

test('el detalle es el pedido de la confirmacion, mas los dos estados, el ultimo pago y el comprobante', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta   = ($this->tiendaPedido)($cuenta);
    $intento = ($this->intento)($venta, 'rechazado');

    $datos = $this->getJson("/api/v1/pedidos/{$venta->id}", $bearer)->assertOk()->json('datos');

    expect($datos)->toHaveKeys(['id', 'numero', 'lineas', 'importes', 'reserva_vence', 'direccion', 'medio_pago_texto'])
        ->and($datos['reserva_vence'])->not->toBeNull()
        ->and($datos['comprobante_disponible'])->toBeFalse()
        ->and($datos['ultimo_pago'])->toBe([
            'id'             => $intento->id,
            'pasarela'       => 'mercadopago',
            'pasarela_texto' => 'Mercado Pago',
            'estado'         => 'rechazado',
            'estado_texto'   => 'Rechazado',
            'creado'         => $intento->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
});

test('los dos estados por separado, en cada situacion del pago', function (string $situacion, string $estado, string $estadoPago, string $texto) {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta, $situacion === 'contado' ? 'contado' : 'pasarela');

    match ($situacion) {
        'esperando'   => ($this->intento)($venta, 'pendiente'),
        'en_revision' => ($this->intento)($venta, 'aprobado'),
        'rechazado'   => ($this->intento)($venta, 'rechazado'),
        'vencido'     => ($this->intento)($venta, 'vencido'),
        'pagado'      => [($this->intento)($venta, 'aprobado', true), $venta->forceFill(['estado' => 'pagada', 'reserva_vence' => null])->save()],
        'cancelado'   => app(VentaService::class)->cancelar($venta),
        default       => null,
    };

    $esperado = ['estado' => $estado, 'estado_pago' => $estadoPago, 'estado_pago_texto' => $texto];

    $detalle = $this->getJson("/api/v1/pedidos/{$venta->id}", $bearer)->assertOk()->json('datos');
    $renglon = $this->getJson('/api/v1/pedidos', $bearer)->assertOk()->json('datos.0');

    expect(array_intersect_key($detalle, $esperado))->toBe($esperado)
        ->and(array_intersect_key($renglon, $esperado))->toBe($esperado)
        ->and($detalle['comprobante_disponible'])->toBe($situacion === 'pagado');
})->with([
    'contado'          => ['contado', 'pendiente_pago', 'pendiente', 'Se paga en el local'],
    'sin intento'      => ['falta_pagar', 'pendiente_pago', 'pendiente', 'Falta pagar'],
    'intento pendiente' => ['esperando', 'pendiente_pago', 'pendiente', 'Esperando la confirmación del pago'],
    'aprobado sin acreditar' => ['en_revision', 'pendiente_pago', 'en_revision', 'Pago recibido, en revisión'],
    'rechazado'        => ['rechazado', 'pendiente_pago', 'rechazado', 'Pago rechazado: podés reintentar'],
    'vencido'          => ['vencido', 'pendiente_pago', 'pendiente', 'Falta pagar'],
    'pagado'           => ['pagado', 'pagada', 'aprobado', 'Pagado'],
    'cancelado'        => ['cancelado', 'cancelada', 'sin_cobrar', 'No se cobró'],
]);

// ---------- comprobante ----------

test('el comprobante de un pedido pagado da una url firmada que baja el pdf sin vendedor', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);
    $venta->forceFill(['estado' => 'pagada', 'reserva_vence' => null])->save();

    $datos = $this->getJson("/api/v1/pedidos/{$venta->id}/comprobante", $bearer)->assertOk()->json('datos');

    expect(array_keys($datos))->toBe(['url', 'vence'])
        ->and($datos['url'])->not->toContain('Bearer')
        ->and($datos['vence'])->toBe(now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'));

    // Sin credencial: el navegador la abre solo.
    app('auth')->forgetGuards();
    $pdf = $this->get($datos['url'])->assertOk();

    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf->headers->get('content-disposition'))->toContain("comprobante-{$venta->numeroFormateado()}.pdf");
});

test('el comprobante ajeno es 404 y el de un pedido sin pagar o cancelado es 409', function () {
    [$duena] = ($this->tiendaCuentaCon)();
    $ajeno   = ($this->tiendaPedido)($duena);
    $ajeno->forceFill(['estado' => 'pagada', 'reserva_vence' => null])->save();

    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $this->getJson("/api/v1/pedidos/{$ajeno->id}/comprobante", $bearer)->assertStatus(404)
        ->assertJsonPath('codigo', 'no_encontrado');

    $sinPagar = ($this->tiendaPedido)($cuenta);
    $this->getJson("/api/v1/pedidos/{$sinPagar->id}/comprobante", $bearer)->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    app(VentaService::class)->cancelar($sinPagar);
    $this->getJson("/api/v1/pedidos/{$sinPagar->id}/comprobante", $bearer)->assertStatus(409);

    [, $soloComprar] = ($this->tiendaCuentaCon)(['tienda.comprar']);
    $this->getJson("/api/v1/pedidos/{$ajeno->id}/comprobante", $soloComprar)->assertStatus(403);
});

test('la url del comprobante vencida o tocada es 403', function () {
    [$cuenta, $bearer] = ($this->tiendaCuentaCon)();
    $venta = ($this->tiendaPedido)($cuenta);
    $otro  = ($this->tiendaPedido)($cuenta);
    Venta::whereKey([$venta->id, $otro->id])->update(['estado' => 'pagada', 'reserva_vence' => null]);

    $url = $this->getJson("/api/v1/pedidos/{$venta->id}/comprobante", $bearer)->json('datos.url');
    app('auth')->forgetGuards();

    // Tocada: el mismo enlace apuntando a otro pedido, y sin firma.
    $this->getJson(str_replace("/pedidos/{$venta->id}/", "/pedidos/{$otro->id}/", $url))->assertStatus(403);
    $this->getJson(strtok($url, '?'))->assertStatus(403);

    $this->travel(6)->minutes();
    $this->getJson($url)->assertStatus(403)->assertJsonPath('codigo', 'sin_permiso');
});
