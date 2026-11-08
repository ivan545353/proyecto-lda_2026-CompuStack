<?php

use App\Models\Categoria;
use App\Models\HistorialPrecio;
use App\Models\LoteAjuste;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Los números del comercio, para que el motor dé 60.500 con un costo de 20.000.
    // `servicioConPolitica()` vive en PrecioServiceTest.
    servicioConPolitica();
});

/*
|--------------------------------------------------------------------------
| El segundo nivel del ajuste masivo
|--------------------------------------------------------------------------
|
| `AjusteMasivoServiceTest` prueba que el servicio hace lo que dice;
| `AjusteMasivoFiltrosTest`, que cada scope filtra. Lo que falta es el cableado:
| que el parámetro de la URL llegue al scope, que cada ruta exija su permiso, que
| la validación rechace y que la pantalla no ofrezca lo que el servidor va a negar.
|
| A-24 pasó once commits sin detectarse porque el scope funcionaba y el cableado no.
| Acá el riesgo es el mismo con siete recortes en lugar de uno.
|
*/

/** Un cuerpo válido que sirve para cualquiera de las rutas; lo que sobra se ignora. */
function cuerpoDeAjuste(array $cambios = []): array
{
    return array_merge([
        'operacion'  => 'precio',
        'porcentaje' => '15',
        'alcance'    => 'todos',
    ], $cambios);
}

/** La URL de la ruta, con el lote que necesite. `loteDePrueba()` vive en PrecioServiceTest. */
function urlDelAjuste(string $ruta, ?string $parametro): string
{
    return $parametro === 'lote'
        ? route($ruta, loteDePrueba())
        : route($ruta);
}

/** La previsualización de un ajuste, como la pide la pantalla: por GET. */
function previsualizacion(array $parametros = []): string
{
    return route('precios.ajuste', array_merge([
        'previsualizar' => 1,
        'operacion'     => 'precio',
        'porcentaje'    => '15',
        'alcance'       => 'recorte',
    ], $parametros));
}

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Las dos mitades de C-2: con todos los permisos del módulo MENOS el de la ruta se
| deniega, y con SÓLO el de la ruta se permite. Si una ruta quedara protegida por el
| permiso equivocado, falla una de las dos.
|
| `PERMISOS_PRECIO` es la lista completa del módulo y vive en PrecioModuloTest.
|
*/

dataset('rutas del ajuste masivo', [
    'pantalla del ajuste' => ['get',  'precios.ajuste',         'precio.ajustar_masivo',  null],
    'aplicar el ajuste'   => ['post', 'precios.ajuste.aplicar', 'precio.ajustar_masivo',  null],
    'historial de lotes'  => ['get',  'precios.lotes',          'precio.ver',             null],
    'deshacer un ajuste'  => ['post', 'precios.lotes.deshacer', 'precio.deshacer_ajuste', 'lote'],
]);

test('ningun otro permiso del modulo habilita la ruta del ajuste', function (string $metodo, string $ruta, string $permiso, ?string $parametro) {
    $otros = array_values(array_diff(permisosDelModulo('precio'), [$permiso]));

    pedirRuta(
        $this->actingAs(usuarioCon(...$otros)),
        $metodo,
        urlDelAjuste($ruta, $parametro),
        cuerpoDeAjuste(),
    )->assertForbidden();
})->with('rutas del ajuste masivo');

test('el permiso de la ruta del ajuste alcanza para pasar', function (string $metodo, string $ruta, string $permiso, ?string $parametro) {
    $respuesta = pedirRuta(
        $this->actingAs(usuarioCon($permiso)),
        $metodo,
        urlDelAjuste($ruta, $parametro),
        cuerpoDeAjuste(),
    );

    expect($respuesta->status())->not->toBe(403);
})->with('rutas del ajuste masivo');

