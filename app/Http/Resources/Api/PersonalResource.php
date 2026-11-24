<?php

namespace App\Http\Resources\Api;

use App\Models\Permiso;
use App\Models\Rol;
use App\Services\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una persona del personal (tramo I), para el listado y la ficha. La contraseña no viaja nunca: ni el
 * hash ni nada que se le parezca (C-1). `es_la_propia` le dice a la pantalla que no ofrezca eliminarse
 * ni generarse un enlace; quien lo decide igual es el servidor.
 *
 * Sólo en la ficha y con `usuario.cambiar_rol` (HL-131): la descripción y los permisos del rol actual
 * («Hoy puede») y los roles que se le pueden asignar, que el servicio decide. Sin el permiso, las claves
 * no están.
 */
class PersonalResource extends JsonResource
{
    public function __construct($resource, private readonly bool $ficha = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $empleado  = $this->empleado;
        $cambiaRol = $this->ficha && $request->user()?->can('usuario.cambiar_rol') === true;

        return [
            'id'              => $this->id,
            'nombre'          => $this->nombre,
            'apellido'        => $this->apellido,
            'nombre_completo' => $this->nombre_completo,
            'email'           => $this->email,
            'activo'          => (bool) $this->activo,
            'acceso_texto'    => $this->activo ? 'Con acceso' : 'Sin acceso',
            'es_la_propia'    => $request->user()?->is($this->resource) === true,
            'rol'             => [
                'id'          => $this->rol->id,
                'nombre'      => $this->rol->nombre,
                'descripcion' => $this->when($cambiaRol, fn () => $this->rol->descripcion),
                'permisos'    => $this->when($cambiaRol, fn () => $this->rol->permisos
                    ->sortBy('clave')->map(fn (Permiso $permiso) => $permiso->descripcion)->values()->all()),
            ],
            'empleado'        => $empleado === null ? null : [
                'legajo'        => $empleado->legajo,
                'dni'           => $empleado->dni,
                'telefono'      => $empleado->telefono,
                'fecha_ingreso' => $empleado->fecha_ingreso?->toDateString(),
                'fecha_baja'    => $empleado->fecha_baja?->toDateString(),
            ],
            'situacion_texto' => match (true) {
                $empleado === null             => 'No corresponde',
                $empleado->fecha_baja !== null => 'Baja el '.$empleado->fecha_baja->format('d/m/Y'),
                default                        => 'En actividad',
            },
            'roles_asignables' => $this->when($cambiaRol, fn () => app(UsuarioService::class)
                ->rolesAsignablesA($this->resource)
                ->map(fn (Rol $rol) => [
                    'id'     => $rol->id,
                    'nombre' => $rol->nombre,
                    'actual' => $rol->id === $this->rol_id,
                ])->values()->all()),
        ];
    }
}
