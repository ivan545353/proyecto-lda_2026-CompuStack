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
    $venta       = ($this->tiendaPedido)();
    $comprobante = $venta->comprobantesTransferencia()->sole();
    $enlace      = route('ventas.comprobante_transferencia', [$venta, $comprobante]);

    $this->actingAs(usuarioCon('venta.ver', 'venta.ver_comprobante_transferencia'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('data-transferencia', false)->assertSee('transferencia.pdf')->assertSee($enlace, false);

    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('transferencia.pdf')->assertDontSee($enlace, false);
});

test('sin comprobante, el detalle dice que todavía no llegó', function () {
    $venta = ($this->tiendaPedido)('transferencia', null);

    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.show', $venta))
        ->assertOk()->assertSee('todavía no cargó el comprobante');
});

test('el comprobante se abre con venta.ver_comprobante_transferencia y ningún otro permiso de ventas lo habilita', function () {
    $venta       = ($this->tiendaPedido)();
    $comprobante = $venta->comprobantesTransferencia()->sole();
    $ruta        = route('ventas.comprobante_transferencia', [$venta, $comprobante]);
    $otros       = array_values(array_diff(permisosDelModulo('venta'), ['venta.ver_comprobante_transferencia']));

    $this->actingAs(usuarioCon(...$otros))->get($ruta)->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('venta.ver_comprobante_transferencia'))->get($ruta)->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('inline');
});

test('el comprobante de un pedido no se abre desde otra venta', function () {
    $una  = ($this->tiendaPedido)();
    $otra = ($this->tiendaPedido)();

    $this->actingAs(usuarioCon('venta.ver_comprobante_transferencia'))
        ->get(route('ventas.comprobante_transferencia', [$otra, $una->comprobantesTransferencia()->sole()]))
        ->assertNotFound();
});

test('al cobrar un pedido por transferencia, el saldo se propone en transferencia y sin billetes', function () {
    $venta = ($this->tiendaPedido)();

    $this->actingAs(usuarioCon('venta.ver', 'venta.cobrar'))->get(route('pagos.create', $venta))
        ->assertOk()
        // El campo de transferencia trae el saldo; el de efectivo, vacío.
        ->assertSeeInOrder(['id="monto-transferencia"', 'value="'.Importe::paraFormulario('54450.00').'"'], false)
        ->assertSeeInOrder(['id="monto-efectivo"', 'value=""'], false)
        ->assertDontSee('data-cobro-billetes', false);
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

    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.index', ['pago' => 'transferencia_en_revision']))
        ->assertOk()
        ->assertSee($porRevisar->numeroFormateado())
        ->assertDontSee($sinComprobante->numeroFormateado())
        ->assertDontSee($contado->numeroFormateado())
        ->assertSee('Pago: Transferencias por revisar');

    expect(Venta::query()->dePago('transferencia_en_revision')->pluck('id')->all())->toBe([$porRevisar->id]);
});

test('un filtro de pago que no existe no se acepta', function () {
    $this->actingAs(usuarioCon('venta.ver'))->get(route('ventas.index', ['pago' => 'cheques']))
        ->assertRedirect(route('ventas.index'));
});
