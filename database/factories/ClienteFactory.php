<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Cliente> */
class ClienteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'       => null,          // cliente de mostrador, sin cuenta
            'razon_social'  => fake('es_AR')->name(),
            'tipo_doc'      => 'dni',
            'nro_doc'       => fake()->unique()->numerify('########'),
            'condicion_iva' => 'consumidor_final',
            'email'         => fake()->unique()->safeEmail(),
            'telefono'      => fake()->numerify('297-5######'),
        ];
    }

    /** Empresa con CUIT: lleva Factura A. */
    public function responsableInscripto(): static
    {
        return $this->state(fn () => [
            'razon_social'  => fake()->company().' S.R.L.',
            'tipo_doc'      => 'cuit',
            'nro_doc'       => fake()->unique()->numerify('30########'),
            'condicion_iva' => 'responsable_inscripto',
        ]);
    }
}
