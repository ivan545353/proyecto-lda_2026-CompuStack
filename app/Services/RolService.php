<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Permiso;
/**
 * Reglas de negocio de roles y permisos.
 *
 * Es el único módulo que puede dejar el sistema inutilizable, así que tiene
 * tres protecciones que los demás no necesitan:
 *
 *   1. Nadie modifica los permisos de su propio rol. Un administrador que se
 *      quita rol.editar deja el sistema sin nadie que pueda devolvérselo.
 *   2. Un rol de sistema no se elimina ni cambia de nombre ni de ámbito.
 *   3. Un rol con usuarios asignados no se elimina.
 *
 * Las tres viven acá y no en la vista: ocultar un botón es comodidad, la regla
 * que protege es esta. Una petición armada a mano llega igual al servicio.
 *
 * El servicio no conoce la petición HTTP ni la sesión: recibe datos y el
 * usuario que ejecuta la acción como parámetros explícitos.
 *
 * Métodos:
 *   crear()                   alta en transacción
 *   actualizar()              edición, con la protección del rol propio
 *   eliminar()                baja, con las dos validaciones previas
 *   conPermisosImplicitos()   agrega el permiso 'ver' de cada módulo tocado
 *   cambianLosPermisos()      compara el conjunto actual con el nuevo
 *   olvidarCache()            invalida la caché de permisos del rol
 */
class RolService
{
    public function crear(array $datos): Rol
    {
        return DB::transaction(function () use ($datos) {
            $rol = Rol::create([
                'nombre'      => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'ambito'      => $datos['ambito'],
                'es_sistema'  => false,
            ]);

            $rol->permisos()->sync($this->conPermisosImplicitos(
                array_map('intval', $datos['permisos'] ?? [])
            ));

            return $rol;
        });
    }

    public function actualizar(Rol $rol, array $datos, User $autor): Rol
    {
        $permisos = $this->conPermisosImplicitos(
            array_map('intval', $datos['permisos'] ?? [])
        );

        // Nadie se quita permisos a sí mismo. Sin esto, un administrador que
        // desmarca 'rol.editar' de su propio rol deja el sistema sin nadie que
        // pueda volver a otorgarlo, y sólo se sale tocando la base a mano.
        if ($rol->id === $autor->rol_id && $this->cambianLosPermisos($rol, $permisos)) {
            throw new ReglaDeNegocioException(
                'No puede modificar los permisos de su propio rol. Pídaselo a otro administrador.'
            );
        }

        return DB::transaction(function () use ($rol, $datos, $permisos) {
            // Un rol de sistema no cambia de nombre ni de ámbito: el ámbito
            // decide si el usuario entra a la gestión o a la tienda, y los
            // seeders lo buscan por nombre.
            $rol->update($rol->es_sistema
                ? ['descripcion' => $datos['descripcion'] ?? null]
                : [
                    'nombre'      => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'ambito'      => $datos['ambito'],
                ]);

            $rol->permisos()->sync($permisos);

            $this->olvidarCache($rol);

            return $rol->fresh();
        });
    }

    public function eliminar(Rol $rol): void
    {
        if ($rol->es_sistema) {
            throw new ReglaDeNegocioException(
                "El rol «{$rol->nombre}» es del sistema y no se puede eliminar."
            );
        }

        if ($rol->usuarios()->exists()) {
            $cantidad = $rol->usuarios()->count();

            throw new ReglaDeNegocioException(
                "El rol «{$rol->nombre}» tiene {$cantidad} usuario(s) asignado(s). "
                .'Reasignelos antes de eliminarlo.'
            );
        }

        DB::transaction(function () use ($rol) {
            $rol->permisos()->detach();
            $rol->delete();
            $this->olvidarCache($rol);
        });
    }

    /** @param  array<int, int>  $nuevos */
    private function cambianLosPermisos(Rol $rol, array $nuevos): bool
    {
        $actuales = $rol->permisos()->pluck('permisos.id')->map('intval')->sort()->values()->all();

        return $actuales !== $nuevos;
    }

    /**
     * La caché de permisos es por rol, así que un solo forget alcanza para
     * todos los usuarios que lo tienen. Sin esto, un permiso revocado seguiría
     * concediéndose hasta una hora.
     */
    private function olvidarCache(Rol $rol): void
    {
        Cache::forget(User::claveCache($rol->id));
    }

        /**
     * Cualquier permiso de un módulo implica poder ver ese módulo.
     *
     * Otorgar 'producto.crear' sin 'producto.ver' deja un rol que puede dar de
     * alta un producto pero no llegar a la pantalla donde se hace. La regla vive
     * acá y no sólo en el formulario porque una petición armada a mano
     * saltearía el JavaScript.
     *
     * Los módulos sin acción 'ver' (por ejemplo panel, con ver_propio y
     * ver_global) no se ven afectados: no existe la clave, no se agrega nada.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function conPermisosImplicitos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $seleccionados = Permiso::whereIn('id', $ids)->get();

        $clavesVer = $seleccionados->pluck('modulo')->unique()
            ->map(fn (string $modulo) => "{$modulo}.ver");

        return $seleccionados->pluck('id')
            ->merge(Permiso::whereIn('clave', $clavesVer)->pluck('id'))
            ->unique()->sort()->values()->all();
    }
}