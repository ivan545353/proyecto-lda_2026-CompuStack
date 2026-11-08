<?php

namespace Database\Factories;

use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Venta>
 *
 * **Ojo con los estados.** `estado`, los importes y las líneas están fuera de
 * `$fillable`, así que se escriben por asignación directa después de crear la fila,
 * igual que `OrdenCompraFactory` y `ProductoFactory::conStock()`.
 *
 * Y hay algo que esta factory **no** hace y que conviene tener claro antes de usarla:
 * `pagada()` escribe el estado y nada más. No descuenta stock, no deja movimiento en
 * el kardex y no crea ningún pago, porque todo eso es la segunda mitad de la fase y
 * lo produce `VentaService`. Sirve para probar listados, filtros, permisos y
 * transiciones —donde lo único que importa es en qué estado está la venta— y **no
 * sirve para probar nada sobre stock ni sobre plata**: una venta así es una fila que
 * el sistema nunca produciría. Lo que se prueba sobre stock o pagos se arma llamando
 * al servicio, no a la factory.
 *
 * No hay `VentaLineaFactory`: las líneas las crea `conLinea()`, que es lo que
 * permite dejarlas coherentes con el total de la venta. Una línea suelta, sin su
 * venta y sin tocar el total, sería otra fila imposible.
 */
class VentaFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Null es el caso más común de mostrador: consumidor final sin
            // identificar. Los tests que necesitan un cliente lo pasan.
            'cliente_id'    => null,
            'usuario_id'    => User::factory(),
            'observaciones' => null,
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Estados
    |----------------------------------------------------------------------
    |
    | `presupuesto` es el estado por omisión y no necesita método: lo pone el
    | DEFAULT de la columna, que está replicado en $attributes.
    |
    | Están sólo los tres que la Etapa 1 alcanza. Los de devolución los agrega la
    | segunda mitad cuando tenga quién los lea: un estado de factory sin ningún test
    | que lo use es código muerto con disfraz de infraestructura.
    |
    */

    public function pagada(): static
    {
        return $this->afterCreating(function (Venta $venta) {
            $venta->estado = 'pagada';
            $venta->save();
        });
    }

    /** Se encadena a pagada(): en el flujo real nadie entrega lo que no se cobró. */
    public function entregada(): static
    {
        return $this->pagada()->afterCreating(function (Venta $venta) {
            $venta->estado = 'entregada';
            $venta->save();
        });
    }

    public function cancelada(): static
    {
        return $this->afterCreating(function (Venta $venta) {
            $venta->estado = 'cancelada';
            $venta->save();
        });
    }

    /**
     * Con una línea, para que la venta tenga contenido y un total creíble.
     *
     * Se puede encadenar con productos distintos. El precio por omisión es el de
     * contado del producto, no un número inventado: así la fila de prueba se parece
     * a la que va a escribir el servicio, que lee el precio de la base y nunca del
     * cliente.
     *
     * El IVA se calcula igual que lo va a calcular `VentaService`: los dos precios
     * del producto son finales —IVA incluido—, así que el neto se obtiene dividiendo
     * y el IVA es la diferencia. Que la cuenta esté en dos lugares es el precio de
     * que la factory no produzca filas que el servicio nunca produciría; cuando el
     * servicio exista, el test de la factory y el del servicio tienen que dar el
     * mismo número, y si no dan, uno de los dos está mal.
     */
    public function conLinea(Producto $producto, int $cantidad, float|string|null $precio = null): static
    {
        return $this->afterCreating(function (Venta $venta) use ($producto, $cantidad, $precio) {
            $precio   = $precio ?? $producto->precio_contado;
            $alicuota = (float) $producto->alicuota_iva;

            $total = round($cantidad * (float) $precio, 2);
            $neto  = round($total / (1 + ($alicuota / 100)), 2);

            // Sólo producto_id y cantidad son asignables: todo lo que el documento
            // imprime lo escribe el servidor, acá y en el servicio.
            $linea = $venta->lineas()->make([
                'producto_id' => $producto->id,
                'cantidad'    => $cantidad,
            ]);

            $linea->descripcion     = $producto->nombre;
            $linea->precio_unitario = $precio;
            $linea->alicuota_iva    = $producto->alicuota_iva;
            $linea->costo_unitario  = $producto->costo_promedio;
            $linea->neto            = $neto;
            $linea->iva             = round($total - $neto, 2);
            $linea->total           = $total;

            $linea->save();

            // El total es derivado. La factory no implementa la fórmula completa
            // —eso es del servicio— pero tampoco deja la venta diciendo cero
            // mientras tiene líneas cargadas.
            $venta->subtotal = $venta->lineas()->sum('total');
            $venta->total    = round(
                (float) $venta->subtotal - (float) $venta->descuento + (float) $venta->costo_envio,
                2,
            );

            $venta->save();
        });
    }
}