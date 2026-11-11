<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta por la que se escriben el stock y el costo.
 *
 * Métodos:
 *   reservar()       compromete unidades para un checkout; sin asiento (D-5)
 *   liberar()        suelta una reserva; nunca baja de cero ni falla (HL-4)
 *   descontar()      salida por venta; valida contra el disponible y consume la reserva propia
 *   reponer()        entrada por devolución; el motivo es opcional
 *   recibirCompra()  entrada por recepción; recalcula el costo promedio ponderado
 *   fijarCosto()     fija el costo neto sin mover stock; no escribe asiento
 *   ajustar()        inventario: se carga el conteo físico, no la diferencia
 */
class StockService
{
    /**
     * Compromete unidades sin moverlas: baja el disponible, no el stock. No deja asiento:
     * el kardex dice qué entró y salió del depósito, y una reserva no mueve mercadería (D-5).
     *
     * @throws StockInsuficienteException
     */
    public function reservar(Producto $producto, int $cantidad): void
    {
        $this->exigirCantidadPositiva($cantidad);

        DB::transaction(function () use ($producto, $cantidad) {
            // Se decide con la fila bloqueada, no con el modelo que trajo quien llama.
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            if ($p->stock_disponible < $cantidad) {
                // Sin el número: este mensaje llega a la tienda, que nunca publica unidades.
                throw new StockInsuficienteException(
                    "Ya no hay stock suficiente de «{$p->nombre}» para {$cantidad} unidades."
                );
            }

            $p->stock_reservado = $p->stock_reservado + $cantidad;
            $p->save();
        });
    }

