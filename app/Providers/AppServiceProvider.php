<?php

namespace App\Providers;

use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
/**
 * Registro de servicios y reglas de autorización.
 *
 * Métodos:
 *   register()  enlaces del contenedor
 *   boot()      Gate::before con la resolución de permisos, el límite de intentos de la
 *               API y la insignia del menú
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

        $this->limiteDeAccesoDeLaTienda();

        View::composer('partials.navbar', function ($vista) {
            $usuario = auth()->user();

            $vista->with('borradoresEsperando', $usuario?->can('compra.aprobar')
                ? OrdenCompra::query()->conEstado('borrador')->count()
                : 0);
        });
    }

    /**
     * Límite de intentos del inicio de sesión y del registro de la API.
     */
    private function limiteDeAccesoDeLaTienda(): void
    {
        RateLimiter::for('tienda-acceso', function (Request $request) {
            $correo = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('tienda-acceso|'.$correo.'|'.$request->ip()),
                Limit::perMinute(30)->by('tienda-acceso-ip|'.$request->ip()),
            ];
        });
    }
}
