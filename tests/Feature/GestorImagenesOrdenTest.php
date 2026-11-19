<?php

use App\Models\Producto;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolPermisoSeeder::class));

// El orden de las imágenes es el de la galería de la tienda, y el formulario lo dice (HL-87).

it('el formulario del producto dice que en la tienda se ven de izquierda a derecha', function () {
    $this->actingAs(admin())->get(route('productos.create'))
        ->assertOk()
        ->assertSee('En la tienda se muestran en este orden')
        ->assertSee('de izquierda a derecha');
});

it('cada imagen guardada lleva su número, y la primera dice que es la principal', function () {
    $producto = Producto::factory()->create(['imagenes' => ['productos/a.jpg', 'productos/b.jpg', 'productos/c.jpg']]);

    $this->actingAs(admin())->get(route('productos.edit', $producto))
        ->assertOk()
        ->assertSeeInOrder([
            'data-posicion="1">1 · Principal',
            'data-posicion="2">2',
            'data-posicion="3">3',
        ], false);
});

it('el logo de una marca no habla de la tienda ni numera', function () {
    $this->actingAs(admin())->get(route('marcas.create'))
        ->assertOk()
        ->assertDontSee('En la tienda se muestran')
        ->assertDontSee('data-posicion', false);
});
