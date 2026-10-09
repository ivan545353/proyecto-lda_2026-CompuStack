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
 * La única puerta por la que se mueve el stock. 
 *
 * Ninguna otra parte del sistema escribe `productos.stock`, `stock_reservado` ni
 * `costo_promedio`: están fuera de `$fillable` justamente para que no se puedan
 * escribir desde un formulario, y acá se tocan por asignación directa, que no pasa
 * por la asignación masiva. Toda variación deja un movimiento en el kardex, y esa
 * afirmación sólo es verdadera si la puerta tiene todas las hojas: por eso están
 * los cuatro métodos, aunque la venta y la devolución las use la Fase 6.
 *
 * **Cada método es dueño de su tipo de movimiento.** El `plan-accion.md` tenía un
 * `reponer($producto, $cantidad, $tipo, ...)` donde el tipo lo elegía quien
 * llamaba; se cambió porque un error en el llamador registraría una devolución
 * como una compra, el kardex mentiría y ningún test fallaría.
 *
 * **El `motivo`, en cambio, sí lo pone quien llama, y no contradice lo de arriba.**
 * Un `$tipo` equivocado hace que el kardex afirme un hecho falso de forma
 * indetectable; un `$motivo` es texto libre que está o no está, y no puede volver
 * mentiroso ningún asiento. Lo exige `ajustar()`, porque un ajuste no tiene
 * documento de origen y el motivo es lo único que responde el «por qué» que pide
 * A-13. En `reponer()` es opcional: la devolución **sí** tiene documento —la venta—
 * así que el «por qué cambió el stock» ya está respondido, y lo que el motivo agrega
 * es lo único que el sistema no puede reconstruir después: por qué el cliente lo
 * trajo de vuelta.
 *
 * **Bloqueo pesimista en los cuatro, no sólo al descontar.** Es M-15: el
 * `moverStock` original hacía `SELECT ... FOR UPDATE` en modo descontar e iba
 * directo al `UPDATE` en modo reponer, así que dos reposiciones simultáneas del
 * mismo producto podían perder una.
 *
 * **El servicio nunca mira `activo`.** El kardex registra movimientos físicos y
 * `activo` es un atributo comercial: un producto discontinuado sigue estando en el
 * depósito, el inventario tiene que poder corregirlo y una orden aprobada antes de
 * la baja tiene que poder ingresar. Que un producto inactivo no se pueda vender es
 * una validación de la línea de venta, no del depósito.
 *
 * Métodos:
 *   descontar()      salida por venta; valida contra el disponible
 *   reponer()        entrada por devolución; el motivo es opcional
 *   recibirCompra()  entrada por recepción; recalcula el costo promedio ponderado
 *   ajustar()        inventario: se carga el conteo físico, no la diferencia
 */
