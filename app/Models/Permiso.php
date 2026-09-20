<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
/**
 * Permiso: una acción nombrada del negocio.
 *
 * `clave` sigue el formato modulo.accion — venta.anular, compra.aprobar. Es la
 * cadena que se pasa a Gate y a la directiva @can.
 *
 * Reemplaza a las cuatro banderas CRUD por (perfil, módulo) del sistema
 * original. Ese diseño no podía expresar acciones que no fueran crear, leer,
 * actualizar o borrar, y el negocio está lleno de ellas: cobrar, anular,
 * aprobar una orden de compra. De ahí salía el valor por omisión permisivo que
 * produjo el hallazgo C-2.
 *
 * `modulo` es sólo el agrupador visual de la pantalla de asignación.
 *
 * Relaciones:
 *   roles()  BelongsToMany  los roles que lo tienen
 */
class Permiso extends Model
{
    use HasFactory;

    protected $table = 'permisos';

    protected $fillable = ['clave', 'modulo', 'descripcion'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'rol_permiso', 'permiso_id', 'rol_id');
    }
}
