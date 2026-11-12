<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Carrito;
use App\Models\Cliente;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaLinea;
use App\Support\Importe;
use App\Services\PrecioService;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de la venta.
 *
 * Métodos:
 *   crear()            alta de un presupuesto, con sus líneas cotizadas
 *   crearOnline()      el pedido de la tienda: nace en `pendiente_pago`, con la reserva del carrito
 *   actualizar()       edición del presupuesto, conservando los precios congelados
 *   recotizar()        relee los precios de todas las líneas e informa qué cambió
 *   marcarPagada()     el pase a `pagada`: descuenta el stock, exactamente una vez (y consume la reserva)
 *   entregar()         la mercadería sale, toda o en parte; no mueve stock ni plata
 *   devolver()         repone stock, revierte los pagos y deriva el estado 
 *   cancelar()         da de baja un presupuesto o un pedido; el pedido suelta su reserva
 *   cancelarVencidos() los pedidos online cuya reserva venció, uno por transacción (D-1)
 *   topeDeDescuento()  el tope que rige para un usuario; lo usan el Request y la vista
 */
class VentaService
{
    /**
     * `StockService` por el constructor, resuelto por el contenedor.
     */
    /** Con qué lista se cotiza cada medio de la tienda. */
    private const LISTA_DEL_MEDIO = ['contado' => 'contado', 'pasarela' => 'lista'];

    public function __construct(private StockService $stock, private PrecioService $precios)
    {
    }

    /**
     * Alta de un presupuesto.
     *
     *
     * @param  array{cliente_id?: int|null, descuento_porcentaje?: float|string|null, observaciones?: string|null, lineas: array<int, array{producto_id: int, cantidad: int}>}  $datos
     *
     * @throws ReglaDeNegocioException
     */
    public function crear(array $datos, User $usuario): Venta
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una venta necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        $porcentaje = (float) ($datos['descuento_porcentaje'] ?? 0);

        $this->exigirDescuentoAutorizado($porcentaje, $usuario);

