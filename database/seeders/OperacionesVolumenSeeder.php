<?php

namespace Database\Seeders;

use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las operaciones a escala: órdenes de compra, ventas, cobros y kardex.
 */
class OperacionesVolumenSeeder extends Seeder
{
    /** Cuántos productos del catálogo tienen movimiento. */
    private const PRODUCTOS_CON_MOVIMIENTO = 4000;

    /**
     * Stock mínimo para que un producto entre en las operaciones.
     */
    private const STOCK_OPERABLE = 50;

    /** Una de cada seis operaciones es una compra. */
    private const UNA_COMPRA_CADA = 6;

    /** Operaciones entre dos volcados de las tandas a la base. */
    private const OPERACIONES_POR_VOLCADO = 250;

    /** @var list<array<string, mixed>> los productos operables, con su stock y costo corrientes */
    private array $productos = [];

    /** @var array<int, int> id de producto => su posición en $productos */
    private array $posiciones = [];

    /** @var array<int, list<int>> id de proveedor => posiciones de los productos que le compra */
    private array $porProveedor = [];

    /** @var list<int> */
    private array $proveedores = [];

    /** @var array<int, true> posiciones cuyo stock o costo cambió */
    private array $tocados = [];

    /** @var list<int> */
    private array $administrativos = [];

    /** @var list<int> */
    private array $vendedores = [];

    /** @var list<int> */
    private array $cajeros = [];

    /** @var list<int> */
    private array $clientes = [];

    private int $baseCompra;

    private int $baseVenta;

    private Carbon $inicio;

    private int $minutosDeVentana;

    /** @var array<string, list<array<string, mixed>>> filas pendientes de insertar, por tabla */
    private array $pendientes = [];

    public function run(array $cantidades): void
    {
        $this->preparar();
        $this->operar($cantidades['compras'], $cantidades['ventas']);
        $this->actualizarProductos();
        $this->resumen();
    }

    // ------------------------------------------------------------------
    // Preparación
    // ------------------------------------------------------------------

    private function preparar(): void
    {
        // Un día después del asiento de inventario inicial, que el seeder del
        // catálogo fecha al principio de la ventana.
        $this->inicio           = now()->subMonths(VolumenSeeder::MESES)->startOfDay()->addDay();
        $this->minutosDeVentana = (int) $this->inicio->diffInMinutes(now());

        $this->baseCompra = (int) (DB::table('ordenes_compra')->max('id') ?? 0);
        $this->baseVenta  = (int) (DB::table('ventas')->max('id') ?? 0);

        $this->administrativos = $this->operadores('Administrativo');
        $this->vendedores      = $this->operadores('Vendedor');
        $this->cajeros         = $this->operadores('Cajero');

        // Los clientes de volumen que tienen correo. El dominio los distingue sin
        // ambigüedad de los de la demostración, que un rango de documentos sorteados
        // no puede garantizar.
        $this->clientes = DB::table('clientes')
            ->where('email', 'like', '%@volumen.local')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->cargarProductos();
        $this->cargarProveedores();

        $this->pendientes = [
            'ordenes_compra'      => [],
            'orden_compra_lineas' => [],
            'ventas'              => [],
            'venta_lineas'        => [],
            'pagos'               => [],
            'movimientos_stock'   => [],
        ];
    }

