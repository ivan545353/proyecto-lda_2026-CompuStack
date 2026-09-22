<?php

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Marca;
use App\Services\ProductoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    $this->service   = app(ProductoService::class);
    $this->categoria = Categoria::factory()->create();

    // Datos como los deja ProductoRequest: ya normalizados y validados.
    $this->datos = fn (array $cambios = []) => array_merge([
        'codigo'              => 'SSD-1TB',
        'nombre'              => 'Disco SSD 1TB',
        'descripcion'         => null,
        'categoria_id'        => $this->categoria->id,
        'marca_id'            => null,
        'proveedor_id'        => null,
        'precio_lista'        => '95000.00',
        'precio_contado'      => null,
        'alicuota_iva'        => '10.50',
        'stock_minimo'        => 2,
        'cantidad_reposicion' => 5,
        'activo'              => true,
    ], $cambios);
});

// --- Alta -----------------------------------------------------------------

test('sin precio de contado se usa el de lista, y si viene se respeta', function () {
    $sinContado = $this->service->crear(($this->datos)());
    $conContado = $this->service->crear(($this->datos)(['codigo' => 'SSD-2TB', 'precio_contado' => '85500.00']));

    expect($sinContado->precio_contado)->toBe('95000.00')
        ->and($conContado->precio_contado)->toBe('85500.00');
});

test('el alta guarda las imagenes en el orden en que se subieron', function () {
    $producto = $this->service->crear(($this->datos)(), [
        UploadedFile::fake()->image('frente.png'),
        UploadedFile::fake()->image('dorso.png'),
    ]);

    expect($producto->imagenes)->toHaveCount(2);

    foreach ($producto->imagenes as $ruta) {
        Storage::disk('public')->assertExists($ruta);
    }
});

test('los archivos que trae validated no terminan en la columna', function () {
    // validated() trae 'imagenes' con los archivos subidos, y 'imagenes' es
    // también una columna. El servicio guarda las rutas, nunca los objetos.
    $archivo  = UploadedFile::fake()->image('frente.png');
    $producto = $this->service->crear(($this->datos)(['imagenes' => [$archivo]]), [$archivo]);

    expect($producto->fresh()->imagenes)->toHaveCount(1)
        ->and($producto->fresh()->imagenes[0])->toStartWith('productos/');
});

test('si la base rechaza el alta, las imagenes subidas se borran', function () {
    // Un código repetido que llega al servicio: por ejemplo, dos pestañas
    // que pasan la validación a la vez. La base lo rechaza por el índice.
    Producto::factory()->create(['codigo' => 'SSD-1TB']);

    expect(fn () => $this->service->crear(($this->datos)(), [UploadedFile::fake()->image('frente.png')]))
        ->toThrow(QueryException::class);

    expect(Storage::disk('public')->allFiles('productos'))->toBe([]);
});

// --- Edición --------------------------------------------------------------


