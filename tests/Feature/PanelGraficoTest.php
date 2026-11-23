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

    // Lo que el panel general trae por la API, con las mismas dos ventas que creó `html`.
    $this->datos = function (): array {
        app('auth')->forgetGuards();

        return $this->getJson('/api/v1/panel/general', bearer(usuarioCon('panel.ver_global')))
            ->assertOk()
            ->json('datos');
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
| Desde el tramo H las filas de las dos tablas y la serie del gráfico llegan por la API
| (`por_dia`, `por_metodo`): el shell trae los <details> plegados y sus plantillas.
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
    $medios  = collect(($this->datos)()['por_metodo'])->keyBy('metodo');

    // La tabla plegada es del shell; sus filas, de la API.
    expect($plegado)->toContain('Neto cobrado por medio de pago')
        ->and($plegado)->toContain('data-filas="por_metodo"')
        ->and($medios['transferencia']['metodo_texto'])->toBe('Transferencia')
        ->and($medios['transferencia']['neto'])->toBe('2000.00');
});

test('lo plegado lo rinde el servidor y se abre sin javascript', function () {
    $html  = ($this->html)();
    $datos = ($this->datos)();

    // Tramo H: los dos <details> siguen siendo del servidor y se abren sin Bootstrap, pero sus
    // números ya no vienen en el HTML (regla 37): son las filas de la API que el JS escribe.
    expect(preg_match_all('/<details\b[^>]*\bdata-numeros\b/', $html))->toBe(2)
        ->and(($this->plegado)($html))->toContain('data-filas="por_dia"')
        ->and(array_sum(array_map('floatval', array_column($datos['por_dia'], 'facturado'))))->toBe(5000.0);
});
