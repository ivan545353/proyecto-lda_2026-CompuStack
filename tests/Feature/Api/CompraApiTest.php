<?php

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Un producto que ese proveedor provee. Propia y no `productoDe()`: ésa vive en otro archivo,
    // y este test tiene que poder correr solo.
    $this->provisto = fn (Proveedor $proveedor) => Producto::factory()->conProveedor($proveedor)->create();
});

test('listar las compras pide compra.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.ver']));

    $this->getJson('/api/v1/compras', bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/compras', bearer(usuarioCon('compra.ver')))->assertOk();
});

test('el listado de compras va de la mas nueva a la mas vieja, con lo que la pantalla muestra', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Distribuidora Norte']);
    $vieja     = OrdenCompra::factory()->conLinea(Producto::factory()->create(), 2, 1500)->create(['proveedor_id' => $proveedor->id]);
    $nueva     = OrdenCompra::factory()->delSistema()->aprobada()->create(['proveedor_id' => $proveedor->id]);

    $respuesta = $this->getJson('/api/v1/compras', bearer(usuarioCon('compra.ver')))->assertOk();

    expect($respuesta->json('datos.*.id'))->toBe([$nueva->id, $vieja->id])
        ->and($respuesta->json('datos.1'))->toMatchArray([
            'numero'                  => $vieja->numeroFormateado(),
            'proveedor'               => ['id' => $proveedor->id, 'razon_social' => 'Distribuidora Norte'],
            'estado'                  => 'borrador',
            'estado_texto'            => 'Borrador',
            'generada_por_el_sistema' => false,
            'editable'                => true,
            'productos'               => 1,
        ])
        ->and($respuesta->json('datos.1.total_estimado'))->toBeString()
        ->and($respuesta->json('datos.0'))->toMatchArray(['generada_por_el_sistema' => true, 'editable' => false]);
});

test('el listado de compras filtra como la pantalla y declara sus criterios', function () {
    $proveedor = Proveedor::factory()->create();
    $suya      = OrdenCompra::factory()->create(['proveedor_id' => $proveedor->id]);
    OrdenCompra::factory()->create();
    OrdenCompra::factory()->cancelada()->create(['proveedor_id' => $proveedor->id]);

    $this->getJson("/api/v1/compras?proveedor_id={$proveedor->id}&estado=".OrdenCompra::ABIERTAS, bearer(usuarioCon('compra.ver')))
        ->assertOk()
        ->assertJsonPath('datos.*.id', [$suya->id])
        ->assertJsonPath('criterios.proveedor_id', $proveedor->id)
        ->assertJsonPath('criterios.estado', OrdenCompra::ABIERTAS);
});

