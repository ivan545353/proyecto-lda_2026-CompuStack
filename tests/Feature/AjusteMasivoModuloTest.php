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
    servicioConPolitica();

    // Los códigos de una vista previa, como los ve la pantalla. Closure y no función global (tests.md).
    $this->codigosDe = fn (string $url, User $usuario) => collect(
        $this->getJson($url, bearer($usuario))->assertOk()->json('datos'),
    )->pluck('codigo')->all();
});

/*
|--------------------------------------------------------------------------
| El segundo nivel del ajuste masivo
|--------------------------------------------------------------------------
|
| `AjusteMasivoServiceTest` prueba que el servicio hace lo que dice;
| `AjusteMasivoFiltrosTest`, que cada scope filtra. Lo que falta es el cableado:
| que el parámetro llegue al scope, que cada endpoint exija su permiso, que la
| validación rechace y que la pantalla no ofrezca lo que el servidor va a negar.
|
| A-24 pasó once commits sin detectarse porque el scope funcionaba y el cableado no.
| Acá el riesgo es el mismo con siete recortes en lugar de uno.
|
| Desde el tramo F de la Etapa 3 la vista previa, el ajuste, el historial y deshacer
| son endpoints: los tests migraron conservando cada aserción.
|
*/

/** Un cuerpo válido que sirve para cualquiera de los endpoints; lo que sobra se ignora. */
function cuerpoDeAjuste(array $cambios = []): array
{
    return array_merge([
        'operacion'        => 'precio',
        'porcentaje'       => '15',
        'alcance'          => 'todos',
        'incluir_manuales' => false,
    ], $cambios);
}

/** La URL de una pantalla del ajuste. Desde el tramo F las dos que quedan son GET sin parámetros. */
function urlDelAjuste(string $ruta, ?string $parametro): string
{
    return route($ruta);
}

/** La vista previa de un ajuste por la API: un GET, como la pide la pantalla. */
function previsualizacion(array $parametros = []): string
{
    return '/api/v1/precios/ajustes/vista-previa?'.http_build_query(array_merge([
        'operacion'        => 'precio',
        'porcentaje'       => '15',
        'alcance'          => 'recorte',
        'incluir_manuales' => 0,
    ], $parametros));
}

