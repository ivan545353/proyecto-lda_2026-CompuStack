<?php

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Los recortes del ajuste masivo
|--------------------------------------------------------------------------
|
| Los siete recortes del plan, y qué scope sirve a cada uno:
|
|   todos los productos          ningún scope: la consulta sin filtrar
|   una categoría y sus hijas    deCategoria()        — de la Fase 3
|   una marca                    deMarca()            — de la Fase 3
|   un proveedor                 deProveedor()        — del tramo B
|   selección manual             conIds()             — acá
|   rango de costo o de precio   conCostoDesde/Hasta, conPrecioDesde/Hasta — acá
|   cruzados                     los anteriores encadenados
|
| El recorte cruzado es el que justifica que esto sean scopes y no un método que
| los combine: encadenarlos es gratis y cada uno se prueba solo. Los cuatro que ya
| existían tienen su test en ProductoFiltrosTest y no se repiten acá; lo que sí se
| prueba acá es que el cruce no deje pasar de más, que es el defecto que A-24
| describe y que un scope correcto por separado no impide.
|
| `conPrecioManual()` no es un recorte: es la tercera salvaguarda. Entra en este
| archivo porque es una condición más de la misma consulta.
|
*/

/** Un producto con costo y precio de contado puestos a mano. */
function productoDeRecorte(float $costo, float $precioContado, array $cambios = []): Producto
{
    return Producto::factory()
        ->conStockYCosto(10, $costo)
        ->create(array_merge(['precio_contado' => $precioContado], $cambios));
}

// --- Selección manual -----------------------------------------------------

test('conIds trae sólo los elegidos', function () {
    $elegido = Producto::factory()->create(['codigo' => 'SI-1']);
    Producto::factory()->create(['codigo' => 'NO-1']);

    expect(Producto::conIds([$elegido->id])->pluck('codigo')->all())->toBe(['SI-1']);
});

test('conIds con la lista vacía no filtra', function () {
    // Como cualquier scope del proyecto: un valor vacío devuelve la consulta sin
    // tocar. Que eso no se convierta en «todos los productos» por un descuido lo
    // impide el Form Request, que exige la lista cuando el recorte es la selección
    // manual. El scope no es el lugar donde se decide eso.
    Producto::factory()->count(3)->create();

    expect(Producto::conIds([])->count())->toBe(3)
        ->and(Producto::conIds(null)->count())->toBe(3);
});

test('conIds ignora lo que no es un id', function () {
    // El ajuste recibe las casillas del listado, y una casilla manipulada a mano no
    // tiene que llegar a la consulta como texto.
    $elegido = Producto::factory()->create(['codigo' => 'SI-1']);
    Producto::factory()->create(['codigo' => 'NO-1']);

    expect(Producto::conIds([$elegido->id, 'todos', '', '-5'])->pluck('codigo')->all())
        ->toBe(['SI-1']);
});

// --- Rango de costo -------------------------------------------------------

test('conCostoDesde incluye el límite', function () {
    productoDeRecorte(10000, 20000, ['codigo' => 'JUSTO']);
    productoDeRecorte(9999.99, 20000, ['codigo' => 'ABAJO']);

    expect(Producto::conCostoDesde(10000)->pluck('codigo')->all())->toBe(['JUSTO']);
});

test('conCostoHasta incluye el límite', function () {
    productoDeRecorte(10000, 20000, ['codigo' => 'JUSTO']);
    productoDeRecorte(10000.01, 20000, ['codigo' => 'ARRIBA']);

    expect(Producto::conCostoHasta(10000)->pluck('codigo')->all())->toBe(['JUSTO']);
});

test('los dos límites juntos dan un rango cerrado', function () {
    productoDeRecorte(5000, 20000, ['codigo' => 'BARATO']);
    productoDeRecorte(15000, 20000, ['codigo' => 'MEDIO']);
    productoDeRecorte(90000, 20000, ['codigo' => 'CARO']);

    expect(Producto::conCostoDesde(10000)->conCostoHasta(20000)->pluck('codigo')->all())
        ->toBe(['MEDIO']);
});

test('un rango de costo sin número no filtra', function () {
    productoDeRecorte(5000, 20000);

    expect(Producto::conCostoDesde(null)->conCostoHasta('')->count())->toBe(1);
});

// --- Rango de precio ------------------------------------------------------

