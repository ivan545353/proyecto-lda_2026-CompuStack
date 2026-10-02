<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Proveedor> */
class ProveedorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'razon_social' => fake()->company().' S.A.',

            // Once dígitos sin separadores, igual que clientes.nro_doc: el
            // mismo dato se guarda igual en todo el sistema, el UNIQUE de la
            // columna sirve de verdad, y el buscador puede comparar contra un
            // CUIT pegado de una factura.
            'cuit'         => fake()->unique()->numerify('30#########'),

            'email'        => fake()->companyEmail(),
            'telefono'     => fake()->numerify('297-4######'),
            'contacto'     => fake('es_AR')->name(),

            // Canal fijo, no aleatorio. Antes era randomElement(), y un canal
            // sorteado vuelve no deterministas los tests del filtro por canal
            // y del envío de la orden: la misma corrida pasa o falla según el
            // valor que salga. La variación se pide con un estado explícito.
            'canal_pedido' => 'manual',
            'portal_url'   => null,

            'plazo_entrega_dias' => fake()->numberBetween(2, 21),
            'activo'             => true,
        ];
    }

    public function porEmail(): static
    {
        return $this->state(fn () => [
            'canal_pedido' => 'email',
            'portal_url'   => null,
        ]);
    }

    /** El portal_url acompaña al canal: sin URL, ese canal no se puede operar. */
    public function porPortal(string $url = 'https://pedidos.proveedor.test'): static
    {
        return $this->state(fn () => [
            'canal_pedido' => 'portal_externo',
            'portal_url'   => $url,
        ]);
    }

    /** Proveedor dado de baja. La baja es lógica: nunca se borra si tiene productos. */
    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}