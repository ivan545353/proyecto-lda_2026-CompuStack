<?php

namespace Database\Factories;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraLinea;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\OrdenCompraLinea> */
class OrdenCompraLineaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'orden_compra_id' => OrdenCompra::factory(),
            'producto_id'     => Producto::factory(),
            'cantidad_pedida' => fake()->numberBetween(1, 20),
            'costo_unitario'  => fake()->randomFloat(2, 1000, 50000),
        ];
    }

    /** Con parte de lo pedido ya recibido. Fuera de $fillable: asignación directa. */
    public function conRecibido(int $cantidad): static
    {
        return $this->afterCreating(function (OrdenCompraLinea $linea) use ($cantidad) {
            $linea->cantidad_recibida = $cantidad;
            $linea->save();
        });
    }
}