test('el administrativo ve el historial y no puede ajustar ni deshacer', function () {
    // El reparto que fijó el tramo D: cambiar cinco mil precios es del Administrador.
    // El Administrativo conserva recalcular un producto, y puede leer qué pasó.
    $usuario = User::factory()->conRol('Administrativo')->create();

    $this->actingAs($usuario)->get(route('precios.lotes'))->assertOk();
    $this->actingAs($usuario)->get(route('precios.ajuste'))->assertForbidden();
    $this->actingAs($usuario)
        ->post(route('precios.lotes.deshacer', loteDePrueba()), [])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| El cableado de los siete recortes
|--------------------------------------------------------------------------
*/

test('la pantalla se abre vacia y no pide nada', function () {
    // Sin el campo oculto no hay nada que validar: la pantalla se dibuja sin
    // previsualización en lugar de rebotar con «la operación es obligatoria».
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(route('precios.ajuste'))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertSee('Previsualizar');
});

test('el recorte por categoria llega al scope e incluye las subcategorias', function () {
    $componentes = Categoria::factory()->create();
    $memorias    = Categoria::factory()->hijaDe($componentes)->create();
    $otra        = Categoria::factory()->create();

    productoConPrecioCalculado(20000, ['codigo' => 'ADENTRO', 'categoria_id' => $memorias->id]);
    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA', 'categoria_id' => $otra->id]);

    $this->actingAs(usuarioCon('precio.ajustar_masivo', 'precio.ver_costo'))
        ->get(previsualizacion(['categoria_id' => $componentes->id]))
        ->assertOk()
        ->assertSee('ADENTRO')
        ->assertDontSee('AFUERA');
});

test('el recorte por marca llega al scope', function () {
    $marca = Marca::factory()->create();

    productoConPrecioCalculado(20000, ['codigo' => 'ADENTRO', 'marca_id' => $marca->id]);
    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA', 'marca_id' => Marca::factory()->create()->id]);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['marca_id' => $marca->id]))
        ->assertOk()
        ->assertSee('ADENTRO')
        ->assertDontSee('AFUERA');
});

test('el recorte por proveedor llega al scope', function () {
    // El caso más frecuente del plan: llegó la lista nueva de un mayorista.
    $mayorista = Proveedor::factory()->create();

    Producto::factory()->conStockYCosto(10, 20000)->conProveedor($mayorista)
        ->create(['codigo' => 'ADENTRO', 'precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA']);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['proveedor_id' => $mayorista->id]))
        ->assertOk()
        ->assertSee('ADENTRO')
        ->assertDontSee('AFUERA');
});

test('la seleccion manual del catalogo llega al scope', function () {
    $elegido = productoConPrecioCalculado(20000, ['codigo' => 'ADENTRO']);
    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA']);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['ids' => [$elegido->id]]))
        ->assertOk()
        ->assertSee('ADENTRO')
        ->assertDontSee('AFUERA');
});

test('la seleccion llega del catalogo sin previsualizar, y la pantalla la conserva', function () {
    // Es el camino de las casillas: el botón del listado abre la pantalla con los ids
    // puestos y sin previsualizar nada. Si esa rama no validara los ids, se perderían.
    $elegido = productoConPrecioCalculado(20000);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(route('precios.ajuste', ['ids' => [$elegido->id]]))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertSee('seleccionados manualmente');
});

test('el recorte por rango de costo llega al scope', function () {
    productoConPrecioCalculado(5000, ['codigo' => 'BARATO']);
    productoConPrecioCalculado(90000, ['codigo' => 'CARO']);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['costo_hasta' => '10000']))
        ->assertOk()
        ->assertSee('BARATO')
        ->assertDontSee('CARO');
});

test('el recorte por rango de precio mira el precio de contado', function () {
    productoConPrecioCalculado(5000, ['codigo' => 'BARATO']);     // 15.125
    productoConPrecioCalculado(90000, ['codigo' => 'CARO']);      // 272.250

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['precio_hasta' => '20000']))
        ->assertOk()
        ->assertSee('BARATO')
        ->assertDontSee('CARO');
});

test('el recorte cruzado por URL no deja pasar lo que cumple una sola condicion', function () {
    $categoria = Categoria::factory()->create();
    $mayorista = Proveedor::factory()->create();

    Producto::factory()->conStockYCosto(10, 20000)->conProveedor($mayorista)->create([
        'codigo' => 'ADENTRO', 'categoria_id' => $categoria->id,
        'precio_contado' => '1000.00', 'precio_lista' => '1000.00',
    ]);

    Producto::factory()->conStockYCosto(10, 20000)->conProveedor(Proveedor::factory()->create())->create([
        'codigo' => 'OTROPROV', 'categoria_id' => $categoria->id,
        'precio_contado' => '1000.00', 'precio_lista' => '1000.00',
    ]);

    productoConPrecioCalculado(20000, ['codigo' => 'OTRACAT']);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['categoria_id' => $categoria->id, 'proveedor_id' => $mayorista->id]))
        ->assertOk()
        ->assertSee('ADENTRO')
        ->assertDontSee('OTROPROV')
        ->assertDontSee('OTRACAT');
});

