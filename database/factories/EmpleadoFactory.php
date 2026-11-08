<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Empleado> */
class EmpleadoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'       => User::factory(),
            'legajo'        => fake()->unique()->bothify('EMP-####'),
            'dni'           => fake()->unique()->numerify('########'),
            'telefono'      => fake()->numerify('297-4######'),
            'fecha_ingreso' => fake()->dateTimeBetween('-5 years')->format('Y-m-d'),
            'fecha_baja'    => null,
        ];
    }
}