    /**
     * Suelta una reserva. Nunca baja de cero ni falla (HL-4): quien libera es un vencimiento o
     * una cancelación, y un error ahí dejaría la reserva tomada para siempre.
     */
    public function liberar(Producto $producto, int $cantidad): void
    {
        $this->exigirCantidadPositiva($cantidad);

        DB::transaction(function () use ($producto, $cantidad) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $p->stock_reservado = max(0, $p->stock_reservado - $cantidad);
            $p->save();
        });
    }

    /**
     * Salida de stock por una venta. Con `$deReserva`, esa parte ya estaba comprometida para
     * esta venta: se consume de `stock_reservado` y cuenta como disponible propio.
     *
     * @throws StockInsuficienteException
     */
    public function descontar(
        Producto $producto,
        int $cantidad,
        Model $origen,
        ?User $usuario = null,
        int $deReserva = 0,
    ): MovimientoStock {
        $this->exigirCantidadPositiva($cantidad);

        if ($deReserva < 0 || $deReserva > $cantidad) {
            throw new ReglaDeNegocioException(
                'La parte reservada de una salida tiene que estar entre cero y la cantidad que sale.'
            );
        }

        return DB::transaction(function () use ($producto, $cantidad, $origen, $usuario, $deReserva) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);
            $disponible = $p->stock_disponible + $deReserva;

            if ($disponible < $cantidad) {
                throw new StockInsuficienteException(
                    "No hay stock disponible de «{$p->nombre}»: hay {$disponible} y se piden {$cantidad}."
                );
            }

            $p->stock           = $p->stock - $cantidad;
            // Con la invariante nunca hace falta el tope; si algo la rompió, no se agrava.
            $p->stock_reservado = max(0, $p->stock_reservado - $deReserva);
            $p->save();

            return $this->registrar($p, 'venta', -$cantidad, $origen, $usuario);
        });
    }

    /**
     * Entrada de stock por una devolución.
     */
    public function reponer(
        Producto $producto,
        int $cantidad,
        Model $origen,
        ?User $usuario = null,
        ?string $motivo = null,
    ): MovimientoStock {
        $this->exigirCantidadPositiva($cantidad);

        return DB::transaction(function () use ($producto, $cantidad, $origen, $usuario, $motivo) {
            // El bloqueo también acá: es literalmente M-15.
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $p->stock = $p->stock + $cantidad;
            $p->save();

            return $this->registrar($p, 'devolucion', $cantidad, $origen, $usuario, $motivo);
        });
    }

    /**
     * Recepción de mercadería: ingresa stock y recalcula el costo promedio
     * ponderado, que es lo que el panel necesita para calcular margen.
     */
    public function recibirCompra(
        Producto $producto,
        int $cantidad,
        float|string $costoUnitario,
        Model $orden,
        User $usuario,
        ?string $remitoNumero = null,
        ?string $remitoFecha = null,
    ): MovimientoStock {
        $this->exigirCantidadPositiva($cantidad);

        if ((float) $costoUnitario < 0) {
            throw new ReglaDeNegocioException('El costo unitario de una recepción no puede ser negativo.');
        }

        return DB::transaction(function () use (
            $producto, $cantidad, $costoUnitario, $orden, $usuario, $remitoNumero, $remitoFecha
        ) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $stockPrevio = $p->stock;
            $costoPrevio = (float) $p->costo_promedio;
            $costoNuevo  = (float) $costoUnitario;
            $promedio = ($stockPrevio + $cantidad) > 0
                ? (($stockPrevio * $costoPrevio) + ($cantidad * $costoNuevo)) / ($stockPrevio + $cantidad)
                : $costoNuevo;

            $p->stock          = $stockPrevio + $cantidad;
            $p->costo_promedio = round($promedio, 2);
            $p->save();

            return $this->registrar($p, 'compra', $cantidad, $orden, $usuario, null, $remitoNumero, $remitoFecha);
        });
    }

    /**
     * @throws ReglaDeNegocioException
     */
    public function fijarCosto(Producto $producto, float|string $costoNeto): string
    {
        if ((float) $costoNeto < 0) {
            throw new ReglaDeNegocioException(
                'El costo de un producto no puede ser negativo: no hay mercadería que se cobre al recibirla.'
            );
        }

        return DB::transaction(function () use ($producto, $costoNeto) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $p->costo_promedio = round((float) $costoNeto, 2);
            $p->save();

            return $p->costo_promedio;
        });
    }

    /**
     * Ajuste de inventario.
     *
     * @throws ReglaDeNegocioException
     */
    public function ajustar(
        Producto $producto,
        int $stockContado,
        string $motivo,
        User $usuario,
        ?int $stockEsperado = null,
    ): MovimientoStock {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new ReglaDeNegocioException(
                'Un ajuste de inventario necesita un motivo: es lo único que explica por qué cambió el stock.'
            );
        }

        if ($stockContado < 0) {
            throw new ReglaDeNegocioException(
                'El stock contado no puede ser negativo: no existen menos de cero unidades en un depósito.'
            );
        }

        return DB::transaction(function () use ($producto, $stockContado, $motivo, $usuario, $stockEsperado) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            if ($stockEsperado !== null && $p->stock !== $stockEsperado) {
                throw new ReglaDeNegocioException(
                    "El stock de «{$p->nombre}» cambió mientras cargabas el ajuste: ahora hay {$p->stock} "
                    ."y la pantalla mostraba {$stockEsperado}. Revisá el conteo y cargalo de nuevo."
                );
            }

            $diferencia = $stockContado - $p->stock;

            if ($diferencia === 0) {
                throw new ReglaDeNegocioException(
                    "El stock contado es el mismo que el registrado ({$p->stock}): no hay nada que ajustar."
                );
            }

            $p->stock = $stockContado;
            $p->save();

            return $this->registrar($p, 'ajuste', $diferencia, null, $usuario, $motivo);
        });
    }

    /**
     * Escribe la fila del kardex. Privado: no hay forma de registrar un
     * movimiento sin mover el stock, que sería un asiento que afirma algo falso.
     */
    private function registrar(
        Producto $producto,
        string $tipo,
        int $cantidad,
        ?Model $origen,
        ?User $usuario,
        ?string $motivo = null,
        ?string $remitoNumero = null,
        ?string $remitoFecha = null,
    ): MovimientoStock {
        return MovimientoStock::create([
            'producto_id' => $producto->id,
            'tipo'        => $tipo,
            'cantidad'    => $cantidad,
            'stock_resultante' => $producto->stock,
            'origen_type' => $origen?->getMorphClass(),
            'origen_id'   => $origen?->getKey(),
            'usuario_id'  => $usuario?->id,
            'motivo'      => $motivo,
            'remito_numero' => $remitoNumero,
            'remito_fecha'  => $remitoFecha,
        ]);
    }
    /**
     * Una cantidad negativa en `descontar` sumaría stock en silencio, y en
     * `reponer` lo restaría. El signo lo decide el método, no quien llama.
     */
    private function exigirCantidadPositiva(int $cantidad): void
    {
        if ($cantidad <= 0) {
            throw new ReglaDeNegocioException(
                'La cantidad de un movimiento de stock tiene que ser mayor que cero. '
                .'Para llevar el stock a un valor exacto está el ajuste de inventario.'
            );
        }
    }
}