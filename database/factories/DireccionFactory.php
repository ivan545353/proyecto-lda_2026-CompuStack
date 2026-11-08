<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Direccion> */
class DireccionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cliente_id'        => Cliente::factory(),
            'calle'             => fake('es_AR')->streetName(),
            'numero'            => (string) fake()->numberBetween(1, 4500),
            'piso_depto'        => null,
            'codigo_postal'     => fake()->numerify('90##'),
            'localidad'         => 'Caleta Olivia',
            'provincia'         => 'Santa Cruz',
            'es_predeterminada' => false,
        ];
    }

    public function predeterminada(): static
    {
        return $this->state(fn () => ['es_predeterminada' => true]);
    }
}