/** Aplica un ajuste por la API. */
function aplicarAjuste($test, User $usuario, array $cambios = [])
{
    return $test->postJson('/api/v1/precios/ajustes', cuerpoDeAjuste($cambios), bearer($usuario));
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
*/

// Las pantallas. Aplicar y deshacer salieron de la web en el tramo F: sus filas son las del dataset
// de endpoints, con el mismo permiso.
dataset('rutas del ajuste masivo', [
    'pantalla del ajuste' => ['get', 'precios.ajuste', 'precio.ajustar_masivo', null],
    'historial de lotes'  => ['get', 'precios.lotes',  'precio.ver',            null],
]);

// Las operaciones, en la API (tramo F de la Etapa 3). `{lote}` se reemplaza por un lote de prueba.
dataset('endpoints del ajuste masivo', [
    'vista previa'       => ['get',  '/api/v1/precios/ajustes/vista-previa?operacion=precio&porcentaje=15&alcance=todos&incluir_manuales=0', 'precio.ajustar_masivo'],
    'aplicar el ajuste'  => ['post', '/api/v1/precios/ajustes',             'precio.ajustar_masivo'],
    'historial de lotes' => ['get',  '/api/v1/precios/lotes',               'precio.ver'],
    'deshacer un ajuste' => ['post', '/api/v1/precios/lotes/{lote}/deshacer', 'precio.deshacer_ajuste'],
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

test('en la API, ningun otro permiso del modulo habilita el endpoint del ajuste', function (string $metodo, string $url, string $permiso) {
    $otros = array_values(array_diff(permisosDelModulo('precio'), [$permiso]));

    $this->json($metodo, str_replace('{lote}', (string) loteDePrueba()->id, $url), cuerpoDeAjuste(), bearer(usuarioCon(...$otros)))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'sin_permiso');
})->with('endpoints del ajuste masivo');

test('en la API, el permiso del endpoint del ajuste alcanza para pasar', function (string $metodo, string $url, string $permiso) {
    // Con un producto que el ajuste alcanza: si no, aplicar y deshacer responderían 409, que no es
    // un problema de permisos pero tampoco prueba que el permiso alcance.
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon($permiso);

    if (str_contains($url, '{lote}')) {
        aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'))->assertCreated();
        app('auth')->forgetGuards();
    }

    $respuesta = $this->json($metodo, str_replace('{lote}', (string) LoteAjuste::query()->latest('id')->value('id'), $url), cuerpoDeAjuste(), bearer($usuario));

    expect($respuesta->status())->toBeLessThan(300);
})->with('endpoints del ajuste masivo');

test('las rutas web de escritura del ajuste ya no existen', function () {
    // Aplicar y deshacer son de la API (tramo F): la web sólo entrega las pantallas.
    $usuario = usuarioCon(...permisosDelModulo('precio'));

    expect($this->actingAs($usuario)->post('/precios/ajuste', cuerpoDeAjuste())->status())->toBeIn([404, 405])
        ->and($this->actingAs($usuario)->post('/precios/lotes/'.loteDePrueba()->id.'/deshacer')->status())->toBeIn([404, 405]);
});

test('las pantallas del ajuste no llevan datos de negocio', function () {
    // Regla 37: el shell trae la estructura; los productos, las marcas y los lotes los pide el JS.
    $marca = Marca::factory()->create(['nombre' => 'MarcaDelShell']);
    productoConPrecioCalculado(20000, ['codigo' => 'CODSHELL', 'marca_id' => $marca->id]);
    $usuario = usuarioCon(...[...permisosDelModulo('precio'), 'marca.ver']);

    aplicarAjuste($this, $usuario)->assertCreated();
    app('auth')->forgetGuards();

    $this->actingAs($usuario)->get(route('precios.ajuste', ['previsualizar' => 1, 'operacion' => 'precio', 'porcentaje' => '15', 'alcance' => 'todos']))
        ->assertOk()
        ->assertSee('data-pantalla="ajuste-masivo"', false)
        ->assertDontSee('CODSHELL')
        ->assertDontSee('MarcaDelShell');

    $this->actingAs($usuario)->get(route('precios.lotes'))
        ->assertOk()
        ->assertSee('data-pantalla="lotes"', false)
        ->assertDontSee('Todos los productos');
});

test('el administrativo ve el historial y no puede ajustar ni deshacer', function () {
    // El reparto que fijó el tramo D: cambiar cinco mil precios es del Administrador.
    // El Administrativo conserva recalcular un producto, y puede leer qué pasó.
    $usuario = User::factory()->conRol('Administrativo')->create();

    $this->actingAs($usuario)->get(route('precios.lotes'))->assertOk();
    $this->actingAs($usuario)->get(route('precios.ajuste'))->assertForbidden();

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/precios/lotes', bearer($usuario))->assertOk();
    aplicarAjuste($this, $usuario)->assertForbidden();
    $this->postJson('/api/v1/precios/lotes/'.loteDePrueba()->id.'/deshacer', [], bearer($usuario))
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

    expect(($this->codigosDe)(previsualizacion(['categoria_id' => $componentes->id]), usuarioCon('precio.ajustar_masivo', 'precio.ver_costo')))
        ->toContain('ADENTRO')
        ->not->toContain('AFUERA');
});

test('el recorte por marca llega al scope', function () {
    $marca = Marca::factory()->create();

    productoConPrecioCalculado(20000, ['codigo' => 'ADENTRO', 'marca_id' => $marca->id]);
    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA', 'marca_id' => Marca::factory()->create()->id]);

    expect(($this->codigosDe)(previsualizacion(['marca_id' => $marca->id]), usuarioCon('precio.ajustar_masivo')))
        ->toContain('ADENTRO')
        ->not->toContain('AFUERA');
});

test('el recorte por proveedor llega al scope', function () {
    // El caso más frecuente del plan: llegó la lista nueva de un mayorista.
    $mayorista = Proveedor::factory()->create();

    Producto::factory()->conStockYCosto(10, 20000)->conProveedor($mayorista)
        ->create(['codigo' => 'ADENTRO', 'precio_contado' => '1000.00', 'precio_lista' => '1000.00']);

    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA']);

    expect(($this->codigosDe)(previsualizacion(['proveedor_id' => $mayorista->id]), usuarioCon('precio.ajustar_masivo')))
        ->toContain('ADENTRO')
        ->not->toContain('AFUERA');
});

test('la seleccion manual del catalogo llega al scope', function () {
    $elegido = productoConPrecioCalculado(20000, ['codigo' => 'ADENTRO']);
    productoConPrecioCalculado(20000, ['codigo' => 'AFUERA']);

    expect(($this->codigosDe)(previsualizacion(['ids' => [$elegido->id]]), usuarioCon('precio.ajustar_masivo')))
        ->toContain('ADENTRO')
        ->not->toContain('AFUERA');
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

    expect(($this->codigosDe)(previsualizacion(['costo_hasta' => '10000']), usuarioCon('precio.ajustar_masivo')))
        ->toContain('BARATO')
        ->not->toContain('CARO');
});

test('el recorte por rango de precio mira el precio de contado', function () {
    productoConPrecioCalculado(5000, ['codigo' => 'BARATO']);     // 15.125
    productoConPrecioCalculado(90000, ['codigo' => 'CARO']);      // 272.250

    expect(($this->codigosDe)(previsualizacion(['precio_hasta' => '20000']), usuarioCon('precio.ajustar_masivo')))
        ->toContain('BARATO')
        ->not->toContain('CARO');
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

    expect(($this->codigosDe)(previsualizacion(['categoria_id' => $categoria->id, 'proveedor_id' => $mayorista->id]), usuarioCon('precio.ajustar_masivo')))
        ->toContain('ADENTRO')
        ->not->toContain('OTROPROV')
        ->not->toContain('OTRACAT');
});

test('la casilla de precio manual cambia a quien alcanza el ajuste', function () {
    productoConCosto(20000, ['codigo' => 'AMANO', 'precio_manual' => true]);

    $usuario = usuarioCon('precio.ajustar_masivo');

    expect(($this->codigosDe)(previsualizacion(['alcance' => 'todos']), $usuario))->not->toContain('AMANO')
        ->and(($this->codigosDe)(previsualizacion(['alcance' => 'todos', 'incluir_manuales' => 1]), $usuario))->toContain('AMANO');
});

test('la vista previa trae los conteos y el criterio escrito', function () {
    $categoria = Categoria::factory()->create(['nombre' => 'Memorias']);
    productoConPrecioCalculado(20000, ['categoria_id' => $categoria->id]);

    $this->getJson(previsualizacion(['categoria_id' => $categoria->id]), bearer(usuarioCon('precio.ajustar_masivo')))
        ->assertOk()
        ->assertJsonPath('conteos.total', 1)
        ->assertJsonPath('conteos.alcanzados', 1)
        ->assertJsonPath('criterio_texto', 'Categoría Memorias y sus subcategorías')
        ->assertJsonPath('datos.0.precio_contado', '60500.00')
        ->assertJsonPath('datos.0.nuevo_contado', '69575.00')
        ->assertJsonPath('datos.0.resultado_texto', 'Cambia precio');
});

test('la API rechaza una vista previa sin la casilla de precio manual en lugar de suponerla', function () {
    // Rechaza, no corrige (M-31): la casilla ausente era «no incluir» en la web.
    $url = '/api/v1/precios/ajustes/vista-previa?operacion=precio&porcentaje=15&alcance=todos';

    $this->getJson($url, bearer(usuarioCon('precio.ajustar_masivo')))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('incluir_manuales', 'errores');
});

test('la vista previa y el historial traen criterios como objeto aunque no haya', function () {
    // HL-137: `json()` decodifica `{}` como `[]`; sólo el texto crudo distingue el objeto de la lista.
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.ver');
    $vista   = '/api/v1/precios/ajustes/vista-previa?operacion=precio&porcentaje=15&alcance=todos&incluir_manuales=0';

    expect($this->getJson($vista, bearer($usuario))->assertOk()->getContent())->toContain('"criterios":{}')
        ->and($this->getJson('/api/v1/precios/lotes', bearer($usuario))->assertOk()->getContent())->toContain('"criterios":{}');
});

/*
|--------------------------------------------------------------------------
| La primera salvaguarda: previsualizar no escribe
|--------------------------------------------------------------------------
*/

test('previsualizar no escribe ningun precio ni ningun lote', function () {
    $producto = productoConPrecioCalculado(20000);
    $asientos = HistorialPrecio::count();

    $this->getJson(previsualizacion(['alcance' => 'todos']), bearer(usuarioCon('precio.ajustar_masivo')))
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

    $this->getJson(previsualizacion(['alcance' => 'todos']), bearer($usuario))
        ->assertOk()
        ->assertJsonPath('conteos.manuales_afuera', 1);

    $this->getJson(previsualizacion(['alcance' => 'todos', 'incluir_manuales' => 1]), bearer($usuario))
        ->assertOk()
        ->assertJsonPath('conteos.manuales_afuera', 0);
});

test('sin ver_costo la previsualizacion no muestra la columna de costo', function () {
    // `precio.ver_costo` gobierna el costo en la ficha, en los listados y en los PDF.
    // La previsualización es el cuarto lugar donde aparece. Lo que no se puede ver no viaja.
    productoConPrecioCalculado(12345.67);

    $sin = $this->getJson(previsualizacion(['alcance' => 'todos']), bearer(usuarioCon('precio.ajustar_masivo')))->assertOk();

    expect($sin->json('datos.0'))->not->toHaveKeys(['costo', 'costo_nuevo'])
        ->and($sin->getContent())->not->toContain('12345.67');

    app('auth')->forgetGuards();

    // La otra dirección: con el permiso, la clave está.
    $this->getJson(previsualizacion(['alcance' => 'todos']), bearer(usuarioCon('precio.ajustar_masivo', 'precio.ver_costo')))
        ->assertOk()
        ->assertJsonPath('datos.0.costo', '12345.67');
});

/*
|--------------------------------------------------------------------------
| La validación rechaza, no corrige
|--------------------------------------------------------------------------
*/

test('un recorte sin ningun criterio se rechaza', function () {
    // Sin esta regla, «un recorte» sin nada elegido aplicaría el ajuste al catálogo
    // entero creyendo que lo limita.
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['alcance' => 'recorte'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alcance', 'errores');

    expect(LoteAjuste::count())->toBe(0);
});

test('pedir todos los productos y ademas un recorte se rechaza', function () {
    // La otra dirección, y es la peligrosa: el usuario creería que filtró.
    $categoria = Categoria::factory()->create();

    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'alcance'      => 'todos',
        'categoria_id' => $categoria->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alcance', 'errores');

    expect(LoteAjuste::count())->toBe(0);
});

test('variar el precio sin porcentaje se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['porcentaje' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('porcentaje', 'errores');
});

test('un porcentaje negativo en formato argentino se acepta', function () {
    // La ayuda del campo promete «-7,5 baja» y el mensaje de error dice lo mismo:
    // `Importe::normalizar()` no conoce el signo, así que el Form Request lo saca,
    // normaliza el número y lo vuelve a pegar.
    $producto = productoConPrecioCalculado(20000);

    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['porcentaje' => '-7,5'])
        ->assertCreated();

    // 60.500 − 7,5 % = 55.962,50
    expect($producto->fresh()->precio_contado)->toBe('55962.50');
});

test('un ajuste de cero por ciento se rechaza en lugar de correr sin efecto', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['porcentaje' => '0'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('porcentaje', 'errores');
});

test('una baja del cien por ciento se rechaza', function () {
    // Dejaría el precio en cero, que es regalar el producto.
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['porcentaje' => '-100'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('porcentaje', 'errores');
});

test('un multiplicador de ganancia menor que uno se rechaza', function () {
    // Vendería por debajo del costo, y acá sobre todo el conjunto a la vez.
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'operacion'  => 'ganancia',
        'porcentaje' => null,
        'ganancia'   => '0,5',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ganancia', 'errores');
});

test('fijar la ganancia sin multiplicador se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'operacion'  => 'ganancia',
        'porcentaje' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ganancia', 'errores');
});

test('redondear sin multiplo se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'operacion'  => 'redondeo',
        'porcentaje' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('redondeo_multiplo', 'errores');
});

