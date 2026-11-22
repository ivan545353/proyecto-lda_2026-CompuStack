<?php

use App\Models\FactorCosto;
use App\Models\HistorialPrecio;
use App\Models\Impuesto;
use App\Models\PoliticaPrecios;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/** La alícuota de IVA del 21 %, que la migración precarga. */
function ivaVeintiuno(): Impuesto
{
    return Impuesto::query()->where('alcance', 'producto')->where('alicuota', '21.00')->sole();
}

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Las dos mitades de C-2: con todos los permisos del módulo MENOS el de la
| ruta se deniega, y con SÓLO el de la ruta se permite. Si una ruta quedara
| protegida por el permiso equivocado, falla una de las dos.
|
| `precio.recalcular` y `precio.ver_costo` entran en el conjunto de «los otros»
| aunque todavía no gobiernen ninguna ruta de este módulo: son del módulo, y lo
| que se prueba es que no abran puertas que no son suyas.
|
*/

// Las pantallas, que siguen en la web. Las escrituras se retiraron en el tramo F: están en
// «endpoints de precios», más abajo, con el mismo permiso.
dataset('rutas de precios', [
    'pantalla de factores' => ['get',    'precios.index',             'precio.ver',    null],
    'simulador'            => ['get',    'precios.simulador',         'precio.ver',    null],
    'alta de impuesto'     => ['get',    'precios.impuestos.create',  'precio.editar', null],
    'editar impuesto'      => ['get',    'precios.impuestos.edit',    'precio.editar', 'impuesto'],
    'alta de factor'       => ['get',    'precios.factores.create',   'precio.editar', null],
    'editar factor'        => ['get',    'precios.factores.edit',     'precio.editar', 'factor'],
]);

/** Un cuerpo que sirve para cualquiera de las rutas: lo que sobra se ignora. */
function cuerpoDePrecios(): array
{
    return [
        'nombre'            => 'Impuesto de prueba',
        'alicuota'          => '5',
        'alcance'           => 'general',
        'comprobante'       => 'otros_nacionales',
        'modo'              => 'recargo',
        'porcentaje'        => '5',
        'orden'             => 0,
        'activo'            => 1,
        'ganancia_global'   => '2',
        'recargo_lista'     => '0',
        'redondeo_multiplo' => '0,01',
        'redondeo_modo'     => 'cercano',
    ];
}

