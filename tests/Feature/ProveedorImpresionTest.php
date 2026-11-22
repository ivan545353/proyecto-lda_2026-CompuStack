<?php

use App\Impresion\ListadoProveedores;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ImpresionService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// La impresión se pide a la API y se abre por enlace firmado (D-22, tramo E de la Etapa 3).
beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->filasDe = fn (string $csv): array => array_map(
        fn (string $linea) => str_getcsv($linea, ';', '"', ''),
        explode("\r\n", rtrim(substr($csv, 3), "\r\n")),
    );
});

test('imprimir los proveedores pide proveedor.ver y ningun otro permiso del modulo lo habilita', function () {
    $otros = array_values(array_diff(permisosDelModulo('proveedor'), ['proveedor.ver']));

    $this->getJson('/api/v1/proveedores/impresion', bearer(usuarioCon(...$otros)))
        ->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
    app('auth')->forgetGuards();

    $url = $this->getJson('/api/v1/proveedores/impresion', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()->json('datos.url');

    $respuesta = $this->get($url)->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain('proveedores-');
});

test('la planilla de proveedores trae lo que muestra la pantalla, con lo que filtra', function () {
    Proveedor::factory()->create([
        'razon_social' => 'Distribuidora Norte S.A.', 'cuit' => '30712345678', 'contacto' => 'Laura Gómez',
        'canal_pedido' => 'email', 'email' => 'pedidos@norte.com.ar', 'plazo_entrega_dias' => 0,
    ]);
    Proveedor::factory()->create(['activo' => false]);

    $consulta = http_build_query([
        'formato'  => 'csv',
        'estado'   => 'activos',
        'columnas' => ['proveedor', 'cuit', 'contacto', 'canal', 'pedidos_a', 'plazo', 'estado'],
    ]);

    $url = $this->getJson("/api/v1/proveedores/impresion?{$consulta}", bearer(usuarioCon('proveedor.ver')))
        ->assertOk()->json('datos.url');

    $filas = ($this->filasDe)($this->get($url)->assertOk()->streamedContent());

    expect($filas[2][0])->toBe('Alcance: Estado: Activos')
        ->and($filas[4])->toBe(['Proveedor', 'CUIT', 'Contacto', 'Canal de pedido', 'Se le pide en', 'Entrega (días)', 'Estado'])
        ->and($filas[5])->toBe([
            'Distribuidora Norte S.A.', '30-71234567-8', 'Laura Gómez', 'Correo electrónico', 'pedidos@norte.com.ar', '', 'Activo',
        ])
        ->and(end($filas)[0])->toBe('Total: 1 proveedor');
});

test('el telefono del proveedor no se imprime, ni pidiendolo', function () {
    $proveedor = Proveedor::factory()->create();

    $encabezado = bearer(usuarioCon('proveedor.ver'));

    $url = $this->getJson('/api/v1/proveedores/impresion?formato=csv', $encabezado)->assertOk()->json('datos.url');
    $csv = $this->get($url)->streamedContent();

    expect($csv)->not->toContain($proveedor->telefono)
        ->not->toContain($proveedor->email);

    // La web volvía al listado con el aviso; la API no da enlace y lo dice en el 422.
    $rechazo = $this->getJson('/api/v1/proveedores/impresion?columnas[]=proveedor&columnas[]=telefono', $encabezado)
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');

    expect($rechazo->getContent())->toContain('columna que este listado no tiene');
});

test('el enlace de imprimir de proveedores lleva los filtros puestos y no la pagina', function () {
    $url = $this->getJson('/api/v1/proveedores/impresion?canal=manual&pagina=2', bearer(usuarioCon('proveedor.ver')))
        ->assertOk()->json('datos.url');

    expect($url)->toContain('canal=manual')->not->toContain('pagina=');
});

test('un canal inexistente de proveedores es 422 y no da enlace', function () {
    $this->getJson('/api/v1/proveedores/impresion?canal=telepatia', bearer(usuarioCon('proveedor.ver')))
        ->assertUnprocessable()
        ->assertJsonPath('codigo', 'validacion')
        ->assertJsonMissingPath('datos');
});

test('los conteos de productos y ordenes salen de la base, sin una consulta por fila', function () {
    $servicio = app(ImpresionService::class);
    $listado  = new ListadoProveedores();
    $usuario  = usuarioCon('proveedor.ver');
    $opciones = $servicio->opciones($listado, ['formato' => 'csv', 'columnas' => ['proveedor', 'productos', 'ordenes']], $usuario);

    $csv = function () use ($servicio, $listado, $usuario, $opciones): string {
        $salida = fopen('php://memory', 'w+');
        $servicio->escribirCsv($listado, Proveedor::query(), $opciones, $usuario, [], $salida);
        rewind($salida);

        return stream_get_contents($salida);
    };

    $alfa = Proveedor::factory()->create(['razon_social' => 'Alfa']);
    Producto::factory()->count(2)->conProveedor($alfa)->create();
    OrdenCompra::factory()->create(['proveedor_id' => $alfa->id]);

    $filas = ($this->filasDe)($csv());
    $pocas = contarConsultas($csv);

    Proveedor::factory()->count(6)->create();
    $muchas = contarConsultas($csv);

    expect($filas[5])->toBe(['Alfa', '2', '1'])
        ->and($muchas)->toBe($pocas);
});
