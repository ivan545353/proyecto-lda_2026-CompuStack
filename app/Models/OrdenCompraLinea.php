<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una orden de compra: un producto, una cantidad y su costo acordado.
 *
 * `cantidad_recibida < cantidad_pedida` es la recepción parcial, y por eso no hay
 * tabla de recepciones de mercadería: el saldo vive acá y el historial de cada
 * entrega está en `movimientos_stock`, con su fecha y su usuario. Una tabla de
 * recepciones sería una copia del kardex con otro nombre.
 *
 * La base tiene `UNIQUE (orden_compra_id, producto_id)`: **una línea por producto
 * por orden**. El formulario tiene que fusionar o rechazar un producto repetido,
 * o el usuario recibe un error de integridad de MariaDB en la cara.
 *
 * `costo_unitario` queda congelado en la línea. Es la misma razón por la que la
 * línea de venta congela el precio: una orden de hace seis meses tiene que seguir
 * diciendo a cuánto se compró, aunque el costo del producto haya cambiado tres
 * veces desde entonces.
 * `codigo_proveedor` se congela por el mismo motivo y en el mismo momento. Además
 * del precio, el pedido imprime con qué código el proveedor identifica el
 * producto; leerlo de la pivote haría que el PDF regenerado de una orden vieja
 * cambie si el proveedor renumeró su catálogo, o quede vacío si el vínculo se
 * quitó. Null significa «no consta», no «se perdió».
 */
class OrdenCompraLinea extends Model
{
    use HasFactory;

    protected $table = 'orden_compra_lineas';

    protected $fillable = ['orden_compra_id', 'producto_id', 'cantidad_pedida', 'costo_unitario'];

    /**
     * `cantidad_recibida` la mueve sólo CompraService al registrar una recepción,
     * y siempre junto con el movimiento de stock correspondiente. Fuera de
     * $fillable para que no se pueda declarar recibido algo que nunca entró al
     * depósito.
     *
     * `codigo_proveedor` también queda fuera de $fillable, por otro motivo: no es
     * un dato que nadie cargue, es una copia que el servidor hace del vínculo al
     * escribir la línea. Si fuera asignable, una petición armada a mano podría
     * hacer que el pedido imprima un código que el proveedor nunca usó.
     */
    protected $attributes = ['cantidad_recibida' => 0];

    protected function casts(): array
    {
        return [
            'cantidad_pedida'   => 'integer',
            'cantidad_recibida' => 'integer',
            'costo_unitario'    => 'decimal:2',
        ];
    }

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    /** Lo que todavía falta recibir de esta línea. */
    public function cantidadPendiente(): int
    {
        return max(0, $this->cantidad_pedida - $this->cantidad_recibida);
    }

    public function estaCompleta(): bool
    {
        return $this->cantidad_recibida >= $this->cantidad_pedida;
    }

    /**
     * Lo que se comprometió por esta línea.
     *
     * Se calcula con la cantidad PEDIDA: `total_estimado` es el compromiso que se
     * autoriza al aprobar, no lo que finalmente entró. Qué entró y a qué costo lo
     * responde el kardex, y el promedio ponderado ya está en el producto.
     */
    public function subtotalPedido(): float
    {
        return round($this->cantidad_pedida * (float) $this->costo_unitario, 2);
    }
}