<?php

namespace Database\Factories;

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<\App\Models\OrdenCompra> */
class OrdenCompraFactory extends Factory
{
    public function definition(): array
    {
        return [
            'proveedor_id'    => Proveedor::factory(),
            'usuario_creo_id' => User::factory(),
            'observaciones'   => null,
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Estados
    |----------------------------------------------------------------------
    |
    | `estado`, las fechas y el total están fuera de $fillable, así que se
    | escriben por asignación directa después de crear la fila. Es el mismo
    | recurso que ProductoFactory::conStock() usa con el stock, y mantiene la
    | regla de que esas columnas sólo se mueven con intención.
    |
    | Los estados se encadenan —enviada() parte de aprobada()— para que la fila de
    | prueba tenga las fechas y los usuarios que tendría en la realidad, en lugar
    | de un estado avanzado sin su historia.
    |
    */

    public function aprobada(): static
    {
        return $this->afterCreating(function (OrdenCompra $orden) {
            $orden->estado            = 'aprobada';
            $orden->usuario_aprobo_id = $orden->usuario_creo_id;
            $orden->fecha_aprobacion  = now();
            $orden->save();
        });
    }

    public function enviada(): static
    {
        return $this->aprobada()->afterCreating(function (OrdenCompra $orden) {
            $orden->estado      = 'enviada';
            $orden->fecha_envio = now();
            $orden->save();
        });
    }

    public function recibidaParcial(): static
    {
        return $this->enviada()->afterCreating(function (OrdenCompra $orden) {
            $orden->estado = 'recibida_parcial';
            $orden->save();
        });
    }

    public function recibida(): static
    {
        return $this->enviada()->afterCreating(function (OrdenCompra $orden) {
            $orden->estado = 'recibida';
            $orden->save();
        });
    }

    public function cancelada(): static
    {
        return $this->afterCreating(function (OrdenCompra $orden) {
            $orden->estado = 'cancelada';
            $orden->save();
        });
    }

    /** Orden generada por la tarea de reposición: no la creó ninguna persona. */
    public function delSistema(): static
    {
        return $this->state(fn () => ['usuario_creo_id' => null]);
    }

    /**
     * Con una línea, para que la orden tenga contenido.
     *
     * Se puede encadenar con productos distintos; con el mismo producto dos veces
     * choca contra el UNIQUE (orden_compra_id, producto_id), que es justamente lo
     * que tiene que pasar.
     */
    public function conLinea(Producto $producto, int $cantidad, float $costo = 1000): static
    {
        return $this->afterCreating(function (OrdenCompra $orden) use ($producto, $cantidad, $costo) {
            $linea = $orden->lineas()->make([
                'producto_id'     => $producto->id,
                'cantidad_pedida' => $cantidad,
                'costo_unitario'  => $costo,
            ]);

            // `codigo_proveedor` está fuera de $fillable: asignación directa, igual
            // que en CompraService, copiándolo del vínculo con el proveedor.
            $linea->codigo_proveedor = DB::table('producto_proveedor')
                ->where('producto_id', $producto->id)
                ->where('proveedor_id', $orden->proveedor_id)
                ->value('codigo_proveedor');

            $linea->save();

            // El total es derivado. Se deja coherente para que la factory no
            // produzca una fila que el servicio nunca produciría.
            $orden->total_estimado = $orden->lineas()->get()
                ->sum(fn ($linea) => $linea->subtotalPedido());

            $orden->save();
        });
    }
}