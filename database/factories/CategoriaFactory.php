<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\Categoria> */
class CategoriaFactory extends Factory
{
    public function definition(): array
    {
        $nombre = fake()->unique()->words(2, true);

        return [
            'parent_id'           => null,
            'nombre'              => Str::ucfirst($nombre),
            'slug'                => Str::slug($nombre),
            'orden'               => fake()->numberBetween(0, 20),
            'peso_default_gramos' => fake()->numberBetween(100, 5000),
            'activo'              => true,
        ];
    }
}