test('la casilla de precio manual cambia a quien alcanza el ajuste', function () {
    productoConCosto(20000, ['codigo' => 'AMANO', 'precio_manual' => true]);

    $usuario = usuarioCon('precio.ajustar_masivo');

    $this->actingAs($usuario)
        ->get(previsualizacion(['alcance' => 'todos']))
        ->assertOk()
        ->assertDontSee('AMANO');

    $this->actingAs($usuario)
        ->get(previsualizacion(['alcance' => 'todos', 'incluir_manuales' => 1]))
        ->assertOk()
        ->assertSee('AMANO');
});

/*
|--------------------------------------------------------------------------
| La primera salvaguarda: previsualizar no escribe
|--------------------------------------------------------------------------
*/

test('previsualizar no escribe ningun precio ni ningun lote', function () {
    $producto = productoConPrecioCalculado(20000);
    $asientos = HistorialPrecio::count();

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['alcance' => 'todos']))
        ->assertOk();

    expect($producto->fresh()->precio_contado)->toBe('60500.00')
        ->and(HistorialPrecio::count())->toBe($asientos)
        ->and(LoteAjuste::count())->toBe(0);
});

test('la previsualizacion avisa cuando hay productos afuera por precio manual', function () {
    // El número que la pantalla tiene que decir ANTES de aplicar: hoy los productos
    // que ya existían entraron con la marca puesta, así que un ajuste sin la casilla
    // no alcanza a casi nadie. El aviso existe sólo cuando hay alguno afuera, y por
    // eso se prueban las dos direcciones: con la casilla marcada no hay nada que avisar.
    productoConPrecioCalculado(20000);
    productoConCosto(20000, ['precio_manual' => true]);

    $usuario = usuarioCon('precio.ajustar_masivo');

    $this->actingAs($usuario)
        ->get(previsualizacion(['alcance' => 'todos']))
        ->assertOk()
        ->assertSee('avisoManualesAfuera');

    $this->actingAs($usuario)
        ->get(previsualizacion(['alcance' => 'todos', 'incluir_manuales' => 1]))
        ->assertOk()
        ->assertDontSee('avisoManualesAfuera');
});

test('sin ver_costo la previsualizacion no muestra la columna de costo', function () {
    // `precio.ver_costo` gobierna el costo en la ficha, en los listados y en los PDF.
    // La previsualización es el cuarto lugar donde aparece.
    productoConPrecioCalculado(12345.67);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->get(previsualizacion(['alcance' => 'todos']))
        ->assertOk()
        ->assertDontSee('12.345,67');
});

/*
|--------------------------------------------------------------------------
| La validación rechaza, no corrige
|--------------------------------------------------------------------------
*/

test('un recorte sin ningun criterio se rechaza', function () {
    // Sin esta regla, «un recorte» sin nada elegido aplicaría el ajuste al catálogo
    // entero creyendo que lo limita.
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['alcance' => 'recorte']))
        ->assertSessionHasErrors('alcance');

    expect(LoteAjuste::count())->toBe(0);
});

test('pedir todos los productos y ademas un recorte se rechaza', function () {
    // La otra dirección, y es la peligrosa: el usuario creería que filtró.
    $categoria = Categoria::factory()->create();

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'alcance'      => 'todos',
            'categoria_id' => $categoria->id,
        ]))
        ->assertSessionHasErrors('alcance');

    expect(LoteAjuste::count())->toBe(0);
});

test('variar el precio sin porcentaje se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['porcentaje' => null]))
        ->assertSessionHasErrors('porcentaje');
});

test('un porcentaje negativo en formato argentino se acepta', function () {
    // La ayuda del campo promete «-7,5 baja» y el mensaje de error dice lo mismo:
    // `Importe::normalizar()` no conoce el signo, así que el Form Request lo saca,
    // normaliza el número y lo vuelve a pegar.
    $producto = productoConPrecioCalculado(20000);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['porcentaje' => '-7,5']))
        ->assertSessionHasNoErrors();

    // 60.500 − 7,5 % = 55.962,50
    expect($producto->fresh()->precio_contado)->toBe('55962.50');
});

test('un ajuste de cero por ciento se rechaza en lugar de correr sin efecto', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['porcentaje' => '0']))
        ->assertSessionHasErrors('porcentaje');
});

test('una baja del cien por ciento se rechaza', function () {
    // Dejaría el precio en cero, que es regalar el producto.
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['porcentaje' => '-100']))
        ->assertSessionHasErrors('porcentaje');
});

test('un multiplicador de ganancia menor que uno se rechaza', function () {
    // Vendería por debajo del costo, y acá sobre todo el conjunto a la vez.
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'operacion'  => 'ganancia',
            'porcentaje' => null,
            'ganancia'   => '0,5',
        ]))
        ->assertSessionHasErrors('ganancia');
});

