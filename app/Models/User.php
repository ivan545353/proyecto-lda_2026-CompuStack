<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
/**
 * Usuario del sistema: credenciales, nombre para mostrar y rol.
 *
 * Es el núcleo de acceso y nada más. Los datos particulares de cada tipo de
 * persona viven en las tablas satélite: los fiscales en `clientes`, los
 * laborales en `empleados`. Meterlos todos acá dejaría columnas siempre nulas
 * para la mitad del padrón.
 *
 * $hidden sobre `password` cierra el hallazgo C-1: en el sistema original el
 * DTO de escritura y el de lectura eran el mismo objeto, y /user/list devolvía
 * el hash bcrypt de todos los usuarios.
 *
 * Relaciones:
 *   rol()       BelongsTo   el rol que determina sus permisos
 *   empleado()  HasOne      si su rol es de ámbito gestión
 *   cliente()   HasOne      si su rol es de ámbito tienda
 *
 * Métodos:
 *   getNombreCompletoAttribute()  accesor para mostrar
 *   clavesDePermiso()             claves del rol, cacheadas por una hora
 *   tienePermiso(string)          lo que consulta Gate::before
 *   esDeGestion()                 lee roles.ambito, nunca el nombre del rol
 *   claveCache(int)               la clave de caché, compartida con RolService
 * 
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * UsuarioFiltroRequest):
 *   ?q=          → buscar($texto)          nombre, apellido, correo o legajo
 *   ?rol_id=     → deRol($id)              rol exacto
 *   ?ambito=     → deAmbito($ambito)       gestion | tienda
 *   ?estado=     → conEstado($estado)      activos | inactivos (acceso)
 *   ?situacion=  → conSituacion($valor)    en_actividad | dados_de_baja (laboral)
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = ['nombre', 'apellido', 'email', 'password', 'rol_id', 'activo'];

    // Cierra el hallazgo C-1: el hash de contraseña nunca sale en una respuesta
    // ni en una vista.
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'activo'            => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function empleado(): HasOne
    {
        return $this->hasOne(Empleado::class, 'user_id');
    }

    public function cliente(): HasOne
    {
        return $this->hasOne(Cliente::class, 'user_id');
    }

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->apellido}, {$this->nombre}";
    }

    /**
     * Claves de permiso del rol, cacheadas por una hora.
     *
     * La caché es por rol y no por usuario: veinte vendedores comparten una sola
     * entrada, y cambiar los permisos del rol invalida a todos de una vez.
     *
     * @return Collection<int, string>
     */
    public function clavesDePermiso(): Collection
    {
        return Cache::remember(
            self::claveCache($this->rol_id),
            now()->addHour(),
            fn () => $this->rol->permisos()->pluck('clave'),
        );
    }

    public function tienePermiso(string $clave): bool
    {
        return $this->clavesDePermiso()->contains($clave);
    }

    /** Distingue el sistema de gestión de la tienda sin comparar nombres de rol. */
    public function esDeGestion(): bool
    {
        return $this->rol->ambito === 'gestion';
    }

    public static function claveCache(int $rolId): string
    {
        return "permisos.rol.{$rolId}";
    }

     // ------------------------------------------------------------------
    // Filtros del listado
    //
    // Un scope por parámetro de la URL, con el mismo nombre declarado acá y
    // en UsuarioFiltroRequest. Es la corrección de fondo de A-24: el
    // UserController original mandaba `nombres` y el UserDao leía `perfil_id`
    // y `estado`, así que el filtro por nombre se ignoraba en silencio. No
    // había ningún lugar donde constara cuál era el correcto.
    //
    // Las columnas van calificadas con `users.` aunque hoy no haya ningún
    // join: el día que lo haya, una columna ambigua rompe la consulta.
    // ------------------------------------------------------------------

    /**
     * Busca por nombre, apellido, correo o legajo.
     *
     * El legajo vive en `empleados`, no acá, así que entra por `orWhereHas`.
     * Se incluye porque es como el administrativo identifica al personal en
     * papel; excluirlo obligaría a recordar el apellido de alguien cuyo
     * legajo tenés delante.
     *
     * El OR va agrupado en un closure. Sin el grupo, encadenar otro filtro
     * después produce `(a AND b) OR c` en lugar de `a AND (b OR c)`: el
     * listado devolvería filas que no cumplen el otro filtro.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $patron = Like::contiene($texto);

            return $query->where(fn (Builder $query) => $query
                ->where('users.nombre', 'like', $patron)
                ->orWhere('users.apellido', 'like', $patron)
                ->orWhere('users.email', 'like', $patron)
                ->orWhereHas('empleado', fn (Builder $q) => $q->where('legajo', 'like', $patron)));
        });
    }

    public function scopeDeRol(Builder $query, int|string|null $id): Builder
    {
        return $query->when(
            ctype_digit((string) $id),
            fn (Builder $query) => $query->where('users.rol_id', (int) $id),
        );
    }

    /**
     * Separa el personal de las cuentas de la tienda.
     *
     * Consulta `roles.ambito`, nunca el nombre del rol. El frontend original
     * decidía con `perfil === 'Administrador'` escrito a mano (M-33), y
     * renombrar un perfil rompía la pantalla sin dar error.
     */
    public function scopeDeAmbito(Builder $query, ?string $ambito): Builder
    {
        return match ($ambito) {
            'gestion', 'tienda' => $query->whereHas(
                'rol',
                fn (Builder $q) => $q->where('ambito', $ambito),
            ),
            default => $query,
        };
    }

    /** Acceso al sistema: si puede o no iniciar sesión. */
    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            'activos'   => $query->where('users.activo', true),
            'inactivos' => $query->where('users.activo', false),
            default     => $query,
        };
    }

    /**
     * Vínculo laboral, que NO es lo mismo que el acceso.
     *
     * `users.activo` dice si puede entrar; `empleados.fecha_baja` dice si
     * sigue trabajando. Un empleado dado de baja conserva su historial de
     * ventas y sigue apareciendo en los reportes del período en que trabajó,
     * así que la baja laboral nunca borra ni desactiva por su cuenta.
     *
     * Sólo aplica a quien tiene fila en `empleados`: las cuentas de tienda
     * quedan fuera de las dos opciones, que es lo correcto.
     */
    public function scopeConSituacion(Builder $query, ?string $situacion): Builder
    {
        return match ($situacion) {
            'en_actividad'  => $query->whereHas('empleado', fn (Builder $q) => $q->whereNull('fecha_baja')),
            'dados_de_baja' => $query->whereHas('empleado', fn (Builder $q) => $q->whereNotNull('fecha_baja')),
            default         => $query,
        };
    }
}
