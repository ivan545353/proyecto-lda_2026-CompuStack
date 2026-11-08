<?php

use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // El HTML del panel general con dos ventas cobradas, una por transferencia.
    $this->html = function (): string {
        $producto = productoConPrecios(contado: 1000);
        ventaCobrada($producto, 3);
        ventaCobrada($producto, 2, metodo: 'transferencia');

        return $this->actingAs(usuarioCon('panel.ver_global'))
            ->get(route('panel.general'))
            ->assertOk()
            ->getContent();
    };

    // Lo que hay adentro de cada <details>, para saber qué quedó plegado.
    $this->plegado = function (string $html): string {
        preg_match_all('/<details\b[^>]*>(.*?)<\/details>/s', $html, $bloques);

        return implode("\n", $bloques[1]);
    };
});

/*
|--------------------------------------------------------------------------
| Un gráfico, y sus números plegados
|--------------------------------------------------------------------------
|
| Las tablas están por accesibilidad: el aria-label del gráfico dice dónde están los
| mismos números. Plegarlas no puede romper esa promesa, y el pliegue es un <details>
| porque un collapse de Bootstrap sin JavaScript queda cerrado para siempre.
|
*/

test('queda un solo grafico, el de facturado por dia', function () {
    $html = ($this->html)();

    expect($html)->toContain('data-grafico-dias')
        ->and($html)->not->toContain('data-grafico-metodos')
        ->and($html)->not->toContain('id="cobradoPorMetodo"');
});

test('la tabla que nombra el aria-label del grafico existe y esta plegada', function () {
    $html = ($this->html)();

    preg_match('/data-grafico-dias[^>]*aria-label="([^"]*)"/s', $html, $rotulo);

    expect($rotulo[1] ?? '')->toContain('«Facturado por día»')
        ->and($rotulo[1] ?? '')->toContain('«Ver los números»')
        ->and(($this->plegado)($html))->toContain('Facturado por día: cada día del período')
        ->and($html)->toMatch('/<summary\b[^>]*>\s*Ver los números\s*<\/summary>/');
});

test('cobrado por medio de pago es una tabla plegada', function () {
    $plegado = ($this->plegado)(($this->html)());

    expect($plegado)->toContain('Neto cobrado por medio de pago')
        ->and($plegado)->toContain('Transferencia')
        ->and($plegado)->toContain('$ 2.000,00');
});

test('lo plegado lo rinde el servidor y se abre sin javascript', function () {
    $html = ($this->html)();

    // Los números ya están en el HTML adentro del <details>: no los trae ningún script.
    expect(($this->plegado)($html))->toContain('$ 5.000,00')
        ->and(preg_match_all('/<details\b[^>]*\bdata-numeros\b/', $html))->toBe(2);
});