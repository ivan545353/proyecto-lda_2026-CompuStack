<?php

use App\Models\OrdenCompra;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Una orden con su total puesto a mano: la columna la mueve sólo CompraService.
    $this->orden = function (Proveedor $proveedor, float $total): OrdenCompra {
        $orden = OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);
        $orden->total_estimado = $total;
        $orden->save();

        return $orden->fresh();
    };
});

test('imprimir las compras pide compra.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.ver']));

    $this->actingAs(usuarioCon(...$otros))->get(route('compras.imprimir'))->assertForbidden();

    $respuesta = $this->actingAs(usuarioCon('compra.ver'))->get(route('compras.imprimir'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('compras-');
});

test('la planilla de compras va de la mas nueva a la mas vieja, con el total de la base y su alcance', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Norte']);
    $vieja     = ($this->orden)($proveedor, 1500);
    $nueva     = ($this->orden)($proveedor, 2500.5);
    ($this->orden)(Proveedor::factory()->create(), 9999);

    $csv = $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.imprimir', ['formato' => 'csv', 'proveedor_id' => $proveedor->id]))
        ->assertOk()
        ->streamedContent();

    $filas = array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );

    expect($filas[2][0])->toBe('Alcance: Proveedor: Distribuidora Norte')
        ->and($filas[4])->toBe(['Orden', 'Fecha', 'Proveedor', 'Estado', 'Total estimado (sin IVA)'])
        ->and($filas[5][0])->toBe($nueva->numeroFormateado())
        ->and($filas[6][0])->toBe($vieja->numeroFormateado())
        ->and(count($filas))->toBe(5 + 2 + 2)
        ->and(end($filas))->toBe(['Total: 2 órdenes', '', '', '', '4000,50']);
});

test('una opcion mal pedida vuelve al listado de compras con sus filtros', function () {
    $proveedor = Proveedor::factory()->create();

    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.imprimir', ['proveedor_id' => $proveedor->id, 'agrupar' => 'proveedor']))
        ->assertRedirect(route('compras.index', ['proveedor_id' => $proveedor->id]))
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'No se imprimió'));
});

test('el boton de imprimir de compras lleva los filtros puestos y no la pagina', function () {
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['estado' => 'borrador', 'page' => 2]))
        ->assertOk()
        ->assertSee(route('compras.imprimir', ['estado' => 'borrador']))
        ->assertDontSee(route('compras.imprimir', ['estado' => 'borrador', 'page' => 2]));
});


test('la planilla de compras declara en su alcance las abiertas y el origen', function () {
    OrdenCompra::factory()->create(['usuario_creo_id' => null]);

    $csv = $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.imprimir', [
            'formato' => 'csv',
            'estado'  => OrdenCompra::ABIERTAS,
            'origen'  => 'reposicion',
        ]))
        ->assertOk()
        ->streamedContent();

    // Sin la etiqueta, el papel diría «Estado: abiertas» con la clave cruda, o nada.
    expect($csv)->toContain('Alcance: Estado: Abiertas (sin recibir ni cancelar) · Origen: Generada por la reposición');
});