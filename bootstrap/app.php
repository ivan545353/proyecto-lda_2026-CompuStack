<?php

use App\Http\Api\RespuestaDeError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
/*
|--------------------------------------------------------------------------
| Arranque de la aplicación
|--------------------------------------------------------------------------
|
| Reemplaza al App.php del sistema original, que registraba los middlewares
| del Pipeline a mano. Acá se declara qué middlewares corren, en qué orden y
| sobre qué grupo de rutas, y cómo se traduce cada excepción en respuesta.
|
| - withRouting()     archivos de rutas y punto de entrada; `api:` agrega /api con
|                     el grupo de middlewares `api`
| - withMiddleware()  web: AutenticarConCredencial y VerificarUsuarioActivo en todo el grupo.
|                     api: ForzarJson en todo el grupo; y tres grupos con nombre,
|                     `api.protegida` (los dos clientes), `tienda.sesion_opcional`
|                     y `tienda.protegida`, que usan routes/api.php y los tests.
|                     Alias: 'gestion' (VerificarAmbitoGestion)
| - withExceptions()  Dos traducciones, y el orden importa:
|                     1. `api/*`: cualquier excepción vuelve con la forma de error
|                        del contrato (RespuestaDeError). Decide por la URL y no por
|                        `expectsJson()`: una ruta inexistente no corre middlewares.
|                     2. web: ReglaDeNegocioException vuelve como mensaje al usuario,
|                        con redirección. Es la de siempre, y no cambió.
|
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // La administración entra con la credencial de la API, en cookie (D-30): la lee
        // AutenticarConCredencial y después se revalida la cuenta en TODO request.
        // El JS la escribe y la lee para mandarla como Bearer: no puede ir cifrada.
        $middleware->encryptCookies(except: [\App\Http\Middleware\AutenticarConCredencial::COOKIE]);
        $middleware->web(append: [
            \App\Http\Middleware\AutenticarConCredencial::class,
            \App\Http\Middleware\VerificarUsuarioActivo::class,
        ]);

        // Ninguna respuesta de api/* es HTML ni redirección: va primero.
        $middleware->api(prepend: [
            \App\Http\Middleware\Api\ForzarJson::class,
        ]);

        // Se aplica sólo al grupo de rutas de gestión.
        $middleware->alias([
            'gestion'               => \App\Http\Middleware\VerificarAmbitoGestion::class,
            'tienda.sesion.opcional' => \App\Http\Middleware\Api\SesionOpcional::class,
            'api.cuenta_activa'      => \App\Http\Middleware\Api\VerificarCuentaActiva::class,
            'tienda.cuenta'          => \App\Http\Middleware\Api\VerificarCuentaTienda::class,
        ]);

        // La credencial se lee antes de que `auth` pregunte quién es: sin esto, la prioridad
        // del framework adelanta Authenticate y toda credencial válida terminaría en el login.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\AutenticarConCredencial::class,
        );

        // La frontera corre antes de buscar el modelo de la ruta: si no, una cuenta de la
        // tienda distinguiría un id que existe (login) de uno que no (404) (HL-54).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\VerificarAmbitoGestion::class,
        );

        // Los tipos de ruta con credencial (docs/api.md, «Autenticación»). Se definen
        // una vez: las rutas y los tests nombran el mismo grupo.
        // Común a los dos clientes: entrar es de toda cuenta activa (HL-98).
        $middleware->group('api.protegida', [
            'auth:sanctum',             // sin credencial o inválida → 401
            'api.cuenta_activa',        // cuenta desactivada → 401
        ]);
        $middleware->group('tienda.sesion_opcional', [
            'tienda.sesion.opcional',   // credencial inválida → 401; sin credencial, visitante
            'api.cuenta_activa',
            'tienda.cuenta',
        ]);
        $middleware->group('tienda.protegida', [
            'auth:sanctum',
            'api.cuenta_activa',
            'tienda.cuenta',            // cuenta de gestión → 401 (D-3)
        ]);

        $middleware->redirectUsersTo('/panel');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 1. La API. Va primero: ReglaDeNegocioException y sus hijas también llegan
        //    acá, y el de abajo las dejaría pasar con una redirección que a la app
        //    no le sirve.
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*')
                ? RespuestaDeError::desde($e, $request)
                : null;
        });

        // 2. La web, sin cambios. Si por algún motivo llegara una de api/*, la deja seguir.
        $exceptions->render(function (\App\Exceptions\ReglaDeNegocioException $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return back()->with('error', $e->getMessage())->withInput();
        });
    })->create();
