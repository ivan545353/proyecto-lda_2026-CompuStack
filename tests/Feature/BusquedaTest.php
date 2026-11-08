<?php

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Proveedor;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El buscador remoto: el contrato del endpoint
|--------------------------------------------------------------------------
|
| Es el único endpoint JSON del sistema y lo van a reusar los tramos F, G, I y J,
| así que lo que se fija acá no es una pantalla: es el contrato. Tres cosas, en
| este orden de importancia:
|
|   1. El permiso, en las dos direcciones de C-2: con todos los del módulo menos
|      el de la ruta se deniega, y con sólo ése se permite.
|   2. El recorte: nunca más de MAXIMO resultados, y `hay_mas` dice si recortó.
|   3. El costo: con `precio.ver_costo` viaja, y sin el permiso la clave NO ESTÁ,
|      que es distinto de estar en null.
|
| Lo que NO se prueba acá es el peso del HTML de las pantallas: eso es
| `PesoHtmlTest`, y es el criterio de terminado del tramo.
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

// url, módulo de permisos y la clave que la ruta exige.
dataset('endpoints', [
    'productos'   => ['/buscar/productos?q=zz',   'producto',  'producto.ver'],
    'clientes'    => ['/buscar/clientes?q=zz',    'cliente',   'cliente.ver'],
    'proveedores' => ['/buscar/proveedores?q=zz', 'proveedor', 'proveedor.ver'],
]);

test('el endpoint exige el permiso de ver su entidad', function (string $url, string $modulo, string $clave) {
    // La primera mitad de C-2: sin esta comprobación, una ruta protegida por el
    // permiso equivocado del mismo módulo pasaría.
    $otros = array_values(array_diff(permisosDelModulo($modulo), [$clave]));

    $this->actingAs(usuarioCon(...$otros))->getJson($url)->assertForbidden();
    $this->actingAs(usuarioCon($clave))->getJson($url)->assertOk();
})->with('endpoints');

test('sin sesion el endpoint contesta 401 y no manda al login', function (string $url) {
    // Un 302 a la pantalla de login le daría HTML a un cliente que pidió JSON, y
    // el desplegable mostraría «no hay coincidencias» en lugar de un error.
    $this->getJson($url)->assertUnauthorized();
})->with([
    ['/buscar/productos?q=zz'],
    ['/buscar/clientes?q=zz'],
    ['/buscar/proveedores?q=zz'],
]);

test('la busqueda sin texto o de una sola letra se rechaza', function (string $query) {
    Producto::factory()->count(3)->create();

    $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos'.$query)
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
})->with([
    'sin el parámetro' => [''],
    'vacío'            => ['?q='],
    'una letra'        => ['?q=a'],
    'sólo espacios'    => ['?q=%20%20'],
]);

test('encuentra por codigo y por nombre, y no trae lo que no coincide', function () {
    Producto::factory()->create(['codigo' => 'TEC-00123', 'nombre' => 'Teclado mecánico']);
    Producto::factory()->create(['codigo' => 'MON-00456', 'nombre' => 'Monitor curvo']);

    $usuario = usuarioCon('producto.ver');

    $porCodigo = $this->actingAs($usuario)->getJson('/buscar/productos?q=TEC-001')->assertOk();
    $porNombre = $this->actingAs($usuario)->getJson('/buscar/productos?q=mecánico')->assertOk();

    expect($porCodigo->json('resultados'))->toHaveCount(1)
        ->and($porCodigo->json('resultados.0.codigo'))->toBe('TEC-00123')
        ->and($porCodigo->json('resultados.0.texto'))->toBe('TEC-00123 — Teclado mecánico')
        ->and($porNombre->json('resultados'))->toHaveCount(1)
        ->and($porNombre->json('resultados.0.codigo'))->toBe('TEC-00123');
});

