<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Proveedor> */
class ProveedorFactory extends Factory
{
    public function definition(): array
    {
        $canal = fake()->randomElement(['email', 'portal_externo', 'manual']);

        return [
            'razon_social'       => fake()->company().' S.A.',
            'cuit'               => fake()->unique()->numerify('30-########-#'),
            'email'              => fake()->companyEmail(),
            'telefono'           => fake()->numerify('297-4######'),
            'contacto'           => fake('es_AR')->name(),
            'canal_pedido'       => $canal,
            'portal_url'         => $canal === 'portal_externo' ? fake()->url() : null,
            'plazo_entrega_dias' => fake()->numberBetween(2, 21),
            'activo'             => true,
        ];
    }
}