/** La URL de la ruta, con el impuesto o el factor que necesite. */
function urlDePrecios(string $ruta, ?string $parametro): string
{
    return match ($parametro) {
        'impuesto' => route($ruta, ivaVeintiuno()),
        'factor'   => route($ruta, FactorCosto::create([
            'nombre' => 'Flete', 'modo' => 'recargo', 'porcentaje' => '10', 'orden' => 0, 'activo' => true,
        ])),
        default => route($ruta),
    };
}

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, ?string $parametro) {
    $otros = array_values(array_diff(permisosDelModulo('precio'), [$permiso]));

    pedirRuta(
        $this->actingAs(usuarioCon(...$otros)),
        $metodo,
        urlDePrecios($ruta, $parametro),
        cuerpoDePrecios(),
    )->assertForbidden();
})->with('rutas de precios');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, ?string $parametro) {
    $respuesta = pedirRuta(
        $this->actingAs(usuarioCon($permiso)),
        $metodo,
        urlDePrecios($ruta, $parametro),
        cuerpoDePrecios(),
    );

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de precios');

// Las operaciones, en la API (tramo F de la Etapa 3). `{impuesto}` y `{factor}` se reemplazan por los del test.
dataset('endpoints de precios', [
    'impuestos'        => ['get',    '/api/v1/impuestos',                'precio.ver'],
    'ficha impuesto'   => ['get',    '/api/v1/impuestos/{impuesto}',     'precio.ver'],
    'crear impuesto'   => ['post',   '/api/v1/impuestos',                'precio.editar'],
    'guardar impuesto' => ['patch',  '/api/v1/impuestos/{impuesto}',     'precio.editar'],
    'borrar impuesto'  => ['delete', '/api/v1/impuestos/{impuesto}',     'precio.editar'],
    'factores'         => ['get',    '/api/v1/factores-costo',           'precio.ver'],
    'ficha factor'     => ['get',    '/api/v1/factores-costo/{factor}',  'precio.ver'],
    'crear factor'     => ['post',   '/api/v1/factores-costo',           'precio.editar'],
    'guardar factor'   => ['patch',  '/api/v1/factores-costo/{factor}',  'precio.editar'],
    'borrar factor'    => ['delete', '/api/v1/factores-costo/{factor}',  'precio.editar'],
    'politica'         => ['get',    '/api/v1/precios/politica',         'precio.ver'],
    'guardar politica' => ['patch',  '/api/v1/precios/politica',         'precio.editar'],
    'simulacion'       => ['get',    '/api/v1/precios/simulacion?costo=1000&alicuota_iva=21.00', 'precio.ver'],
]);

/** La URL del endpoint, con un impuesto general y un factor que el cuerpo de prueba puede guardar. */
function urlDeEndpointDePrecios(string $url): string
{
    $impuesto = Impuesto::create([
        'nombre' => 'Percepción de prueba', 'alicuota' => '3.00',
        'alcance' => 'general', 'comprobante' => 'no_se_muestra', 'orden' => 70, 'activo' => true,
    ]);
    $factor = FactorCosto::create([
        'nombre' => 'Flete', 'modo' => 'recargo', 'porcentaje' => '10', 'orden' => 0, 'activo' => true,
    ]);

    return str_replace(['{impuesto}', '{factor}'], [$impuesto->id, $factor->id], $url);
}

/** El cuerpo de la API: la casilla viaja como booleano. */
function cuerpoDePreciosApi(): array
{
    return array_merge(cuerpoDePrecios(), ['activo' => true]);
}

test('en la API, ningun otro permiso del modulo habilita el endpoint', function (string $metodo, string $url, string $permiso) {
    $otros = array_values(array_diff(permisosDelModulo('precio'), [$permiso]));

    $this->json($metodo, urlDeEndpointDePrecios($url), cuerpoDePreciosApi(), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints de precios');

test('en la API, el permiso del endpoint alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    $respuesta = $this->json($metodo, urlDeEndpointDePrecios($url), cuerpoDePreciosApi(), bearer(usuarioCon($permiso)));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints de precios');

test('quien solo puede ver no recibe los botones de edicion', function () {
    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la pantalla no ofrezca lo que va a rechazar (M-33).
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.index'))
        ->assertOk()
        ->assertDontSee(route('precios.impuestos.create'))
        ->assertDontSee(route('precios.factores.create'))
        ->assertDontSee(route('precios.impuestos.edit', ivaVeintiuno()))
        // Desde el tramo F las filas las arma el JS con la plantilla: el botón no está ni en ella.
        ->assertDontSee('data-accion="editar"', false)
        ->assertDontSee('data-eliminar', false)
        ->assertDontSee('data-formulario-politica', false)
        ->assertSee('data-politica-lectura', false);

    app('auth')->forgetGuards();

    // La otra dirección: con el permiso, la plantilla trae los botones y la política se edita.
    $this->actingAs(usuarioCon('precio.ver', 'precio.editar'))
        ->get(route('precios.index'))
        ->assertOk()
        ->assertSee(route('precios.impuestos.create'))
        ->assertSee('data-accion="editar"', false)
        ->assertSee('data-eliminar', false)
        ->assertSee('data-formulario-politica', false)
        ->assertDontSee('data-politica-lectura', false);
});

test('los shells de precios no traen datos de negocio', function () {
    // Regla 37: la página llega con su estructura, los datos por la API.
    $impuesto = Impuesto::create([
        'nombre' => 'Tasa Secreta', 'alicuota' => '4.00',
        'alcance' => 'general', 'comprobante' => 'no_se_muestra', 'orden' => 80, 'activo' => true,
    ]);
    $factor = FactorCosto::create([
        'nombre' => 'Flete Secreto', 'modo' => 'recargo', 'porcentaje' => '7', 'orden' => 0, 'activo' => true,
    ]);
    $usuario = usuarioCon('precio.ver', 'precio.editar');

    $this->actingAs($usuario)->get(route('precios.index'))
        ->assertOk()
        ->assertSee('data-pantalla="precios"', false)
        ->assertDontSee('Tasa Secreta')
        ->assertDontSee('Flete Secreto')
        ->assertDontSee('IVA 21');

    $this->actingAs($usuario)->get(route('precios.impuestos.edit', $impuesto))
        ->assertOk()
        ->assertSee('data-impuesto="'.$impuesto->id.'"', false)
        ->assertDontSee('Tasa Secreta');

    $this->actingAs($usuario)->get(route('precios.factores.edit', $factor))
        ->assertOk()
        ->assertSee('data-factor="'.$factor->id.'"', false)
        ->assertDontSee('Flete Secreto');

    $this->actingAs($usuario)->get(route('precios.simulador'))
        ->assertOk()
        ->assertSee('data-pantalla="simulador"', false)
        ->assertDontSee('IVA 21');
});

test('las rutas web de escritura de precios ya no existen', function (string $metodo, string $url) {
    // HL-103: la operación está en la API; la web sólo entrega páginas.
    expect(pedirRuta($this->actingAs(admin()), $metodo, $url, cuerpoDePrecios())->status())->toBeIn([404, 405]);
})->with([
    'politica'         => ['put',    '/precios/politica'],
    'crear impuesto'   => ['post',   '/precios/impuestos'],
    'guardar impuesto' => ['put',    '/precios/impuestos/1'],
    'borrar impuesto'  => ['delete', '/precios/impuestos/1'],
    'crear factor'     => ['post',   '/precios/factores'],
    'guardar factor'   => ['put',    '/precios/factores/1'],
    'borrar factor'    => ['delete', '/precios/factores/1'],
]);

/*
|--------------------------------------------------------------------------
| La política del comercio
|--------------------------------------------------------------------------
*/

test('la politica se guarda', function () {
    $this->patchJson('/api/v1/precios/politica', [
        'ganancia_global'   => '2,5',
        'recargo_lista'     => '15',
        'redondeo_multiplo' => '100',
        'redondeo_modo'     => 'arriba',
    ], bearer(usuarioCon('precio.editar')))
        ->assertOk()
        ->assertJsonPath('datos.ganancia_global', '2.500')
        ->assertJsonPath('datos.margen_global', '60.00');

    $politica = PoliticaPrecios::vigente();

    expect($politica->ganancia_global)->toBe('2.500')
        ->and($politica->recargo_lista)->toBe('15.000')
        ->and($politica->redondeo_multiplo)->toBe('100.00')
        ->and($politica->redondeo_modo)->toBe('arriba');
});

test('la politica rechaza un multiplicador que vende por debajo del costo', function () {
    // 0,25 escrito donde iba 2,5 divide por cuatro el precio del catálogo entero,
    // y sin esta regla sale un número en lugar de un error.
    $this->patchJson('/api/v1/precios/politica', [
        'ganancia_global'   => '0,25',
        'recargo_lista'     => '0',
        'redondeo_multiplo' => '0,01',
        'redondeo_modo'     => 'cercano',
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ganancia_global', 'errores');

    expect(PoliticaPrecios::vigente()->ganancia_global)->toBe('1.000');
});

/*
|--------------------------------------------------------------------------
| Impuestos
|--------------------------------------------------------------------------
*/

test('se crea un impuesto general desde la pantalla', function () {
    $this->postJson('/api/v1/impuestos', [
        'nombre'      => 'Impuestos internos',
        'alicuota'    => '10,5',
        'alcance'     => 'general',
        'comprobante' => 'otros_nacionales',
        'orden'       => 50,
        'activo'      => true,
    ], bearer(usuarioCon('precio.editar')))
        ->assertCreated()
        ->assertJsonPath('datos.alicuota', '10.50');

    $impuesto = Impuesto::where('nombre', 'Impuestos internos')->sole();

    expect($impuesto->alicuota)->toBe('10.50')
        ->and($impuesto->alcance)->toBe('general');
});

test('no se puede repetir una alicuota de IVA', function () {
    // Dos filas con el mismo porcentaje describirían lo mismo, y el producto las
    // referencia por valor: el desglose no sabría cuál nombrar.
    $this->postJson('/api/v1/impuestos', [
        'nombre'      => 'IVA veintiuno bis',
        'alicuota'    => '21',
        'alcance'     => 'producto',
        'comprobante' => 'iva',
        'orden'       => 99,
        'activo'      => true,
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alicuota', 'errores');

    expect(Impuesto::where('alcance', 'producto')->where('alicuota', '21.00')->count())->toBe(1);
});

test('el alcance no se puede cambiar aunque el POST venga armado a mano', function () {
    // El <select> deshabilitado de la pantalla no protege nada. Si una alícuota de
    // IVA pasara a alcance general, el motor se la sumaría a todos los productos
    // ADEMÁS del IVA propio de cada uno, y los precios saldrían con IVA dos veces.
    $iva = ivaVeintiuno();

    $this->patchJson("/api/v1/impuestos/{$iva->id}", [
        'nombre'      => $iva->nombre,
        'alicuota'    => '21',
        'alcance'     => 'general',
        'comprobante' => 'iva',
        'orden'       => $iva->orden,
        'activo'      => true,
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alcance', 'errores');

    expect($iva->fresh()->alcance)->toBe('producto');
});

test('un impuesto que usan productos se desactiva en lugar de borrarse', function () {
    $iva = ivaVeintiuno();
    Producto::factory()->create(['alicuota_iva' => '21.00']);

    $this->deleteJson("/api/v1/impuestos/{$iva->id}", [], bearer(usuarioCon('precio.editar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', false);

    expect($iva->fresh())->not->toBeNull()
        ->and($iva->fresh()->activo)->toBeFalse();
});

test('un impuesto que no usa nadie se borra', function () {
    $impuesto = Impuesto::create([
        'nombre' => 'Percepción provincial', 'alicuota' => '2.00',
        'alcance' => 'general', 'comprobante' => 'no_se_muestra', 'orden' => 60, 'activo' => true,
    ]);

    $this->deleteJson("/api/v1/impuestos/{$impuesto->id}", [], bearer(usuarioCon('precio.editar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true);

    expect(Impuesto::find($impuesto->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Factores de costo
|--------------------------------------------------------------------------
*/

test('un recupero del 100 % se rechaza', function () {
    $this->postJson('/api/v1/factores-costo', [
        'nombre' => 'Imposible', 'modo' => 'recupero', 'porcentaje' => '100',
        'orden' => 0, 'activo' => true,
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('porcentaje', 'errores');

    expect(FactorCosto::count())->toBe(0);
});

test('un factor en cero se rechaza en lugar de guardarse sin efecto', function () {
    // Lo que se quiere en ese caso es desactivarlo, y para eso está la casilla.
    $this->postJson('/api/v1/factores-costo', [
        'nombre' => 'Nada', 'modo' => 'recargo', 'porcentaje' => '0',
        'orden' => 0, 'activo' => true,
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('porcentaje', 'errores');
});

test('el factor se borra, porque no lo referencia ninguna fila', function () {
    // A diferencia del impuesto: el factor vive adentro del precio ya calculado y
    // la línea de venta congela impuestos, no factores.
    $factor = FactorCosto::create([
        'nombre' => 'Flete', 'modo' => 'recargo', 'porcentaje' => '10.000', 'orden' => 0, 'activo' => true,
    ]);

    $this->deleteJson("/api/v1/factores-costo/{$factor->id}", [], bearer(usuarioCon('precio.editar')))
        ->assertOk()
        ->assertJsonPath('datos.eliminado', true);

    expect(FactorCosto::find($factor->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| El simulador
|--------------------------------------------------------------------------
*/

test('el simulador se abre vacio y no pide nada', function () {
    // Sin el campo oculto no hay nada que validar: la pantalla se dibuja sin
    // resultado en lugar de rebotar con «el costo es obligatorio».
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.simulador'))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertSee('Cargá un costo');
});

test('el simulador muestra el criterio del tramo y no guarda nada', function () {
    $this->getJson('/api/v1/precios/simulacion?'.http_build_query([
        'costo'        => '20000',
        'ganancia'     => '2,5',
        'alicuota_iva' => '21.00',
    ]), bearer(usuarioCon('precio.ver')))
        ->assertOk()
        ->assertJsonPath('datos.ganancia_es_global', false)
        ->assertJsonPath('datos.calculo.contado.neto', '50000.00')                   // neto
        ->assertJsonPath('datos.calculo.contado.impuestos.0.importe', '10500.00')    // IVA
        ->assertJsonPath('datos.calculo.contado.final', '60500.00');                 // precio final

    expect(HistorialPrecio::count())->toBe(0)
        ->and(Producto::count())->toBe(0);
});

test('un impuesto creado desde la pantalla aparece en el desglose sin tocar codigo', function () {
    // El segundo criterio de terminado del tramo, de punta a punta: se crea por
    // HTTP y se lo encuentra en el desglose, y no hay una línea que lo nombre.
    $this->postJson('/api/v1/impuestos', [
        'nombre'      => 'Impuestos internos',
        'alicuota'    => '10',
        'alcance'     => 'general',
        'comprobante' => 'otros_nacionales',
        'orden'       => 50,
        'activo'      => true,
    ], bearer(usuarioCon('precio.editar')))->assertCreated();

    app('auth')->forgetGuards();

    $calculo = $this->getJson('/api/v1/precios/simulacion?'.http_build_query([
        'costo'        => '1000',
        'ganancia'     => '1',
        'alicuota_iva' => '21.00',
    ]), bearer(usuarioCon('precio.ver')))
        ->assertOk()
        ->json('datos.calculo');

    expect(collect($calculo['contado']['impuestos'])->pluck('nombre'))->toContain('Impuestos internos')
        ->and($calculo['contado']['final'])->toBe('1331.00');     // 1.000 × 1,21 × 1,10
});

test('el listado de impuestos dice cuantos productos usan cada alicuota, y el general no trae la clave', function () {
    Producto::factory()->count(2)->create(['alicuota_iva' => '21.00']);
    Impuesto::create([
        'nombre' => 'Percepción provincial', 'alicuota' => '2.00',
        'alcance' => 'general', 'comprobante' => 'no_se_muestra', 'orden' => 60, 'activo' => true,
    ]);

    $filas = collect($this->getJson('/api/v1/impuestos', bearer(usuarioCon('precio.ver')))->assertOk()->json('datos'));

    expect($filas->firstWhere('alicuota', '21.00')['productos'])->toBe(2)
        ->and($filas->firstWhere('nombre', 'Percepción provincial'))->not->toHaveKey('productos');
});

test('la API rechaza un impuesto sin la casilla de activo en lugar de suponerla', function () {
    // Rechaza, no corrige (M-31): la web convertía la casilla ausente en «inactivo».
    $this->postJson('/api/v1/impuestos', [
        'nombre' => 'Sin casilla', 'alicuota' => '1', 'alcance' => 'general',
        'comprobante' => 'no_se_muestra', 'orden' => 0,
    ], bearer(usuarioCon('precio.editar')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('activo', 'errores');

    expect(Impuesto::where('nombre', 'Sin casilla')->exists())->toBeFalse();
});

test('el simulador sin ganancia usa la global y lo dice', function () {
    $respuesta = $this->getJson('/api/v1/precios/simulacion?costo=1000&alicuota_iva=21.00', bearer(usuarioCon('precio.ver')))
        ->assertOk()
        ->assertJsonPath('datos.ganancia_es_global', true);

    expect($respuesta->json('datos.calculo.ganancia'))->toBe(PoliticaPrecios::vigente()->ganancia_global);
});

test('el simulador de la API rechaza un pedido sin costo', function () {
    // En la API pedir la simulación ya es simular: no hay pantalla vacía que dibujar.
    $this->getJson('/api/v1/precios/simulacion?alicuota_iva=21.00', bearer(usuarioCon('precio.ver')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('costo', 'errores');
});