class StockService
{
    /**
     * Salida de stock por una venta.
     *
     * @throws StockInsuficienteException
     */
    public function descontar(
        Producto $producto,
        int $cantidad,
        Model $origen,
        ?User $usuario = null,
    ): MovimientoStock {
        $this->exigirCantidadPositiva($cantidad);

        return DB::transaction(function () use ($producto, $cantidad, $origen, $usuario) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            // Se valida contra el DISPONIBLE y no contra el stock. Si hay 5 en
            // depósito y 5 comprometidas por checkouts en curso, una venta de
            // mostrador que mirara sólo `stock` dejaría sin su unidad a alguien
            // que ya pagó. Es además la misma definición que usan
            // Producto::scopeConStock() y la reposición automática: si acá usara
            // otra, el sistema diría dos cosas distintas del mismo producto.
            $disponible = $p->stock - $p->stock_reservado;

            if ($disponible < $cantidad) {
                throw new StockInsuficienteException(
                    "No hay stock disponible de «{$p->nombre}»: hay {$disponible} y se piden {$cantidad}."
                );
            }

            $p->stock = $p->stock - $cantidad;
            $p->save();

            return $this->registrar($p, 'venta', -$cantidad, $origen, $usuario);
        });
    }

        /**
     * Entrada de stock por una devolución.
     *
     * El `motivo` es opcional y viaja al asiento del kardex. Ver el docblock de la
     * clase: la devolución tiene documento de origen, así que el motivo no responde
     * «por qué cambió el stock» —eso lo responde el `origen`— sino «por qué el
     * cliente lo trajo de vuelta», que es lo único que no se puede reconstruir
     * después.
     *
     * No valida `activo`, igual que los otros tres. Una unidad que el cliente trae de
     * vuelta entró físicamente al depósito, esté el producto discontinuado o no, y
     * rechazar el asiento sería negarle al kardex la capacidad de registrar lo que
     * pasó — que es lo peor que le puede pasar a un libro.
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
     *
     * El costo llega como string o float porque la línea de la orden lo devuelve
     * con el cast `decimal:2`, que es un string.
     */
    public function recibirCompra(
        Producto $producto,
        int $cantidad,
        float|string $costoUnitario,
        Model $orden,
        User $usuario,
    ): MovimientoStock {
        $this->exigirCantidadPositiva($cantidad);

        if ((float) $costoUnitario < 0) {
            throw new ReglaDeNegocioException('El costo unitario de una recepción no puede ser negativo.');
        }

        return DB::transaction(function () use ($producto, $cantidad, $costoUnitario, $orden, $usuario) {
            $p = Producto::lockForUpdate()->findOrFail($producto->id);

            $stockPrevio = $p->stock;
            $costoPrevio = (float) $p->costo_promedio;
            $costoNuevo  = (float) $costoUnitario;

            // Promedio ponderado. Es el único lugar del sistema donde aparece un
            // flotante, y es inevitable: un promedio es una división. Se redondea
            // a dos decimales ANTES de tocar la base, que es decimal(12,2), así
            // que el importe guardado nunca arrastra el error del flotante. La
            // regla de A-17 es sobre cómo se guarda un importe, no sobre cómo se
            // calcula un promedio.
            $promedio = ($stockPrevio + $cantidad) > 0
                ? (($stockPrevio * $costoPrevio) + ($cantidad * $costoNuevo)) / ($stockPrevio + $cantidad)
                : $costoNuevo;

            $p->stock          = $stockPrevio + $cantidad;
            $p->costo_promedio = round($promedio, 2);
            $p->save();

            return $this->registrar($p, 'compra', $cantidad, $orden, $usuario);
        });
    }

    /**
     * Ajuste de inventario.
     *
     * Se recibe el CONTEO FÍSICO, no la diferencia: el operario que está en el
     * depósito sabe cuántas hay, no cuántas faltan, y pedirle la diferencia lo
     * obliga a restar contra un número de la pantalla que puede estar viejo.
     *
     * `$stockEsperado` es el stock que la pantalla mostró al abrir el formulario.
     * Si al guardar el de la base es otro, el ajuste pisaría un movimiento
     * legítimo de otra persona, así que se rechaza. Es control de concurrencia
     * optimista, y es opcional para que la API de la Etapa 3 pueda ajustar sin
     * una pantalla de la que venir.
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

        // El motivo es obligatorio acá y sólo acá. Un ajuste no tiene documento
        // de origen —`origen` es nullable justamente para esto—, así que es lo
        // único que responde el «por qué» que pide A-13. La regla vive en el
        // servicio y no sólo en el Form Request para que la herede cualquier
        // cliente, incluida la API de la Etapa 3.
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

            // NO se valida que el stock quede por encima de lo reservado, y es
            // deliberado: si el inventario encuentra 3 unidades y hay 5
            // reservadas, la verdad son 3 y lo que está mal son las reservas.
            // Rechazar el ajuste sería negarle al sistema la capacidad de
            // registrar la realidad, que es lo peor que le puede pasar a un
            // kardex. El conflicto se resuelve liberando reservas, en la Etapa 2.
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
    ): MovimientoStock {
        return MovimientoStock::create([
            'producto_id' => $producto->id,
            'tipo'        => $tipo,
            'cantidad'    => $cantidad,
            // El saldo que quedó, leído del mismo objeto que se acaba de guardar.
            'stock_resultante' => $producto->stock,
            // getMorphClass() y no ::class, para respetar un morph map si alguna
            // vez se define uno.
            'origen_type' => $origen?->getMorphClass(),
            'origen_id'   => $origen?->getKey(),
            'usuario_id'  => $usuario?->id,
            'motivo'      => $motivo,
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