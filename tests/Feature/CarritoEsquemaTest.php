<?php

use App\Models\Carrito;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * El esquema del carrito (tramo E). Con `Schema::getColumns()`, sin depender de la ayuda de
 * EsquemaDatosTest: así el archivo corre solo.
 */

beforeEach(function () {
    $this->columna = function (string $tabla, string $columna): array {
        $fila = collect(Schema::getColumns($tabla))->firstWhere('name', $columna);
        expect($fila)->not->toBeNull("No existe {$tabla}.{$columna}");

        return $fila;
    };
});

test('el enum del carrito declara los cuatro estados y coincide con el modelo', function () {
    // `abandonado` no se usa en esta etapa, pero modificar el ENUM después reescribe la tabla.
    preg_match_all("/'([^']*)'/", ($this->columna)('carritos', 'estado')['type'], $valores);

    expect($valores[1])->toEqualCanonicalizing(array_keys(Carrito::ESTADOS));
});

test('el carrito tiene su plazo de reserva y los dos indices', function () {
    expect(($this->columna)('carritos', 'reservado_hasta')['nullable'])->toBeTrue();

    $indices = collect(Schema::getIndexes('carritos'))->pluck('columns')->all();

    expect($indices)->toContain(['user_id', 'estado'])
        ->and($indices)->toContain(['estado', 'reservado_hasta']);
});

test('el renglon guarda referencias decimales y nulas, no precios', function () {
    foreach (['contado_referencia', 'lista_referencia'] as $columna) {
        $c = ($this->columna)('carrito_lineas', $columna);
        expect($c['type'])->toBe('decimal(12,2)')
            ->and($c['nullable'])->toBeTrue();
    }

    expect(($this->columna)('carrito_lineas', 'cantidad')['type'])->toContain('unsigned');
});

test('un producto ocupa un solo renglon por carrito', function () {
    $unico = collect(Schema::getIndexes('carrito_lineas'))
        ->first(fn ($i) => $i['unique'] && $i['columns'] === ['carrito_id', 'producto_id']);

    expect($unico)->not->toBeNull();
});

test('las dos tablas tienen marcas de tiempo', function () {
    foreach (['carritos', 'carrito_lineas'] as $tabla) {
        expect(Schema::hasColumns($tabla, ['created_at', 'updated_at']))->toBeTrue("Faltan en {$tabla}");
    }
});

// Tramo F: el pedido de la tienda es una venta más.

test('la venta apunta a un solo carrito, y el mostrador lo deja nulo', function () {
    expect(($this->columna)('ventas', 'carrito_id')['nullable'])->toBeTrue();

    $unico = collect(Schema::getIndexes('ventas'))
        ->first(fn ($i) => $i['unique'] && $i['columns'] === ['carrito_id']);
    $foranea = collect(Schema::getForeignKeys('ventas'))
        ->first(fn ($f) => $f['columns'] === ['carrito_id']);

    expect($unico)->not->toBeNull()
        ->and($foranea['foreign_table'])->toBe('carritos');
});

test('el medio de pago es nulo o uno de los dos de la tienda', function () {
    $c = ($this->columna)('ventas', 'medio_pago');
    preg_match_all("/'([^']*)'/", $c['type'], $valores);

    expect($c['nullable'])->toBeTrue()
        ->and($valores[1])->toEqualCanonicalizing(['contado', 'pasarela']);
});

test('la direccion se congela con los largos de direcciones', function () {
    foreach (['calle', 'numero', 'piso_depto', 'codigo_postal', 'localidad', 'provincia'] as $campo) {
        $venta = ($this->columna)('ventas', "entrega_{$campo}");
        $origen = ($this->columna)('direcciones', $campo);

        expect($venta['type'])->toBe($origen['type'], "entrega_{$campo}")
            ->and($venta['nullable'])->toBeTrue();
    }
});

test('la reserva del pedido vence y tiene su indice, y el canal el suyo', function () {
    expect(($this->columna)('ventas', 'reserva_vence')['nullable'])->toBeTrue();

    $indices = collect(Schema::getIndexes('ventas'))->pluck('columns')->all();

    expect($indices)->toContain(['estado', 'reserva_vence'])
        ->and($indices)->toContain(['canal']);
});

test('el enum del canal coincide con el modelo', function () {
    preg_match_all("/'([^']*)'/", ($this->columna)('ventas', 'canal')['type'], $valores);

    expect($valores[1])->toEqualCanonicalizing(array_keys(Venta::CANALES));
});

test('el carrito convertido llega a su venta y la venta a su carrito', function () {
    $relacion = (new Carrito)->venta();

    expect($relacion->getForeignKeyName())->toBe('carrito_id')
        ->and((new Venta)->carrito()->getForeignKeyName())->toBe('carrito_id');
});
