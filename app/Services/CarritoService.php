<?php

namespace App\Services;

use App\Exceptions\CarritoCambioException;
use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Carrito;
use App\Models\CarritoLinea;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * El carrito de la tienda, guardado en el servidor. La reserva de stock pasa por StockService.
 *
 * Métodos:
 *   delUsuario()       el carrito vigente, o uno nuevo; con la fila del usuario bloqueada
 *   paraLeer()         el vigente, o uno vacío sin guardar: un GET no crea nada
 *   agregar()          suma un producto; si ya está, suma cantidad
 *   cambiarCantidad()  fija la cantidad de un renglón propio
 *   quitar()           saca un renglón propio
 *   vaciar()           saca todos los renglones
 *   resumen()          renglones, importes, totales y avisos; no escribe nada
 *   iniciarCheckout()  revalida y reserva todo o nada; con cambios, los guarda y recién lanza
 *   soltarCheckout()   libera la reserva y vuelve a `activo`
 *   soltarCheckoutsVencidos()  lo de arriba para los que pasaron `reservado_hasta` (cada minuto)
 */
class CarritoService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** Bloquea al usuario antes de buscar: dos pedidos simultáneos no crean dos carritos. */
    public function delUsuario(User $usuario): Carrito
    {
        return DB::transaction(function () use ($usuario) {
            User::whereKey($usuario->id)->lockForUpdate()->first();

            $carrito = Carrito::where('user_id', $usuario->id)->vigente()->latest('id')->first();

            if ($carrito !== null) {
                return $carrito;
            }

            $nuevo = new Carrito;
            $nuevo->user_id = $usuario->id;
            $nuevo->estado  = 'activo';
            $nuevo->save();

            return $nuevo;
        });
    }

    public function paraLeer(User $usuario): Carrito
    {
        return Carrito::where('user_id', $usuario->id)->vigente()->latest('id')->first()
            ?? tap(new Carrito, fn (Carrito $c) => $c->user_id = $usuario->id);
    }

    /**
     * @throws ModelNotFoundException      el producto no está en la tienda
     * @throws StockInsuficienteException
     * @throws ReglaDeNegocioException     se pasaría de `carrito.cantidad_maxima`
     */
    public function agregar(User $usuario, int $productoId, int $cantidad): CarritoLinea
    {
        return DB::transaction(function () use ($usuario, $productoId, $cantidad) {
            $carrito  = $this->delUsuario($usuario);
            $producto = Producto::enTienda()->whereKey($productoId)->firstOrFail();

            $this->soltarCheckout($carrito);

            $linea = $carrito->lineas()->where('producto_id', $producto->id)->first()
                ?? tap(new CarritoLinea, function (CarritoLinea $l) use ($carrito, $producto) {
                    $l->carrito_id  = $carrito->id;
                    $l->producto_id = $producto->id;
                    $l->cantidad    = 0;
                });

            $total = $linea->cantidad + $cantidad;
            $this->exigirCantidad($producto->fresh(), $total);

            // Quien agrega acaba de ver el precio vigente: esa es la referencia (D-11).
            $linea->cantidad           = $total;
            $linea->contado_referencia = $producto->precio_contado;
            $linea->lista_referencia   = $producto->precio_lista;
            $linea->save();

            return $linea;
        });
    }

    /** @throws ModelNotFoundException  el renglón no es del carrito vigente del usuario */
    public function cambiarCantidad(User $usuario, int $lineaId, int $cantidad): CarritoLinea
    {
        return DB::transaction(function () use ($usuario, $lineaId, $cantidad) {
            $carrito = $this->delUsuario($usuario);
            $linea   = $carrito->lineas()->whereKey($lineaId)->with('producto')->firstOrFail();

            if (! Producto::enTienda()->whereKey($linea->producto_id)->exists()) {
                throw new ReglaDeNegocioException(
                    "«{$linea->producto->nombre}» ya no está en la tienda. Podés quitarlo del carrito."
                );
            }

            $this->soltarCheckout($carrito);
            $this->exigirCantidad($linea->producto->fresh(), $cantidad);

            $linea->cantidad = $cantidad;
            $linea->save();

            return $linea;
        });
    }

    public function quitar(User $usuario, int $lineaId): void
    {
        DB::transaction(function () use ($usuario, $lineaId) {
            $carrito = $this->delUsuario($usuario);
            $linea   = $carrito->lineas()->whereKey($lineaId)->firstOrFail();

            $this->soltarCheckout($carrito);
            $linea->delete();
        });
    }

    public function vaciar(User $usuario): void
    {
        DB::transaction(function () use ($usuario) {
            $carrito = $this->delUsuario($usuario);

            $this->soltarCheckout($carrito);
            $carrito->lineas()->delete();
        });
    }

    /**
     * Lo que muestra el carrito, con los precios vigentes (la referencia sólo detecta el cambio).
     * Los totales cuentan sólo lo que sigue en la tienda: lo que salió no se puede comprar.
     *
     * @return array{carrito: Carrito, renglones: list<array<string, mixed>>, totales: array{contado: string, lista: string}, avisos: list<array<string, mixed>>}
     */
    public function resumen(Carrito $carrito): array
    {
        $lineas = $carrito->exists
            ? $carrito->lineas()->with('producto.categoria')->orderBy('id')->get()
            : collect();

        $enTienda = Producto::enTienda()->whereKey($lineas->pluck('producto_id'))->pluck('id')->flip();
        $propia   = $carrito->estado === 'en_checkout';   // lo reservado por este carrito es suyo

        $renglones = [];
        $avisos    = [];
        $totales   = ['contado' => '0.00', 'lista' => '0.00'];

        foreach ($lineas as $linea) {
            $producto = $linea->producto;
            $vigente  = $enTienda->has($producto->id);

            $subtotal = [
                'contado' => bcmul((string) $producto->precio_contado, (string) $linea->cantidad, 2),
                'lista'   => bcmul((string) $producto->precio_lista, (string) $linea->cantidad, 2),
            ];

            $renglones[] = [
                'linea'     => $linea,
                'producto'  => $producto,
                'en_tienda' => $vigente,
                'unitario'  => ['contado' => (string) $producto->precio_contado, 'lista' => (string) $producto->precio_lista],
                'subtotal'  => $subtotal,
            ];

            if (! $vigente) {
                $avisos[] = $this->cambioInactivo($linea);

                continue;
            }

            $totales['contado'] = bcadd($totales['contado'], $subtotal['contado'], 2);
            $totales['lista']   = bcadd($totales['lista'], $subtotal['lista'], 2);

            $disponible = $producto->stock_disponible + ($propia ? $linea->cantidad : 0);

            if ($linea->cantidad > $disponible) {
                $avisos[] = [
                    'producto_id'    => $producto->id,
                    'descripcion'    => $producto->nombre,
                    'tipo'           => 'disponibilidad',
                    'pedida'         => $linea->cantidad,
                    'disponibilidad' => $producto->disponibilidad(),
                ];
            }

            array_push($avisos, ...$this->cambiosDePrecio($linea));
        }

        return ['carrito' => $carrito, 'renglones' => $renglones, 'totales' => $totales, 'avisos' => $avisos];
    }

    /**
     * Revalida y reserva. Si encontró cambios, actualiza las referencias, **confirma** y recién
     * después lanza: lanzando adentro, el rollback se llevaría la actualización y el segundo
     * intento volvería a fallar (D-11). Sin cambios, reserva cada renglón, todo o nada.
     *
     * @throws CarritoCambioException
     * @throws StockInsuficienteException
     * @throws ReglaDeNegocioException   el carrito está vacío
     */
    public function iniciarCheckout(User $usuario): array
    {
        [$carrito, $cambios] = DB::transaction(function () use ($usuario) {
            $c = Carrito::lockForUpdate()->findOrFail($this->delUsuario($usuario)->id);

            // Un reintento con la reserva en plazo no reserva dos veces.
            if ($c->enCheckoutVigente()) {
                return [$c, []];
            }

            // Vencido y todavía no barrido por el comando: se suelta y se empieza de nuevo.
            $this->soltarCheckout($c);

            $lineas = $c->lineas()->with('producto')->orderBy('producto_id')->get();

            if ($lineas->isEmpty()) {
                throw new ReglaDeNegocioException('Tu carrito está vacío: agregá algún producto para continuar.');
            }

            $enTienda = Producto::enTienda()->whereKey($lineas->pluck('producto_id'))->pluck('id')->flip();
            $cambios  = [];

            foreach ($lineas as $linea) {
                if (! $enTienda->has($linea->producto_id)) {
                    $cambios[] = $this->cambioInactivo($linea);

                    continue;
                }

                $dePrecio = $this->cambiosDePrecio($linea);

                if ($dePrecio !== []) {
                    array_push($cambios, ...$dePrecio);
                    $linea->contado_referencia = $linea->producto->precio_contado;
                    $linea->lista_referencia   = $linea->producto->precio_lista;
                    $linea->save();
                }
            }

            if ($cambios !== []) {
                return [$c, $cambios];
            }

            // Ordenados por producto: dos checkouts con los mismos productos no se bloquean mutuamente.
            foreach ($lineas as $linea) {
                $this->stock->reservar($linea->producto, $linea->cantidad);
            }

            $c->estado          = 'en_checkout';
            $c->reservado_hasta = now()->addMinutes((int) config('tienda.reservas.checkout_minutos'));
            $c->save();

            return [$c, []];
        });

        if ($cambios !== []) {
            throw new CarritoCambioException($cambios);
        }

        return $this->resumen($carrito->fresh());
    }

    /**
     * Libera lo reservado y vuelve a `activo`. Sin checkout, no hace nada. Los productos se
     * liberan en orden de id, como los bloquea `marcarPagada()`: dos carritos no se cruzan.
     */
    public function soltarCheckout(Carrito $carrito): void
    {
        $this->soltar($carrito, soloVencido: false);
    }

    /** Los checkouts que pasaron su plazo. Devuelve cuántos soltó. */
    public function soltarCheckoutsVencidos(): int
    {
        $soltados = 0;

        Carrito::where('estado', 'en_checkout')
            ->where('reservado_hasta', '<=', now())
            ->select('id')
            ->lazyById()
            ->each(function (Carrito $carrito) use (&$soltados) {
                $soltados += (int) $this->soltar($carrito, soloVencido: true);
            });

        return $soltados;
    }

    /** Con `soloVencido`, se vuelve a mirar el plazo con el carrito bloqueado: pudo renovarse. */
    private function soltar(Carrito $carrito, bool $soloVencido): bool
    {
        return DB::transaction(function () use ($carrito, $soloVencido) {
            $c = Carrito::lockForUpdate()->findOrFail($carrito->id);

            if ($c->estado !== 'en_checkout' || ($soloVencido && $c->enCheckoutVigente())) {
                return false;
            }

            foreach ($c->lineas()->with('producto')->orderBy('producto_id')->get() as $linea) {
                $this->stock->liberar($linea->producto, $linea->cantidad);
            }

            $c->estado          = 'activo';
            $c->reservado_hasta = null;
            $c->save();

            $carrito->setRawAttributes($c->getAttributes(), true);

            return true;
        });
    }

    /** Las dos referencias contra el precio vigente; una entrada por lista que cambió. */
    protected function cambiosDePrecio(CarritoLinea $linea): array
    {
        $cambios = [];

        foreach (['contado' => 'precio_contado', 'lista' => 'precio_lista'] as $lista => $columna) {
            $anterior = $linea->{"{$lista}_referencia"};
            $actual   = (string) $linea->producto->{$columna};

            if ($anterior !== null && bccomp((string) $anterior, $actual, 2) !== 0) {
                $cambios[] = [
                    'producto_id' => $linea->producto_id,
                    'descripcion' => $linea->producto->nombre,
                    'tipo'        => 'precio',
                    'lista'       => $lista,
                    'anterior'    => (string) $anterior,
                    'actual'      => $actual,
                ];
            }
        }

        return $cambios;
    }

    protected function cambioInactivo(CarritoLinea $linea): array
    {
        return [
            'producto_id' => $linea->producto_id,
            'descripcion' => $linea->producto->nombre,
            'tipo'        => 'inactivo',
        ];
    }

    /** El tope por renglón y el disponible. Agregar no reserva: la reserva es del checkout. */
    private function exigirCantidad(Producto $producto, int $cantidad): void
    {
        $maxima = (int) config('tienda.carrito.cantidad_maxima');

        if ($cantidad > $maxima) {
            throw new ReglaDeNegocioException(
                "Podés llevar hasta {$maxima} unidades de «{$producto->nombre}» por pedido."
            );
        }

        if ($producto->stock_disponible < $cantidad) {
            throw new StockInsuficienteException(
                "No hay stock suficiente de «{$producto->nombre}» para {$cantidad} unidades."
            );
        }
    }
}
