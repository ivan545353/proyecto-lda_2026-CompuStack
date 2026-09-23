<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
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