test('un producto dado de baja no se ofrece', function () {
    Producto::factory()->inactivo()->create(['nombre' => 'Fuente discontinuada']);

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=discontinuada')
        ->assertOk();

    // El que ya está en el documento que se edita lo rinde el servidor en su propia
    // opción, así que el buscador no necesita ofrecer bajas.
    expect($respuesta->json('resultados'))->toBeEmpty();
});

test('se puede pedir que el buscador incluya los dados de baja', function () {
    Producto::factory()->inactivo()->create(['nombre' => 'Fuente discontinuada']);

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=discontinuada&estado=todos')->assertOk();

    // Lo pide el filtro del kardex: de un producto discontinuado se sigue queriendo
    // mirar la historia, y la pantalla hasta tiene un badge «Producto inactivo» para
    // ese caso. Que por omisión no se ofrezca lo fija el test de arriba.
    expect($respuesta->json('resultados'))->toHaveCount(1);
});

test('un estado que no existe se rechaza', function () {
    // Rechaza, no corrige (M-31): ignorar el parámetro devolvería un conjunto
    // distinto del que el que llama pidió, en silencio.
    $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable&estado=cualquiera')
        ->assertStatus(422);
});

test('el precio y el disponible viajan con cada resultado', function () {
    Producto::factory()
        ->conStock(10, 3)
        ->create(['nombre' => 'Disco rígido sata', 'precio_contado' => '12345.67']);

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=rígido')
        ->assertOk();

    // Es lo que hace que la isla `preciosDelCatalogo` del formulario de venta deje
    // de existir, que es el pendiente #39.
    expect($respuesta->json('resultados.0.precio'))->toBe('12345.67')
        // stock − stock_reservado, la misma definición de todo el sistema.
        ->and($respuesta->json('resultados.0.disponible'))->toBe(7);
});

test('el costo viaja solo con permiso, y sin permiso la clave no esta', function () {
    Producto::factory()->conStockYCosto(5, 8000)->create(['nombre' => 'Memoria ddr4']);

    $con = $this->actingAs(usuarioCon('producto.ver', 'precio.ver_costo'))
        ->getJson('/buscar/productos?q=ddr4')->assertOk();

    $sin = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=ddr4')->assertOk();

    expect($con->json('resultados.0.costo'))->toBe('8000.00')
        // Ausente, no null: null significaría «no tiene costo cargado», y eso sería
        // afirmar un hecho que el servidor no le contestó.
        ->and($sin->json('resultados.0'))->not->toHaveKey('costo');
});

test('el desplegable se topea en veinte y avisa que hay mas', function () {
    Producto::factory()->count(25)->create(['nombre' => 'Cable de red']);

    $muchos = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable')->assertOk();

    expect($muchos->json('resultados'))->toHaveCount(20)
        ->and($muchos->json('hay_mas'))->toBeTrue();

    $pocos = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=zzzz')->assertOk();

    expect($pocos->json('resultados'))->toBeEmpty()
        ->and($pocos->json('hay_mas'))->toBeFalse();
});

test('la cantidad de consultas no crece con la cantidad de resultados', function () {
    $usuario = usuarioCon('producto.ver');
    $buscar  = fn () => $this->actingAs($usuario)->getJson('/buscar/productos?q=cable')->assertOk();

    Producto::factory()->count(3)->create(['nombre' => 'Cable de red']);

    $buscar();   // calentamiento: la primera petición llena la caché de permisos

    $pocas = contarConsultas($buscar);

    Producto::factory()->count(30)->create(['nombre' => 'Cable de red']);

    expect(contarConsultas($buscar))->toBe($pocas);
});

