<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Le da a los productos de demostración lo que la tienda móvil muestra: descripciones reales,
 * fechas de alta repartidas, destacados y fotos.
 *
 * Sólo toca los productos de `CatalogoDemoSeeder`, por código; los del seeder de volumen quedan
 * como están (sin fotos, todos creados el mismo día: es el caso que el tope de novedades cubre).
 * Va después de `CatalogoDemoSeeder` y se puede correr cuantas veces haga falta.
 *
 * Lo que ya editó una persona no se pisa: la descripción sólo se reemplaza si sigue siendo el
 * texto de relleno, y las imágenes sólo si el producto no tiene o si son las que escribió este
 * seeder. Los destacados y la fecha de alta son el guion de la demostración y se reescriben.
 *
 * Las fotos son propias o de uso libre y viven en `database/seeders/imagenes/`, versionadas
 * (ver el LEEME de esa carpeta): `<CÓDIGO>-<n>.jpg|png|webp`, la 1 es la principal. Si falta
 * una, el producto queda sin foto y la API devuelve la de por omisión.
 */
class TiendaDemoSeeder extends Seeder
{
    /** Prefijo de lo que este seeder escribe en el disco público: lo que lo empieza es suyo. */
    private const PREFIJO_DE_FOTOS = 'productos/demo-';

    /** El texto de relleno de `CatalogoDemoSeeder`. */
    private const RELLENO = 'Producto de demostración:';

    /**
     * código => [días desde el alta, destacado, descripción]
     *
     * Cuatro dentro de la ventana de novedades (30 días) y siete fuera: la sección «novedades»
     * muestra unas pocas, no todas. Los destacados son cuatro.
     *
     * @var array<string, array{0: int, 1: bool, 2: string}>
     */
    public const PRODUCTOS = [
        'VG248QG' => [35, true, 'Monitor gamer de 24 pulgadas Full HD con frecuencia de actualización de 165 Hz y tiempo de respuesta de 0,5 ms. Compatible con G-Sync para evitar cortes de imagen. Base regulable en altura e inclinación.'],
        'G505' => [41, true, 'Mouse inalámbrico Logitech con sensor óptico de alta precisión, botones programables y diseño ergonómico para diestros. Ideal para jugar y para trabajar muchas horas sin cansar la mano.'],
        'HDD-SEA1TB' => [52, false, 'Disco rígido interno de 1 TB para PC de escritorio. Formato de 3,5 pulgadas, conexión SATA y 7200 revoluciones por minuto. Una opción económica para guardar documentos, fotos y copias de seguridad.'],
        'SSD-WD1TB' => [3, true, 'Unidad de estado sólido NVMe de 1 TB en formato M.2. Arranca el sistema y abre los programas en segundos, con velocidades de lectura de hasta 4150 MB/s. Se instala directo en la placa madre, sin cables.'],
        'MB-ASUSB450' => [71, false, 'Placa madre ASUS en formato Micro-ATX con socket AM4, compatible con procesadores Ryzen. Admite memoria DDR4, tiene ranura M.2 y salida de video para armar una PC completa sin gastar de más.'],
        'RAM-KVR16' => [8, false, 'Memoria RAM Kingston Fury de 16 GB DDR4 con disipador de calor. Mejora la respuesta del equipo al abrir muchos programas a la vez y se instala sin configuraciones especiales.'],
        'HP-2775' => [60, true, 'Impresora multifunción de inyección de tinta que imprime, copia y escanea. Conexión WiFi para imprimir desde el celular o la notebook. Pensada para el hogar y el estudio.'],
        'HX-STINGER' => [80, false, 'Auriculares con micrófono para jugar y para videollamadas. Diadema ajustable con almohadillas acolchadas y parlantes de 50 mm para un sonido envolvente. Cable con control de volumen.'],
        'MIC-K669B' => [15, false, 'Micrófono USB de condensador con patrón cardioide, que capta la voz de frente y reduce el ruido del ambiente. Se conecta y funciona, sin controladores. Sirve para streaming, podcasts y clases virtuales.'],
        'COR-4000D' => [24, false, 'Gabinete Corsair de torre media con frente perforado para mejorar el flujo de aire y panel lateral de vidrio templado. Espacio de sobra para placas de video largas y para ordenar los cables.'],
        'EVGA-600' => [88, false, 'Fuente de alimentación de 600 W con certificación 80 Plus, para equipos de gama media. Entrega energía estable y eficiente, con los conectores necesarios para una placa de video y varios discos.'],
    ];

