<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProductoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
|
| Reemplaza al RouterHandlerMiddleware, que deducía el controlador del primer
| segmento de la URL con ucfirst() y nunca validaba el método HTTP.
|
| Cada ruta declara explícitamente su verbo, su nombre y el permiso que
| exige. Un permiso mal escrito acá deniega el acceso; nunca lo concede.
|
| Grupos:
|   guest            login (con throttle:5,1)
|   auth             logout
|   auth + gestion   todo el sistema de gestión
|
*/

// Redirección inicial
Route::redirect('/', '/panel');

// Autenticación pública
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'mostrarLogin'])->name('login');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

// Cierre de sesión
Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Sistema de gestión
Route::middleware(['auth', 'gestion'])->group(function () {
    // Panel de control (provisional; la Fase 7 lo reemplaza por métricas reales)
    Route::view('/panel', 'panel.index')->name('panel');

    // Catálogo — Marcas
    Route::get('/marcas', [MarcaController::class, 'index'])
        ->middleware('can:marca.ver')
        ->name('marcas.index');

    Route::get('/marcas/crear', [MarcaController::class, 'create'])
        ->middleware('can:marca.crear')
        ->name('marcas.create');

    Route::post('/marcas', [MarcaController::class, 'store'])
        ->middleware('can:marca.crear')
        ->name('marcas.store');

    Route::get('/marcas/{marca}/editar', [MarcaController::class, 'edit'])
        ->middleware('can:marca.editar')
        ->name('marcas.edit');

    Route::put('/marcas/{marca}', [MarcaController::class, 'update'])
        ->middleware('can:marca.editar')
        ->name('marcas.update');

    Route::delete('/marcas/{marca}', [MarcaController::class, 'destroy'])
        ->middleware('can:marca.eliminar')
        ->name('marcas.destroy');

    // Catálogo — productos
    Route::get('/productos', [ProductoController::class, 'index'])
        ->middleware('can:producto.ver')->name('productos.index');

    Route::get('/productos/crear', [ProductoController::class, 'create'])
        ->middleware('can:producto.crear')->name('productos.create');

    Route::post('/productos', [ProductoController::class, 'store'])
        ->middleware('can:producto.crear')->name('productos.store');

    Route::get('/productos/{producto}/editar', [ProductoController::class, 'edit'])
        ->middleware('can:producto.editar')->name('productos.edit');

    Route::put('/productos/{producto}', [ProductoController::class, 'update'])
        ->middleware('can:producto.editar')->name('productos.update');

    Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])
        ->middleware('can:producto.eliminar')->name('productos.destroy');

    // Catálogo — categorías
    Route::get('/categorias', [CategoriaController::class, 'index'])
        ->middleware('can:categoria.ver')->name('categorias.index');

    Route::get('/categorias/crear', [CategoriaController::class, 'create'])
        ->middleware('can:categoria.crear')->name('categorias.create');

    Route::post('/categorias', [CategoriaController::class, 'store'])
        ->middleware('can:categoria.crear')->name('categorias.store');

    Route::get('/categorias/{categoria}/editar', [CategoriaController::class, 'edit'])
        ->middleware('can:categoria.editar')->name('categorias.edit');

    Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])
        ->middleware('can:categoria.editar')->name('categorias.update');

    Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])
        ->middleware('can:categoria.eliminar')->name('categorias.destroy');
        
    // Administración — Roles y permisos
    Route::get('/roles', [RolController::class, 'index'])
        ->middleware('can:rol.ver')
        ->name('roles.index');

    Route::middleware('can:rol.editar')->group(function () {
        Route::get('/roles/crear', [RolController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RolController::class, 'store'])->name('roles.store');
        Route::get('/roles/{rol}/editar', [RolController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{rol}', [RolController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');
    });
});