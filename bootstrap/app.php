<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
/*
|--------------------------------------------------------------------------
| Arranque de la aplicación
|--------------------------------------------------------------------------
|
| Reemplaza al App.php del sistema original, que registraba los middlewares
| del Pipeline a mano. Acá se declara qué middlewares corren, en qué orden y
| sobre qué grupo de rutas, y cómo se traduce cada excepción en respuesta.
|
| - withRouting()     archivos de rutas y punto de entrada
| - withMiddleware()  VerificarUsuarioActivo en todo el grupo web;
|                     VerificarAmbitoGestion como alias 'gestion'
| - withExceptions()  ReglaDeNegocioException vuelve como mensaje al usuario
|
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Se revalida en TODO request con sesión, no sólo en el login.
        $middleware->web(append: [
            \App\Http\Middleware\VerificarUsuarioActivo::class,
        ]);

        // Se aplica sólo al grupo de rutas de gestión.
        $middleware->alias([
            'gestion' => \App\Http\Middleware\VerificarAmbitoGestion::class,
        ]);
        $middleware->redirectUsersTo('/panel');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\ReglaDeNegocioException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        });
    })->create();
    