    /** Cuántas fotos admite un producto (el mismo tope que la web). */
    private const MAX_FOTOS = Producto::MAX_IMAGENES;

    /** @param  string|null  $origen  carpeta de las fotos; sin ella, `database/seeders/imagenes` */
    public function __construct(private ?string $origen = null)
    {
    }

    public function run(): void
    {
        $origen = $this->origen ?? database_path('seeders/imagenes');

        foreach (self::PRODUCTOS as $codigo => [$dias, $destacado, $descripcion]) {
            $producto = Producto::where('codigo', $codigo)->first();

            if ($producto === null) {
                $this->command?->warn("No existe el producto {$codigo}: corré antes CatalogoDemoSeeder.");

                continue;
            }

            $producto->forceFill([
                'created_at'  => now()->subDays($dias),
                'destacado'   => $destacado,
                'descripcion' => $this->esRelleno($producto->descripcion) ? $descripcion : $producto->descripcion,
            ]);

            $fotos = $this->copiarFotos($codigo, $producto, $origen);

            if ($fotos !== null) {
                $producto->imagenes = $fotos;
            }

            $producto->saveQuietly();
        }
    }

    private function esRelleno(?string $descripcion): bool
    {
        return trim((string) $descripcion) === '' || str_starts_with((string) $descripcion, self::RELLENO);
    }

    /**
     * Copia las fotos del producto al disco público y devuelve la lista de rutas, o null si no hay
     * nada que cambiar: sin fotos en la carpeta, o el producto ya tiene fotos que no son de este
     * seeder.
     *
     * @return array<int, string>|null
     */
    private function copiarFotos(string $codigo, Producto $producto, string $origen): ?array
    {
        $actuales = $producto->imagenes ?? [];
        $esDeAca  = fn (string $ruta) => str_starts_with($ruta, self::PREFIJO_DE_FOTOS);

        if ($actuales !== [] && array_filter($actuales, fn ($ruta) => ! $esDeAca($ruta)) !== []) {
            return null;
        }

        $encontradas = $this->fotosDe($codigo, $origen);

        if ($encontradas === []) {
            return null;
        }

        $disco  = Storage::disk('public');
        $rutas  = [];

        foreach ($encontradas as $numero => [$archivo, $extension]) {
            $ruta = self::PREFIJO_DE_FOTOS.strtolower($codigo)."-{$numero}.{$extension}";
            $disco->put($ruta, (string) file_get_contents($archivo));
            $rutas[] = $ruta;
        }

        // Una foto que ya no está en la carpeta y que escribió este seeder, se retira del disco.
        $disco->delete(array_values(array_diff($actuales, $rutas)));

        return $rutas;
    }

    /**
     * Las fotos de un código, por número, hasta el tope: `<CÓDIGO>-<n>.jpg|jpeg|png|webp`.
     *
     * @return array<int, array{0: string, 1: string}>  número => [ruta del archivo, extensión]
     */
    private function fotosDe(string $codigo, string $origen): array
    {
        if (! is_dir($origen)) {
            return [];
        }

        $patron = '/^'.preg_quote($codigo, '/').'-(\d+)\.(jpe?g|png|webp)$/i';
        $fotos  = [];

        foreach (scandir($origen) ?: [] as $nombre) {
            if (preg_match($patron, $nombre, $partes) === 1) {
                $extension = strtolower($partes[2]) === 'jpeg' ? 'jpg' : strtolower($partes[2]);
                $fotos[(int) $partes[1]] = [$origen.DIRECTORY_SEPARATOR.$nombre, $extension];
            }
        }

        ksort($fotos);

        return array_slice($fotos, 0, self::MAX_FOTOS, true);
    }
}
