<?php

use App\Models\Cliente;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| El alta de cliente desde la venta (tramo I)
|--------------------------------------------------------------------------
|
| La ventana flotante postea al mismo endpoint que el formulario de clientes
| (`POST /api/v1/clientes`, tramo E): el alta rápida y el alta normal son dos
| clientes del mismo camino. Estos tests fijan las dos mitades de esa decisión
| —que las reglas son las mismas, y que la fila que vuelve es la misma que
| traería una búsqueda— y el permiso de la acción.
|
*/

test('el alta pedida en json devuelve 201 con la misma fila que traeria una busqueda', function () {
    $usuario = usuarioCon('cliente.crear', 'cliente.ver');

    $alta = $this->postJson('/api/v1/clientes', datosDeCliente(), bearer($usuario))
        ->assertCreated();

    // Desde el tramo D la búsqueda es el listado de clientes de la API.
    $busqueda = $this->getJson('/api/v1/clientes?q=tilos', bearer($usuario))
        ->assertOk();

    // Igualdad estricta, claves y tipos: si un camino cambia la fila y el otro no, la
    // venta mostraría dos formatos para el mismo cliente.
    expect($alta->json('datos'))->toBe($busqueda->json('datos.0'))
        ->and($alta->json('datos.texto'))->toBe('Panadería Los Tilos — DNI 41556778');
});

test('el alta en json con datos invalidos devuelve 422 con el error en su campo', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente([
        'condicion_iva' => 'responsable_inscripto',
        'nro_doc'       => '',
    ]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'Para esa condición frente al IVA hace falta el número de documento.');

    expect(Cliente::count())->toBe(0);
});

test('el alta en json rechaza el documento repetido con el mismo mensaje que el formulario', function () {
    Cliente::factory()->create(['tipo_doc' => 'dni', 'nro_doc' => '41556778']);

    $this->postJson('/api/v1/clientes', datosDeCliente(), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonPath('errores.nro_doc.0', 'Ya hay un cliente registrado con ese documento.');

    expect(Cliente::count())->toBe(1);
});

test('el alta en json tampoco asigna una cuenta de acceso', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente(['user_id' => admin()->id]), bearer(usuarioCon('cliente.crear')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id', 'errores');

    expect(Cliente::count())->toBe(0);
});

test('crear un cliente desde la venta exige cliente.crear y no alcanza con venta.crear', function () {
    $this->postJson('/api/v1/clientes', datosDeCliente(), bearer(usuarioCon('venta.crear', 'cliente.ver')))
        ->assertForbidden();

    expect(Cliente::count())->toBe(0);

    // Dos cuentas en el mismo test: el guard de la primera no se hereda a la segunda.
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/clientes', datosDeCliente(), bearer(usuarioCon('cliente.crear')))
        ->assertCreated();
});


/*
|--------------------------------------------------------------------------
| La ventana en la pantalla de la venta
|--------------------------------------------------------------------------
*/

test('la venta ofrece el alta de cliente solo a quien puede crear clientes', function () {
    productoConPrecios(contado: 1000);

    $this->actingAs(usuarioCon('venta.crear', 'producto.ver', 'cliente.ver'))
        ->get(route('ventas.create'))
        ->assertOk()
        ->assertDontSee('data-alta-rapida', false)
        ->assertDontSee('no guardaste acá');

    // Sin JavaScript el botón es un enlace a la pantalla de clientes, y el <noscript>
    // dice qué se pierde al seguirlo.
    $this->actingAs(usuarioCon('venta.crear', 'producto.ver', 'cliente.ver', 'cliente.crear'))
        ->get(route('ventas.create'))
        ->assertOk()
        ->assertSee('data-alta-rapida-abre', false)
        ->assertSee('href="'.route('clientes.create').'"', false)
        ->assertSee('data-alta-rapida-api="/clientes"', false)
        ->assertSee('no guardaste acá');
});

test('la ventana del cliente vive afuera del formulario de la venta', function () {
    productoConPrecios(contado: 1000);

    $html = (string) $this->actingAs(usuarioCon('venta.crear', 'producto.ver', 'cliente.ver', 'cliente.crear'))
        ->get(route('ventas.create'))
        ->assertOk()
        ->getContent();

    $venta   = strpos($html, 'action="'.route('ventas.store').'"');
    $cierre  = strpos($html, '</form>', $venta);
    $ventana = strpos($html, 'data-alta-rapida="');

    // Un <form> adentro de otro lo descarta el navegador: el submit de la ventana
    // mandaría la venta entera.
    expect($venta)->toBeInt()
        ->and($ventana)->toBeGreaterThan($cierre);
});

test('la ventana no hace crecer el formulario de venta con el catalogo ni con el padron', function () {
    $usuario = usuarioCon('venta.crear', 'producto.ver', 'cliente.ver', 'cliente.crear');
    $peso    = fn () => strlen((string) $this->actingAs($usuario)->get(route('ventas.create'))->assertOk()->getContent());

    Producto::factory()->count(5)->create();
    Cliente::factory()->count(5)->create();

    $pocos = $peso();

    Producto::factory()->count(55)->create();
    Cliente::factory()->count(55)->create();

    expect($peso())->toBe($pocos);
});