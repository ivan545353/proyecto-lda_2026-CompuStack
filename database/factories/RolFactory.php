<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Rol> */
class RolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre'      => fake()->unique()->jobTitle(),
            'descripcion' => fake()->sentence(6),
            'ambito'      => 'gestion',
            'es_sistema'  => false,
        ];
    }

    public function deTienda(): static
    {
        return $this->state(fn () => ['ambito' => 'tienda']);
    }
}