    /**
     * Los usuarios de volumen de un rol.
     *
     * Filtra por el dominio del correo para no atribuirle miles de operaciones a los
     * empleados de la demostración, cuya ficha quedaría ilegible.
     *
     * @return list<int>
     */
    private function operadores(string $rol): array
    {
        return User::query()
            ->where('rol_id', Rol::where('nombre', $rol)->value('id'))
            ->where('email', 'like', '%@volumen.local')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function cargarProductos(): void
    {
        $this->productos = DB::table('productos')
            ->where('codigo', 'like', VolumenSeeder::PREFIJO.'%')
            ->where('stock', '>=', self::STOCK_OPERABLE)
            ->orderBy('id')
            ->limit(self::PRODUCTOS_CON_MOVIMIENTO)
            ->get(['id', 'nombre', 'precio_contado', 'alicuota_iva', 'costo_promedio', 'stock'])
            ->map(fn (object $producto) => [
                'id'       => (int) $producto->id,
                'nombre'   => $producto->nombre,
                'precio'   => (float) $producto->precio_contado,
                'alicuota' => (float) $producto->alicuota_iva,
                'costo'    => (float) $producto->costo_promedio,
                'stock'    => (int) $producto->stock,
            ])
            ->all();

        $this->posiciones = array_flip(array_column($this->productos, 'id'));
    }

    /**
     * Agrupa los productos operables por su proveedor preferido.
     *
     * Es la agrupación que hace el armador de pedido: una orden por proveedor, con
     * productos que ese proveedor vende. El código y el costo del vínculo se guardan
     * acá porque la línea de la orden los congela.
     */
    private function cargarProveedores(): void
    {
        $vinculos = DB::table('producto_proveedor')
            ->whereIn('producto_id', array_column($this->productos, 'id'))
            ->where('es_preferido', true)
            ->get(['producto_id', 'proveedor_id', 'codigo_proveedor', 'costo_ultimo']);

        foreach ($vinculos as $vinculo) {
            $posicion    = $this->posiciones[(int) $vinculo->producto_id];
            $proveedorId = (int) $vinculo->proveedor_id;

            $this->productos[$posicion]['codigo_proveedor'] = $vinculo->codigo_proveedor;
            $this->productos[$posicion]['costo_proveedor']  = $vinculo->costo_ultimo === null
                ? null
                : (float) $vinculo->costo_ultimo;

            $this->porProveedor[$proveedorId][] = $posicion;
        }

        $this->proveedores = array_keys($this->porProveedor);
    }

    // ------------------------------------------------------------------
    // El hilo cronológico
    // ------------------------------------------------------------------

    private function operar(int $compras, int $ventas): void
    {
        $total          = $compras + $ventas;
        $barra          = $this->command?->getOutput()->createProgressBar($total);
        $numeroDeCompra = 0;
        $numeroDeVenta  = 0;

        $barra?->start();

        for ($e = 0; $e < $total; $e++) {
            $momento = $this->momento($e, $total);

            $esCompra = $compras > 0
                && ($ventas === 0 || $e % self::UNA_COMPRA_CADA === 0);

            if ($esCompra) {
                $compras--;
                $this->compra(++$numeroDeCompra, $momento);
            } else {
                $ventas--;
                $this->venta(++$numeroDeVenta, $momento);
            }

            if ($e % self::OPERACIONES_POR_VOLCADO === self::OPERACIONES_POR_VOLCADO - 1) {
                $this->volcar();
                $barra?->advance(self::OPERACIONES_POR_VOLCADO);
            }
        }

        $this->volcar();
        $barra?->finish();
        $this->command?->newLine();
    }

    /**
     * La fecha de la operación número $e.
     *
     * Reparte las operaciones de forma uniforme en la ventana. Crece con $e, así que
     * el recorrido ya sale ordenado por fecha y no hace falta ordenar nada después.
     */
    private function momento(int $e, int $total): string
    {
        $minutos = intdiv($e * $this->minutosDeVentana, max($total, 1));

        return $this->inicio->copy()->addMinutes($minutos)->toDateTimeString();
    }

    // ------------------------------------------------------------------
    // Compras
    // ------------------------------------------------------------------

    /**
     * Una orden de compra con sus líneas, y su recepción si el estado la tuvo.
     *
     * El estado decide qué columnas se escriben: sin aprobación no hay aprobador ni
     * fecha, y sólo las recibidas mueven stock. Una de cada diez queda con
     * `usuario_creo_id` nulo, que es como quedan las que genera la reposición
     * automática.
     */
    private function compra(int $n, string $momento): void
    {
        if ($this->proveedores === []) {
            return;
        }

        $id          = $this->baseCompra + $n;
        $estado      = $this->estadoDeCompra($n);
        $proveedorId = $this->proveedores[$n % count($this->proveedores)];
        $posiciones  = $this->porProveedor[$proveedorId];
        $cuantas     = min(2 + $n % 4, count($posiciones));

        $aprobada = $estado !== 'borrador' && $estado !== 'cancelada';
        $enviada  = in_array($estado, ['enviada', 'recibida_parcial', 'recibida'], true);
        $total    = 0.0;

        for ($k = 0; $k < $cuantas; $k++) {
            // Posiciones consecutivas del mismo proveedor: nunca repiten producto
            // dentro de la orden, que es lo que exige el UNIQUE de la línea.
            $posicion = $posiciones[($n * 3 + $k) % count($posiciones)];
            $producto = $this->productos[$posicion];

            $cantidad = 5 + ($n + $k) % 20;
            $costo    = $producto['costo_proveedor'] ?? $producto['costo'];
            $recibida = $this->cantidadRecibida($estado, $cantidad, $k);

            $total += round($cantidad * $costo, 2);

            $this->pendientes['orden_compra_lineas'][] = [
                'orden_compra_id'   => $id,
                'producto_id'       => $producto['id'],
                'codigo_proveedor'  => $producto['codigo_proveedor'] ?? null,
                'cantidad_pedida'   => $cantidad,
                'cantidad_recibida' => $recibida,
                'costo_unitario'    => $costo,
                'created_at'        => $momento,
                'updated_at'        => $momento,
            ];

            if ($recibida > 0) {
                $this->recibir($posicion, $recibida, $costo, $id, $momento);
            }
        }

        $this->pendientes['ordenes_compra'][] = [
            'id'                => $id,
            'proveedor_id'      => $proveedorId,
            'estado'            => $estado,
            'total_estimado'    => round($total, 2),
            'usuario_creo_id'   => $n % 10 === 0
                                       ? null
                                       : $this->administrativos[$n % max(count($this->administrativos), 1)] ?? null,
            'usuario_aprobo_id' => $aprobada
                                       ? ($this->administrativos[$n % max(count($this->administrativos), 1)] ?? null)
                                       : null,
            'fecha_aprobacion'  => $aprobada ? $momento : null,
            'fecha_envio'       => $enviada ? $momento : null,
            'observaciones'     => null,
            'created_at'        => $momento,
            'updated_at'        => $momento,
        ];
    }

    private function estadoDeCompra(int $n): string
    {
        return match (true) {
            $n % 14 === 0 => 'cancelada',
            $n % 11 === 0 => 'borrador',
            $n % 9  === 0 => 'aprobada',
            $n % 7  === 0 => 'enviada',
            $n % 5  === 0 => 'recibida_parcial',
            default       => 'recibida',
        };
    }

    /** Lo recibido de una línea: todo si la orden se recibió, la mitad de la primera si fue parcial. */
    private function cantidadRecibida(string $estado, int $cantidad, int $k): int
    {
        return match (true) {
            $estado === 'recibida'                     => $cantidad,
            $estado === 'recibida_parcial' && $k === 0 => intdiv($cantidad, 2),
            $estado === 'recibida_parcial'             => $cantidad,
            default                                    => 0,
        };
    }

    /**
     * La entrada de mercadería: asiento de kardex y costo promedio ponderado.
     *
     * El promedio se recalcula con la misma fórmula que `StockService::recibirCompra()`
     * —`(stock × costo + cantidad × costo_compra) / (stock + cantidad)`— porque de él
     * sale el costo que cada venta posterior congela, y con él el margen del panel.
     */
    private function recibir(int $posicion, int $cantidad, float $costo, int $ordenId, string $momento): void
    {
        $producto = $this->productos[$posicion];
        $stock    = $producto['stock'] + $cantidad;

        $this->productos[$posicion]['stock'] = $stock;
        $this->productos[$posicion]['costo'] = round(
            (($producto['stock'] * $producto['costo']) + ($cantidad * $costo)) / ($producto['stock'] + $cantidad),
            2,
        );

        $this->tocados[$posicion] = true;

        $this->asiento('compra', $posicion, $cantidad, $stock, OrdenCompra::class, $ordenId, $momento);
    }

    // ------------------------------------------------------------------
    // Ventas
    // ------------------------------------------------------------------

    /**
     * Una venta con sus líneas y, si llegó a cobrarse, sus pagos y su kardex.
     *
     * El stock sale al cobrar y no al emitir: un presupuesto no compromete
     * mercadería, así que sus líneas se escriben sin tocar ninguna columna de stock.
     */
    private function venta(int $n, string $momento): void
    {
        $id       = $this->baseVenta + $n;
        $estado   = $this->estadoDeVenta($n);
        $cobrada  = in_array($estado, ['pagada', 'entregada', 'devuelta'], true);
        $devuelta = $estado === 'devuelta';

        // El descuento se saltea en las devueltas para que el contra-asiento sea
        // exactamente menos el total, sin repartir el descuento por línea.
        $porcentaje = ! $devuelta && $n % 5 === 0 ? 8.0 : 0.0;

        $subtotal = 0.0;
        $lineas   = [];

        for ($k = 0; $k < 1 + $n % 4; $k++) {
            $posicion = ($n * 7 + $k * 613) % count($this->productos);
            $producto = $this->productos[$posicion];

            $cantidad = 1 + ($n + $k) % 4;

            if ($cobrada) {
                // Red de contención: el stock operable arranca en 50 y las compras lo
                // reponen, así que esto no debería recortar nunca. Si recortara, es
                // preferible a escribir un stock negativo.
                $cantidad = min($cantidad, $this->productos[$posicion]['stock']);

                if ($cantidad === 0) {
                    continue;
                }
            }

            $totalLinea = round($cantidad * $producto['precio'], 2);
            $neto       = round($totalLinea / (1 + ($producto['alicuota'] / 100)), 2);

            $subtotal += $totalLinea;

            $lineas[] = [
                'venta_id'          => $id,
                'producto_id'       => $producto['id'],
                'descripcion'       => $producto['nombre'],
                'cantidad'          => $cantidad,
                'cantidad_devuelta' => $devuelta ? $cantidad : 0,
                'cantidad_entregada' => null,
                'precio_unitario'   => $producto['precio'],
                'alicuota_iva'      => $producto['alicuota'],
                'costo_unitario'    => $producto['costo'],
                'neto'              => $neto,
                'iva'               => round($totalLinea - $neto, 2),
                'total'             => $totalLinea,
                'created_at'        => $momento,
                'updated_at'        => $momento,
            ];

            if ($cobrada) {
                $this->descontar($posicion, $cantidad, $id, $momento);
            }

            if ($devuelta) {
                $this->reponer($posicion, $cantidad, $id, $momento);
            }
        }

        if ($lineas === []) {
            return;
        }

        $descuento = round($subtotal * $porcentaje / 100, 2);
        $total     = round($subtotal - $descuento, 2);

        $this->pendientes['ventas'][] = [
            'id'            => $id,
            'canal'         => 'mostrador',
            'cliente_id'    => $n % 5 < 2 ? null : $this->clientes[$n % max(count($this->clientes), 1)] ?? null,
            'usuario_id'    => $this->vendedores[$n % max(count($this->vendedores), 1)] ?? null,
            'estado'        => $estado,
            'modo_entrega'  => 'retiro',
            'subtotal'      => round($subtotal, 2),
            'descuento'     => $descuento,
            'costo_envio'   => 0,
            'total'         => $total,
            'observaciones' => null,
            'created_at'    => $momento,
            'updated_at'    => $momento,
        ];

        foreach ($lineas as $linea) {
            $this->pendientes['venta_lineas'][] = $linea;
        }

        if ($cobrada) {
            $this->cobrar($n, $id, $total, $momento, $devuelta);
        }
    }

    private function estadoDeVenta(int $n): string
    {
        return match (true) {
            $n % 25 === 0 => 'cancelada',
            $n % 29 === 0 => 'devuelta',
            $n % 9  === 0 => 'presupuesto',
            $n % 7  === 0 => 'pagada',
            default       => 'entregada',
        };
    }

    /**
     * Los pagos de la venta, y el contra-asiento si se devolvió.
     *
     * Una de cada siete se cobra con dos medios: es el caso que el panel agrupa por
     * método y el que hace que `pagos` no tenga una fila por venta.
     */
    private function cobrar(int $n, int $ventaId, float $total, string $momento, bool $devuelta): void
    {
        $cajero = $this->cajeros[$n % max(count($this->cajeros), 1)] ?? null;

        if ($n % 7 === 0) {
            $mitad = round($total / 2, 2);

            $this->pago($ventaId, Pago::METODOS_EN_USO[$n % 3], $mitad, $cajero, $momento);
            $this->pago($ventaId, Pago::METODOS_EN_USO[($n + 1) % 3], round($total - $mitad, 2), $cajero, $momento);
        } else {
            $this->pago($ventaId, Pago::METODOS_EN_USO[$n % 3], $total, $cajero, $momento);
        }

        if ($devuelta) {
            // Monto negativo: la devolución no borra el cobro, escribe el hecho
            // siguiente. Es la fila que hace que «cobrado» descuente devoluciones y
            // «facturado» no.
            $this->pago(
                $ventaId,
                Pago::METODOS_EN_USO[$n % 3],
                -$total,
                $cajero,
                Carbon::parse($momento)->addMinute()->toDateTimeString(),
            );
        }
    }

    private function pago(int $ventaId, string $metodo, float $monto, ?int $cajero, string $fecha): void
    {
        $this->pendientes['pagos'][] = [
            'venta_id'        => $ventaId,
            'metodo'          => $metodo,
            'monto'           => $monto,
            'comision'        => null,
            'neto_acreditado' => null,
            'cuotas'          => null,
            'mp_payment_id'   => null,
            'mp_status'       => null,
            'usuario_id'      => $cajero,
            'fecha'           => $fecha,
            'created_at'      => $fecha,
            'updated_at'      => $fecha,
        ];
    }

    private function descontar(int $posicion, int $cantidad, int $ventaId, string $momento): void
    {
        $stock = $this->productos[$posicion]['stock'] - $cantidad;

        $this->productos[$posicion]['stock'] = $stock;
        $this->tocados[$posicion]            = true;

        $this->asiento('venta', $posicion, -$cantidad, $stock, Venta::class, $ventaId, $momento);
    }

    private function reponer(int $posicion, int $cantidad, int $ventaId, string $momento): void
    {
        $stock = $this->productos[$posicion]['stock'] + $cantidad;

        $this->productos[$posicion]['stock'] = $stock;
        $this->tocados[$posicion]            = true;

        $this->asiento(
            'devolucion',
            $posicion,
            $cantidad,
            $stock,
            Venta::class,
            $ventaId,
            Carbon::parse($momento)->addMinute()->toDateTimeString(),
        );
    }

    // ------------------------------------------------------------------
    // Kardex y volcado
    // ------------------------------------------------------------------

    /** Un asiento de kardex, con la misma forma que escribe StockService::registrar(). */
    private function asiento(
        string $tipo,
        int $posicion,
        int $cantidad,
        int $stockResultante,
        string $origenType,
        int $origenId,
        string $momento,
    ): void {
        $this->pendientes['movimientos_stock'][] = [
            'producto_id'      => $this->productos[$posicion]['id'],
            'tipo'             => $tipo,
            'cantidad'         => $cantidad,
            'stock_resultante' => $stockResultante,
            'origen_type'      => $origenType,
            'origen_id'        => $origenId,
            'usuario_id'       => null,
            'motivo'           => null,
            'created_at'       => $momento,
            'updated_at'       => $momento,
        ];
    }

    /**
     * Vuelca las filas pendientes.
     *
     * El orden de las tablas no es casual: cada padre se inserta antes que sus
     * hijos, o la clave foránea rechaza la tanda.
     */
    private function volcar(): void
    {
        foreach ($this->pendientes as $tabla => $filas) {
            foreach (array_chunk($filas, VolumenSeeder::TANDA) as $tanda) {
                DB::table($tabla)->insert($tanda);
            }

            $this->pendientes[$tabla] = [];
        }
    }

    /**
     * Escribe el stock y el costo promedio que quedaron después de todo.
     *
     * Una sola transacción para las cuatro mil filas: con un commit por UPDATE esto
     * tarda un orden de magnitud más. No se usa una sentencia a mano con CASE porque
     * el proyecto no escribe SQL a mano.
     */
    private function actualizarProductos(): void
    {
        DB::transaction(function () {
            foreach (array_keys($this->tocados) as $posicion) {
                DB::table('productos')
                    ->where('id', $this->productos[$posicion]['id'])
                    ->update([
                        'stock'          => $this->productos[$posicion]['stock'],
                        'costo_promedio' => $this->productos[$posicion]['costo'],
                    ]);
            }
        });
    }

    private function resumen(): void
    {
        $this->command?->table(
            ['Tabla', 'Filas'],
            collect(['ordenes_compra', 'orden_compra_lineas', 'ventas', 'venta_lineas', 'pagos', 'movimientos_stock'])
                ->map(fn (string $tabla) => [$tabla, number_format(DB::table($tabla)->count(), 0, ',', '.')])
                ->all(),
        );

        // El conteo de críticos usa el scope del modelo y no una condición repetida:
        // si «crítico» se definiera acá otra vez, el seeder podría informar un número
        // distinto del que muestra el panel sobre los mismos datos.
        $this->command?->info(sprintf(
            'Productos con movimiento: %s. Órdenes abiertas: %s. Productos en stock crítico: %s.',
            number_format(count($this->tocados), 0, ',', '.'),
            number_format(OrdenCompra::whereIn('estado', OrdenCompra::ESTADOS_ABIERTOS)->count(), 0, ',', '.'),
            number_format(Producto::query()->where('activo', true)->stockCritico()->count(), 0, ',', '.'),
        ));
    }
}