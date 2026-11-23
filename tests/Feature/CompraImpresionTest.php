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

    // El CSV o el PDF de compras por la API (tramo G): se pide el enlace firmado y se abre sin credencial.
    $this->descargar = function (array $consulta = []) {
        app('auth')->forgetGuards();
        $url = $this->getJson('/api/v1/compras/impresion?'.http_build_query($consulta), bearer(usuarioCon('compra.ver')))
            ->assertOk()->json('datos.url');

        return $this->get($url)->assertOk();
    };
});

test('imprimir las compras pide compra.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.ver']));

    $this->getJson('/api/v1/compras/impresion', bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    $respuesta = ($this->descargar)();

    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('compras-');
});

test('la planilla de compras va de la mas nueva a la mas vieja, con el total de la base y su alcance', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Norte']);
    $vieja     = ($this->orden)($proveedor, 1500);
    $nueva     = ($this->orden)($proveedor, 2500.5);
    ($this->orden)(Proveedor::factory()->create(), 9999);

    $csv = ($this->descargar)(['formato' => 'csv', 'proveedor_id' => $proveedor->id])->streamedContent();

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

// Antes volvía al listado con «No se imprimió»; por la API, la opción mal pedida no firma nada: 422.
test('una opcion mal pedida de compras es 422 y no da enlace', function () {
    $proveedor = Proveedor::factory()->create();

    $this->getJson('/api/v1/compras/impresion?'.http_build_query(['proveedor_id' => $proveedor->id, 'agrupar' => 'proveedor']), bearer(usuarioCon('compra.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agrupar', 'errores')
        ->assertJsonMissingPath('datos.url');
});

test('el enlace de imprimir de compras lleva los filtros puestos y no la pagina', function () {
    $url = $this->getJson('/api/v1/compras/impresion?estado=borrador&pagina=2', bearer(usuarioCon('compra.ver')))
        ->assertOk()->json('datos.url');

    expect($url)->toContain('estado=borrador')->not->toContain('pagina=');
});

test('un filtro invalido de compras impresas se rechaza en lugar de imprimir otra cosa', function () {
    $this->getJson('/api/v1/compras/impresion?estado=telepatia', bearer(usuarioCon('compra.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estado', 'errores');
});

// Desde el tramo G el botón pide el enlace al endpoint con los filtros de la URL; que no lleve la página
// lo prueba «el enlace de imprimir de compras lleva los filtros puestos y no la pagina», arriba.
test('el boton de imprimir de compras pide el enlace al endpoint', function () {
    $this->actingAs(usuarioCon('compra.ver'))
        ->get(route('compras.index', ['estado' => 'borrador', 'pagina' => 2]))
        ->assertOk()
        ->assertSee('data-imprimir-api="/compras/impresion"', false)
        ->assertDontSee('compras/imprimir', false);
});


test('la planilla de compras declara en su alcance las abiertas y el origen', function () {
    OrdenCompra::factory()->create(['usuario_creo_id' => null]);

    $csv = ($this->descargar)([
        'formato' => 'csv',
        'estado'  => OrdenCompra::ABIERTAS,
        'origen'  => 'reposicion',
    ])->streamedContent();

    // Sin la etiqueta, el papel diría «Estado: abiertas» con la clave cruda, o nada.
    expect($csv)->toContain('Alcance: Estado: Abiertas (sin recibir ni cancelar) · Origen: Generada por la reposición');
});