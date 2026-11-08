<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\OrdenCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de productos
|--------------------------------------------------------------------------
|
| El caso original de A-24: ItemController mandaba `categoriaId` y `limit`,
| ItemDao esperaba `codigo`, `nombre`, `categoria`, `stock`, `limit` y
| `offset`. Ningún filtro se aplicaba y el LIMIT tampoco, porque nadie mandaba
| `offset`. Cada filtro del contrato tiene su test acá.
|
*/

// --- Búsqueda -------------------------------------------------------------

test('buscar encuentra por nombre', function () {
    Producto::factory()->create(['nombre' => 'Monitor ASUS VG248QG', 'codigo' => 'VG248QG']);
    Producto::factory()->create(['nombre' => 'Mouse Logitech', 'codigo' => 'G505']);

    expect(Producto::buscar('monitor')->pluck('codigo')->all())->toBe(['VG248QG']);
});

test('buscar encuentra por codigo', function () {
    Producto::factory()->create(['nombre' => 'Monitor ASUS', 'codigo' => 'VG248QG']);
    Producto::factory()->create(['nombre' => 'Mouse Logitech', 'codigo' => 'G505']);

    expect(Producto::buscar('g505')->pluck('nombre')->all())->toBe(['Mouse Logitech']);
});

test('buscar combinado con otro filtro no deja pasar resultados de mas', function () {
    $monitores = Categoria::factory()->create();
    $mouses    = Categoria::factory()->create();

    Producto::factory()->create(['nombre' => 'Monitor gamer', 'codigo' => 'MON-1', 'categoria_id' => $monitores->id]);
    Producto::factory()->create(['nombre' => 'Mouse gamer',   'codigo' => 'MOU-1', 'categoria_id' => $mouses->id]);

    // "gamer" coincide por nombre en las dos categorías. Sin los paréntesis
    // del scope, el filtro de categoría sólo se aplicaría a la búsqueda por
    // código y volverían los dos productos.
    expect(Producto::buscar('gamer')->deCategoria($monitores->id)->pluck('codigo')->all())
        ->toBe(['MON-1']);
});

test('buscar trata los comodines como texto', function () {
    Producto::factory()->create(['nombre' => 'Oferta 100% original', 'codigo' => 'A-1']);
    Producto::factory()->create(['nombre' => 'Fuente 1000W',         'codigo' => 'A-2']);

    // Sin escapar, "100%" también encontraría "1000W".
    expect(Producto::buscar('100%')->pluck('codigo')->all())->toBe(['A-1']);
});

// --- Categoría, marca y estado --------------------------------------------

test('deCategoria incluye los productos de todas sus subcategorias', function () {
    // Componentes › Almacenamiento › Discos SSD, y otra rama aparte
    $almacenamiento = Categoria::factory()->create();
    $ssd            = Categoria::factory()->hijaDe($almacenamiento)->create();
    $nvme           = Categoria::factory()->hijaDe($ssd)->create();
    $otra           = Categoria::factory()->create();

    Producto::factory()->create(['categoria_id' => $almacenamiento->id]);
    Producto::factory()->create(['categoria_id' => $ssd->id]);
    Producto::factory()->create(['categoria_id' => $nvme->id]);
    Producto::factory()->create(['categoria_id' => $otra->id]);

    // Buscar en Almacenamiento trae toda la rama, no sólo lo cargado en ella.
    expect(Producto::deCategoria($almacenamiento->id)->count())->toBe(3)
        ->and(Producto::deCategoria((string) $ssd->id)->count())->toBe(2)
        ->and(Producto::deCategoria($nvme->id)->count())->toBe(1);
});

test('deCategoria con una categoria que no existe no devuelve nada', function () {
    Producto::factory()->count(2)->create();

    // Si la ignorara, mostraría el catálogo entero como si fuera "Todas".
    expect(Producto::deCategoria(99999)->count())->toBe(0);
});

test('deMarca devuelve solo los de esa marca', function () {
    $kingston = Marca::factory()->create();

    Producto::factory()->count(2)->create(['marca_id' => $kingston->id]);
    Producto::factory()->create();

    expect(Producto::deMarca($kingston->id)->count())->toBe(2);
});

test('conEstado separa activos de inactivos', function () {
    Producto::factory()->count(2)->create();
    Producto::factory()->inactivo()->create();

    expect(Producto::conEstado('activos')->count())->toBe(2)
        ->and(Producto::conEstado('inactivos')->count())->toBe(1);
});

// --- Stock ----------------------------------------------------------------

