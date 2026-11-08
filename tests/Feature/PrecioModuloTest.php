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

dataset('rutas de precios', [
    'pantalla de factores' => ['get',    'precios.index',             'precio.ver',    null],
    'simulador'            => ['get',    'precios.simulador',         'precio.ver',    null],
    'guardar politica'     => ['put',    'precios.politica.update',   'precio.editar', null],
    'alta de impuesto'     => ['get',    'precios.impuestos.create',  'precio.editar', null],
    'crear impuesto'       => ['post',   'precios.impuestos.store',   'precio.editar', null],
    'editar impuesto'      => ['get',    'precios.impuestos.edit',    'precio.editar', 'impuesto'],
    'guardar impuesto'     => ['put',    'precios.impuestos.update',  'precio.editar', 'impuesto'],
    'borrar impuesto'      => ['delete', 'precios.impuestos.destroy', 'precio.editar', 'impuesto'],
    'alta de factor'       => ['get',    'precios.factores.create',   'precio.editar', null],
    'crear factor'         => ['post',   'precios.factores.store',    'precio.editar', null],
    'editar factor'        => ['get',    'precios.factores.edit',     'precio.editar', 'factor'],
    'guardar factor'       => ['put',    'precios.factores.update',   'precio.editar', 'factor'],
    'borrar factor'        => ['delete', 'precios.factores.destroy',  'precio.editar', 'factor'],
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

test('quien solo puede ver no recibe los botones de edicion', function () {
    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la pantalla no ofrezca lo que va a rechazar (M-33).
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.index'))
        ->assertOk()
        ->assertDontSee(route('precios.impuestos.create'))
        ->assertDontSee(route('precios.factores.create'))
        ->assertDontSee(route('precios.impuestos.edit', ivaVeintiuno()));
});

/*
|--------------------------------------------------------------------------
| La política del comercio
|--------------------------------------------------------------------------
*/

test('la politica se guarda', function () {
    $this->actingAs(usuarioCon('precio.editar'))
        ->put(route('precios.politica.update'), [
            'ganancia_global'   => '2,5',
            'recargo_lista'     => '15',
            'redondeo_multiplo' => '100',
            'redondeo_modo'     => 'arriba',
        ])
        ->assertRedirect(route('precios.index'));

    $politica = PoliticaPrecios::vigente();

    expect($politica->ganancia_global)->toBe('2.500')
        ->and($politica->recargo_lista)->toBe('15.000')
        ->and($politica->redondeo_multiplo)->toBe('100.00')
        ->and($politica->redondeo_modo)->toBe('arriba');
});

test('la politica rechaza un multiplicador que vende por debajo del costo', function () {
    // 0,25 escrito donde iba 2,5 divide por cuatro el precio del catálogo entero,
    // y sin esta regla sale un número en lugar de un error.
    $this->actingAs(usuarioCon('precio.editar'))
        ->put(route('precios.politica.update'), [
            'ganancia_global'   => '0,25',
            'recargo_lista'     => '0',
            'redondeo_multiplo' => '0,01',
            'redondeo_modo'     => 'cercano',
        ])
        ->assertSessionHasErrors('ganancia_global');

    expect(PoliticaPrecios::vigente()->ganancia_global)->toBe('1.000');
});

/*
|--------------------------------------------------------------------------
| Impuestos
|--------------------------------------------------------------------------
*/

test('se crea un impuesto general desde la pantalla', function () {
    $this->actingAs(usuarioCon('precio.editar'))
        ->post(route('precios.impuestos.store'), [
            'nombre'      => 'Impuestos internos',
            'alicuota'    => '10,5',
            'alcance'     => 'general',
            'comprobante' => 'otros_nacionales',
            'orden'       => 50,
            'activo'      => 1,
        ])
        ->assertRedirect(route('precios.index'));

    $impuesto = Impuesto::where('nombre', 'Impuestos internos')->sole();

    expect($impuesto->alicuota)->toBe('10.50')
        ->and($impuesto->alcance)->toBe('general');
});

test('no se puede repetir una alicuota de IVA', function () {
    // Dos filas con el mismo porcentaje describirían lo mismo, y el producto las
    // referencia por valor: el desglose no sabría cuál nombrar.
    $this->actingAs(usuarioCon('precio.editar'))
        ->post(route('precios.impuestos.store'), [
            'nombre'      => 'IVA veintiuno bis',
            'alicuota'    => '21',
            'alcance'     => 'producto',
            'comprobante' => 'iva',
            'orden'       => 99,
            'activo'      => 1,
        ])
        ->assertSessionHasErrors('alicuota');

    expect(Impuesto::where('alcance', 'producto')->where('alicuota', '21.00')->count())->toBe(1);
});

test('el alcance no se puede cambiar aunque el POST venga armado a mano', function () {
    // El <select> deshabilitado de la pantalla no protege nada. Si una alícuota de
    // IVA pasara a alcance general, el motor se la sumaría a todos los productos
    // ADEMÁS del IVA propio de cada uno, y los precios saldrían con IVA dos veces.
    $iva = ivaVeintiuno();

    $this->actingAs(usuarioCon('precio.editar'))
        ->put(route('precios.impuestos.update', $iva), [
            'nombre'      => $iva->nombre,
            'alicuota'    => '21',
            'alcance'     => 'general',
            'comprobante' => 'iva',
            'orden'       => $iva->orden,
            'activo'      => 1,
        ])
        ->assertSessionHasErrors('alcance');

    expect($iva->fresh()->alcance)->toBe('producto');
});

test('un impuesto que usan productos se desactiva en lugar de borrarse', function () {
    $iva = ivaVeintiuno();
    Producto::factory()->create(['alicuota_iva' => '21.00']);

    $this->actingAs(usuarioCon('precio.editar'))
        ->delete(route('precios.impuestos.destroy', $iva))
        ->assertRedirect(route('precios.index'));

    expect($iva->fresh())->not->toBeNull()
        ->and($iva->fresh()->activo)->toBeFalse();
});

test('un impuesto que no usa nadie se borra', function () {
    $impuesto = Impuesto::create([
        'nombre' => 'Percepción provincial', 'alicuota' => '2.00',
        'alcance' => 'general', 'comprobante' => 'no_se_muestra', 'orden' => 60, 'activo' => true,
    ]);

    $this->actingAs(usuarioCon('precio.editar'))
        ->delete(route('precios.impuestos.destroy', $impuesto));

    expect(Impuesto::find($impuesto->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Factores de costo
|--------------------------------------------------------------------------
*/

test('un recupero del 100 % se rechaza', function () {
    $this->actingAs(usuarioCon('precio.editar'))
        ->post(route('precios.factores.store'), [
            'nombre' => 'Imposible', 'modo' => 'recupero', 'porcentaje' => '100',
            'orden' => 0, 'activo' => 1,
        ])
        ->assertSessionHasErrors('porcentaje');

    expect(FactorCosto::count())->toBe(0);
});

test('un factor en cero se rechaza en lugar de guardarse sin efecto', function () {
    // Lo que se quiere en ese caso es desactivarlo, y para eso está la casilla.
    $this->actingAs(usuarioCon('precio.editar'))
        ->post(route('precios.factores.store'), [
            'nombre' => 'Nada', 'modo' => 'recargo', 'porcentaje' => '0',
            'orden' => 0, 'activo' => 1,
        ])
        ->assertSessionHasErrors('porcentaje');
});

test('el factor se borra, porque no lo referencia ninguna fila', function () {
    // A diferencia del impuesto: el factor vive adentro del precio ya calculado y
    // la línea de venta congela impuestos, no factores.
    $factor = FactorCosto::create([
        'nombre' => 'Flete', 'modo' => 'recargo', 'porcentaje' => '10.000', 'orden' => 0, 'activo' => true,
    ]);

    $this->actingAs(usuarioCon('precio.editar'))
        ->delete(route('precios.factores.destroy', $factor))
        ->assertRedirect(route('precios.index'));

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
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.simulador', [
            'simular'      => 1,
            'costo'        => '20000',
            'ganancia'     => '2,5',
            'alicuota_iva' => '21.00',
        ]))
        ->assertOk()
        ->assertSee('50.000,00')     // neto
        ->assertSee('10.500,00')     // IVA
        ->assertSee('60.500,00');    // precio final

    expect(HistorialPrecio::count())->toBe(0)
        ->and(Producto::count())->toBe(0);
});

test('un impuesto creado desde la pantalla aparece en el desglose sin tocar codigo', function () {
    // El segundo criterio de terminado del tramo, de punta a punta: se crea por
    // HTTP y se lo encuentra en el desglose, y no hay una línea que lo nombre.
    $this->actingAs(usuarioCon('precio.editar'))
        ->post(route('precios.impuestos.store'), [
            'nombre'      => 'Impuestos internos',
            'alicuota'    => '10',
            'alcance'     => 'general',
            'comprobante' => 'otros_nacionales',
            'orden'       => 50,
            'activo'      => 1,
        ]);

    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.simulador', [
            'simular'      => 1,
            'costo'        => '1000',
            'ganancia'     => '1',
            'alicuota_iva' => '21.00',
        ]))
        ->assertOk()
        ->assertSee('Impuestos internos')
        ->assertSee('1.331,00');     // 1.000 × 1,21 × 1,10
});