<?php

use App\Http\Api\Paginado;
use App\Support\Api\Imagenes;
use App\Support\Api\TextosTienda;
use App\Models\Venta;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/** Un recurso mínimo: el contrato no depende de ningún modelo. */
class TiendaRecursoDePrueba extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->resource['id']];
    }
}

function tiendaPaginador(array $filas, int $total, int $porPagina, int $pagina): LengthAwarePaginator
{
    return new LengthAwarePaginator($filas, $total, $porPagina, $pagina);
}

// --- Imagenes -------------------------------------------------------------

test('la imagen guardada sale con el host del pedido y no con APP_URL', function () {
    config(['app.url' => 'http://localhost:8000']);

    $this->get('http://10.0.2.2:8000/up');   // fija la raíz del pedido

    $url = Imagenes::url('productos/ssd.jpg');

    expect($url)->toEndWith('/storage/productos/ssd.jpg');
});

test('sin foto devuelve la imagen por omisión, nunca null', function (?string $ruta) {
    expect(Imagenes::url($ruta))->toEndWith('/img/producto-sin-imagen.svg');
})->with([null, '', '   ', '/']);

test('la imagen por omisión existe en public', function () {
    expect(file_exists(public_path(Imagenes::SIN_IMAGEN)))->toBeTrue();
});

test('una ruta con barra inicial no duplica la barra', function () {
    expect(Imagenes::url('/productos/a.jpg'))->not->toContain('storage//');
});

// --- TextosTienda ---------------------------------------------------------

test('hay texto para cada estado de pedido y habla con el cliente', function () {
    expect(array_keys(TextosTienda::PEDIDO))->toEqual(array_keys(Venta::ESTADOS))
        ->and(TextosTienda::pedido('pendiente_pago'))->toBe('Esperando el pago');
});

test('un valor desconocido se devuelve tal cual y no rompe', function () {
    expect(TextosTienda::pedido('inventado'))->toBe('inventado')
        ->and(TextosTienda::pago('inventado'))->toBe('inventado')
        ->and(TextosTienda::disponibilidad('inventado'))->toBe('inventado')
        ->and(TextosTienda::pasarela('inventado'))->toBe('inventado');
});

test('el simulador se dice como lo que es', function () {
    expect(TextosTienda::pasarela('simulador'))->toContain('no es un cobro real');
});

test('la disponibilidad tiene los tres niveles', function () {
    expect(array_keys(TextosTienda::DISPONIBILIDAD))
        ->toEqual(['disponible', 'ultimas_unidades', 'sin_stock']);
});

// --- Paginado -------------------------------------------------------------

test('un listado trae la envoltura completa', function () {
    $respuesta = Paginado::respuesta(
        tiendaPaginador([['id' => 1], ['id' => 2]], total: 134, porPagina: 20, pagina: 1),
        TiendaRecursoDePrueba::class,
        ['categoria_id' => 3, 'q' => 'ssd', 'orden' => 'nombre', 'seccion' => null],
    )->getData(true);

    expect($respuesta)->toHaveKeys(['datos', 'pagina', 'por_pagina', 'ultima_pagina', 'total', 'criterios'])
        ->and($respuesta['datos'])->toBe([['id' => 1], ['id' => 2]])
        ->and($respuesta['pagina'])->toBe(1)
        ->and($respuesta['por_pagina'])->toBe(20)
        ->and($respuesta['ultima_pagina'])->toBe(7)
        ->and($respuesta['total'])->toBe(134)
        ->and($respuesta['criterios'])->toHaveKey('seccion', null);
});

test('una página después de la última devuelve datos vacíos con los metadatos', function () {
    $respuesta = Paginado::respuesta(
        tiendaPaginador([], total: 134, porPagina: 20, pagina: 99),
        TiendaRecursoDePrueba::class,
        [],
    );

    expect($respuesta->getStatusCode())->toBe(200)
        ->and($respuesta->getData(true)['datos'])->toBe([])
        ->and($respuesta->getData(true)['total'])->toBe(134)
        ->and($respuesta->getData(true)['pagina'])->toBe(99);
});

test('sin criterios, criterios es un objeto y no una lista', function () {
    $json = Paginado::respuesta(tiendaPaginador([], 0, 20, 1), TiendaRecursoDePrueba::class, [])->getContent();

    expect($json)->toContain('"criterios":{}');
});

test('un listado sin resultados tiene una sola página', function () {
    $respuesta = Paginado::respuesta(tiendaPaginador([], 0, 20, 1), TiendaRecursoDePrueba::class, [])->getData(true);

    expect($respuesta['ultima_pagina'])->toBe(1)->and($respuesta['total'])->toBe(0);
});