test('fijar la ganancia sin multiplicador se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'operacion'  => 'ganancia',
            'porcentaje' => null,
        ]))
        ->assertSessionHasErrors('ganancia');
});

test('redondear sin multiplo se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'operacion'  => 'redondeo',
            'porcentaje' => null,
        ]))
        ->assertSessionHasErrors('redondeo_multiplo');
});

test('un rango al reves se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'alcance'     => 'recorte',
            'costo_desde' => '50000',
            'costo_hasta' => '10000',
        ]))
        ->assertSessionHasErrors('costo_hasta');
});

test('una operacion que no existe se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['operacion' => 'borrar_todo']))
        ->assertSessionHasErrors('operacion');
});

test('un producto que no existe en la seleccion se rechaza', function () {
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
            'alcance' => 'recorte',
            'ids'     => [99999],
        ]))
        ->assertSessionHasErrors('ids.0');
});

/*
|--------------------------------------------------------------------------
| El circuito completo, por HTTP
|--------------------------------------------------------------------------
*/

test('aplicar escribe el lote y deja en el historial', function () {
    $producto = productoConPrecioCalculado(20000);

    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste())
        ->assertRedirect(route('precios.lotes'))
        ->assertSessionHas('exito');

    expect($producto->fresh()->precio_contado)->toBe('69575.00')
        ->and(LoteAjuste::sole()->alcanzados)->toBe(1);
});

test('el lote queda con el usuario que lo aplico', function () {
    $usuario = usuarioCon('precio.ajustar_masivo');
    productoConPrecioCalculado(20000);

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste());

    expect(LoteAjuste::sole()->usuario_id)->toBe($usuario->id);
});

test('un ajuste que no alcanza a nadie avisa y no escribe lote', function () {
    // `aviso` y no `exito`: la operación corrió y no hizo falta hacer nada.
    $this->actingAs(usuarioCon('precio.ajustar_masivo'))
        ->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste())
        ->assertRedirect(route('precios.ajuste'))
        ->assertSessionHas('aviso');

    expect(LoteAjuste::count())->toBe(0);
});

test('deshacer el ultimo ajuste devuelve los precios', function () {
    $producto = productoConPrecioCalculado(20000);
    $usuario  = usuarioCon('precio.ajustar_masivo', 'precio.deshacer_ajuste', 'precio.ver');

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste());

    $lote = LoteAjuste::sole();

    $this->actingAs($usuario)
        ->post(route('precios.lotes.deshacer', $lote))
        ->assertRedirect(route('precios.lotes'))
        ->assertSessionHas('exito');

    expect($producto->fresh()->precio_contado)->toBe('60500.00')
        ->and($lote->fresh()->reversion)->not->toBeNull();
});

test('deshacer un ajuste que no es el ultimo se rechaza con su mensaje', function () {
    // La regla vive en el servicio y la excepción se traduce una sola vez, en
    // bootstrap/app.php: el controlador no tiene try/catch.
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.deshacer_ajuste', 'precio.ver');

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste());
    $primero = LoteAjuste::sole();

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste(['porcentaje' => '5']));

    $this->actingAs($usuario)
        ->post(route('precios.lotes.deshacer', $primero))
        ->assertSessionHas('error');

    expect($primero->fresh()->reversion)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| La pantalla no ofrece lo que el servidor va a negar
|--------------------------------------------------------------------------
*/

test('quien no puede deshacer no recibe el boton de deshacer', function () {
    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la pantalla no ofrezca lo que va a rechazar (M-33).
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.ver');

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste());

    $this->actingAs($usuario)
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertDontSee(route('precios.lotes.deshacer', LoteAjuste::sole()));
});

test('el historial muestra el criterio congelado del ajuste', function () {
    $categoria = Categoria::factory()->create(['nombre' => 'Memorias']);
    productoConPrecioCalculado(20000, ['categoria_id' => $categoria->id]);

    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.ver');

    $this->actingAs($usuario)->post(route('precios.ajuste.aplicar'), cuerpoDeAjuste([
        'alcance'      => 'recorte',
        'categoria_id' => $categoria->id,
    ]));

    $categoria->update(['nombre' => 'Memorias RAM']);

    $this->actingAs($usuario)
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertSee('Categoría Memorias y sus subcategorías');
});

test('el historial vacio dice que todavia no se hizo ningun ajuste', function () {
    // Sin filtros no hay vacío por filtro: el único vacío posible es éste.
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertSee('Todavía no se hizo ningún ajuste masivo');
});