        return DB::transaction(function () use ($datos, $lineas, $porcentaje, $usuario) {
            $venta = Venta::create([
                'cliente_id'    => $datos['cliente_id'] ?? null,
                'usuario_id'    => $usuario->id,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $this->escribirLineas($venta, $lineas);
            $this->recalcularTotales($venta, $porcentaje);

            return $venta->fresh('lineas');
        });
    }

    /**
     * El pedido de la tienda. `pendiente_pago` no es una transición: es el estado de nacimiento,
     * como `presupuesto` en el mostrador. La reserva del carrito pasa a ser del pedido sin moverse
     * (HL-4): acá no se reserva nada, sólo se anota hasta cuándo vale.
     *
     * @param  array{calle: string, numero: string, piso_depto?: string|null, codigo_postal: string, localidad: string, provincia: string}|null  $direccion
     *
     * @throws ReglaDeNegocioException
     */
    public function crearOnline(
        Cliente $cliente,
        Carrito $carrito,
        string $medio,
        string $modoEntrega,
        ?array $direccion,
    ): Venta {
        if (! array_key_exists($medio, self::LISTA_DEL_MEDIO)) {
            throw new ReglaDeNegocioException('El medio de pago tiene que ser contado o pasarela.');
        }

        if (! in_array($modoEntrega, ['retiro', 'envio'], true)) {
            throw new ReglaDeNegocioException('La entrega tiene que ser retiro en el local o envío.');
        }

        if ($modoEntrega === 'envio' && $direccion === null) {
            throw new ReglaDeNegocioException('Un envío necesita la dirección adónde mandarlo.');
        }

        $lineas = $carrito->lineas()->orderBy('producto_id')->get()
            ->map(fn ($l) => ['producto_id' => $l->producto_id, 'cantidad' => $l->cantidad])
            ->all();

        if ($lineas === []) {
            throw new ReglaDeNegocioException('El carrito está vacío: no hay nada que pedir.');
        }

        return DB::transaction(function () use ($cliente, $carrito, $medio, $modoEntrega, $direccion, $lineas) {
            $venta = new Venta;
            $venta->cliente_id    = $cliente->id;
            $venta->usuario_id    = null;
            $venta->canal         = 'online';
            $venta->estado        = 'pendiente_pago';
            $venta->carrito_id    = $carrito->id;
            $venta->medio_pago    = $medio;
            $venta->modo_entrega  = $modoEntrega;
            $venta->costo_envio   = $modoEntrega === 'envio' ? (string) config('tienda.envio.costo') : '0.00';
            $venta->reserva_vence = $medio === 'contado'
                ? now()->addHours((int) config('tienda.reservas.contado_horas'))
                : now()->addMinutes((int) config('tienda.reservas.pasarela_minutos'));

            if ($modoEntrega === 'envio') {
                $venta->entrega_calle         = $direccion['calle'];
                $venta->entrega_numero        = $direccion['numero'];
                $venta->entrega_piso_depto    = $direccion['piso_depto'] ?? null;
                $venta->entrega_codigo_postal = $direccion['codigo_postal'];
                $venta->entrega_localidad     = $direccion['localidad'];
                $venta->entrega_provincia     = $direccion['provincia'];
            }

            $venta->save();

            $this->escribirLineas($venta, $lineas, self::LISTA_DEL_MEDIO[$medio]);
            $this->recalcularTotales($venta, 0);

            return $venta->fresh('lineas');
        });
    }

    /**
     * Edición de un presupuesto.
     *
     * @param  array{cliente_id?: int|null, descuento_porcentaje?: float|string|null, observaciones?: string|null, lineas: array<int, array{producto_id: int, cantidad: int}>}  $datos
     *
     * @throws ReglaDeNegocioException
     */
    public function actualizar(Venta $venta, array $datos, User $usuario): Venta
    {
        $lineas = $datos['lineas'] ?? [];

        if ($lineas === []) {
            throw new ReglaDeNegocioException('Una venta necesita al menos un producto.');
        }

        $this->exigirProductosDistintos($lineas);

        $porcentaje = (float) ($datos['descuento_porcentaje'] ?? 0);

        $this->exigirDescuentoAutorizado($porcentaje, $usuario);

        return DB::transaction(function () use ($venta, $datos, $lineas, $porcentaje) {

            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->exigirPresupuesto($v, 'modificar');

            $v->update([
                'cliente_id'    => $datos['cliente_id'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $this->ajustarLineas($v, $lineas);
            $this->recalcularTotales($v, $porcentaje);

            return $v->fresh('lineas');
        });
    }

    /**
     * Relee los precios de todas las líneas y devuelve qué cambió.
     *
     * @return array{venta: Venta, cambios: array<int, array{descripcion: string, precio_anterior: string, precio_nuevo: string}>, total_anterior: string}
     *
     * @throws ReglaDeNegocioException
     */
    public function recotizar(Venta $venta): array
    {
        return DB::transaction(function () use ($venta) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            $this->exigirPresupuesto($v, 'recotizar');
            $porcentaje    = $v->descuentoPorcentaje();
            $totalAnterior = $v->total;

            $lineas = $v->lineas()->get();

            $productos = Producto::query()
                ->whereIn('id', $lineas->pluck('producto_id'))
                ->get()
                ->keyBy('id');

            $cambios = [];

            foreach ($lineas as $linea) {
                $producto = $productos->get($linea->producto_id);

                if ($producto === null) {
                    throw new ReglaDeNegocioException(
                        "«{$linea->descripcion}» ya no existe en el catálogo, así que no se puede recotizar. "
                        .'Quitalo del presupuesto.'
                    );
                }

                // Acá sí se exige que esté activo, al contrario que en la edición:
                // recotizar es cotizar, y no se cotiza lo que no se puede vender.
                if (! $producto->activo) {
                    throw new ReglaDeNegocioException(
                        "«{$producto->nombre}» está dado de baja y no se puede cotizar. Quitalo del "
                        .'presupuesto, o volvé a activarlo en el catálogo.'
                    );
                }

                $precioAnterior = $linea->precio_unitario;
                $precioNuevo    = $producto->precio_contado;

                $importes = $this->importesDe($precioNuevo, $producto->alicuota_iva, $linea->cantidad);

                $linea->descripcion     = $producto->nombre;
                $linea->precio_unitario = $precioNuevo;
                $linea->alicuota_iva    = $producto->alicuota_iva;
                $linea->costo_unitario  = $producto->costo_promedio;
                $linea->neto            = $importes['neto'];
                $linea->iva             = $importes['iva'];
                $linea->total           = $importes['total'];

                $linea->save();
                $this->congelarImpuestos($linea, $importes['impuestos']);

                // Se comparan como números y no como texto: '1000.00' y '1000.0'
                // son el mismo precio y no un cambio que informar.
                if ((float) $precioAnterior !== (float) $precioNuevo) {
                    $cambios[] = [
                        'descripcion'     => $producto->nombre,
                        'precio_anterior' => $precioAnterior,
                        'precio_nuevo'    => $precioNuevo,
                    ];
                }
            }

            $this->recalcularTotales($v, $porcentaje);

            return [
                'venta'          => $v->fresh('lineas'),
                'cambios'        => $cambios,
                'total_anterior' => $totalAnterior,
            ];
        });
    }

    /**
     * El pase a `pagada`: descuenta el stock, exactamente una vez.
     *
     * @throws \App\Exceptions\TransicionInvalidaException   la venta no puede pasar a pagada
     * @throws ReglaDeNegocioException                       hay un producto dado de baja
     * @throws \App\Exceptions\StockInsuficienteException    no hay disponible de algún producto
     */
    public function marcarPagada(Venta $venta, ?User $usuario): Venta
    {
        return DB::transaction(function () use ($venta, $usuario) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            MaquinaEstadosVenta::validar($v->estado, 'pagada');

            $lineas = $v->lineas()->with('producto')->orderBy('producto_id')->get();

            if ($lineas->isEmpty()) {
                throw new ReglaDeNegocioException(
                    "La venta {$v->numeroFormateado()} no tiene productos, así que no hay nada que cobrar."
                );
            }

            foreach ($lineas as $linea) {
                if (! $linea->producto->activo) {
                    throw new ReglaDeNegocioException(
                        "«{$linea->producto->nombre}» está dado de baja y no se puede vender, así que la "
                        ."venta {$v->numeroFormateado()} no se puede cobrar. Quitá el renglón del "
                        .'presupuesto, o volvé a activar el producto en el catálogo.'
                    );
                }
            }
            // Con reserva, sus unidades ya eran de este pedido: valen aunque la hora haya pasado,
            // mientras nadie la haya liberado (HL-10). `reserva_vence` en null es «ya no hay».
            $conReserva = $v->reserva_vence !== null;

            foreach ($lineas as $linea) {
                $this->stock->descontar(
                    $linea->producto, $linea->cantidad, $v, $usuario, $conReserva ? $linea->cantidad : 0,
                );
            }

            $v->reserva_vence = null;
            $this->cambiarEstado($v, 'pagada');

            return $v->fresh(['lineas', 'movimientos']);
        });
    }

    /**
     * La mercadería sale del local, toda o en parte: deja su entrega y no mueve stock ni plata.
     *
     * @param  array<int|string, int|string>|null  $cantidades  [id de renglón => cuántas salen]; null entrega todo lo pendiente
     *
     * @throws ReglaDeNegocioException
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function entregar(Venta $venta, ?User $usuario = null, ?array $cantidades = null): Venta
    {
        $pedidas = $cantidades === null
            ? null
            : array_filter(array_map('intval', $cantidades), fn (int $cantidad) => $cantidad > 0);

        if ($pedidas === []) {
            throw new ReglaDeNegocioException('Indicá cuántas unidades se entregan en al menos un producto.');
        }

        return DB::transaction(function () use ($venta, $usuario, $pedidas) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            if (! MaquinaEstadosVenta::puedeEntregar($v->estado)) {
                MaquinaEstadosVenta::validar($v->estado, 'entregada');
            }

            $v->load('lineas');

            $salen = $this->unidadesQueSalen($v, $pedidas);

            // El null de un renglón anterior a las entregas se vuelve un número en la primera entrega.
            foreach ($v->lineas as $linea) {
                $linea->cantidad_entregada = ($linea->cantidad_entregada ?? 0) + ($salen[$linea->id] ?? 0);
                $linea->save();
            }

            $this->cambiarEstado($v, $v->estadoDeEntrega());

            $entrega = $v->entregas()->create(['usuario_id' => $usuario?->id]);

            foreach ($salen as $lineaId => $cantidad) {
                $entrega->lineas()->create(['venta_linea_id' => $lineaId, 'cantidad' => $cantidad]);
            }

            return $v->fresh(['lineas', 'entregas.lineas']);
        });
    }

    /**
     * Cuánto sale de cada renglón, validado contra lo pendiente con la venta ya bloqueada.
     *
     * @param  array<int, int>|null  $pedidas
     * @return array<int, int>  [id de renglón => cantidad]
     *
     * @throws ReglaDeNegocioException
     */
    private function unidadesQueSalen(Venta $venta, ?array $pedidas): array
    {
        if ($pedidas === null) {
            return $venta->lineas
                ->filter(fn (VentaLinea $linea) => $linea->cantidadPendienteDeEntregar() > 0)
                ->mapWithKeys(fn (VentaLinea $linea) => [$linea->id => $linea->cantidadPendienteDeEntregar()])
                ->all();
        }

        $lineas = $venta->lineas->keyBy('id');

        foreach ($pedidas as $lineaId => $cantidad) {
            $linea = $lineas->get($lineaId);

            if ($linea === null) {
                throw new ReglaDeNegocioException(
                    "Alguno de los renglones que se intenta entregar no pertenece a la venta "
                    ."{$venta->numeroFormateado()}. Volvé a cargar la pantalla."
                );
            }

            $pendiente = $linea->cantidadPendienteDeEntregar();

            if ($cantidad > $pendiente) {
                throw new ReglaDeNegocioException(
                    "De «{$linea->descripcion}» quedan {$pendiente} unidad(es) por entregar y se están "
                    ."entregando {$cantidad}. No se puede entregar más de lo que se vendió."
                );
            }
        }

        return $pedidas;
    }

    /**
     * La devolución, total o parcial.
     *
     * @param  array{cantidades: array<int|string, int|string>, metodo: string, motivo?: string|null}  $datos
     *
     * @throws ReglaDeNegocioException
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function devolver(Venta $venta, array $datos, User $usuario): Venta
    {
        // Las líneas donde no vuelve nada no son un error: el formulario las manda en
        // cero porque muestra todas. Lo que no puede pasar es que no vuelva nada en
        // ninguna. Mismo criterio que `CompraService::recibir()`.
        $cantidades = array_filter(
            array_map('intval', $datos['cantidades'] ?? []),
            fn (int $cantidad) => $cantidad > 0,
        );

        if ($cantidades === []) {
            throw new ReglaDeNegocioException(
                'Indicá cuántas unidades se devuelven en al menos un producto.'
            );
        }

        $metodo = (string) ($datos['metodo'] ?? '');

        $this->exigirMedioDeDevolucion($metodo);
        $motivo = trim((string) ($datos['motivo'] ?? '')) ?: null;

        return DB::transaction(function () use ($venta, $cantidades, $metodo, $motivo, $usuario) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            if (! MaquinaEstadosVenta::puedeDevolver($v->estado)) {
                throw new ReglaDeNegocioException(
                    "La venta {$v->numeroFormateado()} está «{$v->estadoTexto()}» y en ese estado no se "
                    .'devuelve. Se devuelve lo que se cobró: un presupuesto se cancela, y una venta ya '
                    .'devuelta por completo no tiene nada más que volver.'
                );
            }

            $lineas = $v->lineas()
                ->with('producto')
                ->whereIn('id', array_keys($cantidades))
                ->orderBy('producto_id')
                ->get();

            if ($lineas->count() !== count($cantidades)) {
                throw new ReglaDeNegocioException(
                    "Alguno de los renglones que se intenta devolver no pertenece a la venta "
                    ."{$v->numeroFormateado()}. Volvé a cargar la pantalla."
                );
            }

            $subtotalDevuelto = 0.0;

            foreach ($lineas as $linea) {
                $cantidad  = $cantidades[$linea->id];
                $pendiente = $linea->cantidadPendienteDeDevolver();

                if ($cantidad > $pendiente) {
                    throw new ReglaDeNegocioException(
                        "De «{$linea->descripcion}» se vendieron {$linea->cantidad} unidad(es), ya se "
                        ."devolvieron {$linea->cantidad_devuelta} y se están devolviendo {$cantidad}: "
                        ."quedan {$pendiente} por devolver. No se puede devolver más de lo que se vendió."
                    );
                }

                $this->stock->reponer($linea->producto, $cantidad, $v, $usuario, $motivo);

                // `cantidad_devuelta` está fuera de `$fillable`: asignación directa.
                $linea->cantidad_devuelta = $linea->cantidad_devuelta + $cantidad;
                $linea->save();

                $subtotalDevuelto += round($cantidad * (float) $linea->precio_unitario, 2);
            }

            $subtotalDevuelto = round($subtotalDevuelto, 2);

            $completa = ! $v->fresh('lineas')->tienePendientesDeDevolver();

            $retenido  = $v->pagado();
            $aDevolver = $completa
                ? $retenido
                : min($this->parteProporcional($v, $subtotalDevuelto), $retenido);

            if ($aDevolver > 0) {

                $reversion = $v->pagos()->make([
                    'metodo' => $metodo,
                    'monto'  => -$aDevolver,
                ]);

                $reversion->usuario_id = $usuario->id;
                $reversion->fecha      = now();

                $reversion->save();
            }

            $this->cambiarEstado($v, $completa ? 'devuelta' : 'devuelta_parcial');

            return $v->fresh(['lineas', 'pagos', 'movimientos']);
        });
    }

    /**
     * Cancela un presupuesto.
     *
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    public function cancelar(Venta $venta): Venta
    {
        return DB::transaction(function () use ($venta) {
            $v = Venta::lockForUpdate()->findOrFail($venta->id);

            MaquinaEstadosVenta::validar($v->estado, 'cancelada');
            $this->soltarReserva($v);
            $this->cambiarEstado($v, 'cancelada');

            return $v->fresh();
        });
    }

    /**
     * Cancela los pedidos online cuya reserva venció. Cada uno en su transacción: uno que falla
     * no frena a los demás. No cancela lo que está en el aire (HL-63, §1.3 E): un intento de pago
     * pendiente, o uno aprobado que todavía no se acreditó —esa plata ya entró—.
     */
    public function cancelarVencidos(): int
    {
        $cancelados = 0;

        Venta::query()
            ->where('canal', 'online')
            ->where('estado', 'pendiente_pago')
            ->where('reserva_vence', '<', now())
            ->whereDoesntHave('intentosPago', fn (Builder $q) => self::enElAire($q))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$cancelados) {
                $cancelados += DB::transaction(function () use ($id) {
                    $v = Venta::lockForUpdate()->find($id);

                    // Se vuelve a preguntar con la fila bloqueada: pudo cobrarse mientras tanto.
                    if ($v === null || $v->estado !== 'pendiente_pago'
                        || $v->reserva_vence === null || $v->reserva_vence->isFuture()
                        || self::enElAire($v->intentosPago()->getQuery())->exists()) {
                        return 0;
                    }

                    $this->soltarReserva($v);
                    $this->cambiarEstado($v, 'cancelada');

                    return 1;
                });
            });

        return $cancelados;
    }

