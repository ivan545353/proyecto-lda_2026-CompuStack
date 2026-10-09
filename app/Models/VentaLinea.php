<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de venta: un producto, una cantidad, y el precio del momento.
 *
 * **Todo lo que el documento imprime está congelado acá.** `precio_unitario` y
 * `alicuota_iva` son lo único que hace que una venta de hace seis meses siga siendo
 * reproducible después de un aumento; eso el sistema original ya lo hacía bien y se
 * conserva. `costo_unitario` es nuevo y es para el margen: sin él, el panel lo
 * calcularía contra el costo de hoy y daría números falsos cada vez que cambia un
 * costo. `neto` e `iva` se persisten en lugar de calcularse al vuelo porque son los
 * importes que se declaran a AFIP: recalcularlos con una división por
 * `1 + alicuota` en cada consulta introduce diferencias de centavos contra lo ya
 * declarado.
 *
 * **`$fillable` tiene dos campos y eso es a propósito.** Son los únicos dos que el
 * formulario manda: qué producto y cuántos. El precio, la alícuota, el costo y los
 * tres importes los **lee y calcula el servidor** y los escribe por asignación
 * directa. Releer el precio de la base y no confiar en el que manda el cliente es la
 * defensa correcta contra la manipulación de precios —la nota positiva de M-14, que
 * hay que conservar—, y dejarlos fuera de `$fillable` la convierte en una segunda
 * barrera: una petición armada a mano con `lineas[0][precio_unitario]=1` no es un
 * campo que el servicio tenga que acordarse de ignorar, es un campo que el modelo no
 * puede escribir. Es el mismo recurso que `UsuarioService` usa con `rol_id` para
 * cerrar C-3.
 *
 * Como contrapartida, un `create()` o un `make()` con esos campos **los descarta en
 * silencio**: el servicio los asigna uno por uno después de instanciar la línea,
 * igual que `CompraService` con `codigo_proveedor`.
 *
 * `cantidad_devuelta` arranca en cero y la mueve sólo la devolución, que es la
 * segunda mitad de la fase. Es el contador que impide devolver más de lo comprado:
 * sin él, alguien devuelve dos unidades tres veces y se lleva seis. Vive en la línea
 * y no en una tabla aparte porque es un saldo, no un evento: el historial de cada
 * devolución va a estar en las notas de crédito.
 *
 * **No hay `UNIQUE (venta_id, producto_id)` en la base**, a diferencia de
 * `orden_compra_lineas`. El mismo producto dos veces en una venta no es un agujero
 * de plata —el precio lo pone el servidor, así que las dos líneas valdrían lo
 * mismo— pero es un documento con el mismo renglón repetido. Se rechaza en el Form
 * Request y en el servicio, con el mismo criterio y el mismo mensaje que compras, sin
 * tocar el esquema.
 *
 * Relaciones:
 *   venta()     BelongsTo
 *   producto()  BelongsTo
 *
 * Métodos:
 *   cantidadPendienteDeDevolver()  lo que todavía no volvió
 *   estaDevueltaPorCompleto()      si ya volvió todo

 */
class VentaLinea extends Model
{
    protected $table = 'venta_lineas';

    /** Lo único que manda el formulario. Todo lo demás lo escribe el servidor. */
    protected $fillable = ['producto_id', 'cantidad'];

    protected $attributes = ['cantidad_devuelta' => 0];

    protected function casts(): array
    {
        return [
            'cantidad'          => 'integer',
            'cantidad_devuelta' => 'integer',
            'precio_unitario'   => 'decimal:2',
            'alicuota_iva'      => 'decimal:2',
            'costo_unitario'    => 'decimal:2',
            'neto'              => 'decimal:2',
            'iva'               => 'decimal:2',
            'total'             => 'decimal:2',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

        /**
     * Lo que todavía no se devolvió de esta línea.
     *
     * Espejo de `OrdenCompraLinea::cantidadPendiente()`, y con el mismo `max(0, …)`
     * por el mismo motivo: si por un error quedara más devuelto que vendido, la
     * resta daría negativo y una cantidad pendiente negativa no significa nada. Que
     * no pueda pasar lo garantiza la validación de la devolución
     * —`cantidad_devuelta + a_devolver <= cantidad`—; esto es la red.
     */
    public function cantidadPendienteDeDevolver(): int
    {
        return max(0, $this->cantidad - $this->cantidad_devuelta);
    }

    public function estaDevueltaPorCompleto(): bool
    {
        return $this->cantidad_devuelta >= $this->cantidad;
    }
}