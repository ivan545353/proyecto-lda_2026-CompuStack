<?php

use App\Models\Carrito;
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
