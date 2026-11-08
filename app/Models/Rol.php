<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
/**
 * Rol: el cargo de una persona y, con él, lo que puede hacer.
 *
 * `ambito` (gestion | tienda) decide a qué aplicación entra el usuario y si le
 * corresponde fila en `empleados` o en `clientes`. Es una columna explícita y
 * no una comparación por nombre: el frontend original decidía la visibilidad
 * del menú con `perfil === 'Administrador'` escrito a mano, así que renombrar
 * un perfil rompía la interfaz en silencio (hallazgo M-33).
 *
 * `es_sistema` protege los cinco roles base. Como el módulo permite
 * administrarlos, sin esa bandera alguien puede borrar el rol Administrador y
 * dejar el sistema sin nadie que lo administre.
 *
 * Relaciones:
 *   permisos()  BelongsToMany  vía rol_permiso
 *   usuarios()  HasMany        los que tienen este rol
 *
 * Métodos:
 *   esDeGestion()  si el ámbito es de gestión
 */
class Rol extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = ['nombre', 'descripcion', 'ambito', 'es_sistema'];

    protected function casts(): array
    {
        return ['es_sistema' => 'boolean'];
    }

    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'rol_permiso', 'rol_id', 'permiso_id');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }

    public function esDeGestion(): bool
    {
        return $this->ambito === 'gestion';
    }
}
