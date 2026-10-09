<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\MovimientoStock;
use App\Models\OrdenCompra;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CompraService;
use App\Services\PagoService;
use App\Services\ProductoService;
use App\Services\StockService;
use App\Services\VentaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Operaciones de demostración: compras, stock, ventas, cobros y devoluciones.
 *
 * **Todo pasa por los servicios, nada se escribe a mano**, y es la decisión que
 * sostiene al seeder entero: una venta `pagada` escrita directo no tiene pagos ni
 * movimiento de kardex, y una orden `recibida` escrita directo no movió stock ni
 * recalculó el costo promedio. Serían filas que el sistema no puede producir, y la
 * demostración mostraría pantallas con datos imposibles. Es el mismo motivo por el que
 * `VentaFactory::pagada()` avisa que no sirve para probar stock ni plata.
 *
 * **El reloj se mueve con `Carbon::setTestNow()`** para repartir las operaciones en los
 * últimos dos meses y medio. Sin eso, todo tendría la fecha de la siembra: el gráfico
 * del panel sería un solo punto, el filtro de período no tendría nada que filtrar y el
 * kardex no contaría ninguna historia. Las operaciones se escriben en el mismo orden en
 * que ocurrieron, así que el `stock_resultante` de cada asiento es coherente con su
 * fecha. El `finally` devuelve el reloj a su lugar pase lo que pase.
 */
class OperacionesDemoSeeder extends Seeder
{
    private Carbon $hoy;

    public function run(): void
    {
        if (Venta::exists() || OrdenCompra::exists()) {
            $this->command?->warn(
                'Ya hay compras o ventas cargadas: este seeder no se ejecuta dos veces. '
                .'Para rearmar la demostración, corré php artisan migrate:fresh --seed.'
            );

            return;
        }

        $this->hoy = Carbon::today();

        try {
            $this->compras();
            $this->ventas();
            $this->bajaLogica();
            $this->ajusteDeInventario();
            $this->reposicionAutomatica();
        } finally {
            Carbon::setTestNow();
        }

        $this->resumen();
    }

    // ------------------------------------------------------------------
    // Compras: las seis situaciones que la pantalla tiene que poder mostrar
    // ------------------------------------------------------------------

    private function compras(): void
    {
        $compras = app(CompraService::class);
        $quien   = $this->usuario('administrativo@sistema.local');

        $austral   = $this->proveedor('Distribuidora Austral S.A.');
        $sur       = $this->proveedor('Insumos del Sur S.R.L.');
        $patagonia = $this->proveedor('Tecno Patagonia');

        // 1. El ciclo completo. Es la que trae el stock de los dos productos que el
        //    catálogo deja en cero, así que tiene que recibirse antes de las ventas.
        $this->en(70);
        $completa = $compras->crearBorrador([
            'proveedor_id'  => $austral->id,
            'observaciones' => 'Reposición de temporada.',
            'lineas'        => [
                $this->lineaDeCompra('HX-STINGER', 10, 27000),
                $this->lineaDeCompra('COR-4000D', 6, 48000),
                $this->lineaDeCompra('EVGA-600', 10, 26000),
            ],
        ], $quien);

        $this->en(69);
        $compras->aprobar($completa, $quien);
        $compras->marcarEnviada($completa->fresh());

        $this->en(63);
        $compras->recibir($completa->fresh('lineas'), $this->loPendiente($completa), $quien);

        // 2. Recepción parcial: llegaron los discos y dos de los cinco monitores. La
        //    orden queda abierta y la pantalla de recepción tiene algo que terminar
        //    durante la demostración.
        $this->en(68);
        $parcial = $compras->crearBorrador([
            'proveedor_id' => $sur->id,
            'lineas'       => [
                $this->lineaDeCompra('VG248QG', 5, 290000),
                $this->lineaDeCompra('SSD-WD1TB', 10, 57000),
            ],
        ], $quien);

        $this->en(67);
        $compras->aprobar($parcial, $quien);
        $compras->marcarEnviada($parcial->fresh());

        $this->en(55);
        $porCodigo = $parcial->fresh('lineas.producto')->lineas
            ->keyBy(fn ($linea) => $linea->producto->codigo);

        $compras->recibir($parcial->fresh('lineas'), [
            $porCodigo['VG248QG']->id   => 2,
            $porCodigo['SSD-WD1TB']->id => 10,
        ], $quien);

        // 3. Aprobada y sin enviar: el botón de «marcar enviada» necesita un sujeto.
        $this->en(45);
        $aprobada = $compras->crearBorrador([
            'proveedor_id' => $patagonia->id,
            'lineas'       => [
                $this->lineaDeCompra('HDD-SEA1TB', 15, 18000),
                $this->lineaDeCompra('RAM-KVR16', 12, 46000),
            ],
        ], $quien);

        $this->en(44);
        $compras->aprobar($aprobada, $quien);

        // 4. Enviada y esperando mercadería.
        $this->en(42);
        $enviada = $compras->crearBorrador([
            'proveedor_id' => $austral->id,
            'lineas'       => [
                $this->lineaDeCompra('MB-ASUSB450', 8, 36000),
                $this->lineaDeCompra('HP-2775', 5, 52000),
            ],
        ], $quien);

        $this->en(41);
        $compras->aprobar($enviada, $quien);
        $compras->marcarEnviada($enviada->fresh());

        // 5. Cancelada antes de aprobarse. Sin fecha de aprobación no tiene PDF:
        //    nunca salió, así que no hay papel que mostrarle a nadie.
        $this->en(40);
        $cancelada = $compras->crearBorrador([
            'proveedor_id'  => $patagonia->id,
            'observaciones' => 'Se consigue más barato en otro proveedor.',
            'lineas'        => [$this->lineaDeCompra('MIC-K669B', 10, 17000)],
        ], $quien);

        $this->en(39);
        $compras->cancelar($cancelada);
    }