test('el orden decide que imagenes quedan, en que orden y cual es la principal', function () {
    $a = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $b = UploadedFile::fake()->image('b.png')->store('productos', 'public');
    $c = UploadedFile::fake()->image('c.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a, $b, $c]]);

    $editado = $this->service->actualizar($producto, ($this->datos)(), [UploadedFile::fake()->image('nueva.png')], [
        ['tipo' => 'nueva',  'indice' => 0],
        ['tipo' => 'actual', 'ruta' => $c],
        ['tipo' => 'actual', 'ruta' => $a],
    ]);

    // La nueva queda como principal, C pasa antes que A, y B se quitó.
    expect($editado->imagenes)->toHaveCount(3)
        ->and($editado->imagenes[1])->toBe($c)
        ->and($editado->imagenes[2])->toBe($a);

    Storage::disk('public')->assertExists($editado->imagenes[0]);
    Storage::disk('public')->assertMissing($b);
});

test('un orden vacio quita todas las imagenes', function () {
    // Es la razón del formato JSON: un orden[] vacío no enviaría ningún
    // campo, y el servidor no distinguiría "quitá todas" de "no las tocaste".
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $editado = $this->service->actualizar($producto, ($this->datos)(), [], []);

    expect($editado->imagenes)->toBeNull();
    Storage::disk('public')->assertMissing($a);
});

test('sin orden se conservan las actuales y las nuevas van al final', function () {
    // Sin JavaScript, o desde la API sin orden: se puede agregar, no reordenar.
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $editado = $this->service->actualizar($producto, ($this->datos)(), [UploadedFile::fake()->image('b.png')]);

    expect($editado->imagenes)->toHaveCount(2)
        ->and($editado->imagenes[0])->toBe($a);
});

test('un archivo nuevo que el orden no usa no se guarda', function () {
    $producto = $this->service->crear(($this->datos)(), [UploadedFile::fake()->image('a.png')], []);

    expect($producto->imagenes)->toBeNull()
        ->and(Storage::disk('public')->allFiles('productos'))->toBe([]);
});

test('el orden no puede hacer pasar un archivo ajeno por imagen del producto ni borrarlo', function () {
    Storage::disk('public')->put('config/secreto.txt', 'no borrar');
    $a        = UploadedFile::fake()->image('a.png')->store('productos', 'public');
    $producto = Producto::factory()->create(['imagenes' => [$a]]);

    $editado = $this->service->actualizar($producto, ($this->datos)(), [], [
        ['tipo' => 'actual', 'ruta' => 'config/secreto.txt'],
        ['tipo' => 'actual', 'ruta' => '../../.env'],
        ['tipo' => 'actual', 'ruta' => $a],
    ]);

    // Las rutas ajenas se ignoran: la lista queda sólo con la imagen propia,
    // y el archivo ajeno sigue en su lugar.
    expect($editado->imagenes)->toBe([$a]);
    Storage::disk('public')->assertExists('config/secreto.txt');
});

test('editar no toca el stock ni el costo aunque vengan en los datos', function () {
    $producto = Producto::factory()->conStock(7)->create();
    $producto->costo_promedio = 123.45;
    $producto->save();

    $this->service->actualizar($producto->fresh(), ($this->datos)(['stock' => 999, 'costo_promedio' => 1]));

    // Sin kardex no hay cambio de stock (A-13): eso lo hace la Fase 5.
    expect($producto->fresh()->stock)->toBe(7)
        ->and($producto->fresh()->costo_promedio)->toBe('123.45');
});

// --- Baja -----------------------------------------------------------------

test('un producto sin stock ni referencias se borra con sus imagenes', function () {
    $imagen   = UploadedFile::fake()->image('1.png')->store('productos', 'public');
    $producto = Producto::factory()->sinStock()->create(['imagenes' => [$imagen]]);

    expect($this->service->eliminar($producto))->toBeTrue();

    $this->assertModelMissing($producto);
    Storage::disk('public')->assertMissing($imagen);
});

test('un producto con stock se desactiva en lugar de borrarse', function () {
    $producto = Producto::factory()->conStock(3)->create();

    expect($this->service->eliminar($producto))->toBeFalse()
        ->and($producto->fresh()->activo)->toBeFalse();
});

test('un producto con historial se desactiva aunque no tenga stock', function () {
    $producto = Producto::factory()->sinStock()->create();

    // Un ajuste que lo dejó en cero: el stock es 0, pero el kardex registra
    // que existió. Borrarlo rompería la clave foránea del movimiento y
    // perdería el rastro de quién lo ajustó.
    DB::table('movimientos_stock')->insert([
        'producto_id'      => $producto->id,
        'tipo'             => 'ajuste',
        'cantidad'         => -2,
        'stock_resultante' => 0,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect($this->service->eliminar($producto))->toBeFalse();
    $this->assertModelExists($producto);
});

test('las opciones incluyen la marca actual aunque este inactiva, y no otras inactivas', function () {
    $actual   = Marca::factory()->inactiva()->create();
    $otra     = Marca::factory()->inactiva()->create();
    $activa   = Marca::factory()->create();
    $producto = Producto::factory()->create(['marca_id' => $actual->id]);

    $ids = $this->service->opciones($producto)['marcas']->pluck('id')->all();

    expect($ids)->toContain($actual->id)->toContain($activa->id)
        ->and(in_array($otra->id, $ids, true))->toBeFalse();
});