test('el recorte por proveedor y el de con proveedor filtran', function () {
    $austral = Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral S.A.']);
    $otro    = Proveedor::factory()->create(['razon_social' => 'Mayorista Sur S.R.L.']);

    Producto::factory()->conProveedor($austral, 1000)->create(['nombre' => 'Switch austral']);
    Producto::factory()->conProveedor($otro, 2000)->create(['nombre' => 'Switch del otro']);
    Producto::factory()->create(['nombre' => 'Switch sin proveedor']);

    $usuario = usuarioCon('producto.ver');

    $delProveedor = $this->actingAs($usuario)
        ->getJson("/buscar/productos?q=switch&proveedor_id={$austral->id}")->assertOk();

    $conProveedor = $this->actingAs($usuario)
        ->getJson('/buscar/productos?q=switch&con_proveedor=1')->assertOk();

    expect($delProveedor->json('resultados'))->toHaveCount(1)
        ->and($delProveedor->json('resultados.0.nombre'))->toBe('Switch austral')
        // El armador no ofrece lo que después va a rechazar por no tener a quién
        // pedírselo: los dos con proveedor sí, el tercero no.
        ->and($conProveedor->json('resultados'))->toHaveCount(2);
});

test('un proveedor inexistente en el recorte se rechaza', function () {
    // Rechaza, no corrige (M-31): ignorar el recorte devolvería el catálogo entero,
    // que es lo contrario de lo que el usuario pidió.
    $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=switch&proveedor_id=9999')
        ->assertStatus(422);
});

test('los proveedores de un producto salen en el orden en que conviene elegirlos', function () {
    $alfa  = Proveedor::factory()->create(['razon_social' => 'Alfa S.A.']);
    $beta  = Proveedor::factory()->create(['razon_social' => 'Beta S.A.']);
    $gamma = Proveedor::factory()->create(['razon_social' => 'Gamma S.A.']);

    // Alfa queda preferido por ser el primero; Beta lo desmarca al pedirlo.
    $producto = Producto::factory()
        ->conProveedor($alfa, 1000)
        ->conProveedor($beta, 5000, null, true)
        ->conProveedor($gamma, null)
        ->create();

    $respuesta = $this->actingAs(usuarioCon('proveedor.ver', 'precio.ver_costo'))
        ->getJson("/buscar/productos/{$producto->id}/proveedores")->assertOk();

    // El preferido primero aunque sea más caro —a veces el barato entrega en tres
    // semanas—, después por costo, y el que no tiene costo cargado al final.
    expect(array_column($respuesta->json('resultados'), 'nombre'))
        ->toBe(['Beta S.A.', 'Alfa S.A.', 'Gamma S.A.'])
        ->and($respuesta->json('resultados.0.preferido'))->toBeTrue()
        ->and($respuesta->json('resultados.2.costo'))->toBeNull();
});

test('un proveedor dado de baja no se ofrece para pedirle', function () {
    $activo   = Proveedor::factory()->create(['razon_social' => 'Activo S.A.']);
    $inactivo = Proveedor::factory()->inactivo()->create(['razon_social' => 'Inactivo S.A.']);

    $producto = Producto::factory()
        ->conProveedor($activo, 1000)
        ->conProveedor($inactivo, 10)
        ->create();

    $respuesta = $this->actingAs(usuarioCon('proveedor.ver'))
        ->getJson("/buscar/productos/{$producto->id}/proveedores")->assertOk();

    expect($respuesta->json('resultados'))->toHaveCount(1)
        ->and($respuesta->json('resultados.0.nombre'))->toBe('Activo S.A.');
});

test('los proveedores de un producto exigen proveedor ver y ocultan el costo sin permiso', function () {
    $proveedor = Proveedor::factory()->create();
    $producto  = Producto::factory()->conProveedor($proveedor, 1000)->create();
    $url       = "/buscar/productos/{$producto->id}/proveedores";

    $otros = array_values(array_diff(permisosDelModulo('proveedor'), ['proveedor.ver']));

    $this->actingAs(usuarioCon(...$otros))->getJson($url)->assertForbidden();

    $sinCosto = $this->actingAs(usuarioCon('proveedor.ver'))->getJson($url)->assertOk();

    // Sin el permiso la clave no está, así que la pantalla no puede decir «sin costo
    // cargado» sobre un dato que el servidor no le contestó.
    expect($sinCosto->json('resultados.0'))->not->toHaveKey('costo');
});

