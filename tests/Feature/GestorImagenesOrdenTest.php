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

// Desde el tramo D la edición de producto es un shell: las imágenes llegan por la API, en su orden, y
// el gestor las numera con el mismo texto. La numeración sin JS es del componente: se afirma ahí.
it('cada imagen guardada lleva su número, y la primera dice que es la principal', function () {
    $imagenes = ['productos/a.jpg', 'productos/b.jpg', 'productos/c.jpg'];

    $this->withViewErrors([])
        ->blade('<x-gestor-imagenes :actuales="$imagenes" :max="5" en-tienda />', ['imagenes' => $imagenes])
        ->assertSeeInOrder([
            'data-posicion="1">1 · Principal',
            'data-posicion="2">2',
            'data-posicion="3">3',
        ], false);
});

it('la ficha de gestion trae las imagenes guardadas en su orden', function () {
    $producto = Producto::factory()->create(['imagenes' => ['productos/a.jpg', 'productos/b.jpg', 'productos/c.jpg']]);

    $this->getJson("/api/v1/productos/{$producto->id}?forma=gestion", bearer(admin()))
        ->assertOk()
        ->assertJsonPath('datos.imagenes.*.ruta', ['productos/a.jpg', 'productos/b.jpg', 'productos/c.jpg']);
});

it('el logo de una marca no habla de la tienda ni numera', function () {
    $this->actingAs(admin())->get(route('marcas.create'))
        ->assertOk()
        ->assertDontSee('En la tienda se muestran')
        ->assertDontSee('data-posicion', false);
});
