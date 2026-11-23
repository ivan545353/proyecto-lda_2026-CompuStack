<?php

use App\Models\Producto;
use App\Models\Venta;
use Carbon\Carbon;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

const PERMISOS_PANEL = [
    'panel.ver_propio',
    'panel.ver_global',
];

dataset('rutas del panel', [
    'panel propio'  => ['get', 'panel', 'panel.ver_propio'],
    'panel general' => ['get', 'panel.general', 'panel.ver_global'],
]);

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| En las dos direcciones, como en todos los módulos: con el otro permiso del módulo
| se deniega, y con el propio se permite. La primera mitad es la que detecta una ruta
| protegida por el permiso equivocado, que es lo que el `?? "can_update"` del original
| hacía pasar desapercibido (C-2). Hasta esta fase /panel no exigía ninguno.
|
*/

test('ningun otro permiso del modulo habilita las rutas del panel', function (string $metodo, string $ruta, string $permiso) {
    $otros = array_values(array_diff(PERMISOS_PANEL, [$permiso]));

    pedirRuta($this->actingAs(usuarioCon(...$otros)), $metodo, route($ruta))
        ->assertForbidden();
})->with('rutas del panel');

test('el permiso de la ruta alcanza para pasar en las rutas del panel', function (string $metodo, string $ruta, string $permiso) {
    pedirRuta($this->actingAs(usuarioCon($permiso)), $metodo, route($ruta))
        ->assertOk();
})->with('rutas del panel');

// Tramo H: los números van por la API, con el mismo permiso por panel (C-2), en las dos direcciones.
dataset('endpoints del panel', [
    'panel propio'  => ['/api/v1/panel', 'panel.ver_propio'],
    'panel general' => ['/api/v1/panel/general', 'panel.ver_global'],
]);

test('ningun otro permiso del modulo habilita los endpoints del panel', function (string $ruta, string $permiso) {
    $otros = array_values(array_diff(PERMISOS_PANEL, [$permiso]));

    $this->getJson($ruta, bearer(usuarioCon(...$otros)))->assertForbidden();
})->with('endpoints del panel');

test('el permiso del endpoint alcanza para pasar en los endpoints del panel', function (string $ruta, string $permiso) {
    $this->getJson($ruta, bearer(usuarioCon($permiso)))->assertOk();
})->with('endpoints del panel');

