<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos a escala. **No son datos de demostración: son datos de medición.**
 */
class VolumenSeeder extends Seeder
{

    public const PREFIJO = 'VOL-';

    public const CANTIDADES = [
        'proveedores' => 300,
        'productos'   => 20000,
        'clientes'    => 5000,
        'compras'     => 2000,
        'ventas'      => 10000,
    ];

    public const TANDA = 500;


    public const MESES = 18;

    public function run(array $cantidades = self::CANTIDADES): void
    {
        $this->exigirEntornoNoProductivo();
        $this->exigirIndispensables();
        $this->exigirBaseSinDatosDeVolumen();

        $this->call(CatalogoVolumenSeeder::class, false, ['cantidades' => $cantidades]);
        $this->call(PersonasVolumenSeeder::class, false, ['cantidades' => $cantidades]);
        $this->call(OperacionesVolumenSeeder::class, false, ['cantidades' => $cantidades]);
    }

    private function exigirEntornoNoProductivo(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Los datos de volumen no se cargan en producción: son veinte mil productos '
                .'inventados que quedarían mezclados con el catálogo real.'
            );
        }
    }

    private function exigirIndispensables(): void
    {

        $faltantes = collect(['Administrador', 'Vendedor', 'Cajero'])
            ->reject(fn (string $nombre) => Rol::where('nombre', $nombre)->exists());

        if ($faltantes->isNotEmpty()) {
            throw new RuntimeException(
                'Faltan los roles base ('.$faltantes->implode(', ').'). '
                .'Corré php artisan migrate:fresh --seed antes de cargar el volumen.'
            );
        }

        if (! $this->administradorExiste()) {
            throw new RuntimeException(
                'No hay ningún usuario con rol Administrador, y el inventario inicial '
                .'necesita a alguien a quien atribuirle el asiento del kardex. '
                .'Corré php artisan db:seed primero.'
            );
        }
    }

    private function exigirBaseSinDatosDeVolumen(): void
    {

        $cargados = DB::table('productos')->where('codigo', 'like', self::PREFIJO.'%')->count();

        if ($cargados > 0) {
            throw new RuntimeException(
                "Ya hay {$cargados} producto(s) de volumen cargados. Este seeder no se "
                .'ejecuta dos veces: los códigos y los CUIT son secuenciales y sus columnas '
                .'son UNIQUE. Para rearmar: php artisan migrate:fresh --seed y después '
                .'php artisan datos:volumen.'
            );
        }
    }

    private function administradorExiste(): bool
    {
        return User::where('rol_id', Rol::where('nombre', 'Administrador')->value('id'))->exists();
    }
}