    /** Los intentos que impiden cancelar: pendientes, o aprobados sin su pago escrito. */
    private static function enElAire(Builder $intentos): Builder
    {
        return $intentos->where(fn (Builder $q) => $q->where('estado', 'pendiente')
            ->orWhere(fn (Builder $q) => $q->where('estado', 'aprobado')->whereNull('acreditado_at')));
    }

    /** Devuelve al disponible lo que el pedido retenía. Sin reserva, no hace nada. */
    private function soltarReserva(Venta $venta): void
    {
        if ($venta->reserva_vence === null) {
            return;
        }

        // Ordenados por producto, como al reservar: dos liberaciones no se bloquean mutuamente.
        foreach ($venta->lineas()->with('producto')->orderBy('producto_id')->get() as $linea) {
            $this->stock->liberar($linea->producto, $linea->cantidad);
        }

        $venta->reserva_vence = null;
    }

    /**
     * El tope de descuento que rige para este usuario, en porcentaje.
     */
    public function topeDeDescuento(User $usuario): float
    {
        return (float) ($usuario->tienePermiso('venta.autorizar_descuento')
            ? config('venta.descuento.tope_autorizado')
            : config('venta.descuento.tope_general'));
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /**
     * Escribe las líneas cotizando cada una contra el precio de la base.
     *
     * @param  array<int, array{producto_id: int, cantidad: int}>  $lineas
     */
    private function escribirLineas(Venta $venta, array $lineas, string $lista = 'contado'): void
    {
        $productos = Producto::query()
            ->whereIn('id', array_column($lineas, 'producto_id'))
            ->get()
            ->keyBy('id');

        foreach ($lineas as $datos) {
            $producto = $productos->get((int) $datos['producto_id']);
            if ($producto === null) {
                throw new ReglaDeNegocioException(
                    'Uno de los productos de la venta ya no existe. Volvé a cargar la pantalla.'
                );
            }

            if (! $producto->activo) {
                throw new ReglaDeNegocioException(
                    "«{$producto->nombre}» está dado de baja y no se puede vender. Si hay que venderlo, "
                    .'hay que volver a activarlo en el catálogo.'
                );
            }

            $cantidad = (int) $datos['cantidad'];

            if ($cantidad < 1) {
                throw new ReglaDeNegocioException(
                    "La cantidad de «{$producto->nombre}» tiene que ser al menos 1."
                );
            }
            // El mostrador cotiza a contado; el pedido online, con la lista de su medio (HL-14).
            $precio   = $lista === 'lista' ? $producto->precio_lista : $producto->precio_contado;
            $importes = $this->importesDe($precio, $producto->alicuota_iva, $cantidad);

            $linea = $venta->lineas()->make([
                'producto_id' => $producto->id,
                'cantidad'    => $cantidad,
            ]);

            $linea->descripcion     = $producto->nombre;
            $linea->precio_unitario = $precio;
            $linea->alicuota_iva    = $producto->alicuota_iva;

            $linea->costo_unitario  = $producto->costo_promedio;
            $linea->neto            = $importes['neto'];
            $linea->iva             = $importes['iva'];
            $linea->total           = $importes['total'];
            $linea->save();
            $this->congelarImpuestos($linea, $importes['impuestos']);
        }
    }

    /**
     * Ajusta las líneas al pedido nuevo, sin borrar lo que sobrevive.
     *
     * @param  array<int, array{producto_id: int, cantidad: int}>  $lineas
     */
    private function ajustarLineas(Venta $venta, array $lineas): void
    {
        $pedidos    = collect($lineas)->keyBy(fn (array $linea) => (int) $linea['producto_id']);
        $existentes = $venta->lineas()->get()->keyBy('producto_id');

        $venta->lineas()->whereNotIn('producto_id', $pedidos->keys())->delete();

        $nuevas = $pedidos
            ->reject(fn (array $linea, int $productoId) => $existentes->has($productoId))
            ->values()
            ->all();

        if ($nuevas !== []) {
            $this->escribirLineas($venta, $nuevas);
        }

        foreach ($existentes as $productoId => $linea) {
            if (! $pedidos->has($productoId)) {
                continue;
            }

            $cantidad = (int) $pedidos->get($productoId)['cantidad'];

            if ($cantidad < 1) {
                throw new ReglaDeNegocioException(
                    "La cantidad de «{$linea->descripcion}» tiene que ser al menos 1. "
                    .'Si no se vende, quitá el renglón.'
                );
            }

            if ($cantidad === $linea->cantidad) {
                continue;   
            }

            $this->recalcularLinea($linea, $cantidad);
        }
    }

    /**
     * Recalcula los importes de una línea que ya existe, con el precio que ya tiene.
     */
    private function recalcularLinea(VentaLinea $linea, int $cantidad): void
    {
        $importes = $this->importesDe($linea->precio_unitario, $linea->alicuota_iva, $cantidad);

        $linea->cantidad = $cantidad;
        $linea->neto     = $importes['neto'];
        $linea->iva      = $importes['iva'];
        $linea->total    = $importes['total'];

        $linea->save();
        $this->congelarImpuestos($linea, $importes['impuestos']);
    }

    /**
     * Los tres importes de una línea.
     *
     *
     * @return array{neto: float, iva: float, total: float}
     */
    private function importesDe(float|string $precio, float|string $alicuota, int $cantidad): array
    {
        $total    = round($cantidad * (float) $precio, 2);
        $desglose = $this->precios->desglosarImporte($total, $alicuota);

        $iva = array_sum(array_map(
            fn (array $impuesto) => $impuesto['comprobante'] === 'iva' ? $impuesto['importe'] : 0,
            $desglose['impuestos'],
        ));

        return [
            'neto'      => $desglose['neto'],
            'iva'       => round($iva, 2),
            'total'     => $total,
            'impuestos' => $desglose['impuestos'],
        ];
    }

    /**
     * Congela el desglose del renglón: una fila por impuesto, con el nombre y la
     * alícuota copiados.
     *
     * Se reescribe entero y no se completa: la línea pudo cambiar de cantidad o de
     * precio, y un desglose mezclado entre dos cotizaciones no suma el total.
     *
     * @param  array<int, array{nombre: string, alicuota: float, comprobante: string, importe: float}>  $impuestos
     */
    private function congelarImpuestos(VentaLinea $linea, array $impuestos): void
    {
        $linea->impuestos()->delete();

        foreach (array_values($impuestos) as $orden => $impuesto) {
            $linea->impuestos()->create([
                'nombre'      => $impuesto['nombre'],
                'alicuota'    => $impuesto['alicuota'],
                'importe'     => $impuesto['importe'],
                'comprobante' => $impuesto['comprobante'],
                'orden'       => $orden,
            ]);
        }
    }

    /**
     * Sólo un presupuesto se modifica.
     */
    private function exigirPresupuesto(Venta $venta, string $accion): void
    {
        if (! $venta->esPresupuesto()) {
            throw new ReglaDeNegocioException(
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y ya no se puede "
                ."{$accion}: lo que se cobró no se corrige editando, se devuelve."
            );
        }
    }

    /**
     * Cambia el estado validando la transición. **Privado, a propósito.**
     *
     * @throws \App\Exceptions\TransicionInvalidaException
     */
    private function cambiarEstado(Venta $venta, string $nuevo): void
    {
        MaquinaEstadosVenta::validar($venta->estado, $nuevo);

        $venta->estado = $nuevo;

        $venta->save();
    }

    /**
     * Recalcula los tres importes de la cabecera desde las líneas guardadas.
     *
     */
    private function recalcularTotales(Venta $venta, float $porcentaje): void
    {
        $subtotal  = (float) $venta->lineas()->sum('total');
        $descuento = round($subtotal * $porcentaje / 100, 2);

        // Los tres están fuera de $fillable: asignación directa, igual que el estado.
        $venta->subtotal  = $subtotal;
        $venta->descuento = $descuento;
        $venta->total     = round($subtotal - $descuento + (float) $venta->costo_envio, 2);

        $venta->save();
    }

    /**
     *
     * El mensaje dice el número y qué hacer, porque no hay flujo de autorización:
     * quien no puede, no puede, y alguien con el permiso tiene que cargar la venta.
     * Está anotado en los pendientes de usabilidad con ese motivo.
     */
    private function exigirDescuentoAutorizado(float $porcentaje, User $usuario): void
    {
        if ($porcentaje < 0) {
            throw new ReglaDeNegocioException(
                'El descuento no puede ser negativo: eso sería un recargo, y el recargo por tarjeta '
                .'no lo calcula este sistema.'
            );
        }

        $tope = $this->topeDeDescuento($usuario);

        if ($porcentaje > $tope) {
            throw new ReglaDeNegocioException(
                "El descuento máximo que podés aplicar es del {$tope} %, y estás cargando {$porcentaje} %. "
                .'Un descuento mayor lo tiene que cargar alguien con autorización para hacerlo.'
            );
        }
    }

    /**
     * Una sola línea por producto, con la cantidad total.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function exigirProductosDistintos(array $lineas): void
    {
        $productos = array_column($lineas, 'producto_id');

        if (count($productos) !== count(array_unique($productos))) {
            throw new ReglaDeNegocioException(
                'La venta tiene el mismo producto cargado más de una vez. Dejá una sola línea '
                .'por producto, con la cantidad total.'
            );
        }
    }

    /**
     * La parte del dinero que corresponde a lo que vuelve, en una devolución parcial.
     *
     */
    private function parteProporcional(Venta $venta, float $subtotalDevuelto): float
    {
        $subtotal = (float) $venta->subtotal;

        if ($subtotal <= 0) {
            return 0.0;
        }

        $descuento = round((float) $venta->descuento * $subtotalDevuelto / $subtotal, 2);

        return round($subtotalDevuelto - $descuento, 2);
    }

    /**
     * El medio por el que vuelve la plata tiene que existir y estar operativo.
     *
     */
    private function exigirMedioDeDevolucion(string $metodo): void
    {
        if (! in_array($metodo, Pago::METODOS_EN_USO, true)) {
            $disponibles = collect(Pago::METODOS_EN_USO)
                ->map(fn (string $clave) => Pago::METODOS[$clave])
                ->implode(', ');

            throw new ReglaDeNegocioException(
                'Indicá por qué medio vuelve la plata. Los disponibles son: '.$disponibles.'.'
            );
        }
    }
}