test('encuentra clientes por razon social y por documento', function () {
    Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos', 'tipo_doc' => 'dni', 'nro_doc' => '41556778']);
    Cliente::factory()->create(['razon_social' => 'Ferretería del Centro', 'tipo_doc' => 'cuit', 'nro_doc' => '30712345678']);

    $usuario = usuarioCon('cliente.ver');

    $porNombre = $this->actingAs($usuario)->getJson('/buscar/clientes?q=tilos')->assertOk();
    $porDoc    = $this->actingAs($usuario)->getJson('/buscar/clientes?q=41556778')->assertOk();

    expect($porNombre->json('resultados.0.texto'))->toBe('Panadería Los Tilos — DNI 41556778')
        ->and($porDoc->json('resultados'))->toHaveCount(1)
        ->and($porDoc->json('resultados.0.razon_social'))->toBe('Panadería Los Tilos');
});

test('un cliente sin documento cargado se nombra solo con su razon social', function () {
    Cliente::factory()->create(['razon_social' => 'Mostrador sin datos', 'nro_doc' => null]);

    $respuesta = $this->actingAs(usuarioCon('cliente.ver'))
        ->getJson('/buscar/clientes?q=mostrador')->assertOk();

    expect($respuesta->json('resultados.0.texto'))->toBe('Mostrador sin datos')
        ->and($respuesta->json('resultados.0.documento'))->toBeNull();
});

test('encuentra proveedores activos por razon social', function () {
    Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral S.A.']);
    Proveedor::factory()->inactivo()->create(['razon_social' => 'Austral Vieja S.A.']);

    $respuesta = $this->actingAs(usuarioCon('proveedor.ver'))
        ->getJson('/buscar/proveedores?q=austral')->assertOk();

    expect($respuesta->json('resultados'))->toHaveCount(1)
        ->and($respuesta->json('resultados.0.texto'))->toBe('Distribuidora Austral S.A.');
});

test('el buscador no ofrece los productos que ya estan cargados', function () {
    $cargado = Producto::factory()->create(['nombre' => 'Cable de red corto']);
    $libre   = Producto::factory()->create(['nombre' => 'Cable de red largo']);

    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable&excluir[]='.$cargado->id)
        ->assertOk();

    // El pendiente #30 dejando de existir: no se puede elegir dos veces lo que no se
    // ofrece dos veces.
    expect($respuesta->json('resultados'))->toHaveCount(1)
        ->and($respuesta->json('resultados.0.id'))->toBe($libre->id);
});

test('excluir un id que no existe no recorta nada y no se rechaza', function () {
    Producto::factory()->create(['nombre' => 'Cable de red']);

    // A diferencia de `proveedor_id`, que sí exige existir: ignorar ese recorte
    // devolvería el catálogo entero, y acá un id que no está ya no se ofrecía.
    $respuesta = $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable&excluir[]=999999')->assertOk();

    expect($respuesta->json('resultados'))->toHaveCount(1);
});

test('excluir mas productos que los que entran en un documento se rechaza', function () {
    // Cincuenta es el tope de líneas de los dos Form Requests, así que una lista más
    // larga no puede venir de una pantalla.
    $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable&'.http_build_query(['excluir' => range(1, 51)]))
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

test('excluir algo que no es un id se rechaza', function (string $query) {
    // Rechaza, no corrige (M-31). El segundo caso es el que el componente tiene
    // prohibido mandar: una lista vacía se omite del todo, no se manda vacía.
    $this->actingAs(usuarioCon('producto.ver'))
        ->getJson('/buscar/productos?q=cable'.$query)
        ->assertStatus(422);
})->with([
    'texto en la lista' => ['&excluir[]=todos'],
    'un elemento vacío' => ['&excluir[]='],
    'no es una lista'   => ['&excluir=3'],
]);