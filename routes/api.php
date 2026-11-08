<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la tienda — /api/v1
|--------------------------------------------------------------------------
|
| El prefijo `api` y el grupo de middlewares `api` (ForzarJson) los pone
| `withRouting(api: …)` en bootstrap/app.php; acá se agrega la versión.
|
| Tres grupos, según lo que exige la ruta (docs/api.md, «Autenticación»). Los
| middlewares de cada uno se definen UNA vez, en bootstrap/app.php, como grupos
| con nombre: las rutas y los tests nombran el mismo grupo, y no hay dos listas
| que puedan separarse.
|
|   pública          nada. La credencial no se lee.
|   sesión opcional  el catálogo. Sin credencial sigue como visitante; con una
|                    inválida, 401 `sesion_invalida`. Con sesión y permiso, agrega precios.
|   protegida        credencial válida, cuenta activa y de tienda; el permiso lo pide
|                    cada ruta.
|
| Todavía vacíos: los endpoints entran por tramo (C catálogo, D cuenta y precios,
| E carrito, F pedidos, G pagos, H consulta). El inicio de sesión y el registro,
| que van con `throttle:tienda-acceso`, son del tramo D.
|
*/
Route::prefix('v1')->group(function () {

    // Pública.
    Route::group([], function () {
        //
    });

    // Sesión opcional.
    Route::middleware('tienda.sesion_opcional')->group(function () {
        //
    });

    // Protegida.
    Route::middleware('tienda.protegida')->group(function () {
        //
    });
});
