<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CarritoService;
use App\Services\TransferenciaService;
use App\Support\Importe;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * La transferencia de la tienda en la web (tramo L, D-27): el comprobante se ve con su permiso, el
 * cobro propone el saldo en transferencia y el listado encuentra lo que hay que revisar.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    Storage::fake('local');

    // Un pedido de la tienda por los mismos servicios que la app; con `$comprobante`, ya cargado.
    $this->tiendaPedido = function (string $medio = 'transferencia', ?string $comprobante = 'transferencia.pdf'): Venta {
        $cuenta = User::factory()->create(['rol_id' => Rol::where('nombre', 'Cliente')->firstOrFail()->id]);
        Cliente::factory()->create(['user_id' => $cuenta->id]);
        $producto = Producto::factory()->conStockYCosto(10, 30000)->create([
            'categoria_id' => Categoria::factory()->create()->id, 'precio_contado' => '54450.00',
            'precio_lista' => '60500.00', 'alicuota_iva' => 21,
        ]);
        $carritos = app(CarritoService::class);
        $carritos->agregar($cuenta, $producto->id, 1);
        $carritos->iniciarCheckout($cuenta);
        $venta = $carritos->confirmar($cuenta, [
            'carrito_id' => $carritos->paraLeer($cuenta)->id, 'medio_pago' => $medio, 'modo_entrega' => 'retiro',
        ])['venta'];

        if ($medio === 'transferencia' && $comprobante !== null) {
            app(TransferenciaService::class)->cargarComprobante(
                $venta, UploadedFile::fake()->create($comprobante, 20, 'application/pdf'),
            );
        }

        return $venta->fresh();
    };
});

test('el detalle muestra el comprobante cargado, con su enlace sólo para quien puede verlo', function () {
    $venta = ($this->tiendaPedido)();

    // Tramo H: el comprobante llega por la API; el enlace es un botón del shell, con su @can (regla 12).
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.medio_pago', 'transferencia')
        ->assertJsonPath('datos.comprobantes_transferencia.0.nombre_original', 'transferencia.pdf');

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.ver', 'venta.ver_comprobante_transferencia'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('data-transferencia', false)->assertSee('data-abrir-comprobante', false);

    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('data-transferencia', false)->assertDontSee('data-abrir-comprobante', false);
});

test('sin comprobante, el detalle dice que todavía no llegó', function () {
    $venta = ($this->tiendaPedido)('transferencia', null);

    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.medio_pago', 'transferencia')
        ->assertJsonPath('datos.comprobantes_transferencia', []);

    app('auth')->forgetGuards();

    // El texto es de la plantilla: el JS lo muestra cuando la lista viene vacía.
    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('todavía no cargó el comprobante');
});

test('el comprobante se abre con venta.ver_comprobante_transferencia y ningún otro permiso de ventas lo habilita', function () {
    $venta       = ($this->tiendaPedido)();
    $comprobante = $venta->comprobantesTransferencia()->sole();
    $ruta        = "/api/v1/ventas/{$venta->id}/comprobantes-transferencia/{$comprobante->id}";
    $otros       = array_values(array_diff(permisosDelModulo('venta'), ['venta.ver_comprobante_transferencia']));

    // Tramo H: la API da el enlace firmado con el permiso, y el navegador lo abre (HL-130).
    $this->getJson($ruta, bearer(usuarioCon(...$otros)))->assertForbidden();

    app('auth')->forgetGuards();

    $enlace = $this->getJson($ruta, bearer(usuarioCon('venta.ver_comprobante_transferencia')))->assertOk()->json('datos.url');

    app('auth')->forgetGuards();

    $respuesta = $this->get($enlace)->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('inline');
});

test('el comprobante de un pedido no se abre desde otra venta', function () {
    $una  = ($this->tiendaPedido)();
    $otra = ($this->tiendaPedido)();

    $ajeno = $una->comprobantesTransferencia()->sole();

    $this->getJson("/api/v1/ventas/{$otra->id}/comprobantes-transferencia/{$ajeno->id}", bearer(usuarioCon('venta.ver_comprobante_transferencia')))
        ->assertNotFound();
});

test('al cobrar un pedido por transferencia, el saldo se propone en transferencia y sin billetes', function () {
    $venta = ($this->tiendaPedido)();

    // Tramo H: dónde se propone el saldo lo dice la ficha; el JS lo pone en el campo de transferencia,
    // deja vacío el de efectivo y saca los billetes, que sólo van con el efectivo como principal.
    $this->getJson("/api/v1/ventas/{$venta->id}", bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('datos.cobro.principal', 'transferencia')
        ->assertJsonPath('datos.importes.saldo', '54450.00');
});

test('una venta de mostrador sigue proponiendo el efectivo', function () {
    $venta = ($this->tiendaPedido)('contado');

    $this->actingAs(usuarioCon('venta.ver', 'venta.cobrar'))->get(route('pagos.create', $venta))
        ->assertOk()
        ->assertSee('data-cobro-billetes', false)
        ->assertSee('id="monto-efectivo"', false);
});

test('el filtro «Transferencias por revisar» trae sólo las que tienen comprobante y no se cobraron', function () {
    $porRevisar = ($this->tiendaPedido)();
    $sinComprobante = ($this->tiendaPedido)('transferencia', null);
    $contado = ($this->tiendaPedido)('contado');

    // Tramo H: las filas, de la API; el chip, del shell con la URL.
    $filas = $this->getJson('/api/v1/ventas?pago=transferencia_en_revision', bearer(usuarioCon('venta.ver')))
        ->assertOk()
        ->assertJsonPath('criterios.pago', 'transferencia_en_revision')
        ->json('datos');

    expect(array_column($filas, 'id'))->toBe([$porRevisar->id])
        ->and(array_column($filas, 'id'))->not->toContain($sinComprobante->id, $contado->id);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.index', ['pago' => 'transferencia_en_revision']))
        ->assertOk()
        ->assertSee('Pago: Transferencias por revisar');

    expect(Venta::query()->dePago('transferencia_en_revision')->pluck('id')->all())->toBe([$porRevisar->id]);
});

test('un filtro de pago que no existe no se acepta', function () {
    $this->getJson('/api/v1/ventas?pago=cheques', bearer(usuarioCon('venta.ver')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('pago', 'errores');
});
