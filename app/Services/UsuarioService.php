<?php

namespace App\Services;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Password as BrokerDePassword;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\ReglaDeNegocioException;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de personal.
 *
 * Reglas propias:
 *
 *   1. La invariante del modelo de datos: un usuario tiene fila en `empleados`
 *      si y sólo si su rol es de ámbito gestión, y en `clientes` si y sólo si
 *      es de ámbito tienda. Se garantiza acá, creando las dos filas en la
 *      MISMA transacción. Si el satélite falla, el usuario tampoco queda: una
 *      cuenta sin legajo es una fila que nadie sabe qué es.
 *
 *   2. `actualizar()` no escribe `rol_id` ni `password`, aunque vengan en el
 *      arreglo. El rol es una acción aparte (C-3) y la contraseña tiene su
 *      propia pantalla. Los campos se enumeran uno por uno justamente para que
 *      un campo de más en la entrada no se convierta en una escritura.
 *
 *   3. Un usuario con historial no se borra: se desactiva (M-16).
 *
 *     La rama 'tienda' no la alcanza ninguna pantalla de la Etapa 1: la de
 *     personal sólo acepta roles de gestión y la de clientes no crea cuentas.
 *     No es código muerto: está probada en UsuarioServiceTest y es la base del
 *     registro de la tienda de la Etapa 2, donde una persona se crea la cuenta
 *     y su ficha de cliente en la misma transacción.
 *
 * Métodos:
 *   crear()       alta del usuario y de su satélite, en una transacción
 *   actualizar()  edición de los datos personales y del satélite; si queda inactiva,
 *                 revoca sus credenciales de la API
 *   eliminar()    baja física o lógica según tenga historial; devuelve cuál fue.
 *                 En las dos revoca sus credenciales de la API
 */
class UsuarioService
{
    /**
     * El permiso que permite recomponer el sistema.
     */
    private const PERMISO_DE_RESCATE = 'usuario.editar';

    public function __construct(private readonly CredencialService $credenciales)
    {
    }

    /**
     * Columnas que registran quién hizo qué.
     */
    private const HISTORIAL = [
        'ventas'            => ['usuario_id'],
        'pagos'             => ['usuario_id'],
        'movimientos_stock' => ['usuario_id'],
        'ordenes_compra'    => ['usuario_creo_id', 'usuario_aprobo_id'],
    ];

    public function crear(array $datos): User
    {
        return DB::transaction(function () use ($datos) {
            $rol = Rol::findOrFail($datos['rol_id']);

            $usuario = User::create([
                'nombre'   => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'email'    => $datos['email'],
                'password' => $datos['password'],   // el cast 'hashed' lo encripta
                'rol_id'   => $rol->id,
                'activo'   => $datos['activo'],
            ]);

            $this->guardarSatelite($usuario, $rol, $datos);

            return $usuario;
        });
    }

