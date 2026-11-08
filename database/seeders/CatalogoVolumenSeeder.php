<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Rol;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo a escala: categorías, marcas, proveedores y productos.
 */
class CatalogoVolumenSeeder extends Seeder
{
    /** Rama => familia => variantes. Lenguaje del negocio, como el resto del sistema. */
    private const ARBOL = [
        'Redes' => [
            'Switches' => ['Gestionables', 'No gestionables', 'PoE'],
            'Routers'  => ['Hogar', 'Empresariales', 'Mesh'],
            'Cableado' => ['Patch cord', 'Cable por metro', 'Fichas y conectores'],
        ],
        'Energía' => [
            'UPS'             => ['Hasta 1 kVA', 'De 1 a 3 kVA', 'Más de 3 kVA'],
            'Estabilizadores' => ['Monofásicos', 'Con supresor', 'De rack'],
            'Cables de poder' => ['Interlock', 'Zapatillas', 'Prolongadores'],
        ],
        'Notebooks y tablets' => [
            'Notebooks' => ['Línea hogar', 'Línea empresa', 'Línea gamer'],
            'Tablets'   => ['Hasta 10 pulgadas', 'Más de 10 pulgadas', 'Con teclado'],
            'Repuestos' => ['Baterías', 'Cargadores', 'Teclados de repuesto'],
        ],
        'Insumos de oficina' => [
            'Cartuchos' => ['Negro', 'Color', 'Alto rendimiento'],
            'Tóner'     => ['Monocromo', 'Color', 'Compatibles'],
            'Papel'     => ['Resmas A4', 'Resmas oficio', 'Rollos para ticket'],
        ],
    ];

    /** Peso por omisión de cada rama, que la hoja hereda (requerimiento 42). */
    private const PESOS = [
        'Redes'               => 500,
        'Energía'             => 6000,
        'Notebooks y tablets' => 2200,
        'Insumos de oficina'  => 900,
    ];

    /**
     * Treinta marcas, ninguna de las diez de la demostración.
     */
    private const MARCAS = [
        'TP-Link', 'Mercusys', 'Noganet', 'Nisuta', 'Trendnet', 'Ubiquiti',
        'Mikrotik', 'D-Link', 'Intelbras', 'APC', 'Forza', 'TRV',
        'Atomlux', 'Lyonn', 'Enerbox', 'Lenovo', 'Acer', 'Dell',
        'Samsung', 'Lexmark', 'Brother', 'Epson', 'Canon', 'Xerox',
        'Pelikan', 'Ledesma', 'Rhein', 'Boligoma', 'Exo', 'BGH',
    ];


    private const RUBROS   = ['Distribuidora', 'Importadora', 'Mayorista', 'Comercial', 'Insumos', 'Tecno'];
    private const SUFIJOS  = ['S.A.', 'S.R.L.'];
    private const NUCLEOS  = [
        'Comodoro', 'Trelew', 'Rawson', 'Madryn', 'Esquel', 'Pampa', 'Chubut',
        'Deseado', 'Perito Moreno', 'Caleta', 'Sarmiento', 'Gaiman', 'Dolavon',
        'Camarones', 'Rada Tilly', 'Patagónica', 'Atlántica', 'Andina',
        'Cordillera', 'Meseta', 'Golfo', 'Valle', 'Río Chico', 'Litoral', 'Sur',
    ];

    private int $usuarioId;

    private string $ahora;

    /** Fecha del asiento de inventario inicial: antes que cualquier operación. */
    private string $inicioDelInventario;

    public function run(array $cantidades): void
    {
        $this->ahora               = now()->toDateTimeString();
        $this->inicioDelInventario = now()->subMonths(VolumenSeeder::MESES)->startOfDay()->toDateTimeString();
        $this->usuarioId           = $this->administrador();

        $hojas       = $this->categorias();
        $marcas      = $this->marcas();
        $proveedores = $this->proveedores($cantidades['proveedores']);

        $this->command?->info(sprintf(
            'Base del catálogo: %d categorías (%d hojas), %d marcas, %d proveedores.',
            DB::table('categorias')->count(), count($hojas), count($marcas), count($proveedores),
        ));

        $this->productos($cantidades['productos'], $hojas, $marcas, $proveedores);
        $this->resumen();
    }

