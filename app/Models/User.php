<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
}