    public function actualizar(User $usuario, array $datos): User
    {
        return DB::transaction(function () use ($usuario, $datos) {
            // El dato está bien escrito; lo que no corresponde es la operación.
            // Por eso es una regla de negocio y no un error de validación.
            if (! $datos['activo']) {
                $this->exigirQueQuedeAlguienQuePuedaAdministrar($usuario, 'desactivar');
            }

            // Ni rol_id ni password, aunque el arreglo los traiga.
            $usuario->update([
                'nombre'   => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'email'    => $datos['email'],
                'activo'   => $datos['activo'],
            ]);

            // Una cuenta desactivada no sigue operando por la API: se cortan sus
            // credenciales en la misma transacción que la desactivó.
            if (! $datos['activo']) {
                $this->credenciales->revocarTodas($usuario);
            }

            $this->guardarSatelite($usuario, $usuario->rol, $datos);

            return $usuario->fresh();
        });
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminar(User $usuario): bool
    {
        $this->exigirQueQuedeAlguienQuePuedaAdministrar($usuario, 'eliminar');

        // Las dos ramas cortan las credenciales: la que desactiva no pasa por
        // actualizar(), y `personal_access_tokens` no tiene FK, así que borrar la
        // fila de `users` las dejaría huérfanas.
        if ($this->tieneHistorial($usuario)) {
            DB::transaction(function () use ($usuario) {
                $usuario->update(['activo' => false]);
                $this->credenciales->revocarTodas($usuario);
            });

            return false;
        }

        DB::transaction(function () use ($usuario) {
            $this->credenciales->revocarTodas($usuario);
            $usuario->delete();
        });

        return true;
    }

    /**
     * Roles que se le pueden asignar a esta persona.
     *
     * @return Collection<int, Rol>
     */
    public function rolesAsignablesA(User $usuario): Collection
    {
        return Rol::query()
            ->where('ambito', $usuario->rol->ambito)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Cambia el rol de una persona.
     *
     * Acción separada de la edición, con su propio permiso.
     */
    public function cambiarRol(User $usuario, int $rolId): User
    {
        return DB::transaction(function () use ($usuario, $rolId) {
            $rolNuevo = Rol::findOrFail($rolId);

            if ($rolNuevo->ambito !== $usuario->rol->ambito) {
                throw new ReglaDeNegocioException(
                    'Un rol de gestión y uno de tienda piden datos distintos, así que no se '
                    .'intercambian. Si la persona dejó de trabajar acá, cargale la fecha de '
                    .'baja y creá su ficha de cliente aparte.'
                );
            }

            if (! $rolNuevo->permisos()->where('clave', self::PERMISO_DE_RESCATE)->exists()) {
                $this->exigirQueQuedeAlguienQuePuedaAdministrar($usuario, 'cambiarle el rol a');
            }

            $usuario->update(['rol_id' => $rolNuevo->id]);

            return $usuario->fresh();
        });
    }

    /**
     * Cambia la contraseña de una cuenta.
     */
    public function cambiarPassword(User $usuario, string $password): User
    {
        return DB::transaction(function () use ($usuario, $password) {
            // El cast 'hashed' del modelo lo encripta. Nunca se llama a bcrypt()
            // acá: si el hash se hiciera en dos lugares, algún día uno de los
            // dos se olvidaría.
            $usuario->update(['password' => $password]);

            DB::table('password_reset_tokens')->where('email', $usuario->email)->delete();

            // Quien cambia la contraseña porque se la sacaron espera que el otro quede
            // afuera: también en la app, no sólo en el navegador.
            $this->credenciales->revocarTodas($usuario);

            return $usuario->fresh();
        });
    }

    /**
     * Crea un token de un solo uso para que una persona fije su contraseña.
     *
     */
    public function crearTokenDeRestablecimiento(User $usuario): string
    {
        if (! $usuario->activo) {
            throw new ReglaDeNegocioException(
                'Esa cuenta no tiene acceso al sistema, así que un enlace no le serviría: '
                .'podría fijar una contraseña y seguir sin poder entrar. Devolvele el acceso primero.'
            );
        }

        return BrokerDePassword::broker()->createToken($usuario);
    }

    /**
     * Crea o actualiza el satélite que corresponde al ámbito del rol.
     */
    private function guardarSatelite(User $usuario, Rol $rol, array $datos): void
    {
        match ($rol->ambito) {
            'gestion' => $usuario->empleado()->updateOrCreate([], [
                'legajo'        => $datos['empleado']['legajo'],
                'dni'           => $datos['empleado']['dni'],
                'telefono'      => $datos['empleado']['telefono'] ?? null,
                'fecha_ingreso' => $datos['empleado']['fecha_ingreso'],
                'fecha_baja'    => $datos['empleado']['fecha_baja'] ?? null,
            ]),

            'tienda' => $usuario->cliente()->updateOrCreate([], [
                'razon_social'  => $datos['cliente']['razon_social'],
                'tipo_doc'      => $datos['cliente']['tipo_doc'],
                'nro_doc'       => $datos['cliente']['nro_doc'] ?? null,
                'condicion_iva' => $datos['cliente']['condicion_iva'],
                'email'         => $datos['cliente']['email'] ?? null,
                'telefono'      => $datos['cliente']['telefono'] ?? null,
            ]),
        };
    }

    private function tieneHistorial(User $usuario): bool
    {
        foreach (self::HISTORIAL as $tabla => $columnas) {
            $existe = DB::table($tabla)
                ->where(function ($query) use ($columnas, $usuario) {
                    foreach ($columnas as $columna) {
                        $query->orWhere($columna, $usuario->id);
                    }
                })
                ->exists();

            if ($existe) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Es la única cuenta activa capaz de administrar usuarios?
     */
    private function esElUltimoQuePuedeAdministrar(User $usuario): bool
    {
        // Una cuenta ya desactivada no era la red de contención de nadie.
        if (! $usuario->activo || ! $usuario->tienePermiso(self::PERMISO_DE_RESCATE)) {
            return false;
        }

        return ! User::query()
            ->where('activo', true)
            ->whereKeyNot($usuario->id)
            ->whereHas('rol.permisos', fn ($query) => $query->where('clave', self::PERMISO_DE_RESCATE))
            ->exists();
    }

    private function exigirQueQuedeAlguienQuePuedaAdministrar(User $usuario, string $accion): void
    {
        if ($this->esElUltimoQuePuedeAdministrar($usuario)) {
            throw new ReglaDeNegocioException(
                "No se puede {$accion} la única cuenta activa que puede administrar personal. "
                .'Asigná ese permiso a otra persona antes de continuar.'
            );
        }
    }
}