    // ------------------------------------------------------------------
    // Categorías, marcas y proveedores
    // ------------------------------------------------------------------

    /** @return list<array{id: int, familia: string, variante: string}> las hojas */
    private function categorias(): array
    {
        $hojas = [];


        $ordenRama = DB::table('categorias')->whereNull('parent_id')->count();

        foreach (self::ARBOL as $rama => $familias) {
            $peso   = self::PESOS[$rama];
            $ramaId = $this->categoria(null, $rama, $ordenRama++, $peso);

            $ordenFamilia = 0;

            foreach ($familias as $familia => $variantes) {
                $familiaId = $this->categoria($ramaId, $familia, $ordenFamilia++, $peso);

                foreach (array_values($variantes) as $ordenVariante => $variante) {
                    $hojas[] = [
                        'id'       => $this->categoria($familiaId, $variante, $ordenVariante, $peso),
                        'familia'  => $familia,
                        'variante' => $variante,
                    ];
                }
            }
        }

        return $hojas;
    }

    /**
     * Una categoría, con su slug resuelto por el helper del proyecto.
     */
    private function categoria(?int $parentId, string $nombre, int $orden, int $peso): int
    {
        return DB::table('categorias')->insertGetId([
            'parent_id'           => $parentId,
            'nombre'              => $nombre,
            'slug'                => Slug::unicoPara(Categoria::class, $nombre),
            'orden'               => $orden,
            'peso_default_gramos' => $peso,
            'activo'              => true,
            'created_at'          => $this->ahora,
            'updated_at'          => $this->ahora,
        ]);
    }

    /** @return list<array{id: int, nombre: string}> */
    private function marcas(): array
    {
        $filas = [];

        foreach (self::MARCAS as $nombre) {
            $filas[] = [
                'nombre'     => $nombre,
                'slug'       => Slug::unicoPara(Marca::class, $nombre),
                'logo'       => null,
                'activo'     => true,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ];
        }

        DB::table('marcas')->insert($filas);

        return DB::table('marcas')
            ->whereIn('nombre', self::MARCAS)
            ->orderBy('id')
            ->get(['id', 'nombre'])
            ->map(fn (object $marca) => ['id' => (int) $marca->id, 'nombre' => $marca->nombre])
            ->all();
    }