test('un usuario sin ningun permiso de panel no entra a ninguna de las dos', function () {
    $usuario = usuarioCon('venta.ver');

    $this->actingAs($usuario)->get(route('panel'))->assertForbidden();
    $this->actingAs($usuario)->get(route('panel.general'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| El inicio
|--------------------------------------------------------------------------
|
| Ahora que el panel exige permiso, la raíz y el login no pueden apuntar derecho ahí:
| un rol sin ningún permiso de panel vería un 403 sin navbar del que salir.
|
*/

test('el inicio manda al panel general a quien puede ver el conjunto', function () {
    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('inicio'))
        ->assertRedirect(route('panel.general'));
});

test('el inicio manda al panel propio a quien solo ve lo suyo', function () {
    $this->actingAs(usuarioCon('panel.ver_propio'))
        ->get(route('inicio'))
        ->assertRedirect(route('panel'));
});

test('el inicio manda a la primera pantalla disponible a quien no ve ningun panel', function () {
    $this->actingAs(usuarioCon('venta.ver'))
        ->get(route('inicio'))
        ->assertRedirect(route('ventas.index'));
});

test('el inicio explica el problema cuando el rol no tiene ninguna pantalla', function () {
    $this->actingAs(usuarioCon())
        ->get(route('inicio'))
        ->assertForbidden();
});

test('la raiz redirige al inicio y no al panel', function () {
    $this->actingAs(usuarioCon('panel.ver_propio'))
        ->get('/')
        ->assertRedirect('/inicio');
});

/*
|--------------------------------------------------------------------------
| El período llega del parámetro de la URL al servicio
|--------------------------------------------------------------------------
|
| Es la lección de A-24: el scope andaba y el cableado no. Un filtro que el
| controlador no pasa deja el panel mostrando siempre el mes en curso sin que nada
| falle a la vista.
|
*/

test('el periodo de la URL llega a las metricas', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');

    $this->travelTo(Carbon::parse('2026-03-10 10:00'));
    ventaCobrada($producto, 3, vendedor: $vendedor, cajero: $vendedor);
    $this->travelBack();

    $this->travelTo(Carbon::parse('2026-04-10 10:00'));
    ventaCobrada($producto, 1, vendedor: $vendedor, cajero: $vendedor);
    $this->travelBack();

    // Tramo H: el shell pide a la API la consulta de su URL, tal cual.
    $this->getJson('/api/v1/panel?desde=2026-03-01&hasta=2026-03-31', bearer($vendedor))
        ->assertOk()
        ->assertJsonPath('datos.periodo.desde', '2026-03-01')
        ->assertJsonPath('datos.vendi.cantidad', 1)
        ->assertJsonPath('datos.vendi.facturado', '3000.00');
});

test('el periodo por omision es el mes en curso', function () {
    $this->getJson('/api/v1/panel', bearer(usuarioCon('panel.ver_propio')))
        ->assertOk()
        ->assertJsonPath('datos.periodo.desde', now()->startOfMonth()->toDateString())
        ->assertJsonPath('datos.periodo.hasta', now()->toDateString());
});

test('un periodo invalido se rechaza con el error en su campo', function () {
    // La fecha final anterior a la inicial. Tramo H: antes redirigía al mes en curso con un
    // aviso; por la API es un 422 que el filtro marca en su campo (patrón HL-120), y el
    // panel no muestra números de un período que nadie pidió.
    $this->getJson('/api/v1/panel?desde=2026-03-31&hasta=2026-03-01', bearer(usuarioCon('panel.ver_propio')))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('hasta', 'errores')
        ->assertJsonMissingPath('datos');
});

/*
|--------------------------------------------------------------------------
| Cada pantalla muestra lo suyo, en las dos direcciones
|--------------------------------------------------------------------------
*/

test('el panel propio muestra lo que vendi y lo que cobre por separado', function () {
    $producto = productoConPrecios(contado: 1000);
    $vendedor = usuarioCon('panel.ver_propio');
    $cajero   = usuarioCon('panel.ver_propio');

    ventaCobrada($producto, 3, vendedor: $vendedor, cajero: $cajero);

    // Quién vendió y quién cobró son dos columnas de dos tablas (M-19): el Cajero no
    // vende, así que sin el segundo bloque su panel sería todo ceros.
    $this->getJson('/api/v1/panel', bearer($cajero))
        ->assertOk()
        ->assertJsonPath('datos.vendi.cantidad', 0)
        ->assertJsonPath('datos.cobre.neto', '3000.00');

    app('auth')->forgetGuards();

    $this->actingAs($cajero)
        ->get(route('panel'))
        ->assertOk()
        ->assertSeeText('Lo que cobré');
});

test('el panel propio no entrega los numeros del conjunto', function () {
    ventaCobrada(productoConPrecios(contado: 1000), 3);   // de otro vendedor

    $this->getJson('/api/v1/panel', bearer(usuarioCon('panel.ver_propio')))
        ->assertOk()
        ->assertJsonPath('datos.vendi.cantidad', 0)
        ->assertJsonMissingPath('datos.ranking_vendedores')
        ->assertJsonMissingPath('datos.margen_bruto')
        ->assertJsonMissingPath('datos.cobrado');
});

test('el panel general entrega el conjunto y ya no calcula los rankings', function () {
    ventaCobrada(productoConPrecios(contado: 1000, costo: 600), 3);

    // Desde el tramo L el ranking y los más vendidos son listados (ventas.vendedores y
    // ventas.productos): el panel no los calcula para no mostrarlos.
    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('datos.facturado', '3000.00')
        ->assertJsonPath('datos.margen_bruto', '1200.00')
        ->assertJsonMissingPath('datos.ranking_vendedores')
        ->assertJsonMissingPath('datos.mas_vendidos');
});

test('el panel general informa el margen bruto y los descuentos por separado', function () {
    ventaCobrada(productoConPrecios(contado: 1000, costo: 600), 3, porcentajeDescuento: 10);

    // El margen sale de la línea y no conoce `ventas.descuento`. La pantalla lo rotula
    // como bruto y muestra los descuentos al lado, en vez de informar un margen
    // optimista sin decirlo.
    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('datos.margen_bruto', '1200.00')
        ->assertJsonPath('datos.descuentos', '300.00');

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSeeText('Margen bruto')
        ->assertSeeText('Descuentos otorgados');
});

test('el panel general muestra el stock critico y las compras abiertas', function () {
    Producto::factory()->conStock(1)->create(['stock_minimo' => 5]);

    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('datos.stock_critico', 1)
        ->assertJsonPath('datos.compras_abiertas', 0);
});

test('el panel propio enlaza al conjunto solo si el usuario puede verlo', function () {
    $this->actingAs(usuarioCon('panel.ver_propio', 'panel.ver_global'))
        ->get(route('panel'))
        ->assertOk()
        ->assertSee(route('panel.general'));

    $this->actingAs(usuarioCon('panel.ver_propio'))
        ->get(route('panel'))
        ->assertOk()
        ->assertDontSee(route('panel.general'));
});

test('el panel vacio distingue el periodo sin ventas de la falta de datos', function () {
    Venta::factory()->conLinea(productoConPrecios(contado: 1000), 2)->create();

    // Un presupuesto no es una venta cobrada: el período queda sin ventas, con su aviso.
    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('datos.cantidad', 0)
        ->assertJsonPath('datos.por_metodo', []);

    app('auth')->forgetGuards();

    $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->assertSeeText('No hubo ventas cobradas en este período');
});

/*
|--------------------------------------------------------------------------
| Los gráficos
|--------------------------------------------------------------------------
*/

test('los graficos reciben los numeros ya agregados y no las filas', function () {
    $producto = productoConPrecios(contado: 1000);

    ventaCobrada($producto, 3);
    ventaCobrada($producto, 2);

    // Dos ventas del mismo día llegan como UN punto con la suma ya hecha en la base, no
    // como dos filas para que el navegador las agrupe. Eso último sería A-26 otra vez.
    // Tramo H: la serie es `por_dia` de la API.
    $serie = $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->json('datos.por_dia');

    expect($serie)->toHaveCount(1)
        ->and($serie[0]['facturado'])->toBe('5000.00')
        ->and($serie[0]['cantidad'])->toBe(2);
});

test('sin ventas en el periodo el panel no emite ningun grafico', function () {
    $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
        ->assertOk()
        ->assertJsonPath('datos.por_dia', []);

    app('auth')->forgetGuards();

    // Tramo H: el lienzo es del shell, adentro de un bloque que nace oculto; el JS lo muestra
    // sólo si el período tiene ventas, así que sin ventas no se dibuja nada.
    $html = $this->actingAs(usuarioCon('panel.ver_global'))
        ->get(route('panel.general'))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/data-si="con_ventas" hidden>.*data-grafico-dias/s');
});

test('los shells de los paneles no traen datos de negocio', function () {
    // Regla 37: la página llega con su estructura; los números, por la API.
    ventaCobrada(productoConPrecios(contado: 1234), 3);
    Producto::factory()->conStock(1)->create(['stock_minimo' => 5]);

    foreach ([route('panel'), route('panel.general')] as $pantalla) {
        $this->actingAs(usuarioCon('panel.ver_propio', 'panel.ver_global', 'compra.ver', 'compra.crear'))
            ->get($pantalla)
            ->assertOk()
            ->assertDontSee('3.702,00')
            ->assertDontSee('id="facturadoPorDia"', false)
            ->assertDontSee('Sin datos del período anterior');
    }
});
