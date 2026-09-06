<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fuente de verdad de qué puede hacer cada rol.
 *
 * Los permisos son claves nombradas, no banderas CRUD por módulo. El sistema
 * original tenía cuatro flags por (perfil, módulo) y toda acción fuera del CRUD
 * caía en un `?? "can_update"`: por eso un vendedor podía anular ventas cobradas
 * teniendo can_delete = 0 (hallazgo C-2). Acá lo que no está asignado, no se
 * puede hacer.
 */
class RolPermisoSeeder extends Seeder
{
    /** Acciones por módulo. Agregar una acción acá es agregar un permiso. */
    private const PERMISOS = [
        'categoria' => ['ver', 'crear', 'editar', 'eliminar'],
        'marca'     => ['ver', 'crear', 'editar', 'eliminar'],
        'producto'  => ['ver', 'crear', 'editar', 'eliminar'],
        'usuario'   => ['ver', 'crear', 'editar', 'eliminar', 'cambiar_rol'],
        'cliente'   => ['ver', 'crear', 'editar', 'eliminar'],
        'proveedor' => ['ver', 'crear', 'editar', 'eliminar'],
        'compra'    => ['ver', 'crear', 'editar', 'aprobar', 'recibir'],
        'venta'     => ['ver', 'crear', 'editar', 'cobrar', 'anular'],
        'stock'     => ['ver', 'ajustar'],
        'rol'       => ['ver', 'editar'],
        'panel'     => ['ver_propio', 'ver_global'],
    ];

    private const ROLES = [
        'Administrador' => [
            'descripcion' => 'Acceso total al sistema de gestión.',
            'ambito'      => 'gestion',
            'permisos'    => '*',
        ],
        'Administrativo' => [
            'descripcion' => 'Catálogo, personas, compras y stock. Ve las ventas, no las opera.',
            'ambito'      => 'gestion',
            'permisos'    => [
                'categoria.*', 'marca.*', 'producto.*', 'cliente.*', 'proveedor.*',
                'compra.*', 'stock.*', 'venta.ver', 'panel.ver_global',
            ],
        ],
        'Vendedor' => [
            'descripcion' => 'Emite presupuestos y ventas. No cobra ni anula.',
            'ambito'      => 'gestion',
            'permisos'    => [
                'categoria.ver', 'producto.ver', 'cliente.ver', 'cliente.crear',
                'venta.ver', 'venta.crear', 'venta.editar', 'panel.ver_propio',
            ],
        ],
        'Cajero' => [
            'descripcion' => 'Registra cobros sobre ventas existentes.',
            'ambito'      => 'gestion',
            'permisos'    => [
                'producto.ver', 'venta.ver', 'venta.cobrar', 'panel.ver_propio',
            ],
        ],
        'Cliente' => [
            'descripcion' => 'Cuenta de la tienda online. Sin acceso a la gestión.',
            'ambito'      => 'tienda',
            'permisos'    => [],
        ],
    ];

    public function run(): void
    {
        $this->crearPermisos();
        $this->crearRoles();
    }

    private function crearPermisos(): void
    {
        foreach (self::PERMISOS as $modulo => $acciones) {
            foreach ($acciones as $accion) {
                Permiso::updateOrCreate(
                    ['clave' => "{$modulo}.{$accion}"],
                    [
                        'modulo'      => $modulo,
                        'descripcion' => ucfirst(str_replace('_', ' ', $accion))." {$modulo}",
                    ],
                );
            }
        }
    }

    private function crearRoles(): void
    {
        foreach (self::ROLES as $nombre => $definicion) {
            $rol = Rol::updateOrCreate(
                ['nombre' => $nombre],
                [
                    'descripcion' => $definicion['descripcion'],
                    'ambito'      => $definicion['ambito'],
                    // Impide borrar los roles base desde el módulo de roles y
                    // dejar el sistema sin nadie que lo administre.
                    'es_sistema'  => true,
                ],
            );

            $rol->permisos()->sync($this->resolver($definicion['permisos']));
        }
    }

    /**
     * Traduce la definición a ids de permiso. Acepta '*', 'modulo.*' y claves
     * exactas. Una clave inexistente aborta el seeder: un permiso mal escrito
     * no se concede, y tampoco se ignora en silencio.
     *
     * @param  string|array<int, string>  $definicion
     * @return array<int, int>
     */
    private function resolver(string|array $definicion): array
    {
        if ($definicion === '*') {
            return Permiso::pluck('id')->all();
        }

        $ids = [];

        foreach ($definicion as $patron) {
            if (str_ends_with($patron, '.*')) {
                $encontrados = Permiso::where('modulo', substr($patron, 0, -2))->pluck('id')->all();
            } else {
                $encontrados = Permiso::where('clave', $patron)->pluck('id')->all();
            }

            if ($encontrados === []) {
                throw new RuntimeException("El permiso '{$patron}' no existe. Revisar RolPermisoSeeder::PERMISOS.");
            }

            $ids = array_merge($ids, $encontrados);
        }

        return array_values(array_unique($ids));
    }
}