    /** @return list<int> los ids, para repartirlos entre los productos */
    private function proveedores(int $cantidad): array
    {
        $filas = [];
        $cuits = [];

        foreach (range(1, $cantidad) as $i) {
            $posicion = $i - 1;


            $cuit    = '309'.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            $cuits[] = $cuit;

            $canal = match ($i % 3) {
                0       => 'email',
                1       => 'portal_externo',
                default => 'manual',
            };

            $filas[] = [
                'razon_social' => sprintf(
                    '%s %s %s',
                    self::RUBROS[$posicion % count(self::RUBROS)],
                    self::NUCLEOS[intdiv($posicion, count(self::RUBROS)) % count(self::NUCLEOS)],
                    self::SUFIJOS[intdiv($posicion, 150) % count(self::SUFIJOS)],
                ),
                'cuit'     => $cuit,
                'email'    => "pedidos{$i}@volumen.local",
                'telefono' => '297-4'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'contacto' => 'Mesa de pedidos',


                'canal_pedido' => $canal,
                'portal_url'   => $canal === 'portal_externo'
                                      ? "https://pedidos.proveedor{$i}.volumen.local"
                                      : null,

                'plazo_entrega_dias' => 2 + $i % 20,

                'activo'     => $i % 40 !== 0,
                'created_at' => $this->ahora,
                'updated_at' => $this->ahora,
            ];
        }

        foreach (array_chunk($filas, VolumenSeeder::TANDA) as $tanda) {
            DB::table('proveedores')->insert($tanda);
        }

        return DB::table('proveedores')
            ->whereIn('cuit', $cuits)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------------
    // Productos: la tanda, su pivote y su asiento de inventario
    // ------------------------------------------------------------------

    /**
     * @param  list<array{id: int, familia: string, variante: string}>  $hojas
     * @param  list<array{id: int, nombre: string}>  $marcas
     * @param  list<int>  $proveedores
     */
    private function productos(int $cantidad, array $hojas, array $marcas, array $proveedores): void
    {
        $barra = $this->command?->getOutput()->createProgressBar($cantidad);
        $barra?->start();


        foreach (array_chunk(range(1, $cantidad), VolumenSeeder::TANDA) as $tanda) {
            $filas = [];

            foreach ($tanda as $i) {
                $filas[$i] = $this->producto($i, $hojas, $marcas);
            }

            DB::table('productos')->insert(array_values($filas));

            $ids = DB::table('productos')
                ->whereIn('codigo', array_column($filas, 'codigo'))
                ->pluck('id', 'codigo');

            $this->vinculos($filas, $ids, $proveedores);
            $this->inventarioInicial($filas, $ids);

            $barra?->advance(count($tanda));
        }

        $barra?->finish();
        $this->command?->newLine();
    }

    /**
     * Una fila de producto, derivada del índice.
     *
     * Los módulos no son arbitrarios: cada uno existe para que un filtro del
     * listado tenga algo que filtrar y para que un caso del negocio esté
     * representado.
     *
     *   % 71 → crítico        282 productos por debajo del mínimo: es lo que mide el
     *                         panel, lo que el tramo G va a sugerir reponer y lo que
     *                         el filtro ?stock=critico tiene que encontrar
     *   % 50 → inactivo       400 dados de baja, para el filtro ?estado=inactivos
     *   % 20 → IVA 10,5 %     1.000 productos, para que el desglose del tramo C no
     *   % 97 → exento            se pruebe sólo contra el 21 %
     *   % 5  → peso propio    el resto hereda el de la categoría
     *
     * @param  list<array{id: int, familia: string, variante: string}>  $hojas
     * @param  list<array{id: int, nombre: string}>  $marcas
     */
    private function producto(int $i, array $hojas, array $marcas): array
    {
        $hoja  = $hojas[$i % count($hojas)];
        $marca = $marcas[$i % count($marcas)];

        $critico = $i % 71 === 0;
        $contado = 1000 + ($i % 900) * 500;

        return [
            'categoria_id' => $hoja['id'],
            'marca_id'     => $marca['id'],
            'codigo'       => VolumenSeeder::PREFIJO.str_pad((string) $i, 5, '0', STR_PAD_LEFT),


            'nombre' => "{$hoja['familia']} {$marca['nombre']} {$hoja['variante']} MX-".(1000 + $i),


            'descripcion' => 'Producto de volumen, cargado para medir el sistema. No es un producto real.',
            'imagenes'    => null,

            'precio_contado' => $contado,
            'precio_lista'   => round($contado * 1.15, 2),

            'alicuota_iva' => match (true) {
                $i % 97 === 0 => '0.00',
                $i % 20 === 0 => '10.50',
                default       => '21.00',
            },


            'costo_promedio'      => round($contado * 0.62, 2),
            'stock'               => $critico ? $i % 3 : 60 + ($i % 240),
            'stock_reservado'     => 0,
            'stock_minimo'        => $critico ? 5 : 2 + $i % 9,


            'cantidad_reposicion' => 10 + $i % 20,
            'peso_gramos'         => $i % 5 === 0 ? 100 + ($i % 40) * 50 : null,
            'destacado'           => false,
            'activo'              => $i % 50 !== 0,
            'created_at'          => $this->ahora,
            'updated_at'          => $this->ahora,
        ];
    }

    /**
     * Uno, dos o tres proveedores por producto, con exactamente uno preferido.
     *
     * La invariante de `producto_proveedor` —si hay al menos un vínculo, exactamente
     * uno está marcado— la garantiza `ProductoProveedorService`, no el esquema. Este
     * seeder no la implementa, pero **tampoco la rompe**: datos de prueba inválidos
     * hacen que lo que se mide sea otra cosa. Es lo mismo que declara
     * `ProductoFactory::conProveedor()`.
     *
     * Los proveedores se eligen corridos desde el índice, así que los tres de un
     * producto son siempre distintos y la clave primaria compuesta nunca choca.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  Collection<string, int>  $ids
     * @param  list<int>  $proveedores
     */
    private function vinculos(array $filas, Collection $ids, array $proveedores): void
    {
        $cuantos  = count($proveedores);
        $vinculos = [];

        foreach ($filas as $i => $fila) {
            $costo = (float) $fila['costo_promedio'];

            // min() por si alguien pide menos de tres proveedores desde el comando:
            // sin eso, el mismo proveedor entraría dos veces en el mismo producto.
            for ($k = 0; $k < min(1 + $i % 3, $cuantos); $k++) {
                $proveedorId = $proveedores[($i + $k) % $cuantos];

                $vinculos[] = [
                    'producto_id'  => $ids[$fila['codigo']],
                    'proveedor_id' => $proveedorId,

                    // Cada proveedor cobra distinto: es lo que el comparador compara.
                    // El preferido no es el más barato a propósito, que es el caso real
                    // donde el barato entrega en tres semanas.
                    'costo_ultimo'     => round($costo * (0.95 + 0.05 * $k), 2),
                    'codigo_proveedor' => 'P'.$proveedorId.'-'.$fila['codigo'],
                    'es_preferido'     => $k === 0,
                    'created_at'       => $this->ahora,
                    'updated_at'       => $this->ahora,
                ];
            }
        }

        foreach (array_chunk($vinculos, VolumenSeeder::TANDA) as $tanda) {
            DB::table('producto_proveedor')->insert($tanda);
        }
    }

    /**
     * El asiento de kardex del stock con el que cada producto arranca.
     *
     * Tipo `ajuste` y no `compra`: una carga inicial de inventario no tiene orden de
     * compra detrás, y `ajuste` es justamente el tipo cuyo `origen` es nulo y cuyo
     * motivo es obligatorio —`StockService::ajustar()` lo exige con esas palabras,
     * porque sin documento de origen el motivo es lo único que explica por qué
     * cambió el stock—.
     *
     * `cantidad` va con signo y `stock_resultante` es el saldo después, igual que lo
     * escribe `StockService::registrar()`. Eso hace que el accesor de stock anterior
     * del tramo B —`stock_resultante − cantidad`— dé cero para estos asientos, que
     * es la verdad: antes de la carga no había nada.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @param  Collection<string, int>  $ids
     */
    private function inventarioInicial(array $filas, Collection $ids): void
    {
        $asientos = [];

        foreach ($filas as $fila) {

            if ($fila['stock'] === 0) {
                continue;
            }

            $asientos[] = [
                'producto_id'      => $ids[$fila['codigo']],
                'tipo'             => 'ajuste',
                'cantidad'         => $fila['stock'],
                'stock_resultante' => $fila['stock'],
                'origen_type'      => null,
                'origen_id'        => null,
                'usuario_id'       => $this->usuarioId,
                'motivo'           => 'Carga inicial de inventario (datos de volumen).',
                'created_at'       => $this->inicioDelInventario,
                'updated_at'       => $this->inicioDelInventario,
            ];
        }

        foreach (array_chunk($asientos, VolumenSeeder::TANDA) as $tanda) {
            DB::table('movimientos_stock')->insert($tanda);
        }
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /** El administrador, por su rol y no por su correo: el correo sale del .env. */
    private function administrador(): int
    {
        return (int) User::query()
            ->where('rol_id', Rol::where('nombre', 'Administrador')->value('id'))
            ->orderBy('id')
            ->value('id');
    }

    private function resumen(): void
    {

        $this->command?->table(
            ['Tabla', 'Filas'],
            collect(['productos', 'producto_proveedor', 'movimientos_stock', 'proveedores', 'marcas', 'categorias'])
                ->map(fn (string $tabla) => [$tabla, number_format(DB::table($tabla)->count(), 0, ',', '.')])
                ->all(),
        );

        $criticos = DB::table('productos')
            ->where('activo', true)
            ->whereRaw('(stock - stock_reservado) <= stock_minimo')
            ->count();

        $this->command?->warn(
            "Quedaron {$criticos} producto(s) en stock crítico. Es a propósito: son los que "
            .'el panel cuenta y los que el armador va a sugerir reponer.'
        );
    }
}