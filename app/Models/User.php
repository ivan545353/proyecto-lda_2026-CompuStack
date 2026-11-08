<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
/**
 * Usuario del sistema: credenciales, nombre para mostrar y rol.
 *
 * Es el núcleo de acceso y nada más. Los datos particulares de cada tipo de
 * persona viven en las tablas satélite: los fiscales en `clientes`, los
 * laborales en `empleados`. Meterlos todos acá dejaría columnas siempre nulas
 * para la mitad del padrón.
 *
 * Relaciones:
 *   rol()       BelongsTo   el rol que determina sus permisos
 *   empleado()  HasOne      si su rol es de ámbito gestión
 *   cliente()   HasOne      si su rol es de ámbito tienda
 *   ventas()    HasMany     lo que vendió
 *   pagos()     HasMany     lo que cobró, que no es lo mismo que haber vendido
 *
 * Métodos:
 *   getNombreCompletoAttribute()  accesor para mostrar
 *   clavesDePermiso()             claves del rol, memorizadas por petición
 *   tienePermiso(string)          lo que consulta Gate::before
 *   esDeGestion()                 lee roles.ambito, nunca el nombre del rol
 *   claveCache(int)               la clave de caché, compartida con RolService
 * 
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * PersonalFiltroRequest):
 *   ?q=          → buscar($texto)          nombre, apellido, correo o legajo
 *   ?rol_id=     → deRol($id)              rol exacto
 *   ?ambito=     → deAmbito($ambito)       gestion | tienda
 *   ?estado=     → conEstado($estado)      activos | inactivos (acceso)
 *   ?situacion=  → conSituacion($valor)    en_actividad | dados_de_baja (laboral)
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Las ventas que hizo esta persona, como vendedor.
     */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'usuario_id');
    }

    /**
     * Los cobros que registró esta persona, como cajero.
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'usuario_id');
    }

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->apellido}, {$this->nombre}";
    }

    /**
     * El resultado de clavesDePermiso() dentro de esta petición.
     * @var Collection<int, string>|null
     */
    private ?Collection $clavesMemorizadas = null;

    /**
     * Claves de permiso del rol, memorizadas por petición y cacheadas por una hora.
     *
     * La caché compartida es por rol y no por usuario: veinte vendedores comparten
     * una sola entrada, y cambiar los permisos del rol invalida a todos de una vez.
     *
     * @return Collection<int, string>
     */
    public function clavesDePermiso(): Collection
    {
        return $this->clavesMemorizadas ??= Cache::remember(
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
    // ------------------------------------------------------------------

    /**
     * Busca por nombre, apellido, correo o legajo.
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