test('un rango al reves se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'alcance'     => 'recorte',
        'costo_desde' => '50000',
        'costo_hasta' => '10000',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('costo_hasta', 'errores');
});

test('una operacion que no existe se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), ['operacion' => 'borrar_todo'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operacion', 'errores');
});

test('un producto que no existe en la seleccion se rechaza', function () {
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'), [
        'alcance' => 'recorte',
        'ids'     => [99999],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0', 'errores');
});

/*
|--------------------------------------------------------------------------
| El circuito completo, por HTTP
|--------------------------------------------------------------------------
*/

test('aplicar escribe el lote y deja en el historial', function () {
    $producto = productoConPrecioCalculado(20000);

    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'))
        ->assertCreated()
        ->assertJsonPath('datos.alcanzados', 1)
        ->assertJsonPath('mensaje', 'Ajuste aplicado a 1 producto(s). Se puede deshacer.');

    expect($producto->fresh()->precio_contado)->toBe('69575.00')
        ->and(LoteAjuste::sole()->alcanzados)->toBe(1);
});

test('el lote queda con el usuario que lo aplico', function () {
    $usuario = usuarioCon('precio.ajustar_masivo');
    productoConPrecioCalculado(20000);

    aplicarAjuste($this, $usuario)->assertCreated();

    expect(LoteAjuste::sole()->usuario_id)->toBe($usuario->id);
});

