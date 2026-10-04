<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Support\Facades\DB;
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

    public function conStock(int $cantidad, int $reservado = 0): static
    {
        return $this->afterCreating(function ($producto) use ($cantidad, $reservado) {
            $producto->stock           = $cantidad;
            $producto->stock_reservado = $reservado;
            $producto->save();
        });
    }

    /** Producto dado de baja. */
    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    public function sinStock(): static
    {
        return $this->conStock(0);
    }

    /**
     * Stock y costo promedio juntos: las dos columnas que sólo mueve el
     * StockService, puestas por asignación directa porque están fuera de
     * $fillable.
     */
    public function conStockYCosto(int $cantidad, float $costo, int $reservado = 0): static
    {
        return $this->afterCreating(function ($producto) use ($cantidad, $costo, $reservado) {
            $producto->stock           = $cantidad;
            $producto->stock_reservado = $reservado;
            $producto->costo_promedio  = $costo;
            $producto->save();
        });
    }

        /**
     * Vincula el producto a un proveedor con su costo y su código.
     *
     * Se puede encadenar con proveedores distintos. El primero queda preferido, y
     * pasar `preferido: true` en uno posterior desmarca a los demás: la factory no
     * implementa la invariante —eso es del servicio— pero tampoco la rompe, porque
     * datos de prueba inválidos hacen que los tests prueben otra cosa.
     */
    public function conProveedor(
        Proveedor $proveedor,
        ?float $costo = null,
        ?string $codigo = null,
        bool $preferido = false,
    ): static {
        return $this->afterCreating(function (Producto $producto) use ($proveedor, $costo, $codigo, $preferido) {
            $esElPrimero = ! $producto->proveedores()->exists();

            $producto->proveedores()->attach($proveedor->id, [
                'costo_ultimo'     => $costo,
                'codigo_proveedor' => $codigo,
                'es_preferido'     => $preferido || $esElPrimero,
            ]);

            if ($preferido) {
                DB::table('producto_proveedor')
                    ->where('producto_id', $producto->id)
                    ->where('proveedor_id', '!=', $proveedor->id)
                    ->update(['es_preferido' => false]);
            }
        });
    }
}