test('con stock y sin stock se calculan sobre lo disponible', function () {
    Producto::factory()->conStock(5)->create();
    Producto::factory()->sinStock()->create();
    // Hay 3 en el depósito, pero las 3 están reservadas: no se pueden vender.
    Producto::factory()->conStock(3, reservado: 3)->create();

    expect(Producto::conStock('disponible')->count())->toBe(1)
        ->and(Producto::conStock('agotado')->count())->toBe(2);
});

test('stock bajo incluye lo que esta en el minimo, por debajo o agotado', function () {
    Producto::factory()->conStock(10)->create(['stock_minimo' => 3]);              // holgado
    Producto::factory()->conStock(3)->create(['stock_minimo' => 3]);               // en el mínimo
    Producto::factory()->conStock(1)->create(['stock_minimo' => 3]);               // por debajo
    Producto::factory()->sinStock()->create(['stock_minimo' => 3]);                // agotado
    Producto::factory()->conStock(5, reservado: 4)->create(['stock_minimo' => 2]); // disponible 1

    expect(Producto::conStock('critico')->count())->toBe(4)
        // El filtro y el scope que usa la reposición automática coinciden.
        ->and(Producto::stockCritico()->count())->toBe(4);
});

test('para reponer es lo critico que se repone y todavia no viene en camino', function () {
    $proveedor = Proveedor::factory()->create();

    $aPedir   = Producto::factory()->conStock(0)->conProveedor($proveedor)->create(['stock_minimo' => 5]);
    $enCamino = Producto::factory()->conStock(0)->conProveedor($proveedor)->create(['stock_minimo' => 5]);

    Producto::factory()->conStock(50)->conProveedor($proveedor)->create(['stock_minimo' => 5]);  // holgado
    Producto::factory()->conStock(0)->conProveedor($proveedor)->create(['stock_minimo' => 0]);   // no se repone solo
    Producto::factory()->inactivo()->conStock(0)->conProveedor($proveedor)->create(['stock_minimo' => 5]);

    // Lo que ya viene en camino no se vuelve a pedir: es el error más caro de todos.
    OrdenCompra::factory()->conLinea($enCamino, 10)->create(['proveedor_id' => $proveedor->id]);

    expect(Producto::paraReponer()->pluck('id')->all())->toBe([$aPedir->id]);
});

test('sin proveedor para reponer son los que hay que pedir y no tienen a quien pedirle', function () {
    $deBaja = Proveedor::factory()->inactivo()->create();

    Producto::factory()->conStock(0)->create(['stock_minimo' => 5, 'codigo' => 'SIN-001']);
    Producto::factory()->conStock(0)->conProveedor($deBaja)->create(['stock_minimo' => 5, 'codigo' => 'BAJA-001']);
    Producto::factory()->conStock(0)->conProveedor(Proveedor::factory()->create())
        ->create(['stock_minimo' => 5, 'codigo' => 'PEDIBLE-1']);
    Producto::factory()->conStock(50)->create(['stock_minimo' => 5, 'codigo' => 'HOLGADO-1']);

    expect(Producto::sinProveedorParaReponer()->orderBy('codigo')->pluck('codigo')->all())
        ->toBe(['BAJA-001', 'SIN-001'])
        // El aviso del armador cuenta esto y el enlace del catálogo filtra esto mismo.
        ->and(Producto::conStock('sin_proveedor')->count())->toBe(2);
});

test('con proveedor activo deja afuera al que solo tiene proveedores de baja', function () {
    $pedible = Producto::factory()->conProveedor(Proveedor::factory()->create())->create();

    Producto::factory()->conProveedor(Proveedor::factory()->inactivo()->create())->create();
    Producto::factory()->create();

    expect(Producto::conProveedorActivo()->pluck('id')->all())->toBe([$pedible->id])
        ->and(Producto::sinProveedorActivo()->count())->toBe(2);
});

// --- Valores vacíos y combinación -----------------------------------------

test('los filtros sin valor o con un valor desconocido no filtran', function () {
    Producto::factory()->conStock(5)->create();
    Producto::factory()->sinStock()->inactivo()->create();

    foreach ([null, '', 'cualquier-cosa'] as $valor) {
        expect(Producto::buscar($valor === 'cualquier-cosa' ? null : $valor)->count())->toBe(2)
            ->and(Producto::deCategoria($valor)->count())->toBe(2)
            ->and(Producto::deMarca($valor)->count())->toBe(2)
            ->and(Producto::conEstado($valor)->count())->toBe(2)
            ->and(Producto::conStock($valor)->count())->toBe(2);
    }
});

