<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\Auth\RestablecerPasswordController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DireccionController;
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

    Route::get('/restablecer/{token}', [RestablecerPasswordController::class, 'mostrar'])
        ->name('password.reset');

    Route::post('/restablecer', [RestablecerPasswordController::class, 'restablecer'])
        ->middleware('throttle:5,1')
        ->name('password.store');
});

//CIERRE DE SESIÓN Y CUENTA
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/cuenta/password', [CuentaController::class, 'editarPassword'])
        ->name('cuenta.password.edit');

    Route::put('/cuenta/password', [CuentaController::class, 'actualizarPassword'])
        ->name('cuenta.password.update');
});

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

    // Personas — Personal (cuentas de ámbito gestión)
    Route::get('/personal', [PersonalController::class, 'index'])
        ->middleware('can:usuario.ver')->name('personal.index');

    Route::get('/personal/crear', [PersonalController::class, 'create'])
        ->middleware('can:usuario.crear')->name('personal.create');

    Route::post('/personal', [PersonalController::class, 'store'])
        ->middleware('can:usuario.crear')->name('personal.store');

    Route::get('/personal/{usuario}/editar', [PersonalController::class, 'edit'])
        ->middleware('can:usuario.editar')->name('personal.edit');

    Route::put('/personal/{usuario}', [PersonalController::class, 'update'])
        ->middleware('can:usuario.editar')->name('personal.update');

    Route::delete('/personal/{usuario}', [PersonalController::class, 'destroy'])
        ->middleware('can:usuario.eliminar')->name('personal.destroy');

    Route::get('/personal/{usuario}/rol', [PersonalController::class, 'editarRol'])
        ->middleware('can:usuario.cambiar_rol')->name('personal.rol.edit');

    Route::patch('/personal/{usuario}/rol', [PersonalController::class, 'cambiarRol'])
        ->middleware('can:usuario.cambiar_rol')->name('personal.rol.update');

    Route::post('/personal/{usuario}/restablecer-contrasena', [PersonalController::class, 'generarEnlaceDeRestablecimiento'])
        ->middleware('can:usuario.resetear_password')->name('personal.restablecer');

    // Personas — Clientes
    Route::get('/clientes', [ClienteController::class, 'index'])
        ->middleware('can:cliente.ver')->name('clientes.index');

    Route::get('/clientes/crear', [ClienteController::class, 'create'])
        ->middleware('can:cliente.crear')->name('clientes.create');

    Route::post('/clientes', [ClienteController::class, 'store'])
        ->middleware('can:cliente.crear')->name('clientes.store');

    Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit'])
        ->middleware('can:cliente.editar')->name('clientes.edit');

    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])
        ->middleware('can:cliente.editar')->name('clientes.update');

    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])
        ->middleware('can:cliente.eliminar')->name('clientes.destroy');

    // Direcciones del cliente.
    Route::middleware('can:cliente.editar')->group(function () {
        Route::get('/clientes/{cliente}/direcciones/crear', [DireccionController::class, 'create'])
            ->name('direcciones.create');

        Route::post('/clientes/{cliente}/direcciones', [DireccionController::class, 'store'])
            ->name('direcciones.store');

        Route::get('/clientes/{cliente}/direcciones/{direccion}/editar', [DireccionController::class, 'edit'])
            ->name('direcciones.edit');

        Route::put('/clientes/{cliente}/direcciones/{direccion}', [DireccionController::class, 'update'])
            ->name('direcciones.update');

        Route::delete('/clientes/{cliente}/direcciones/{direccion}', [DireccionController::class, 'destroy'])
            ->name('direcciones.destroy');
    });
        
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

     // Compras — Proveedores
    Route::get('/proveedores', [ProveedorController::class, 'index'])
        ->middleware('can:proveedor.ver')->name('proveedores.index');

    Route::get('/proveedores/crear', [ProveedorController::class, 'create'])
        ->middleware('can:proveedor.crear')->name('proveedores.create');

    Route::post('/proveedores', [ProveedorController::class, 'store'])
        ->middleware('can:proveedor.crear')->name('proveedores.store');

    Route::get('/proveedores/{proveedor}/editar', [ProveedorController::class, 'edit'])
        ->middleware('can:proveedor.editar')->name('proveedores.edit');

    Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])
        ->middleware('can:proveedor.editar')->name('proveedores.update');

    Route::delete('/proveedores/{proveedor}', [ProveedorController::class, 'destroy'])
        ->middleware('can:proveedor.eliminar')->name('proveedores.destroy');
        
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