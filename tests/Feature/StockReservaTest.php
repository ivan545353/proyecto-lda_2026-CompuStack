<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Marca;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\StockService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * La reserva por la única puerta (tramo E, D-5, HL-4). El mostrador sigue en StockServiceTest,
 * que no se toca: `descontar()` sin `$deReserva` tiene que comportarse como antes.
 */

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
    $this->stock  = app(StockService::class);
    $this->origen = fn () => Marca::factory()->create();

    // La escritura de la otra petición, justo antes de que esta tome el bloqueo. Con
    // `beforeExecuting` y no `DB::listen`: el listener corre después de la consulta, y acá el
    // bloqueo y la lectura son la misma, así que lo inyectado ya no entraría en la cuenta.
    $this->antesDelBloqueo = function (callable $competidora): void {
        $hecho = false;

        DB::beforeExecuting(function (string $sql) use (&$hecho, $competidora) {
            $sql = strtolower($sql);

            if (! $hecho && str_contains($sql, 'from `productos`') && str_contains($sql, 'for update')) {
                $hecho = true;
                $competidora();
            }
        });
    };
});

// ---------- reservar() ----------

test('reservar suma a lo reservado, no toca el stock ni deja asiento', function () {
    $producto = Producto::factory()->conStock(10, 2)->create();

    $this->stock->reservar($producto, 3);

    $p = $producto->fresh();
    expect($p->stock)->toBe(10)
        ->and($p->stock_reservado)->toBe(5)
        ->and(MovimientoStock::where('producto_id', $producto->id)->count())->toBe(0);
});

test('reservar bloquea la fila del producto', function () {
    $producto = Producto::factory()->conStock(10)->create();

    DB::enableQueryLog();
    $this->stock->reservar($producto, 1);
    $consultas = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
    DB::disableQueryLog();

    expect(strtolower($consultas))->toContain('for update');
});

test('reservar sin disponible lanza y no toca nada', function () {
    $producto = Producto::factory()->conStock(5, 3)->create();

    expect(fn () => $this->stock->reservar($producto, 3))->toThrow(StockInsuficienteException::class);

    expect($producto->fresh()->stock_reservado)->toBe(3);
});

test('reservar con el disponible justo pasa', function () {
    $producto = Producto::factory()->conStock(5, 3)->create();

    $this->stock->reservar($producto, 2);

    expect($producto->fresh()->stock_disponible)->toBe(0);
});

test('una reserva competidora que entra antes del bloqueo no deja reservar de mas', function () {
    // 5 en depósito y nada reservado: el modelo que trae quien llama dice «hay 5». Otro
    // checkout reserva 4 justo antes de que este tome el bloqueo. Si se decidiera con el
    // modelo recibido, quedarían 7 reservadas sobre 5.
    $producto = Producto::factory()->conStock(5)->create();
    $producto->refresh();

    ($this->antesDelBloqueo)(fn () => DB::table('productos')->where('id', $producto->id)
        ->update(['stock_reservado' => 4]));

    expect(fn () => $this->stock->reservar($producto, 3))->toThrow(StockInsuficienteException::class);
});

test('reservar rechaza una cantidad que no es positiva', function () {
    $producto = Producto::factory()->conStock(5)->create();

    expect(fn () => $this->stock->reservar($producto, 0))->toThrow(ReglaDeNegocioException::class);
});

// ---------- liberar() ----------

test('liberar resta de lo reservado', function () {
    $producto = Producto::factory()->conStock(10, 6)->create();

    $this->stock->liberar($producto, 4);

    expect($producto->fresh()->stock_reservado)->toBe(2);
});

test('liberar nunca deja lo reservado en negativo ni falla', function () {
    // Un ajuste de inventario o una liberación repetida pueden pedir soltar más de lo que hay.
    $producto = Producto::factory()->conStock(10, 2)->create();

    $this->stock->liberar($producto, 5);

    expect($producto->fresh()->stock_reservado)->toBe(0);
});

test('liberar funciona aunque el stock haya quedado por debajo de lo reservado', function () {
    $producto = Producto::factory()->conStock(5, 5)->create();
    $this->stock->ajustar($producto, 3, 'Faltante detectado en inventario', admin());

    $this->stock->liberar($producto, 5);

    $p = $producto->fresh();
    expect($p->stock)->toBe(3)->and($p->stock_reservado)->toBe(0);
});

test('liberar no deja asiento', function () {
    $producto = Producto::factory()->conStock(10, 3)->create();

    $this->stock->liberar($producto, 3);

    expect(MovimientoStock::where('producto_id', $producto->id)->count())->toBe(0);
});

// ---------- descontar() con reserva ----------

test('descontar con reserva consume lo reservado y no falla con el stock justo', function () {
    // 3 en depósito, las 3 reservadas para este pedido: el disponible es 0, pero son suyas.
    $producto = Producto::factory()->conStock(3, 3)->create();

    $movimiento = $this->stock->descontar($producto, 3, ($this->origen)(), admin(), deReserva: 3);

    $p = $producto->fresh();
    expect($p->stock)->toBe(0)
        ->and($p->stock_reservado)->toBe(0)
        ->and($movimiento->cantidad)->toBe(-3)
        ->and($movimiento->stock_resultante)->toBe(0);
});

test('descontar con reserva no toca lo que reservaron otros', function () {
    // 10 en depósito: 2 de este pedido y 5 de otros carritos. Disponible propio: 3 + 2.
    $producto = Producto::factory()->conStock(10, 7)->create();

    $this->stock->descontar($producto, 2, ($this->origen)(), admin(), deReserva: 2);

    $p = $producto->fresh();
    expect($p->stock)->toBe(8)->and($p->stock_reservado)->toBe(5);
});

test('descontar con reserva parcial valida el resto contra el disponible', function () {
    $producto = Producto::factory()->conStock(5, 4)->create();   // disponible 1

    // 2 reservadas + 1 libre alcanzan para 3; para 4, no.
    expect(fn () => $this->stock->descontar($producto, 4, ($this->origen)(), admin(), deReserva: 2))
        ->toThrow(StockInsuficienteException::class);

    $this->stock->descontar($producto, 3, ($this->origen)(), admin(), deReserva: 2);

    $p = $producto->fresh();
    expect($p->stock)->toBe(2)->and($p->stock_reservado)->toBe(2);
});

test('descontar rechaza una parte reservada mayor que la cantidad o negativa', function () {
    $producto = Producto::factory()->conStock(10, 5)->create();

    expect(fn () => $this->stock->descontar($producto, 2, ($this->origen)(), admin(), deReserva: 3))
        ->toThrow(ReglaDeNegocioException::class)
        ->and(fn () => $this->stock->descontar($producto, 2, ($this->origen)(), admin(), deReserva: -1))
        ->toThrow(ReglaDeNegocioException::class);

    expect($producto->fresh()->stock_reservado)->toBe(5);
});

test('descontar sin reserva sigue sin poder usar lo reservado por otros', function () {
    $producto = Producto::factory()->conStock(5, 5)->create();

    expect(fn () => $this->stock->descontar($producto, 1, ($this->origen)(), admin()))
        ->toThrow(StockInsuficienteException::class);
});
