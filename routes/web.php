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
use App\Http\Controllers\StockController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ProductoProveedorController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\InformeVentasController;
use App\Http\Controllers\PrecioController;
use App\Http\Controllers\BusquedaController;
use App\Http\Controllers\AjusteMasivoController;
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

// Redirección inicial. Va al inicio y no al panel: ahora el panel exige permiso.
Route::redirect('/', '/inicio');

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
    // Panel. Dos rutas y dos permisos
    Route::get('/inicio', [PanelController::class, 'inicio'])->name('inicio');

    Route::get('/panel', [PanelController::class, 'propio'])
        ->middleware('can:panel.ver_propio')->name('panel');

    Route::get('/panel/general', [PanelController::class, 'general'])
        ->middleware('can:panel.ver_global')->name('panel.general');

    // Catálogo — Marcas
    Route::get('/marcas', [MarcaController::class, 'index'])
        ->middleware('can:marca.ver')
        ->name('marcas.index');

    Route::get('/marcas/imprimir', [MarcaController::class, 'imprimir'])
        ->middleware('can:marca.ver')
        ->name('marcas.imprimir');

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
    
    // Imprimir lo que el listado muestra pide el mismo permiso que verlo: el costo lo cuida su columna.
    Route::get('/productos/imprimir', [ProductoController::class, 'imprimir'])
        ->middleware('can:producto.ver')->name('productos.imprimir');

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
    Route::post('/productos/{producto}/recalcular', [ProductoController::class, 'recalcular'])
        ->middleware('can:precio.recalcular')->name('productos.recalcular');
    
    // Proveedores de un producto: el comparador de precios.
    Route::middleware('can:producto.editar')->group(function () {
    Route::get('/productos/{producto}/proveedores', [ProductoProveedorController::class, 'index'])
        ->name('producto-proveedores.index');

    Route::post('/productos/{producto}/proveedores', [ProductoProveedorController::class, 'store'])
        ->name('producto-proveedores.store');

    Route::get('/productos/{producto}/proveedores/{proveedor}/editar', [ProductoProveedorController::class, 'edit'])
        ->name('producto-proveedores.edit');

    Route::put('/productos/{producto}/proveedores/{proveedor}', [ProductoProveedorController::class, 'update'])
        ->name('producto-proveedores.update');

    // Acción propia: elegir a quién pedirle sin reenviar el costo ni el código.
    Route::patch('/productos/{producto}/proveedores/{proveedor}/preferido', [ProductoProveedorController::class, 'preferido'])
        ->name('producto-proveedores.preferido');

    Route::delete('/productos/{producto}/proveedores/{proveedor}', [ProductoProveedorController::class, 'destroy'])
        ->name('producto-proveedores.destroy');
});

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

    Route::get('/clientes/imprimir', [ClienteController::class, 'imprimir'])
        ->middleware('can:cliente.ver')->name('clientes.imprimir');

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

    Route::get('/categorias/imprimir', [CategoriaController::class, 'imprimir'])
        ->middleware('can:categoria.ver')->name('categorias.imprimir');

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

    Route::get('/proveedores/imprimir', [ProveedorController::class, 'imprimir'])
        ->middleware('can:proveedor.ver')->name('proveedores.imprimir');
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

    // Compras — Stock y kardex
    Route::get('/stock', [StockController::class, 'index'])
        ->middleware('can:stock.ver')->name('stock.index');

    Route::get('/stock/imprimir', [StockController::class, 'imprimir'])
        ->middleware('can:stock.ver')->name('stock.imprimir');

    Route::get('/stock/{producto}/ajuste', [StockController::class, 'crearAjuste'])
        ->middleware('can:stock.ajustar')->name('stock.ajuste.create');

    Route::post('/stock/{producto}/ajuste', [StockController::class, 'guardarAjuste'])
        ->middleware('can:stock.ajustar')->name('stock.ajuste.store');

    // Buscador remoto.
    Route::get('/buscar/productos', [BusquedaController::class, 'productos'])
        ->middleware('can:producto.ver')->name('busqueda.productos');

    Route::get('/buscar/productos/{producto}/proveedores', [BusquedaController::class, 'proveedoresDeProducto'])
        ->whereNumber('producto')
        ->middleware('can:proveedor.ver')->name('busqueda.producto.proveedores');

    Route::get('/buscar/clientes', [BusquedaController::class, 'clientes'])
        ->middleware('can:cliente.ver')->name('busqueda.clientes');

    Route::get('/buscar/proveedores', [BusquedaController::class, 'proveedores'])
        ->middleware('can:proveedor.ver')->name('busqueda.proveedores');

    // Compras
    Route::get('/compras', [CompraController::class, 'index'])
        ->middleware('can:compra.ver')->name('compras.index');

    Route::get('/compras/imprimir', [CompraController::class, 'imprimir'])
        ->middleware('can:compra.ver')->name('compras.imprimir');
    
    Route::get('/compras/pedido', [CompraController::class, 'armar'])
        ->middleware('can:compra.crear')->name('compras.pedido');

    Route::post('/compras/pedido', [CompraController::class, 'guardarPedido'])
        ->middleware('can:compra.crear')->name('compras.pedido.store');

    Route::get('/compras/sugeridos', [CompraController::class, 'sugeridos'])
        ->middleware('can:compra.crear')->name('compras.sugeridos');

    Route::get('/compras/{orden}', [CompraController::class, 'show'])
        ->whereNumber('orden')
        ->middleware('can:compra.ver')->name('compras.show');

    Route::get('/compras/{orden}/editar', [CompraController::class, 'edit'])
        ->whereNumber('orden')
        ->middleware('can:compra.editar')->name('compras.edit');

    Route::put('/compras/{orden}', [CompraController::class, 'update'])
        ->whereNumber('orden')
        ->middleware('can:compra.editar')->name('compras.update');

    // Acciones de estado. 
    Route::post('/compras/{orden}/aprobar', [CompraController::class, 'aprobar'])
        ->whereNumber('orden')
        ->middleware('can:compra.aprobar')->name('compras.aprobar');

    // Marcar enviada exige el mismo permiso que aprobar
    Route::post('/compras/{orden}/enviada', [CompraController::class, 'marcarEnviada'])
        ->whereNumber('orden')
        ->middleware('can:compra.aprobar')->name('compras.enviada');

    // Cerrar incompleta es parte del ciclo de recepción: el proveedor no va a
    // entregar el resto y la orden se cierra con lo que llegó.
    Route::post('/compras/{orden}/cerrar', [CompraController::class, 'cerrarIncompleta'])
        ->whereNumber('orden')
        ->middleware('can:compra.recibir')->name('compras.cerrar');

    Route::post('/compras/{orden}/cancelar', [CompraController::class, 'cancelar'])
        ->whereNumber('orden')
        ->middleware('can:compra.aprobar')->name('compras.cancelar');

    // Recepción de mercadería. Dos rutas con el mismo permiso
    Route::get('/compras/{orden}/recepcion', [CompraController::class, 'recepcion'])
        ->whereNumber('orden')
        ->middleware('can:compra.recibir')->name('compras.recepcion');

    Route::post('/compras/{orden}/recepcion', [CompraController::class, 'recibir'])
        ->whereNumber('orden')
        ->middleware('can:compra.recibir')->name('compras.recibir');

    Route::get('/compras/{orden}/pdf', [CompraController::class, 'pdf'])
        ->whereNumber('orden')
        ->middleware('can:compra.ver')->name('compras.pdf');
        
    // Ventas
    Route::get('/ventas', [VentaController::class, 'index'])
        ->middleware('can:venta.ver')->name('ventas.index');
        
    Route::get('/ventas/crear', [VentaController::class, 'create'])
        ->middleware('can:venta.crear')->name('ventas.create');

    Route::post('/ventas', [VentaController::class, 'store'])
    ->middleware('can:venta.crear')->name('ventas.store');

    Route::get('/ventas/exportar', [VentaController::class, 'exportar'])
    ->middleware('can:venta.ver')->name('ventas.exportar');

    // Los informes que salieron del panel: muestran el conjunto, así que piden el mismo
    // permiso que el panel general y no venta.ver, que tiene también el Vendedor.
    Route::get('/ventas/vendedores', [InformeVentasController::class, 'vendedores'])
        ->middleware('can:panel.ver_global')->name('ventas.vendedores');

    Route::get('/ventas/productos', [InformeVentasController::class, 'productos'])
        ->middleware('can:panel.ver_global')->name('ventas.productos');
        
    Route::get('/ventas/{venta}', [VentaController::class, 'show'])
        ->whereNumber('venta')
        ->middleware('can:venta.ver')->name('ventas.show');

        Route::get('/ventas/{venta}/editar', [VentaController::class, 'edit'])
        ->whereNumber('venta')
        ->middleware('can:venta.editar')->name('ventas.edit');

    Route::put('/ventas/{venta}', [VentaController::class, 'update'])
        ->whereNumber('venta')
        ->middleware('can:venta.editar')->name('ventas.update');

    Route::post('/ventas/{venta}/recotizar', [VentaController::class, 'recotizar'])
        ->whereNumber('venta')
        ->middleware('can:venta.editar')->name('ventas.recotizar');

    Route::post('/ventas/{venta}/cancelar', [VentaController::class, 'cancelar'])
        ->whereNumber('venta')
        ->middleware('can:venta.editar')->name('ventas.cancelar');

    // COBRO Y ENTREGA
    Route::get('/ventas/{venta}/cobro', [PagoController::class, 'create'])
        ->whereNumber('venta')
        ->middleware('can:venta.cobrar')->name('pagos.create');

    Route::post('/ventas/{venta}/cobro', [PagoController::class, 'store'])
        ->whereNumber('venta')
        ->middleware('can:venta.cobrar')->name('pagos.store');

    Route::get('/ventas/{venta}/entrega', [VentaController::class, 'entrega'])
        ->whereNumber('venta')
        ->middleware('can:venta.entregar')->name('ventas.entrega');

    Route::post('/ventas/{venta}/entregar', [VentaController::class, 'entregar'])
        ->whereNumber('venta')
        ->middleware('can:venta.entregar')->name('ventas.entregar');

    // El comprobante se busca dentro de su venta: el de otra venta da 404.
    Route::get('/ventas/{venta}/entregas/{entrega}/pdf', [VentaController::class, 'entregaPdf'])
        ->whereNumber(['venta', 'entrega'])
        ->scopeBindings()
        ->middleware('can:venta.ver')->name('ventas.entregas.pdf');

    Route::get('/ventas/{venta}/devolucion', [VentaController::class, 'devolucion'])
        ->whereNumber('venta')
        ->middleware('can:venta.anular')->name('ventas.devolucion');

    Route::post('/ventas/{venta}/devolucion', [VentaController::class, 'devolver'])
        ->whereNumber('venta')
        ->middleware('can:venta.anular')->name('ventas.devolver');

    Route::get('/ventas/{venta}/pdf', [VentaController::class, 'pdf'])
        ->whereNumber('venta')
        ->middleware('can:venta.ver')->name('ventas.pdf');

    // Administración — Roles y permisos
    Route::get('/roles', [RolController::class, 'index'])
        ->middleware('can:rol.ver')
        ->name('roles.index');
    
    // Administración — Precios
    Route::get('/precios', [PrecioController::class, 'index'])
        ->middleware('can:precio.ver')->name('precios.index');
    
    Route::get('/precios/simulador', [PrecioController::class, 'simulador'])
        ->middleware('can:precio.ver')->name('precios.simulador');

    // Precios — ajuste masivo. 
    Route::get('/precios/ajuste', [AjusteMasivoController::class, 'index'])
        ->middleware('can:precio.ajustar_masivo')->name('precios.ajuste');

    Route::post('/precios/ajuste', [AjusteMasivoController::class, 'aplicar'])
        ->middleware('can:precio.ajustar_masivo')->name('precios.ajuste.aplicar');

    Route::get('/precios/lotes', [AjusteMasivoController::class, 'lotes'])
        ->middleware('can:precio.ver')->name('precios.lotes');

    Route::post('/precios/lotes/{lote}/deshacer', [AjusteMasivoController::class, 'deshacer'])
        ->middleware('can:precio.deshacer_ajuste')->name('precios.lotes.deshacer');

    Route::middleware('can:precio.editar')->group(function () {
            Route::put('/precios/politica', [PrecioController::class, 'actualizarPolitica'])
                ->name('precios.politica.update');

            Route::get('/precios/impuestos/crear', [PrecioController::class, 'crearImpuesto'])
                ->name('precios.impuestos.create');

            Route::post('/precios/impuestos', [PrecioController::class, 'guardarImpuesto'])
                ->name('precios.impuestos.store');

            Route::get('/precios/impuestos/{impuesto}/editar', [PrecioController::class, 'editarImpuesto'])
                ->name('precios.impuestos.edit');

            Route::put('/precios/impuestos/{impuesto}', [PrecioController::class, 'actualizarImpuesto'])
                ->name('precios.impuestos.update');

            Route::delete('/precios/impuestos/{impuesto}', [PrecioController::class, 'eliminarImpuesto'])
                ->name('precios.impuestos.destroy');

            Route::get('/precios/factores/crear', [PrecioController::class, 'crearFactor'])
                ->name('precios.factores.create');

            Route::post('/precios/factores', [PrecioController::class, 'guardarFactor'])
                ->name('precios.factores.store');

            Route::get('/precios/factores/{factor}/editar', [PrecioController::class, 'editarFactor'])
                ->name('precios.factores.edit');

            Route::put('/precios/factores/{factor}', [PrecioController::class, 'actualizarFactor'])
                ->name('precios.factores.update');

            Route::delete('/precios/factores/{factor}', [PrecioController::class, 'eliminarFactor'])
                ->name('precios.factores.destroy');
        });

    Route::middleware('can:rol.editar')->group(function () {
        Route::get('/roles/crear', [RolController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RolController::class, 'store'])->name('roles.store');
        Route::get('/roles/{rol}/editar', [RolController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{rol}', [RolController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{rol}', [RolController::class, 'destroy'])->name('roles.destroy');
    });
});