<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\Marca> */
class MarcaFactory extends Factory
{
    public function definition(): array
    {
        $nombre = fake()->unique()->company();

        return [
            'nombre' => $nombre,
            'slug'   => Str::slug($nombre),
            'logo'   => null,
            'activo' => true,
        ];
    }
}
