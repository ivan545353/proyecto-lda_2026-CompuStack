<?php

use App\Impresion\ImpresionFirmada;
use App\Models\Marca;
use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/*
| La impresión de un listado por enlace firmado (D-22, tramo D de la Etapa 3). El endpoint de cada
| módulo pide el enlace con la credencial; acá se prueba el mecanismo común, con marcas.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->firmada = app(ImpresionFirmada::class);
    $this->usuario = usuarioCon('marca.ver');

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('el enlace vence a los minutos de config y se abre sin credencial: el PDF', function () {
    $enlace = $this->firmada->enlace('marcas', [], [], $this->usuario);

    expect($enlace['vence'])->toBe(now()->addMinutes(config('impresion.enlace_minutos'))->utc()->format('Y-m-d\TH:i:s\Z'));

    $respuesta = $this->get($enlace['url'])->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('marcas-');
});

test('la firma lleva los filtros y las opciones, también una lista de columnas', function () {
    $logitech = Marca::factory()->create(['nombre' => 'Logitech']);
    Producto::factory()->count(2)->create(['marca_id' => $logitech->id]);
    Marca::factory()->create(['nombre' => 'Genius']);
    Marca::factory()->create(['activo' => false]);

    $entrada = ['estado' => 'activas', 'formato' => 'csv', 'columnas' => ['nombre', 'productos']];
    $enlace  = $this->firmada->enlace('marcas', ['estado' => 'activas', 'q' => null, 'page' => 2], $entrada, $this->usuario);

    expect($enlace['url'])->not->toContain('page=');

    $filas = ($this->filasDe)($this->get($enlace['url'])->assertOk()->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Estado: Activas')
        ->and($filas[4])->toBe(['Marca', 'Productos'])
        ->and($filas[5])->toBe(['Logitech', '2'])
        ->and($filas[6])->toBe(['Genius', '0'])
        ->and(end($filas)[0])->toBe('Total: 2 marcas');
});

test('una opción mal pedida no firma nada: 422 al pedir el enlace', function () {
    expect(fn () => $this->firmada->enlace('marcas', [], ['columnas' => ['inventada']], $this->usuario))
        ->toThrow(ValidationException::class);
});

test('un enlace tocado o vencido es 403 en JSON', function () {
    $url = $this->firmada->enlace('marcas', ['estado' => 'activas'], [], $this->usuario)['url'];

    $this->get(str_replace('estado=activas', 'estado=inactivas', $url))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    $this->get(str_replace('pedido_por='.$this->usuario->id, 'pedido_por=1', $url))->assertForbidden();

    $this->travel(config('impresion.enlace_minutos') + 1)->minutes();
    $this->get($url)->assertForbidden();
});

test('si la cuenta perdió el permiso o se desactivó, el enlace vigente ya no sirve', function (string $caso) {
    $url = $this->firmada->enlace('marcas', [], [], $this->usuario)['url'];

    match ($caso) {
        'sin permiso' => $this->usuario->rol->permisos()->detach(),
        'desactivada' => $this->usuario->forceFill(['activo' => false])->save(),
    };
    $this->usuario->refresh();

    $this->get($url)->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
})->with(['sin permiso', 'desactivada']);

test('un listado que no se imprime por enlace no tiene ruta', function () {
    $this->get('/api/v1/impresiones/inventado')->assertNotFound();
});