test('un ajuste que no alcanza a nadie avisa y no escribe lote', function () {
    // No es un 201: la operación corrió y no hizo falta hacer nada, y un «creado» diría
    // que pasó algo que no pasó (HL-122).
    aplicarAjuste($this, usuarioCon('precio.ajustar_masivo'))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio');

    expect(LoteAjuste::count())->toBe(0);
});

test('deshacer el ultimo ajuste devuelve los precios', function () {
    $producto = productoConPrecioCalculado(20000);
    $usuario  = usuarioCon('precio.ajustar_masivo', 'precio.deshacer_ajuste', 'precio.ver');

    aplicarAjuste($this, $usuario)->assertCreated();

    $lote = LoteAjuste::sole();

    $this->postJson("/api/v1/precios/lotes/{$lote->id}/deshacer", [], bearer($usuario))
        ->assertCreated()
        ->assertJsonPath('datos.operacion', 'deshacer')
        ->assertJsonPath('datos.revierte_a.id', $lote->id);

    expect($producto->fresh()->precio_contado)->toBe('60500.00')
        ->and($lote->fresh()->reversion)->not->toBeNull();
});

test('deshacer un ajuste que no es el ultimo se rechaza con su mensaje', function () {
    // La regla vive en el servicio y la excepción se traduce una sola vez, en
    // bootstrap/app.php: el controlador no tiene try/catch.
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.deshacer_ajuste', 'precio.ver');

    aplicarAjuste($this, $usuario)->assertCreated();
    $primero = LoteAjuste::sole();

    aplicarAjuste($this, $usuario, ['porcentaje' => '5'])->assertCreated();

    $this->postJson("/api/v1/precios/lotes/{$primero->id}/deshacer", [], bearer($usuario))
        ->assertStatus(409)
        ->assertJsonPath('codigo', 'regla_de_negocio')
        ->assertJsonPath('mensaje', 'Sólo se puede deshacer el último ajuste. Los precios anteriores que guarda el historial valen mientras no haya pasado nada después.');

    expect($primero->fresh()->reversion)->toBeNull();
});

