<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ComprobanteTransferencia;
use App\Models\MovimientoStock;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\CredencialService;
use App\Services\PagoService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * La transferencia bancaria como medio de la tienda (tramo L, D-27, HL-90): se ofrece aparte del
 * efectivo, paga el precio de contado y espera el comprobante.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->carritos = app(CarritoService::class);

    $this->tiendaProducto = fn (int $stock = 10) => Producto::factory()->conStockYCosto($stock, 30000)->create([
        'categoria_id'   => Categoria::factory()->create()->id,
        'precio_contado' => '54450.00',
        'precio_lista'   => '60500.00',
        'alicuota_iva'   => 21,
    ]);

    $this->tiendaCuenta = function (): array {
        $cuenta = User::factory()->create(['rol_id' => Rol::where('nombre', 'Cliente')->firstOrFail()->id]);
        Cliente::factory()->create(['user_id' => $cuenta->id]);
        app('auth')->forgetGuards();

        return [$cuenta, ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($cuenta)['credencial']]];
    };

    // El pedido por transferencia, por los mismos endpoints que la app.
    $this->tiendaPedidoPorTransferencia = function (User $cuenta, array $bearer, int $cantidad = 2) {
        $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, $cantidad);
        $this->carritos->iniciarCheckout($cuenta);

        return $this->postJson('/api/v1/pedidos', [
            'carrito_id' => $this->carritos->paraLeer($cuenta)->id, 'medio_pago' => 'transferencia', 'modo_entrega' => 'retiro',
        ], $bearer);
    };
});

test('el checkout ofrece la transferencia aparte del efectivo, con el precio de contado', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);

    $medios = $this->postJson('/api/v1/carrito/checkout', [], $bearer)->assertOk()->json('datos.medios');

    expect(array_column($medios, 'medio'))->toBe(['contado', 'transferencia', 'pasarela'])
        ->and($medios[1])->toBe(['medio' => 'transferencia', 'texto' => 'Transferencia bancaria', 'modos_entrega' => ['retiro', 'envio'], 'total' => '54450.00']);
});

test('confirmar por transferencia congela el precio de contado y reserva hasta que llegue el comprobante', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();

    ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)
        ->assertCreated()
        ->assertJsonPath('datos.medio_pago', 'transferencia')
        ->assertJsonPath('datos.medio_pago_texto', 'Transferencia bancaria')
        ->assertJsonPath('datos.lineas.0.precio_unitario', '54450.00')
        ->assertJsonPath('datos.importes.total', '108900.00')
        ->assertJsonPath('datos.reserva_vence', now()->addHours(48)->utc()->format('Y-m-d\TH:i:s\Z'));
});

test('sin comprobante, el pedido dice que falta cargarlo y el pago queda pendiente', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');

    $this->getJson("/api/v1/pedidos/{$id}", $bearer)
        ->assertOk()
        ->assertJsonPath('datos.estado_pago', 'pendiente')
        ->assertJsonPath('datos.estado_pago_texto', 'Falta cargar el comprobante de la transferencia');
});

test('un pedido por transferencia no se paga por la pasarela', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');

    $this->postJson("/api/v1/pedidos/{$id}/pagos", ['pasarela' => 'mercadopago'], $bearer)
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect(Venta::findOrFail($id)->intentosPago()->count())->toBe(0);
});

// ---------- el comprobante ----------

test('cargar el comprobante deja el pedido en revisión, guarda el archivo y avisa el plazo', function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-11-13 10:00:00'));   // un viernes
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');

    $this->post("/api/v1/pedidos/{$id}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create('transferencia.pdf', 200, 'application/pdf'),
    ], $bearer + ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('mensaje', 'Recibimos tu comprobante. Dentro de las 72 horas hábiles confirmamos el pago y seguimos con tu pedido.')
        ->assertJsonPath('datos.estado_pago', 'en_revision')
        ->assertJsonPath('datos.estado_pago_texto', 'Pago recibido, en revisión')
        ->assertJsonPath('datos.transferencia.comprobante_cargado', true)
        // Tres días hábiles desde el viernes: el miércoles, no el lunes.
        ->assertJsonPath('datos.reserva_vence', Carbon::parse('2026-11-18 10:00:00')->utc()->format('Y-m-d\TH:i:s\Z'));

    $comprobante = ComprobanteTransferencia::where('venta_id', $id)->sole();

    expect($comprobante->nombre_original)->toBe('transferencia.pdf')
        ->and($comprobante->tipo)->toBe('application/pdf');
    Storage::disk('local')->assertExists($comprobante->ruta);
});

test('el detalle dice a quién transferir, y sin comprobante todavía no avisa el plazo', function () {
    config(['tienda.transferencia.alias' => 'LOCAL.CUENTA.UNO', 'tienda.transferencia.titular' => 'Local S.A.']);
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');

    $this->getJson("/api/v1/pedidos/{$id}", $bearer)->assertOk()->assertJsonPath('datos.transferencia', [
        'alias' => 'LOCAL.CUENTA.UNO', 'titular' => 'Local S.A.', 'comprobante_cargado' => false, 'aviso' => null,
    ]);
});

