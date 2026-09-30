<?php

namespace App\Services;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\ReglaDeNegocioException;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de usuarios.
 *
 * Mismo patrón que MarcaService y CategoriaService: recibe datos ya validados,
 * no conoce la petición ni la sesión, y la API de la Etapa 3 lo va a usar sin
 * cambios.
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
 * Métodos:
 *   crear()       alta del usuario y de su satélite, en una transacción
 *   actualizar()  edición de los datos personales y del satélite
 *   eliminar()    baja física o lógica según tenga historial; devuelve cuál fue
 */
class UsuarioService
{
    /**
     * El permiso que permite recomponer el sistema.
     *
     * Quien lo tiene puede volver a activar cualquier cuenta, incluida la que
     * alguien desactivó por error. Mientras exista una cuenta activa con este
     * permiso, ningún error es irreversible; si no queda ninguna, no hay forma
     * de entrar a arreglarlo.
     *
     */
    private const PERMISO_DE_RESCATE = 'usuario.editar';
    /**
     * Columnas que registran quién hizo qué.
     *
     * Las tablas existen desde la Fase 1 aunque sus modelos lleguen en las
     * fases 5 y 6, y el peligro es concreto en las dos direcciones:
     * `ventas.usuario_id` y `pagos.usuario_id` restringen el borrado, así que
     * la base devolvería un error de clave foránea en la cara del usuario; y
     * `movimientos_stock.usuario_id` y las de `ordenes_compra` son
     * nullOnDelete, así que borrar la cuenta pondría esas columnas en null y
     * el kardex dejaría de poder responder quién ajustó el stock — que es
     * exactamente la pregunta para la que existe (A-13).
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

        if ($this->tieneHistorial($usuario)) {
            $usuario->update(['activo' => false]);

            return false;
        }

        // Sin historial es una cuenta creada por error. Al borrarla, la fila de
        // `empleados` se va con ella (cascadeOnDelete: un legajo sin persona no
        // significa nada) y la de `clientes` sobrevive con user_id en null
        // (nullOnDelete: el cliente sigue existiendo, pasa a ser de mostrador).
        // Las dos cascadas están declaradas en el esquema y son distintas
        // porque los dos satélites son cosas distintas.
        DB::transaction(fn () => $usuario->delete());

        return true;
    }

        /**
     * Roles que se le pueden asignar a esta persona.
     *
     * Sólo los de su mismo ámbito. Cambiar de gestión a tienda no es un cambio
     * de rol: es cambiar qué clase de persona es, y el satélite lo demuestra.
     * Un Vendedor tiene legajo y fecha de ingreso; un Cliente tiene CUIT y
     * condición frente al IVA. Convertir uno en otro exigiría datos que este
     * formulario no pide y borraría historial laboral que nadie pidió borrar.
     *
     * Esto es a la vez el juego de opciones de la pantalla y la fuente de
     * verdad que valida CambiarRolRequest: una sola definición, no dos.
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
     * Acción separada de la edición, con su propio permiso. En el sistema
     * original `perfil_id` era un campo más del body de `user/update` y no se
     * comparaba la identidad del que pedía: cualquiera con permiso de edición
     * se asignaba el perfil Administrador (C-3).
     *
     * La prohibición de cambiar el rol PROPIO vive en CambiarRolRequest,
     * porque depende de quién está pidiendo y el servicio no conoce la sesión.
     * Acá viven las dos reglas que no dependen del que pide.
     *
     * No hace falta invalidar caché: `permisos.rol.{id}` es por rol, no por
     * usuario, y el rol no cambió. Y el permiso nuevo rige desde el request
     * siguiente porque Gate lo consulta cada vez — lo contrario de A-5, donde
     * el perfil viajaba dentro del JWT y el cambio tardaba hasta una hora.
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

            // Sólo si el rol nuevo NO conserva la capacidad de administrar:
            // pasar de Administrador a un rol que también puede editar
            // usuarios es perfectamente válido.
            if (! $rolNuevo->permisos()->where('clave', self::PERMISO_DE_RESCATE)->exists()) {
                $this->exigirQueQuedeAlguienQuePuedaAdministrar($usuario, 'cambiarle el rol a');
            }

            $usuario->update(['rol_id' => $rolNuevo->id]);

            return $usuario->fresh();
        });
    }

    /**
     * Cambia la contraseña de una cuenta.
     *
     * Borra los tokens de restablecimiento pendientes de ese correo. Si alguien
     * pidió un token y después se acordó de la contraseña y la cambió a mano, el
     * token viejo tiene que morir: si no, sigue sirviendo para cambiarla de
     * nuevo hasta que expire.
     */
    public function cambiarPassword(User $usuario, string $password): User
    {
        return DB::transaction(function () use ($usuario, $password) {
            // El cast 'hashed' del modelo lo encripta. Nunca se llama a bcrypt()
            // acá: si el hash se hiciera en dos lugares, algún día uno de los
            // dos se olvidaría.
            $usuario->update(['password' => $password]);

            DB::table('password_reset_tokens')->where('email', $usuario->email)->delete();

            return $usuario->fresh();
        });
    }

    /**
     * Crea o actualiza el satélite que corresponde al ámbito del rol.
     *
     * El `match` no tiene rama por defecto a propósito: si mañana apareciera un
     * ámbito nuevo, esto lanza UnhandledMatchError y la transacción revierte,
     * en vez de crear en silencio un usuario sin satélite. Es el mismo criterio
     * de denegación por defecto del Gate. Hoy el ENUM de la base sólo admite
     * dos valores, así que la rama es inalcanzable: está para el día que deje
     * de serlo.
     */
    private function guardarSatelite(User $usuario, Rol $rol, array $datos): void
    {
        match ($rol->ambito) {
            'gestion' => $usuario->empleado()->updateOrCreate([], [
                'legajo'        => $datos['empleado']['legajo'],
                'dni'           => $datos['empleado']['dni'],
                'telefono'      => $datos['empleado']['telefono'] ?? null,
                'fecha_ingreso' => $datos['empleado']['fecha_ingreso'],
                // La baja laboral NO toca users.activo: son datos distintos.
                // Un empleado dado de baja conserva su historial y sigue
                // apareciendo en los reportes del período en que trabajó.
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
     *
     * Es la misma idea que `roles.es_sistema`, que impide borrar el rol
     * Administrador y dejar el sistema sin nadie que lo administre. Acá se
     * aplica a las personas: un rol con permisos no sirve de nada si no queda
     * nadie que lo tenga.
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
                "No se puede {$accion} la única cuenta activa que puede administrar usuarios. "
                .'Asigná ese permiso a otra persona antes de continuar.'
            );
        }
    }
}