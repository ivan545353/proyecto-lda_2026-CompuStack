<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
/**
 * Registro de servicios y reglas de autorización.
 *
 * Acá vive el Gate::before que resuelve TODOS los permisos del sistema. Es el
 * reemplazo del AuthorizationHandlerMiddleware original, que traducía la acción
 * a una de cuatro banderas CRUD con `?? "can_update"` por defecto: toda acción
 * fuera del CRUD heredaba el permiso de actualizar
 *
 * El callback concede si el rol tiene el permiso y devuelve null si no lo
 * tiene. Como no hay ninguna otra regla definida, null implica denegar: eso es
 * la denegación por defecto.
 *
 * Métodos:
 *   register()  enlaces del contenedor
 *   boot()      Gate::before con la resolución de permisos
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Concede si el rol del usuario tiene la clave. Si no la tiene devuelve
        // null, y el flujo normal de Gate —sin regla definida para esa
        // habilidad— DENIEGA. Denegación por defecto.
        //
        // el sistema original resolvía el permiso con
        // MAPA_PERMISOS[$action] ?? "can_update", así que toda acción fuera del
        // CRUD (cobrar, anular, habilitar) heredaba el permiso de actualizar.
        // Un vendedor con can_update sobre el módulo sale podía anular ventas
        // cobradas teniendo can_delete = 0.
        Gate::before(function (User $user, string $ability) {
            return $user->tienePermiso($ability) ? true : null;
        });
    }
}