test('el historial dice cual es el unico lote que se puede deshacer', function () {
    // La pantalla ofrece el botón en esa fila y en ninguna otra: lo decide el servidor.
    productoConPrecioCalculado(20000);
    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.ver');

    aplicarAjuste($this, $usuario)->assertCreated();
    aplicarAjuste($this, $usuario, ['porcentaje' => '5'])->assertCreated();

    $lotes = $this->getJson('/api/v1/precios/lotes', bearer($usuario))->assertOk()->json('datos');

    expect(array_column($lotes, 'reversible'))->toBe([true, false]);
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

    aplicarAjuste($this, $usuario)->assertCreated();

    $this->actingAs($usuario)
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertDontSee('data-deshacer', false);

    // La otra dirección: con el permiso, el botón está en el shell.
    $this->actingAs(usuarioCon('precio.ver', 'precio.deshacer_ajuste'))
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertSee('data-deshacer', false);
});

test('el historial muestra el criterio congelado del ajuste', function () {
    $categoria = Categoria::factory()->create(['nombre' => 'Memorias']);
    productoConPrecioCalculado(20000, ['categoria_id' => $categoria->id]);

    $usuario = usuarioCon('precio.ajustar_masivo', 'precio.ver');

    aplicarAjuste($this, $usuario, [
        'alcance'      => 'recorte',
        'categoria_id' => $categoria->id,
    ])->assertCreated();

    $categoria->update(['nombre' => 'Memorias RAM']);

    $this->getJson('/api/v1/precios/lotes', bearer($usuario))
        ->assertOk()
        ->assertJsonPath('datos.0.criterio_texto', 'Categoría Memorias y sus subcategorías');
});

test('el historial vacio dice que todavia no se hizo ningun ajuste', function () {
    // Sin filtros no hay vacío por filtro: el único vacío posible es éste.
    $this->actingAs(usuarioCon('precio.ver'))
        ->get(route('precios.lotes'))
        ->assertOk()
        ->assertSee('Todavía no se hizo ningún ajuste masivo');
});
