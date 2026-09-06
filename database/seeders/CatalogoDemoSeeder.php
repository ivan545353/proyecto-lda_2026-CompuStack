<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos ficticios para la demostración (los pide la consigna).
 *
 * El catálogo se arma a mano y no con factories: para defender la migración hay
 * que poder comparar contra el sistema original, y para eso conviene que los
 * productos sean reconocibles. Se toman los del dump lp_2025, descartando las
 * filas basura ('asdasdas', 'afsadgasdgsdfg') que el original no validaba
 * (hallazgo B-23).
 */
class CatalogoDemoSeeder extends Seeder
{
    /** nombre => [subcategorías], con el peso por defecto de cada rama. */
    private const CATEGORIAS = [
        'Componentes'     => ['peso' => 1200, 'hijas' => ['Placas de video', 'Motherboards', 'Memorias RAM', 'Fuentes', 'Gabinetes']],
        'Almacenamiento'  => ['peso' => 400,  'hijas' => ['Discos HDD', 'Discos SSD']],
        'Periféricos'     => ['peso' => 700,  'hijas' => ['Monitores', 'Teclados y mouses', 'Auriculares', 'Micrófonos']],
        'Impresión'       => ['peso' => 6000, 'hijas' => ['Impresoras']],
        'Accesorios'      => ['peso' => 300,  'hijas' => []],
    ];

    private const MARCAS = [
        'ASUS', 'Corsair', 'EVGA', 'HP', 'HyperX', 'Kingston',
        'Logitech', 'Seagate', 'Western Digital', 'Fifine',
    ];

    private const PROVEEDORES = [
        ['razon_social' => 'Distribuidora Austral S.A.', 'cuit' => '30-71234567-8', 'canal_pedido' => 'email',          'plazo' => 7],
        ['razon_social' => 'Insumos del Sur S.R.L.',     'cuit' => '30-70987654-3', 'canal_pedido' => 'portal_externo', 'plazo' => 14],
        ['razon_social' => 'Tecno Patagonia',            'cuit' => '20-33445566-9', 'canal_pedido' => 'manual',         'plazo' => 3],
    ];

    /** [código, nombre, categoría, marca, precio lista, stock, mínimo, reposición] */
    private const PRODUCTOS = [
        ['VG248QG',    'Monitor ASUS VG248QG 165Hz',        'Monitores',         'ASUS',            500000, 4,  2, 5],
        ['G505',       'Mouse Logitech G505 inalámbrico',   'Teclados y mouses', 'Logitech',         40000, 28, 6, 20],
        ['HDD-SEA1TB', 'Disco Seagate Barracuda 1TB',       'Discos HDD',        'Seagate',          30000, 8,  5, 15],
        ['SSD-WD1TB',  'Disco SSD WD Blue SN580 1TB',       'Discos SSD',        'Western Digital',  95000, 12, 4, 10],
        ['MB-ASUSB450','Motherboard ASUS B450M',            'Motherboards',      'ASUS',             60000, 12, 3, 8],
        ['RAM-KVR16',  'Memoria Kingston Fury 16GB DDR4',   'Memorias RAM',      'Kingston',         75000, 15, 5, 12],
        ['HP-2775',    'Impresora HP Deskjet 2775',         'Impresoras',        'HP',               85000, 7,  2, 5],
        ['HX-STINGER', 'Auriculares HyperX Cloud Stinger',  'Auriculares',       'HyperX',           45000, 0,  4, 10],
        ['MIC-K669B',  'Micrófono Fifine K669B',            'Micrófonos',        'Fifine',           28000, 21, 5, 10],
        ['COR-4000D',  'Gabinete Corsair 4000D Airflow',    'Gabinetes',         'Corsair',          80000, 0,  2, 6],
        ['EVGA-600',   'Fuente EVGA 600W 80+',              'Fuentes',           'EVGA',             45000, 7,  3, 10],
    ];

    public function run(): void
    {
        $categorias = $this->crearCategorias();
        $marcas     = $this->crearMarcas();
        $proveedor  = $this->crearProveedores();

        foreach (self::PRODUCTOS as [$codigo, $nombre, $categoria, $marca, $lista, $stock, $minimo, $reposicion]) {
            $producto = Producto::create([
                'categoria_id'        => $categorias[$categoria],
                'marca_id'            => $marcas[$marca],
                'proveedor_id'        => $proveedor->id,
                'codigo'              => $codigo,
                'nombre'              => $nombre,
                'descripcion'         => "Producto de demostración: {$nombre}.",
                'precio_lista'        => $lista,
                'precio_contado'      => round($lista * 0.90, 2),
                'alicuota_iva'        => 21.00,
                'stock_minimo'        => $minimo,
                'cantidad_reposicion' => $reposicion,
                'activo'              => true,
            ]);

            // stock y costo_promedio están fuera del fillable: sólo los mueve el
            // StockService. Acá se fijan directo por ser carga inicial de demo.
            $producto->stock          = $stock;
            $producto->costo_promedio = round($lista * 0.62, 2);
            $producto->save();
        }
    }

    /** @return array<string, int> nombre de categoría => id */
    private function crearCategorias(): array
    {
        $ids = [];

        foreach (self::CATEGORIAS as $nombre => $definicion) {
            $padre = Categoria::create([
                'nombre'              => $nombre,
                'slug'                => Str::slug($nombre),
                'orden'               => count($ids),
                'peso_default_gramos' => $definicion['peso'],
            ]);

            $ids[$nombre] = $padre->id;

            foreach ($definicion['hijas'] as $orden => $hija) {
                $ids[$hija] = Categoria::create([
                    'parent_id'           => $padre->id,
                    'nombre'              => $hija,
                    'slug'                => Str::slug($hija),
                    'orden'               => $orden,
                    'peso_default_gramos' => $definicion['peso'],
                ])->id;
            }
        }

        return $ids;
    }

    /** @return array<string, int> nombre de marca => id */
    private function crearMarcas(): array
    {
        $ids = [];

        foreach (self::MARCAS as $nombre) {
            $ids[$nombre] = Marca::create([
                'nombre' => $nombre,
                'slug'   => Str::slug($nombre),
            ])->id;
        }

        return $ids;
    }

    private function crearProveedores(): Proveedor
    {
        $primero = null;

        foreach (self::PROVEEDORES as $datos) {
            $proveedor = Proveedor::create([
                'razon_social'       => $datos['razon_social'],
                'cuit'               => $datos['cuit'],
                'email'              => 'ventas@'.Str::slug($datos['razon_social']).'.com.ar',
                'telefono'           => '297-4'.random_int(100000, 999999),
                'contacto'           => 'Mesa de pedidos',
                'canal_pedido'       => $datos['canal_pedido'],
                'portal_url'         => $datos['canal_pedido'] === 'portal_externo'
                                            ? 'https://pedidos.'.Str::slug($datos['razon_social']).'.com.ar'
                                            : null,
                'plazo_entrega_dias' => $datos['plazo'],
            ]);

            $primero ??= $proveedor;
        }

        return $primero;
    }
}