test('un filtro invalido del listado de compras es 422, tambien un rango invertido', function () {
    $this->getJson('/api/v1/compras?estado=telepatia&desde=2026-05-10&hasta=2026-05-01', bearer(usuarioCon('compra.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado', 'hasta'], 'errores');
});

test('los proveedores para elegir no piden proveedor.ver, y activos=1 deja afuera a los inactivos', function () {
    $activo   = Proveedor::factory()->create(['razon_social' => 'A Activo']);
    $inactivo = Proveedor::factory()->inactivo()->create(['razon_social' => 'B Inactivo']);

    $this->getJson('/api/v1/compras/proveedores', bearer(usuarioCon('compra.ver')))
        ->assertOk()
        ->assertJsonPath('datos.*.id', [$activo->id, $inactivo->id])
        ->assertJsonPath('datos.1.activo', false);

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/compras/proveedores?activos=1', bearer(usuarioCon('compra.ver')))
        ->assertOk()
        ->assertJsonPath('datos.*.id', [$activo->id])
        ->assertJsonMissingPath('datos.0.cuit');
});

test('la ficha de una orden trae sus lineas, lo que entro y quien la recibio', function () {
    $producto = Producto::factory()->create(['codigo' => 'SSD-1TB', 'nombre' => 'Disco SSD']);
    $orden    = OrdenCompra::factory()->aprobada()->conLinea($producto, 5, 1200)->create();
    $quien    = admin();
    app(\App\Services\CompraService::class)->recibir($orden, [$orden->lineas()->value('id') => 2], $quien, [
        'numero' => 'R-0001-00000042', 'fecha' => '2026-11-20',
    ]);

    $respuesta = $this->getJson("/api/v1/compras/{$orden->id}", bearer(usuarioCon('compra.ver')))->assertOk();

    expect($respuesta->json('datos'))->toMatchArray([
        'numero'              => $orden->numeroFormateado(),
        'estado'              => 'recibida_parcial',
        'unidades_recibidas'  => 2,
        'unidades_pendientes' => 3,
    ])
        ->and($respuesta->json('datos.lineas.0'))->toMatchArray([
            'producto'           => ['id' => $producto->id, 'codigo' => 'SSD-1TB', 'nombre' => 'Disco SSD', 'activo' => true],
            'cantidad_pedida'    => 5,
            'cantidad_recibida'  => 2,
            'cantidad_pendiente' => 3,
            'costo_unitario'     => '1200.00',
            'subtotal'           => '6000.00',
        ])
        ->and($respuesta->json('datos.recepciones.0'))->toMatchArray([
            'cantidad'       => 2,
            'origen_texto'   => "Orden de compra {$orden->numeroFormateado()}",
            'registrado_por' => "{$quien->nombre} {$quien->apellido}",
        ])
        ->and($respuesta->json('datos.recepciones.0.remito_texto'))->toContain('R-0001-00000042');
});

test('las acciones de la ficha las decide el estado, no la pantalla', function (?string $estado, array $acciones) {
    $fabrica = $estado === null ? OrdenCompra::factory() : OrdenCompra::factory()->{$estado}();
    $orden   = $fabrica->conLinea(Producto::factory()->create(), 4)->create();

    if ($estado === 'recibidaParcial') {
        $orden->lineas()->update(['cantidad_recibida' => 1]);
    }

    $this->getJson("/api/v1/compras/{$orden->id}", bearer(usuarioCon('compra.ver')))
        ->assertOk()
        ->assertJsonPath('datos.acciones', $acciones);
})->with([
    'borrador'         => [null, ['editar' => true, 'aprobar' => true, 'enviar' => false, 'recibir' => false, 'cerrar' => false, 'cancelar' => true, 'imprimir' => false]],
    'aprobada'         => ['aprobada', ['editar' => false, 'aprobar' => false, 'enviar' => true, 'recibir' => true, 'cerrar' => false, 'cancelar' => true, 'imprimir' => true]],
    'recibida parcial' => ['recibidaParcial', ['editar' => false, 'aprobar' => false, 'enviar' => false, 'recibir' => true, 'cerrar' => true, 'cancelar' => false, 'imprimir' => true]],
    'cancelada'        => ['cancelada', ['editar' => false, 'aprobar' => false, 'enviar' => false, 'recibir' => false, 'cerrar' => false, 'cancelar' => false, 'imprimir' => false]],
]);

test('ver una orden pide compra.ver, y una que no existe es 404', function () {
    $orden = OrdenCompra::factory()->create();
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.ver']));

    $this->getJson("/api/v1/compras/{$orden->id}", bearer(usuarioCon(...$otros)))->assertForbidden();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/compras/999999', bearer(usuarioCon('compra.ver')))
        ->assertNotFound()->assertJsonPath('codigo', 'no_encontrado');
});

test('el pedido en PDF se baja por enlace firmado y sin credencial', function () {
    $orden = OrdenCompra::factory()->aprobada()->conLinea(Producto::factory()->create(), 2)->create();

    $url = $this->getJson("/api/v1/compras/{$orden->id}/pdf", bearer(usuarioCon('compra.ver')))
        ->assertOk()->json('datos.url');

    app('auth')->forgetGuards();
    $respuesta = $this->get($url)->assertOk();

    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain("orden-compra-{$orden->numeroFormateado()}.pdf")
        ->and($orden->fresh()->estado)->toBe('aprobada');
});

test('una orden sin aprobar no tiene pedido: 409 y ningun enlace', function () {
    $orden = OrdenCompra::factory()->create();

    $this->getJson("/api/v1/compras/{$orden->id}/pdf", bearer(usuarioCon('compra.ver')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio')
        ->assertJsonMissingPath('datos.url');
});

test('el enlace del pedido tocado es 403, y si la cuenta perdio compra.ver ya no sirve', function () {
    $orden   = OrdenCompra::factory()->aprobada()->create();
    $otra    = OrdenCompra::factory()->aprobada()->create();
    $usuario = usuarioCon('compra.ver');
    $url     = $this->getJson("/api/v1/compras/{$orden->id}/pdf", bearer($usuario))->json('datos.url');

    // A otra orden que existe: el binding resuelve antes que la firma, y un id inexistente sería 404.
    app('auth')->forgetGuards();
    $this->getJson(str_replace("compras/{$orden->id}/", "compras/{$otra->id}/", $url))->assertForbidden();

    // Como lo hace RolService al cambiar un rol: la caché de sus permisos se olvida.
    $usuario->rol->permisos()->detach();
    \Illuminate\Support\Facades\Cache::forget(\App\Models\User::claveCache($usuario->rol_id));
    $this->getJson($url)->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
});

dataset('escrituras de la orden', [
    'editar'            => ['patch', '', 'compra.editar'],
    'aprobar'           => ['post', '/aprobar', 'compra.aprobar'],
    'marcar enviada'    => ['post', '/enviada', 'compra.aprobar'],
    'cancelar'          => ['post', '/cancelar', 'compra.aprobar'],
    'cerrar incompleta' => ['post', '/cerrar', 'compra.recibir'],
]);

test('cada escritura de la orden pide su permiso y ningun otro del modulo la habilita', function (string $metodo, string $sufijo, string $permiso) {
    $proveedor = Proveedor::factory()->create();
    $orden     = OrdenCompra::factory()->conLinea(($this->provisto)($proveedor), 3)->create(['proveedor_id' => $proveedor->id]);
    $otros     = array_values(array_diff(permisosDelModulo('compra'), [$permiso]));
    $datos     = $metodo === 'patch' ? datosDeOrdenCompra($proveedor, [lineaDeOrden($orden->lineas()->first()->producto, 2)]) : [];

    $this->json($metodo, "/api/v1/compras/{$orden->id}{$sufijo}", $datos, bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    app('auth')->forgetGuards();

    expect($this->json($metodo, "/api/v1/compras/{$orden->id}{$sufijo}", $datos, bearer(usuarioCon($permiso)))->status())
        ->not->toBe(403);
})->with('escrituras de la orden');

test('editar una orden aprobada es 409 y no toca sus lineas', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = ($this->provisto)($proveedor);
    $orden     = OrdenCompra::factory()->aprobada()->conLinea($producto, 2)->create(['proveedor_id' => $proveedor->id]);

    $this->patchJson("/api/v1/compras/{$orden->id}", datosDeOrdenCompra($proveedor, [lineaDeOrden($producto, 9)]), bearer(usuarioCon('compra.editar')))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect($orden->fresh('lineas')->lineas->first()->cantidad_pedida)->toBe(2);
});

test('el estado y el total no llegan desde el cuerpo: se rechazan', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = ($this->provisto)($proveedor);
    $orden     = OrdenCompra::factory()->conLinea($producto, 2)->create(['proveedor_id' => $proveedor->id]);

    $this->patchJson("/api/v1/compras/{$orden->id}", datosDeOrdenCompra($proveedor, [lineaDeOrden($producto, 2)], [
        'estado' => 'aprobada', 'total_estimado' => '1.00',
    ]), bearer(usuarioCon('compra.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['estado', 'total_estimado'], 'errores');

    expect($orden->fresh()->estado)->toBe('borrador');
});

test('los sugeridos y el armador piden compra.crear, y el pedido tambien', function (string $metodo, string $ruta) {
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.crear']));

    $this->json($metodo, $ruta, ['lineas' => []], bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    app('auth')->forgetGuards();

    expect($this->json($metodo, $ruta, ['lineas' => []], bearer(usuarioCon('compra.crear')))->status())->not->toBe(403);
})->with([
    'sugeridos' => ['get', '/api/v1/compras/sugeridos'],
    'armador'   => ['get', '/api/v1/compras/armador'],
    'pedido'    => ['post', '/api/v1/compras'],
]);

test('los sugeridos vienen agrupados por proveedor y cuentan los que no tienen a quien pedirle', function () {
    $zeta = Proveedor::factory()->create(['razon_social' => 'Zeta']);
    $alfa = Proveedor::factory()->create(['razon_social' => 'Alfa']);
    $deZeta = Producto::factory()->conProveedor($zeta, costo: 100)->create(['codigo' => 'A-1', 'stock_minimo' => 5, 'cantidad_reposicion' => 3]);
    $deAlfa = Producto::factory()->conProveedor($alfa, costo: 200)->create(['codigo' => 'B-1', 'stock_minimo' => 5, 'cantidad_reposicion' => 2]);
    Producto::factory()->count(2)->conStock(0)->create(['stock_minimo' => 5]);

    $this->getJson('/api/v1/compras/sugeridos', bearer(usuarioCon('compra.crear')))
        ->assertOk()
        ->assertJsonPath('datos.*.id', [$deZeta->id, $deAlfa->id])
        ->assertJsonPath('datos.0.proveedor', ['id' => $zeta->id, 'razon_social' => 'Zeta'])
        ->assertJsonPath('datos.0.cantidad_reposicion', 3)
        ->assertJsonPath('grupos.*.proveedor.razon_social', ['Alfa', 'Zeta'])
        ->assertJsonPath('grupos.0.productos', [$deAlfa->id])
        ->assertJsonPath('sin_proveedor', 2);
});

test('el costo de los sugeridos viaja solo con precio.ver_costo, ni en null', function () {
    Producto::factory()->conProveedor(Proveedor::factory()->create(), costo: 1000)->create(['stock_minimo' => 5, 'cantidad_reposicion' => 3]);

    $sin = $this->getJson('/api/v1/compras/sugeridos', bearer(usuarioCon('compra.crear')))->assertOk();

    expect($sin->json('datos.0'))->not->toHaveKey('costo_ultimo')
        ->and($sin->json('grupos.0'))->not->toHaveKey('estimado')
        ->and($sin->json())->not->toHaveKey('estimado_pagina');

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/compras/sugeridos', bearer(usuarioCon('compra.crear', 'precio.ver_costo')))
        ->assertOk()
        ->assertJsonPath('datos.0.costo_ultimo', '1000.00')
        ->assertJsonPath('grupos.0.estimado', '3000.00')
        ->assertJsonPath('estimado_pagina', '3000.00');
});

test('el armador convierte los elegidos en renglones y cuenta los que ya no hacian falta', function () {
    $proveedor = Proveedor::factory()->create(['razon_social' => 'Austral']);
    $sigue     = Producto::factory()->conProveedor($proveedor, costo: 12000)->create(['codigo' => 'FUE-001', 'stock_minimo' => 5, 'cantidad_reposicion' => 7]);
    $cargado   = Producto::factory()->conStock(50)->conProveedor($proveedor)->create(['stock_minimo' => 5]);

    $respuesta = $this->getJson('/api/v1/compras/armador?'.http_build_query(['productos' => [$sigue->id, $cargado->id]]), bearer(usuarioCon('compra.crear')))
        ->assertOk()
        ->assertJsonCount(1, 'datos')
        ->assertJsonPath('datos.0.producto.codigo', 'FUE-001')
        ->assertJsonPath('datos.0.proveedor', ['id' => $proveedor->id, 'razon_social' => 'Austral'])
        ->assertJsonPath('datos.0.cantidad_pedida', 7)
        ->assertJsonPath('datos.0.costo_unitario', '12000.00')
        ->assertJsonPath('descartados', 1)
        ->assertJsonPath('hay_pedibles', true);

    expect($respuesta->json('aviso'))->toContain('ya no hace falta');
});

test('el armador abierto a mano no trae renglones, y una lista invalida es 422', function () {
    $this->getJson('/api/v1/compras/armador', bearer(usuarioCon('compra.crear')))
        ->assertOk()
        ->assertJsonPath('datos', [])
        ->assertJsonPath('aviso', null)
        ->assertJsonPath('hay_pedibles', false);

    $this->getJson('/api/v1/compras/armador?productos[]=999999', bearer(usuarioCon('compra.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('productos.0', 'errores');
});

test('recibir pide compra.recibir y ningun otro permiso del modulo lo habilita', function () {
    $orden = OrdenCompra::factory()->enviada()->conLinea(Producto::factory()->create(), 3)->create();
    $datos = ['cantidades' => [$orden->lineas()->value('id') => 1]];
    $otros = array_values(array_diff(permisosDelModulo('compra'), ['compra.recibir']));

    $this->postJson("/api/v1/compras/{$orden->id}/recepciones", $datos, bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');

    app('auth')->forgetGuards();

    $this->postJson("/api/v1/compras/{$orden->id}/recepciones", $datos, bearer(usuarioCon('compra.recibir')))
        ->assertCreated()
        ->assertJsonPath('datos.unidades_recibidas', 1);
});
