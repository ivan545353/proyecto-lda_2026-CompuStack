<?php

use App\Models\Proveedor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de proveedores
|--------------------------------------------------------------------------
|
| Primer nivel de los dos que lleva cada módulo: acá se prueba que los scopes
| filtran. Que el parámetro de la URL llegue al scope se prueba en
| ProveedorModuloTest, y hacen falta los dos: A-24 sobrevivió once commits
| porque el scope funcionaba y el cableado no.
|
*/

test('buscar devuelve solo los proveedores cuya razon social contiene el texto', function () {
    Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral S.A.']);
    Proveedor::factory()->create(['razon_social' => 'Austral Insumos S.R.L.']);
    Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia']);

    $resultado = Proveedor::buscar('austral')->pluck('razon_social');

    expect($resultado)->toHaveCount(2)
        ->and($resultado)->toContain('Distribuidora Austral S.A.', 'Austral Insumos S.R.L.');
});

test('buscar sin texto no filtra nada', function () {
    Proveedor::factory()->count(3)->create();

    expect(Proveedor::buscar(null)->count())->toBe(3)
        ->and(Proveedor::buscar('')->count())->toBe(3)
        ->and(Proveedor::buscar('   ')->count())->toBe(3);
});

test('buscar trata el comodin de LIKE como texto literal', function () {
    Proveedor::factory()->create(['razon_social' => 'Insumos 100% Originales']);
    Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia']);

    // Sin escapar, '%' traería las dos: el buscador devolvería la tabla entera
    // a quien busque un porcentaje.
    expect(Proveedor::buscar('100%')->count())->toBe(1);
});

test('buscar encuentra por CUIT aunque venga pegado con guiones', function () {
    Proveedor::factory()->create(['razon_social' => 'Distribuidora Austral S.A.', 'cuit' => '30712345678']);
    Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia',            'cuit' => '20334455669']);

    // El CUIT se lee de una factura escrito con guiones y se guarda sin ellos.
    expect(Proveedor::buscar('30-71234567-8')->count())->toBe(1)
        ->and(Proveedor::buscar('30712345678')->count())->toBe(1);
});

test('un texto con pocos digitos no se compara contra el CUIT', function () {
    Proveedor::factory()->create([
        'razon_social' => 'Distribuidora Austral S.A.',
        'cuit'         => '30712345678',
        'contacto'     => 'Mesa de pedidos',
        'email'        => 'ventas@austral.test',
    ]);

    // El 2 está dentro del CUIT, pero "sur 2" no es la búsqueda de un
    // documento: sin el piso de 6 dígitos, cualquier número traería medio
    // padrón de proveedores.
    expect(Proveedor::buscar('sur 2')->count())->toBe(0);
});

test('buscar tambien mira el contacto y el correo', function () {
    Proveedor::factory()->create(['razon_social' => 'Tecno Patagonia', 'contacto' => 'Mariana Ledesma', 'email' => 'compras@tecno.test']);
    Proveedor::factory()->create(['razon_social' => 'Insumos del Sur', 'contacto' => 'Mesa de pedidos', 'email' => 'ventas@sur.test']);

    expect(Proveedor::buscar('ledesma')->count())->toBe(1)
        ->and(Proveedor::buscar('compras@')->count())->toBe(1);
});

test('conEstado separa activos de inactivos', function () {
    Proveedor::factory()->count(2)->create();
    Proveedor::factory()->inactivo()->create();

    expect(Proveedor::conEstado('activos')->count())->toBe(2)
        ->and(Proveedor::conEstado('inactivos')->count())->toBe(1);
});

test('conEstado sin valor o con un valor desconocido no filtra', function () {
    Proveedor::factory()->count(2)->create();
    Proveedor::factory()->inactivo()->create();

    expect(Proveedor::conEstado(null)->count())->toBe(3)
        ->and(Proveedor::conEstado('')->count())->toBe(3)
        ->and(Proveedor::conEstado('cualquier-cosa')->count())->toBe(3);
});

test('conCanal filtra por el canal de pedido', function () {
    Proveedor::factory()->porEmail()->create();
    Proveedor::factory()->porPortal()->create();
    Proveedor::factory()->count(2)->create();   // 'manual', el valor por omisión

    expect(Proveedor::conCanal('email')->count())->toBe(1)
        ->and(Proveedor::conCanal('portal_externo')->count())->toBe(1)
        ->and(Proveedor::conCanal('manual')->count())->toBe(2);
});

test('conCanal sin valor o con un canal inexistente no filtra', function () {
    Proveedor::factory()->count(3)->create();

    expect(Proveedor::conCanal(null)->count())->toBe(3)
        ->and(Proveedor::conCanal('')->count())->toBe(3)
        ->and(Proveedor::conCanal('telepatia')->count())->toBe(3);
});

test('el OR de buscar no se lleva puesto el filtro de estado', function () {
    Proveedor::factory()->create([
        'razon_social' => 'Distribuidora Austral S.A.',
        'contacto'     => 'Mesa de pedidos',
        'email'        => 'ventas@austral.test',
    ]);
    Proveedor::factory()->inactivo()->create([
        'razon_social' => 'Austral Insumos S.R.L.',
        'contacto'     => 'Mesa de pedidos',
        'email'        => 'ventas@insumos.test',
    ]);

    // Si el OR no estuviera agrupado en un closure, la condición quedaría
    // `razon_social LIKE … OR (email LIKE … AND activo = 1)` y el inactivo
    // volvería igual.
    $resultado = Proveedor::buscar('austral')->conEstado('activos')->pluck('razon_social');

    expect($resultado)->toHaveCount(1)
        ->and($resultado->first())->toBe('Distribuidora Austral S.A.');
});

test('los tres filtros se combinan entre si', function () {
    Proveedor::factory()->porEmail()->create(['razon_social' => 'Distribuidora Austral S.A.']);
    Proveedor::factory()->porPortal()->create(['razon_social' => 'Austral Insumos S.R.L.']);
    Proveedor::factory()->porEmail()->inactivo()->create(['razon_social' => 'Austral Vieja S.A.']);

    expect(Proveedor::buscar('austral')->conEstado('activos')->conCanal('email')->pluck('razon_social')->all())
        ->toBe(['Distribuidora Austral S.A.']);
});