    // ------------------------------------------------------------------
    // Ventas: los seis estados alcanzables, los tres medios de cobro y los
    // dos vendedores, repartidos en el tiempo
    // ------------------------------------------------------------------

    private function ventas(): void
    {
        $ventas = app(VentaService::class);

        $sofia  = $this->usuario('vendedor@sistema.local');
        $martin = $this->usuario('vendedor2@sistema.local');
        $caja   = $this->usuario('cajero@sistema.local');
        $admin  = $this->administrador();

        $estudio = Cliente::where('razon_social', 'Estudio Contable Austral S.R.L.')->firstOrFail();
        $camila  = Cliente::where('email', 'cliente@sistema.local')->firstOrFail();

        // Los de mostrador, para que no sean todas a consumidor final.
        $mostrador = Cliente::query()
            ->whereNull('user_id')
            ->where('id', '!=', $estudio->id)
            ->orderBy('id')
            ->take(4)
            ->get();

        // --- Entregadas, el caso normal -------------------------------------

        $venta = $this->venta(40, $sofia, null, ['G505' => 2, 'MIC-K669B' => 1]);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 40);
        $this->entregar($venta, 40);

        $venta = $this->venta(32, $martin, $estudio, ['SSD-WD1TB' => 3, 'RAM-KVR16' => 2]);
        $this->cobrar($venta, ['transferencia' => $venta->total], $caja, 32);
        $this->entregar($venta, 31);

        $venta = $this->venta(28, $sofia, $mostrador[0], ['HX-STINGER' => 2]);
        $this->cobrar($venta, ['qr' => $venta->total], $caja, 28);
        $this->entregar($venta, 28);

