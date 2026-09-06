<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Producto> */
class ProductoFactory extends Factory
{
    public function definition(): array
    {
        $lista = fake()->randomFloat(2, 5000, 900000);

        return [
            'categoria_id'        => Categoria::factory(),
            'marca_id'            => Marca::factory(),
            'proveedor_id'        => Proveedor::factory(),
            'codigo'              => fake()->unique()->bothify('??-#####'),
            'nombre'              => fake()->unique()->words(3, true),
            'descripcion'         => fake()->sentence(12),
            'imagenes'            => null,
            'precio_lista'        => $lista,
            // Invariante del negocio: contado nunca supera al de lista.
            'precio_contado'      => round($lista * 0.90, 2),
            'alicuota_iva'        => 21.00,
            'stock_minimo'        => fake()->numberBetween(2, 10),
            'cantidad_reposicion' => fake()->numberBetween(5, 30),
            'peso_gramos'         => fake()->numberBetween(50, 8000),
            'destacado'           => false,
            'activo'              => true,
        ];
    }

    /**
     * El stock no es asignable en masa (sólo lo mueve el StockService, Fase 5).
     * Para armar datos de prueba se fija directamente sobre el modelo.
     */
    public function conStock(int $cantidad): static
    {
        return $this->afterCreating(function ($producto) use ($cantidad) {
            $producto->stock = $cantidad;
            $producto->save();
        });
    }

    public function sinStock(): static
    {
        return $this->conStock(0);
    }
}
