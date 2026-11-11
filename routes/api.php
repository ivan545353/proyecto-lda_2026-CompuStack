<?php

use App\Http\Controllers\Api\V1\CarritoController;
use App\Http\Controllers\Api\V1\CarritoLineaController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\RegistroController;
use App\Http\Controllers\Api\V1\SesionController;
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
        // El árbol no depende de quién mira: no lee la credencial (plan §6.8).
        Route::get('categorias', [CategoriaController::class, 'index']);

        // Límite por correo e IP (AppServiceProvider): frena la prueba de contraseñas y el alta en masa.
        Route::middleware('throttle:tienda-acceso')->group(function () {
            Route::post('registro', [RegistroController::class, 'store']);
            Route::post('sesion', [SesionController::class, 'store']);
        });
    });

    // Sesión opcional.
    Route::middleware('tienda.sesion_opcional')->group(function () {
        Route::get('productos', [ProductoController::class, 'index']);
        // Sin binding implícito: el modelo encontraría un producto inactivo.
        Route::get('productos/{producto}', [ProductoController::class, 'show'])->whereNumber('producto');
    });

    // Protegida.
    Route::middleware('tienda.protegida')->group(function () {
        // Sin permiso propio: cerrar la sesión es de toda cuenta, como cambiar la contraseña.
        Route::delete('sesion', [SesionController::class, 'destroy']);
        Route::get('perfil', [PerfilController::class, 'show']);

        // El carrito (tramo E). `{linea}` sin binding implícito: el servicio la busca dentro del
        // carrito del usuario, y una ajena es 404 igual que una que no existe.
        Route::middleware('can:tienda.comprar')->group(function () {
            Route::get('carrito', [CarritoController::class, 'show']);
            Route::delete('carrito', [CarritoController::class, 'vaciar']);
            Route::post('carrito/lineas', [CarritoLineaController::class, 'store']);
            Route::patch('carrito/lineas/{linea}', [CarritoLineaController::class, 'update'])->whereNumber('linea');
            Route::delete('carrito/lineas/{linea}', [CarritoLineaController::class, 'destroy'])->whereNumber('linea');
            // Reserva el carrito. Cualquier escritura posterior al carrito suelta la reserva.
            Route::post('carrito/checkout', [CheckoutController::class, 'store']);
        });
    });
});