        $venta = $this->venta(25, $martin, null, ['COR-4000D' => 1, 'EVGA-600' => 1]);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 25);
        $this->entregar($venta, 25);

        // --- Devuelta por completo ------------------------------------------

        $venta = $this->venta(21, $sofia, $camila, ['HP-2775' => 1]);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 21);
        $this->entregar($venta, 21);

        $this->en(19);
        $ventas->devolver($venta->fresh('lineas'), [
            'cantidades' => [$venta->fresh('lineas')->lineas->first()->id => 1],
            'metodo'     => 'efectivo',
        ], $caja);

        // --- Cobrada con dos medios -----------------------------------------

        $venta = $this->venta(18, $martin, null, ['MIC-K669B' => 4, 'G505' => 3]);
        $mitad = round((float) $venta->total / 2, 2);
        $this->cobrar($venta, [
            'efectivo'      => $mitad,
            'transferencia' => round((float) $venta->total - $mitad, 2),
        ], $caja, 18);
        $this->entregar($venta, 18);

        // --- Devuelta parcial: el cliente devolvió una de las dos placas -----

        $venta = $this->venta(14, $sofia, $mostrador[1], ['MB-ASUSB450' => 2]);
        $this->cobrar($venta, ['transferencia' => $venta->total], $caja, 14);
        $this->entregar($venta, 14);

        $this->en(12);
        $ventas->devolver($venta->fresh('lineas'), [
            'cantidades' => [$venta->fresh('lineas')->lineas->first()->id => 1],
            // El medio lo elige quien devuelve: entró por transferencia y la plata
            // vuelve en efectivo de la caja.
            'metodo'     => 'efectivo',
        ], $caja);

        // --- Con descuento autorizado: sólo el Administrador llega al 20 % ---

        $venta = $this->venta(10, $admin, $estudio, ['VG248QG' => 1], descuento: 20);
        $this->cobrar($venta, ['transferencia' => $venta->total], $caja, 10);
        $this->entregar($venta, 9);

        // --- Las últimas, para que el panel del mes en curso tenga datos -----

        $venta = $this->venta(7, $sofia, null, ['MIC-K669B' => 6]);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 7);
        $this->entregar($venta, 7);

        $venta = $this->venta(5, $martin, $mostrador[2], ['HX-STINGER' => 4]);
        $this->cobrar($venta, ['qr' => $venta->total], $caja, 5);
        $this->entregar($venta, 5);

        // Con el tope general de descuento: es hasta donde llega un vendedor.
        $venta = $this->venta(3, $sofia, null, ['G505' => 4, 'MIC-K669B' => 3], descuento: 8);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 3);
        $this->entregar($venta, 3);

        // Cobradas y sin entregar: el botón de entregar necesita un sujeto.
        $venta = $this->venta(2, $martin, $camila, ['SSD-WD1TB' => 2]);
        $this->cobrar($venta, ['transferencia' => $venta->total], $caja, 2);

        $venta = $this->venta(1, $sofia, null, ['MIC-K669B' => 3, 'EVGA-600' => 2]);
        $this->cobrar($venta, ['efectivo' => $venta->total], $caja, 1);

        // --- Presupuestos: uno cancelado y dos abiertos ----------------------

        $cancelado = $this->venta(6, $sofia, null, ['HP-2775' => 1],
            observaciones: 'El cliente lo iba a pensar.');

        $this->en(6, 15);
        $ventas->cancelar($cancelado->fresh());

        $this->venta(1, $martin, $mostrador[3], ['HDD-SEA1TB' => 2],
            observaciones: 'Pasa a buscarlo el viernes.');

        $this->venta(0, $sofia, $estudio, ['RAM-KVR16' => 4, 'MB-ASUSB450' => 1],
            observaciones: 'Esperando la orden de compra del estudio.');
    }

    // ------------------------------------------------------------------
    // Una baja lógica, para poder mostrar M-16 en vivo
    // ------------------------------------------------------------------

    private function bajaLogica(): void
    {
        // El gabinete sale del catálogo, pero tiene una venta: el servicio lo
        // desactiva en lugar de borrarlo, así que deja de ofrecerse y la venta de hace
        // un mes sigue completa. `eliminar()` devuelve false justamente cuando pasó
        // esto, y el mensaje de la pantalla lo dice con palabras.
        $this->en(2, 9);

        app(ProductoService::class)->eliminar($this->producto('COR-4000D'));
    }

    // ------------------------------------------------------------------
    // Un ajuste de inventario, para que el kardex tenga también ese tipo
    // ------------------------------------------------------------------

    private function ajusteDeInventario(): void
    {
        // Va fechado hoy y no en el pasado: se escribe después de todas las ventas, y
        // un asiento de kardex con fecha anterior a otro que lo precede dejaría la
        // columna `stock_resultante` contando una historia que no cierra.
        $this->en(0, 16);

        $producto = $this->producto('G505');

        app(StockService::class)->ajustar(
            $producto,
            $producto->stock - 2,
            'Faltante detectado en el conteo de góndola.',
            $this->usuario('administrativo@sistema.local'),
        );
    }

    // ------------------------------------------------------------------
    // La reposición automática, corrida de verdad
    // ------------------------------------------------------------------

    private function reposicionAutomatica(): void
    {
        // Se llama al comando en lugar de escribir el borrador a mano: así el que la
        // demostración muestra es el que produce el automatismo, con `usuario_creo_id`
        // en null, y de paso queda a la vista que no duplica pedidos — los productos
        // que ya están en una orden abierta no vuelven a pedirse.
        $this->en(0, 18);

        Artisan::call('compras:generar-reposicion');
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /** Mueve el reloj, para que la operación quede fechada ahí. */
    private function en(int $diasAtras, int $hora = 10): void
    {
        Carbon::setTestNow(
            $this->hoy->copy()->subDays($diasAtras)->setTime($hora, random_int(0, 59))
        );
    }

    private function producto(string $codigo): Producto
    {
        return Producto::where('codigo', $codigo)->firstOrFail();
    }

    private function proveedor(string $razonSocial): Proveedor
    {
        return Proveedor::where('razon_social', $razonSocial)->firstOrFail();
    }

    private function usuario(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** El administrador, por su rol y no por su correo: el correo sale del .env. */
    private function administrador(): User
    {
        return User::where('rol_id', Rol::where('nombre', 'Administrador')->value('id'))
            ->orderBy('id')
            ->firstOrFail();
    }

    /** @return array{producto_id: int, cantidad_pedida: int, costo_unitario: float} */
    private function lineaDeCompra(string $codigo, int $cantidad, float $costo): array
    {
        return [
            'producto_id'     => $this->producto($codigo)->id,
            'cantidad_pedida' => $cantidad,
            'costo_unitario'  => $costo,
        ];
    }

    /** Lo que falta recibir de cada línea, para recibir la orden entera. */
    private function loPendiente(OrdenCompra $orden): array
    {
        return $orden->fresh('lineas')->lineas
            ->mapWithKeys(fn ($linea) => [$linea->id => $linea->cantidadPendiente()])
            ->all();
    }

    /** @param  array<string, int>  $lineas  código del producto => cantidad */
    private function venta(
        int $diasAtras,
        User $vendedor,
        ?Cliente $cliente,
        array $lineas,
        float $descuento = 0,
        ?string $observaciones = null,
    ): Venta {
        $this->en($diasAtras);

        return app(VentaService::class)->crear([
            'cliente_id'           => $cliente?->id,
            'descuento_porcentaje' => $descuento,
            'observaciones'        => $observaciones,
            'lineas'               => collect($lineas)
                ->map(fn (int $cantidad, string $codigo) => [
                    'producto_id' => $this->producto($codigo)->id,
                    'cantidad'    => $cantidad,
                ])
                ->values()
                ->all(),
        ], $vendedor);
    }

    /** @param  array<string, float|string>  $medios  método => monto; suman el total */
    private function cobrar(Venta $venta, array $medios, User $cajero, int $diasAtras): void
    {
        $this->en($diasAtras, 12);

        app(PagoService::class)->cobrar($venta, [
            'pagos' => collect($medios)
                ->map(fn (float|string $monto, string $metodo) => [
                    'metodo' => $metodo,
                    'monto'  => $monto,
                ])
                ->values()
                ->all(),
        ], $cajero);
    }

    private function entregar(Venta $venta, int $diasAtras): void
    {
        $this->en($diasAtras, 17);

        app(VentaService::class)->entregar($venta->fresh());
    }

    private function resumen(): void
    {
        $this->command?->info(sprintf(
            'Demostración cargada: %d órdenes de compra, %d ventas, %d pagos y %d movimientos de stock.',
            OrdenCompra::count(),
            Venta::count(),
            Pago::count(),
            MovimientoStock::count(),
        ));

        $criticos = Producto::query()->where('activo', true)->stockCritico()->count();

        if ($criticos > 0) {
            $this->command?->warn(
                "Quedaron {$criticos} producto(s) en stock crítico, con su borrador de reposición "
                .'generado por el sistema esperando aprobación.'
            );
        }
    }
}