test('el rango de precio mira el de contado y no el de lista', function () {
    // El de lista es el de contado con el recargo adentro: recortar por él sería
    // recortar por el mismo número corrido, y el ajuste se cargaría con un límite
    // que el operador no reconoce.
    productoDeRecorte(5000, 10000, ['codigo' => 'CONTADO-10K', 'precio_lista' => 50000]);
    productoDeRecorte(5000, 40000, ['codigo' => 'CONTADO-40K', 'precio_lista' => 12000]);

    expect(Producto::conPrecioHasta(20000)->pluck('codigo')->all())->toBe(['CONTADO-10K']);
});

test('conPrecioDesde incluye el límite', function () {
    productoDeRecorte(5000, 10000, ['codigo' => 'JUSTO']);
    productoDeRecorte(5000, 9999.99, ['codigo' => 'ABAJO']);

    expect(Producto::conPrecioDesde(10000)->pluck('codigo')->all())->toBe(['JUSTO']);
});

// --- La tercera salvaguarda ----------------------------------------------

test('conPrecioManual en false deja afuera los fijados a mano', function () {
    Producto::factory()->create(['codigo' => 'CALCULADO', 'precio_manual' => false]);
    Producto::factory()->create(['codigo' => 'A-MANO', 'precio_manual' => true]);

    expect(Producto::conPrecioManual(false)->pluck('codigo')->all())->toBe(['CALCULADO']);
});

test('conPrecioManual en true trae sólo los fijados a mano', function () {
    Producto::factory()->create(['codigo' => 'CALCULADO', 'precio_manual' => false]);
    Producto::factory()->create(['codigo' => 'A-MANO', 'precio_manual' => true]);

    expect(Producto::conPrecioManual(true)->pluck('codigo')->all())->toBe(['A-MANO']);
});

test('conPrecioManual en null no filtra, que es lo que hace la casilla', function () {
    // Incluir los de precio manual no es pedir los manuales: es dejar de excluirlos.
    Producto::factory()->create(['precio_manual' => false]);
    Producto::factory()->create(['precio_manual' => true]);

    expect(Producto::conPrecioManual(null)->count())->toBe(2);
});

// --- El recorte cruzado ---------------------------------------------------

test('el recorte cruzado no deja pasar lo que cumple sólo una condición', function () {
    // El caso del plan: llegó la lista nueva del mayorista, pero sólo para las
    // memorias. Cada scope por separado está bien y el cruce es lo que puede estar
    // mal, que es la forma de A-24.
    $componentes = Categoria::factory()->create();
    $memorias    = Categoria::factory()->hijaDe($componentes)->create();
    $monitores   = Categoria::factory()->create();

    $mayorista = Proveedor::factory()->create();
    $otro      = Proveedor::factory()->create();

    // El único que cumple las tres: está bajo Componentes, se le compra al
    // mayorista y su precio lo calcula el motor.
    Producto::factory()->conProveedor($mayorista)->create([
        'codigo' => 'ALCANZADO', 'categoria_id' => $memorias->id, 'precio_manual' => false,
    ]);

    Producto::factory()->conProveedor($mayorista)->create([
        'codigo' => 'ES-MANUAL', 'categoria_id' => $memorias->id, 'precio_manual' => true,
    ]);

    Producto::factory()->conProveedor($otro)->create([
        'codigo' => 'OTRO-PROVEEDOR', 'categoria_id' => $memorias->id, 'precio_manual' => false,
    ]);

    Producto::factory()->conProveedor($mayorista)->create([
        'codigo' => 'OTRA-CATEGORIA', 'categoria_id' => $monitores->id, 'precio_manual' => false,
    ]);

    $alcanzados = Producto::query()
        ->deCategoria($componentes->id)
        ->deProveedor($mayorista->id)
        ->conPrecioManual(false)
        ->pluck('codigo')
        ->all();

    expect($alcanzados)->toBe(['ALCANZADO']);
});

test('el cruce de un rango con una marca se aplica entero', function () {
    $marca = Marca::factory()->create();
    $otra  = Marca::factory()->create();

    productoDeRecorte(5000, 20000, ['codigo' => 'ALCANZADO', 'marca_id' => $marca->id]);
    productoDeRecorte(90000, 20000, ['codigo' => 'MUY-CARO', 'marca_id' => $marca->id]);
    productoDeRecorte(5000, 20000, ['codigo' => 'OTRA-MARCA', 'marca_id' => $otra->id]);

    expect(Producto::deMarca($marca->id)->conCostoHasta(10000)->pluck('codigo')->all())
        ->toBe(['ALCANZADO']);
});