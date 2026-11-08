<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega `entregada_parcial` a `ventas.estado`.
 *
 * **Por qué ahora, si la Etapa 1 no lo usa.** Es la única pieza de la venta con
 * faltante que no es barata de agregar después: `modelo-datos.md` ya tiene
 * analizado que `venta_lineas.cantidad_entregada` y la clave foránea a la línea de
 * orden de compra son `ADD COLUMN` nullables —baratos en cualquier momento—, y que
 * un valor más en el ENUM **reescribe la tabla**. Hoy la tabla está vacía, así que
 * la reescritura no cuesta nada; después de la entrega, con datos cargados, deja de
 * ser gratis. Si la venta con faltante se va a hacer alguna vez, el valor se
 * declara acá.
 *
 * **Por qué en su lugar lógico y no pegado al final.** Agregar un valor al final de
 * un ENUM es la única modificación que MariaDB puede hacer in-place; insertarlo en
 * el medio renumera los índices internos y reescribe cada fila. Con cero filas eso
 * es gratis, y es justamente la ventaja de hacerlo hoy: el ENUM queda leyéndose en
 * el orden del flujo —parcialmente entregada antes que entregada— en lugar de con
 * un valor colgado al final por una razón técnica que en seis meses nadie recuerda.
 *
 * **Por qué `MODIFY COLUMN` a mano y no `->change()`.** Un `MODIFY COLUMN` reemplaza
 * la definición completa de la columna: si no se repiten `NOT NULL` y el `DEFAULT`,
 * la columna los pierde. Escribirlo explícito deja a la vista qué va a quedar y
 * permite comparar la línea contra la migración original. El `down()` es la misma
 * operación con la lista anterior, así que la vuelta atrás es literal.
 *
 * El rollback es seguro: ninguna transición de `MaquinaEstadosVenta` llega a
 * `entregada_parcial`, así que no puede haber una fila en ese estado que quedaría
 * fuera del ENUM al revertir.
 */
return new class extends Migration
{
    /** Los once, con `entregada_parcial` en el orden del flujo. */
    private const ONCE = [
        'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
        'despachada', 'lista_retiro', 'entregada_parcial', 'entregada',
        'cancelada', 'devuelta_parcial', 'devuelta',
    ];

    /** Los diez de la Fase 1, tal como los declaró su migración. */
    private const DIEZ = [
        'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
        'despachada', 'lista_retiro', 'entregada', 'cancelada',
        'devuelta_parcial', 'devuelta',
    ];

    public function up(): void
    {
        $this->declarar(self::ONCE);
    }

    public function down(): void
    {
        $this->declarar(self::DIEZ);
    }

    /** @param  array<int, string>  $valores */
    private function declarar(array $valores): void
    {
        $lista = collect($valores)->map(fn (string $valor) => "'{$valor}'")->implode(', ');

        DB::statement(
            "ALTER TABLE ventas MODIFY COLUMN estado ENUM({$lista}) NOT NULL DEFAULT 'presupuesto'"
        );
    }
};