test('todos los filtros se combinan entre si', function () {
    $categoria = Categoria::factory()->create();
    $marca     = Marca::factory()->create();

    $buscado = Producto::factory()->conStock(5)
        ->create(['nombre' => 'Disco SSD 1TB', 'categoria_id' => $categoria->id, 'marca_id' => $marca->id]);

    // Cada uno falla en exactamente un criterio.
    Producto::factory()->conStock(5)->create(['nombre' => 'Disco SSD 2TB', 'marca_id' => $marca->id]);
    Producto::factory()->conStock(5)->create(['nombre' => 'Disco SSD 512', 'categoria_id' => $categoria->id]);
    Producto::factory()->sinStock()->create(['nombre' => 'Disco SSD 256', 'categoria_id' => $categoria->id, 'marca_id' => $marca->id]);
    Producto::factory()->conStock(5)->inactivo()->create(['nombre' => 'Disco SSD 128', 'categoria_id' => $categoria->id, 'marca_id' => $marca->id]);

    $resultado = Producto::buscar('ssd')
        ->deCategoria($categoria->id)
        ->deMarca($marca->id)
        ->conEstado('activos')
        ->conStock('disponible')
        ->pluck('id')
        ->all();

    expect($resultado)->toBe([$buscado->id]);
});

// --- Proveedor ------------------------------------------------------------
//
// El filtro que el pendiente #31 pedía: desde la ficha de un proveedor no se podía
// ver qué productos provee, porque el catálogo no tenía por dónde filtrarlos.

test('de proveedor devuelve solo los que se le compran a ese proveedor', function () {
    $austral = Proveedor::factory()->create();
    $sur     = Proveedor::factory()->create();

    Producto::factory()->conProveedor($austral)->create(['codigo' => 'A-1']);
    Producto::factory()->conProveedor($sur)->create(['codigo' => 'B-1']);
    Producto::factory()->create(['codigo' => 'C-1']);   // sin ningún proveedor

    expect(Producto::deProveedor($austral->id)->pluck('codigo')->all())->toBe(['A-1']);
});

test('de proveedor encuentra el producto aunque ese proveedor no sea el preferido', function () {
    $preferido = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    Producto::factory()
        ->conProveedor($preferido, preferido: true)
        ->conProveedor($otro)
        ->create(['codigo' => 'A-1']);

    // Buscar por el preferido es otra pregunta: acá lo que se filtra es a quién se le
    // compra, y a los dos se les compra.
    expect(Producto::deProveedor($otro->id)->pluck('codigo')->all())->toBe(['A-1']);
});

test('de proveedor combinado con la busqueda no deja pasar resultados de mas', function () {
    $austral = Proveedor::factory()->create();
    $sur     = Proveedor::factory()->create();

    Producto::factory()->conProveedor($austral)->create(['nombre' => 'Cable gamer', 'codigo' => 'A-1']);
    Producto::factory()->conProveedor($sur)->create(['nombre' => 'Mouse gamer', 'codigo' => 'B-1']);

    // "gamer" coincide por nombre en los dos. Si el OR de la búsqueda no estuviera
    // agrupado, el filtro de proveedor sólo se aplicaría a una de sus ramas.
    expect(Producto::buscar('gamer')->deProveedor($austral->id)->pluck('codigo')->all())->toBe(['A-1']);
});

test('de proveedor con un id inexistente no devuelve nada', function () {
    Producto::factory()->conProveedor(Proveedor::factory()->create())->create();

    // Vacío y no el catálogo entero: un proveedor que ya no está no significa «todos».
    expect(Producto::deProveedor(999_999)->count())->toBe(0);
});

test('de proveedor sin valor no filtra', function () {
    Producto::factory()->count(2)->create();

    expect(Producto::deProveedor(null)->count())->toBe(2);
});

// --- El recorte del buscador remoto ---------------------------------------

test('sinIds deja afuera los que ya estan cargados', function () {
    $cargado = Producto::factory()->create(['codigo' => 'YA-1']);
    Producto::factory()->create(['codigo' => 'LIBRE-1']);

    expect(Producto::sinIds([$cargado->id])->pluck('codigo')->all())->toBe(['LIBRE-1']);
});

test('sinIds con la lista vacia no filtra', function () {
    // El documento que todavía no tiene ningún renglón, que es como abre el
    // formulario: el vacío significa «no excluyas nada».
    Producto::factory()->count(3)->create();

    expect(Producto::sinIds([])->count())->toBe(3)
        ->and(Producto::sinIds(null)->count())->toBe(3);
});

test('sinIds ignora lo que no es un id', function () {
    $cargado = Producto::factory()->create(['codigo' => 'YA-1']);
    Producto::factory()->create(['codigo' => 'LIBRE-1']);

    expect(Producto::sinIds([$cargado->id, 'todos', '', '-5'])->pluck('codigo')->all())
        ->toBe(['LIBRE-1']);
});