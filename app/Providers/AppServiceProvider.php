<?php

namespace App\Providers;

use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
/**
 * Registro de servicios y reglas de autorización.
 *
 * Métodos:
 *   register()  enlaces del contenedor
 *   boot()      Gate::before con la resolución de permisos, y la insignia del menú
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Gate::before(function (User $user, string $ability) {
            return $user->tienePermiso($ability) ? true : null;
        });

        View::composer('partials.navbar', function ($vista) {
            $usuario = auth()->user();

            $vista->with('borradoresEsperando', $usuario?->can('compra.aprobar')
                ? OrdenCompra::query()->conEstado('borrador')->count()
                : 0);
        });
    }
}