test('un segundo comprobante se suma al primero, no lo pisa', function () {
    Storage::fake('local');
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');
    $cargar = fn (string $nombre) => $this->post("/api/v1/pedidos/{$id}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create($nombre, 50, 'image/jpeg'),
    ], $bearer + ['Accept' => 'application/json'])->assertCreated();

    $cargar('primero.jpg');
    $cargar('segundo.jpg');

    expect(ComprobanteTransferencia::where('venta_id', $id)->orderBy('id')->pluck('nombre_original')->all())
        ->toBe(['primero.jpg', 'segundo.jpg']);
});

test('el comprobante del pedido de otro da 404, igual que uno que no existe', function () {
    Storage::fake('local');
    [$una, $bearerUna] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($una, $bearerUna)->json('datos.id');
    [, $bearerOtra] = ($this->tiendaCuenta)();
    $cargar = fn (int $pedido) => $this->post("/api/v1/pedidos/{$pedido}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], $bearerOtra + ['Accept' => 'application/json']);

    $cargar($id)->assertNotFound()->assertJsonPath('codigo', 'no_encontrado');
    $cargar(999999)->assertNotFound();

    expect(ComprobanteTransferencia::count())->toBe(0);
});

test('un archivo que no es foto ni PDF, o que pesa más de 5 MB, se rechaza', function (string $nombre, int $kb, string $tipo) {
    Storage::fake('local');
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');

    $this->post("/api/v1/pedidos/{$id}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create($nombre, $kb, $tipo),
    ], $bearer + ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('codigo', 'validacion');

    expect(ComprobanteTransferencia::count())->toBe(0);
})->with([
    'texto'  => ['nota.txt', 10, 'text/plain'],
    'pesado' => ['grande.pdf', 6000, 'application/pdf'],
]);

test('un pedido que no espera transferencia no recibe comprobante: 409', function () {
    Storage::fake('local');
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);
    $this->carritos->iniciarCheckout($cuenta);
    $contado = $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $this->carritos->paraLeer($cuenta)->id, 'medio_pago' => 'contado', 'modo_entrega' => 'retiro',
    ], $bearer)->json('datos.id');

    $this->post("/api/v1/pedidos/{$contado}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], $bearer + ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect(ComprobanteTransferencia::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('cargar el comprobante pide tienda.cargar_comprobante', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer)->json('datos.id');
    $rol = Rol::create(['nombre' => 'Tienda sin comprobante', 'ambito' => 'tienda', 'es_sistema' => false]);
    $rol->permisos()->sync(Permiso::where('modulo', 'tienda')->where('clave', '!=', 'tienda.cargar_comprobante')->pluck('id'));
    $cuenta->update(['rol_id' => $rol->id]);
    app('auth')->forgetGuards();

    $this->post("/api/v1/pedidos/{$id}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], $bearer + ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
});

test('confirmar la transferencia es el cobro de siempre: sale el stock y queda el asiento', function () {
    Storage::fake('local');
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $id = ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer, 2)->json('datos.id');
    $this->post("/api/v1/pedidos/{$id}/comprobante-transferencia", [
        'archivo' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
    ], $bearer + ['Accept' => 'application/json'])->assertCreated();
    $venta = Venta::findOrFail($id);

    app(PagoService::class)->cobrar($venta, ['pagos' => [['metodo' => 'transferencia', 'monto' => $venta->total]]], admin());

    expect($venta->fresh()->estado)->toBe('pagada')
        ->and($venta->fresh()->situacionDelPago())->toBe('pagado')
        ->and((int) MovimientoStock::where('origen_type', Venta::class)->where('origen_id', $id)->sum('cantidad'))->toBe(-2);
});

test('el listado no crece en consultas con los pedidos por transferencia', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer);
    $uno = contarConsultas(fn () => $this->getJson('/api/v1/pedidos', $bearer)->assertOk());

    ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer);
    ($this->tiendaPedidoPorTransferencia)($cuenta, $bearer);
    $tres = contarConsultas(fn () => $this->getJson('/api/v1/pedidos', $bearer)->assertOk());

    expect($tres)->toBe($uno);
});

test('un medio que no existe se rechaza con los tres que hay', function () {
    [$cuenta, $bearer] = ($this->tiendaCuenta)();
    $this->carritos->agregar($cuenta, ($this->tiendaProducto)()->id, 1);
    $this->carritos->iniciarCheckout($cuenta);

    $this->postJson('/api/v1/pedidos', [
        'carrito_id' => $this->carritos->paraLeer($cuenta)->id, 'medio_pago' => 'cheque', 'modo_entrega' => 'retiro',
    ], $bearer)
        ->assertStatus(422)
        ->assertJsonPath('errores.medio_pago.0', 'Elegí pagar en efectivo en el local, por transferencia o por Mercado Pago.');
});
