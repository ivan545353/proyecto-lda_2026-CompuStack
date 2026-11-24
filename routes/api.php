<?php

use App\Http\Controllers\Api\V1\ComprobanteTransferenciaController;
use App\Http\Controllers\Api\V1\AjusteMasivoController;
use App\Http\Controllers\Api\V1\CarritoController;
use App\Http\Controllers\Api\V1\CarritoLineaController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ClienteController;
use App\Http\Controllers\Api\V1\CompraController;
use App\Http\Controllers\Api\V1\ContrasenaController;
use App\Http\Controllers\Api\V1\DireccionController;
use App\Http\Controllers\Api\V1\FactorCostoController;
use App\Http\Controllers\Api\V1\ImpresionController;
use App\Http\Controllers\Api\V1\ImpuestoController;
use App\Http\Controllers\Api\V1\InformeController;
use App\Http\Controllers\Api\V1\MarcaController;
use App\Http\Controllers\Api\V1\PagoPedidoController;
use App\Http\Controllers\Api\V1\PanelController;
use App\Http\Controllers\Api\V1\PedidoController;
use App\Http\Controllers\Api\V1\PersonalController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\PoliticaPreciosController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\ProductoProveedorController;
use App\Http\Controllers\Api\V1\ProveedorController;
use App\Http\Controllers\Api\V1\RegistroController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\SesionController;
use App\Http\Controllers\Api\V1\SimuladorController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\TiendaController;
use App\Http\Controllers\Api\V1\VentaController;
use App\Http\Controllers\Api\V1\YoController;
use App\Impresion\ComprobanteVenta;
use App\Impresion\DocumentosDeVenta;
use App\Impresion\ImpresionFirmada;
use App\Impresion\PedidoCompra;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la tienda — /api/v1
|--------------------------------------------------------------------------
|
| El prefijo `api` y el grupo de middlewares `api` (ForzarJson) los pone
| `withRouting(api: …)` en bootstrap/app.php; acá se agrega la versión.
|
| Cuatro grupos, según lo que exige la ruta (docs/api.md, «Autenticación»). Los
| middlewares de cada uno se definen UNA vez, en bootstrap/app.php, como grupos
| con nombre: las rutas y los tests nombran el mismo grupo, y no hay dos listas
| que puedan separarse.
|
|   pública          nada. La credencial no se lee.
|   sesión opcional  el catálogo. Sin credencial sigue como visitante; con una
|                    inválida, 401 `sesion_invalida`. Con sesión y permiso, agrega precios.
|   protegida común  credencial válida y cuenta activa, de gestión o de tienda (HL-98).
|   protegida        credencial válida, cuenta activa y de tienda; el permiso lo pide
|                    cada ruta.
|
| Todavía vacíos: los endpoints entran por tramo (C catálogo, D cuenta y precios,
| E carrito, F pedidos, G pagos, H consulta). El inicio de sesión y el registro,
| que van con `throttle:tienda-acceso`, son del tramo D.
|
*/
Route::prefix('v1')->group(function () {

    // Credencial si sirve: el árbol no depende de quién mira (plan Etapa 2 §6.8); la gestión,
    // con permiso, pide además sus filtros (tramo D). Una credencial que no sirve no es 401.
    Route::middleware('api.credencial_si_sirve')->group(function () {
        Route::get('categorias', [CategoriaController::class, 'index']);
    });

    // Pública.
    Route::group([], function () {
        // Lo que la tienda ofrece, para el bloque de confianza de Inicio (tramo J, HL-81). Sin precios.
        Route::get('tienda', [TiendaController::class, 'show']);

        // Límite por correo e IP (AppServiceProvider): frena la prueba de contraseñas y el alta en masa.
        // El inicio de sesión es el mismo para gestión y tienda: el límite también.
        Route::middleware('throttle:tienda-acceso')->group(function () {
            Route::post('registro', [RegistroController::class, 'store']);
            Route::post('sesion', [SesionController::class, 'store']);
            // Fijar la contraseña con el enlace (tramo I): el token es la autorización, y probar tokens
            // también se frena por correo e IP.
            Route::post('contrasena/restablecer', [ContrasenaController::class, 'restablecer']);
        });

        // El comprobante, abierto por el navegador sin credencial: la firma temporal es la
        // autorización (D-22). La pide `GET pedidos/{pedido}/comprobante`; vencida o tocada, 403.
        Route::get('pedidos/{pedido}/comprobante.pdf', [PedidoController::class, 'descargarComprobante'])
            ->whereNumber('pedido')->middleware('signed')->name(ComprobanteVenta::RUTA);

        // Los listados impresos, por el mismo camino (tramo D): la firma cubre quién lo pidió, los
        // filtros y las opciones; al abrirlo se vuelve a exigir cuenta activa y permiso.
        Route::get('impresiones/{listado}', [ImpresionController::class, 'descargar'])
            ->whereIn('listado', array_keys(ImpresionFirmada::LISTADOS))
            ->middleware('signed')->name(ImpresionFirmada::RUTA);

        // El pedido formal de una orden de compra (tramo G, HL-126): igual que un listado, la firma cubre
        // quién lo pidió y al abrirlo se vuelve a exigir `compra.ver`. Lo pide `GET compras/{orden}/pdf`.
        Route::get('compras/{orden}/pedido.pdf', [CompraController::class, 'descargarPdf'])
            ->whereNumber('orden')->middleware('signed')->name(PedidoCompra::RUTA);

        // Los documentos de una venta (tramo H, HL-129, HL-130): la firma cubre quién lo pidió, y al abrirlo
        // se vuelve a exigir el permiso y que el documento sea de esa venta. Los piden los GET de abajo.
        Route::get('ventas/{venta}/documento.pdf', [VentaController::class, 'descargarPdf'])
            ->whereNumber('venta')->middleware('signed')->name(DocumentosDeVenta::RUTA_DOCUMENTO);
        Route::get('ventas/{venta}/entregas/{entrega}/remito.pdf', [VentaController::class, 'descargarRemito'])
            ->whereNumber(['venta', 'entrega'])->middleware('signed')->name(DocumentosDeVenta::RUTA_REMITO);
        Route::get('ventas/{venta}/comprobantes-transferencia/{comprobante}/archivo', [VentaController::class, 'descargarTransferencia'])
            ->whereNumber(['venta', 'comprobante'])->middleware('signed')->name(DocumentosDeVenta::RUTA_TRANSFERENCIA);
    });

    // Sesión opcional, de los dos clientes (HL-108): qué ve cada uno lo decide el permiso.
    Route::middleware('api.sesion_opcional')->group(function () {
        Route::get('productos', [ProductoController::class, 'index']);
        // Sin binding implícito: el modelo encontraría un producto inactivo.
        Route::get('productos/{producto}', [ProductoController::class, 'show'])->whereNumber('producto');
    });

    // Protegida común: los dos clientes, el mismo endpoint (tramo B de la Etapa 3).
    Route::middleware('api.protegida')->group(function () {
        // Sin permiso propio: cerrar la sesión y saber quién soy es de toda cuenta.
        Route::delete('sesion', [SesionController::class, 'destroy']);
        Route::get('yo', [YoController::class, 'show']);
        // La contraseña propia (tramo I): rota la credencial y devuelve la nueva (HL-132).
        Route::put('yo/contrasena', [YoController::class, 'contrasena']);

        // El catálogo de la administración (tramo D). Un permiso por acción (C-2), en la ruta.
        Route::middleware('can:marca.ver')->group(function () {
            Route::get('marcas', [MarcaController::class, 'index']);
            Route::get('marcas/impresion', [MarcaController::class, 'impresion']);
            Route::get('marcas/{marca}', [MarcaController::class, 'show'])->whereNumber('marca');
        });
        Route::post('marcas', [MarcaController::class, 'store'])->middleware('can:marca.crear');
        // Con logo, `POST` + `_method=PATCH` en multipart: PHP no lee archivos de un `PATCH` (HL-110).
        Route::patch('marcas/{marca}', [MarcaController::class, 'update'])->whereNumber('marca')->middleware('can:marca.editar');
        Route::delete('marcas/{marca}', [MarcaController::class, 'destroy'])->whereNumber('marca')->middleware('can:marca.eliminar');

        // Los proveedores (tramos D y E). Un permiso por acción (C-2), en la ruta.
        Route::middleware('can:proveedor.ver')->group(function () {
            Route::get('proveedores', [ProveedorController::class, 'index']);
            Route::get('proveedores/impresion', [ProveedorController::class, 'impresion']);
            Route::get('proveedores/{proveedor}', [ProveedorController::class, 'show'])->whereNumber('proveedor');
        });
        Route::post('proveedores', [ProveedorController::class, 'store'])->middleware('can:proveedor.crear');
        Route::patch('proveedores/{proveedor}', [ProveedorController::class, 'update'])->whereNumber('proveedor')->middleware('can:proveedor.editar');
        Route::delete('proveedores/{proveedor}', [ProveedorController::class, 'destroy'])->whereNumber('proveedor')->middleware('can:proveedor.eliminar');

        // Los clientes (tramos D y E). Un permiso por acción (C-2), en la ruta.
        Route::middleware('can:cliente.ver')->group(function () {
            Route::get('clientes', [ClienteController::class, 'index']);
            Route::get('clientes/impresion', [ClienteController::class, 'impresion']);
            Route::get('clientes/{cliente}', [ClienteController::class, 'show'])->whereNumber('cliente');
        });
        Route::post('clientes', [ClienteController::class, 'store'])->middleware('can:cliente.crear');
        Route::patch('clientes/{cliente}', [ClienteController::class, 'update'])->whereNumber('cliente')->middleware('can:cliente.editar');
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])->whereNumber('cliente')->middleware('can:cliente.eliminar');
        Route::post('clientes/{cliente}/acceso-tienda', [ClienteController::class, 'accesoTienda'])->whereNumber('cliente')->middleware('can:cliente.dar_acceso_tienda');
        // Las direcciones: una dirección no tiene existencia autónoma, así que las tres piden `cliente.editar`.
        // La lista viaja en la ficha del cliente.
        Route::middleware('can:cliente.editar')->group(function () {
            Route::post('clientes/{cliente}/direcciones', [DireccionController::class, 'store'])->whereNumber('cliente');
            Route::patch('clientes/{cliente}/direcciones/{direccion}', [DireccionController::class, 'update'])->whereNumber(['cliente', 'direccion']);
            Route::delete('clientes/{cliente}/direcciones/{direccion}', [DireccionController::class, 'destroy'])->whereNumber(['cliente', 'direccion']);
        });

        // El listado y la ficha de gestión son `GET productos?forma=gestion` y `GET productos/{p}?forma=gestion`,
        // arriba: el mismo recurso que la tienda (regla 36).
        Route::middleware('can:producto.ver')->group(function () {
            Route::get('productos/impresion', [ProductoController::class, 'impresion']);
            Route::get('productos/opciones', [ProductoController::class, 'opciones']);
        });
        Route::post('productos', [ProductoController::class, 'store'])->middleware('can:producto.crear');
        // Con imágenes, `POST` + `_method=PATCH` en multipart (HL-110).
        Route::patch('productos/{producto}', [ProductoController::class, 'update'])->whereNumber('producto')->middleware('can:producto.editar');
        Route::delete('productos/{producto}', [ProductoController::class, 'destroy'])->whereNumber('producto')->middleware('can:producto.eliminar');
        Route::post('productos/{producto}/recalcular', [ProductoController::class, 'recalcular'])->whereNumber('producto')->middleware('can:precio.recalcular');

        // Los proveedores de un producto. El listado decide su permiso en el Form Request, porque depende
        // de la forma: a quién pedirle (proveedor.ver) o el comparador (producto.editar).
        Route::get('productos/{producto}/proveedores', [ProductoProveedorController::class, 'index'])->whereNumber('producto');
        Route::middleware('can:producto.editar')->group(function () {
            Route::post('productos/{producto}/proveedores', [ProductoProveedorController::class, 'store'])->whereNumber('producto');
            Route::get('productos/{producto}/proveedores/{proveedor}', [ProductoProveedorController::class, 'show'])->whereNumber(['producto', 'proveedor']);
            Route::patch('productos/{producto}/proveedores/{proveedor}', [ProductoProveedorController::class, 'update'])->whereNumber(['producto', 'proveedor']);
            Route::post('productos/{producto}/proveedores/{proveedor}/preferido', [ProductoProveedorController::class, 'preferido'])->whereNumber(['producto', 'proveedor']);
            Route::delete('productos/{producto}/proveedores/{proveedor}', [ProductoProveedorController::class, 'destroy'])->whereNumber(['producto', 'proveedor']);
        });

        // El kardex y el ajuste por conteo (tramo F). Ver el kardex y ajustar son permisos distintos.
        Route::middleware('can:stock.ver')->group(function () {
            Route::get('stock/movimientos', [StockController::class, 'movimientos']);
            Route::get('stock/movimientos/impresion', [StockController::class, 'impresion']);
            Route::get('stock/usuarios', [StockController::class, 'usuarios']);
            Route::get('productos/{producto}/stock', [StockController::class, 'producto'])->whereNumber('producto');
        });
        Route::post('productos/{producto}/ajustes-stock', [StockController::class, 'ajustar'])->whereNumber('producto')->middleware('can:stock.ajustar');

        // Las órdenes de compra (tramo G). Ver, crear, editar, aprobar y recibir son permisos distintos (C-2).
        Route::middleware('can:compra.ver')->group(function () {
            Route::get('compras', [CompraController::class, 'index']);
            Route::get('compras/impresion', [CompraController::class, 'impresion']);
            Route::get('compras/proveedores', [CompraController::class, 'proveedores']);
            Route::get('compras/{orden}', [CompraController::class, 'show'])->whereNumber('orden');
            Route::get('compras/{orden}/pdf', [CompraController::class, 'pdf'])->whereNumber('orden');
        });
        // El armador y sus sugeridos: `compra.crear`, como sus pantallas.
        Route::middleware('can:compra.crear')->group(function () {
            Route::get('compras/sugeridos', [CompraController::class, 'sugeridos']);
            Route::get('compras/armador', [CompraController::class, 'armador']);
            Route::post('compras', [CompraController::class, 'store']);
        });
        Route::post('compras/{orden}/recepciones', [CompraController::class, 'recibir'])->whereNumber('orden')->middleware('can:compra.recibir');
        Route::patch('compras/{orden}', [CompraController::class, 'update'])->whereNumber('orden')->middleware('can:compra.editar');
        Route::middleware('can:compra.aprobar')->group(function () {
            Route::post('compras/{orden}/aprobar', [CompraController::class, 'aprobar'])->whereNumber('orden');
            Route::post('compras/{orden}/enviada', [CompraController::class, 'enviada'])->whereNumber('orden');
            Route::post('compras/{orden}/cancelar', [CompraController::class, 'cancelar'])->whereNumber('orden');
        });
        // Cerrar incompleta es del que recibe: es decir que el resto no va a llegar.
        Route::post('compras/{orden}/cerrar', [CompraController::class, 'cerrar'])->whereNumber('orden')->middleware('can:compra.recibir');

        // Los dos paneles (tramo H): un permiso cada uno, porque ver lo propio y ver el conjunto son dos acciones.
        Route::get('panel', [PanelController::class, 'propio'])->middleware('can:panel.ver_propio');
        Route::get('panel/general', [PanelController::class, 'general'])->middleware('can:panel.ver_global');

        // Los informes que salieron del panel general muestran el conjunto: el mismo permiso que él.
        Route::middleware('can:panel.ver_global')->group(function () {
            Route::get('informes/vendedores', [InformeController::class, 'vendedores']);
            Route::get('informes/productos', [InformeController::class, 'productos']);
        });

        // Las ventas de la administración (tramo H). Los pedidos del cliente siguen en `pedidos`, con `tienda.*`.
        Route::middleware('can:venta.ver')->group(function () {
            Route::get('ventas', [VentaController::class, 'index']);
            Route::get('ventas/vendedores', [VentaController::class, 'vendedores']);
            Route::get('ventas/impresion', [VentaController::class, 'impresion']);
            Route::get('ventas/{venta}', [VentaController::class, 'show'])->whereNumber('venta');
            Route::get('ventas/{venta}/pdf', [VentaController::class, 'pdf'])->whereNumber('venta');
            Route::get('ventas/{venta}/entregas/{entrega}/pdf', [VentaController::class, 'remito'])->whereNumber(['venta', 'entrega']);
        });
        Route::post('ventas', [VentaController::class, 'store'])->middleware('can:venta.crear');
        // Recotizar y cancelar son de quien opera el presupuesto: no mueven stock ni plata (cancelar no es anular).
        Route::middleware('can:venta.editar')->group(function () {
            Route::patch('ventas/{venta}', [VentaController::class, 'update'])->whereNumber('venta');
            Route::post('ventas/{venta}/recotizar', [VentaController::class, 'recotizar'])->whereNumber('venta');
            Route::post('ventas/{venta}/cancelar', [VentaController::class, 'cancelar'])->whereNumber('venta');
        });
        Route::get('ventas/{venta}/comprobantes-transferencia/{comprobante}', [VentaController::class, 'transferencia'])
            ->whereNumber(['venta', 'comprobante'])->middleware('can:venta.ver_comprobante_transferencia');
        Route::post('ventas/{venta}/pagos', [VentaController::class, 'cobrar'])->whereNumber('venta')->middleware('can:venta.cobrar');
        Route::post('ventas/{venta}/entregas', [VentaController::class, 'entregar'])->whereNumber('venta')->middleware('can:venta.entregar');
        // La devolución es la que toca la plata: `venta.anular`, no `venta.editar`.
        Route::post('ventas/{venta}/devoluciones', [VentaController::class, 'devolver'])->whereNumber('venta')->middleware('can:venta.anular');

        // El listado de categorías es `GET categorias?forma=lista`, arriba: el mismo recurso (regla 36).
        Route::middleware('can:categoria.ver')->group(function () {
            Route::get('categorias/impresion', [CategoriaController::class, 'impresion']);
            Route::get('categorias/padres-posibles', [CategoriaController::class, 'padresPosibles']);
            Route::get('categorias/{categoria}', [CategoriaController::class, 'show'])->whereNumber('categoria');
        });
        Route::post('categorias', [CategoriaController::class, 'store'])->middleware('can:categoria.crear');
        Route::patch('categorias/{categoria}', [CategoriaController::class, 'update'])->whereNumber('categoria')->middleware('can:categoria.editar');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->whereNumber('categoria')->middleware('can:categoria.eliminar');

        // Los impuestos y los factores de costo (tramo F). Leer pide `precio.ver`; escribir, `precio.editar`.
        Route::middleware('can:precio.ver')->group(function () {
            Route::get('impuestos', [ImpuestoController::class, 'index']);
            Route::get('impuestos/{impuesto}', [ImpuestoController::class, 'show'])->whereNumber('impuesto');
            Route::get('factores-costo', [FactorCostoController::class, 'index']);
            Route::get('factores-costo/{factor}', [FactorCostoController::class, 'show'])->whereNumber('factor');
            Route::get('precios/politica', [PoliticaPreciosController::class, 'show']);
            Route::get('precios/simulacion', [PoliticaPreciosController::class, 'simulacion']);
        });
        Route::middleware('can:precio.editar')->group(function () {
            Route::post('impuestos', [ImpuestoController::class, 'store']);
            Route::patch('impuestos/{impuesto}', [ImpuestoController::class, 'update'])->whereNumber('impuesto');
            Route::delete('impuestos/{impuesto}', [ImpuestoController::class, 'destroy'])->whereNumber('impuesto');
            Route::post('factores-costo', [FactorCostoController::class, 'store']);
            Route::patch('factores-costo/{factor}', [FactorCostoController::class, 'update'])->whereNumber('factor');
            Route::delete('factores-costo/{factor}', [FactorCostoController::class, 'destroy'])->whereNumber('factor');
            Route::patch('precios/politica', [PoliticaPreciosController::class, 'update']);
        });

        // El ajuste masivo (tramo F): la vista previa y el ajuste comparten el Form Request, para que no
        // puedan mostrar un conjunto y escribir otro. El historial se lee con `precio.ver`.
        Route::middleware('can:precio.ajustar_masivo')->group(function () {
            Route::get('precios/ajustes/vista-previa', [AjusteMasivoController::class, 'vistaPrevia']);
            Route::post('precios/ajustes', [AjusteMasivoController::class, 'store']);
        });
        Route::get('precios/lotes', [AjusteMasivoController::class, 'lotes'])->middleware('can:precio.ver');
        Route::post('precios/lotes/{lote}/deshacer', [AjusteMasivoController::class, 'deshacer'])->whereNumber('lote')->middleware('can:precio.deshacer_ajuste');

        // El personal (tramo I). Un permiso por acción (C-2), en la ruta. Una cuenta de la tienda es 404.
        // Las opciones de rol sirven al filtro y al alta: su Form Request acepta cualquiera de los dos.
        Route::get('personal/roles', [PersonalController::class, 'roles']);
        Route::middleware('can:usuario.ver')->group(function () {
            Route::get('personal', [PersonalController::class, 'index']);
            Route::get('personal/{usuario}', [PersonalController::class, 'show'])->whereNumber('usuario');
        });
        Route::post('personal', [PersonalController::class, 'store'])->middleware('can:usuario.crear');
        Route::patch('personal/{usuario}', [PersonalController::class, 'update'])->whereNumber('usuario')->middleware('can:usuario.editar');
        Route::delete('personal/{usuario}', [PersonalController::class, 'destroy'])->whereNumber('usuario')->middleware('can:usuario.eliminar');
        Route::put('personal/{usuario}/rol', [PersonalController::class, 'cambiarRol'])->whereNumber('usuario')->middleware('can:usuario.cambiar_rol');
        Route::post('personal/{usuario}/restablecer-contrasena', [PersonalController::class, 'restablecer'])
            ->whereNumber('usuario')->middleware('can:usuario.resetear_password');

        // Roles y permisos (tramo I): leer con `rol.ver`, escribir con `rol.editar`, como en la web.
        Route::middleware('can:rol.ver')->group(function () {
            Route::get('roles', [RolController::class, 'index']);
            Route::get('roles/{rol}', [RolController::class, 'show'])->whereNumber('rol');
            Route::get('permisos', [RolController::class, 'permisos']);
        });
        Route::middleware('can:rol.editar')->group(function () {
            Route::post('roles', [RolController::class, 'store']);
            Route::patch('roles/{rol}', [RolController::class, 'update'])->whereNumber('rol');
            Route::delete('roles/{rol}', [RolController::class, 'destroy'])->whereNumber('rol');
        });
    });

    // Protegida de la tienda.
    Route::middleware('tienda.protegida')->group(function () {
        Route::get('perfil', [PerfilController::class, 'show']);
        // La cuenta propia, como leerla (D-22): lo fiscal que decide el comprobante no viaja.
        Route::patch('perfil', [PerfilController::class, 'update']);

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
            // Confirma el checkout (tramo F). Idempotente por `carrito_id` (D-8).
            Route::post('pedidos', [PedidoController::class, 'store']);

            // Pagar (tramo G). Como mucho un intento pendiente por pedido (§6.6). Ajeno, 404.
            Route::post('pedidos/{pedido}/pagos', [PagoPedidoController::class, 'store'])->whereNumber('pedido');

            // El simulador existe sólo con su llave encendida: en producción no hay ruta (D-13).
            if (config('tienda.pagos.simulador')) {
                Route::post('simulador/intentos/{intento}/aprobar', [SimuladorController::class, 'aprobar'])->whereNumber('intento');
                Route::post('simulador/intentos/{intento}/rechazar', [SimuladorController::class, 'rechazar'])->whereNumber('intento');
            }
        });

        // El comprobante de una transferencia (tramo L, D-27). Ajeno, 404; si no lo espera, 409.
        Route::middleware('can:tienda.cargar_comprobante')->group(function () {
            Route::post('pedidos/{pedido}/comprobante-transferencia', [ComprobanteTransferenciaController::class, 'store'])
                ->whereNumber('pedido');
        });

        // Leer los pagos (tramo G). Si el intento está pendiente, `show` consulta al proveedor antes.
        Route::middleware('can:tienda.ver_pedidos')->group(function () {
            // Mis pedidos (tramo H). Sólo los online de la cuenta; ajeno o inexistente, 404.
            Route::get('pedidos', [PedidoController::class, 'index']);
            Route::get('pedidos/{pedido}', [PedidoController::class, 'show'])->whereNumber('pedido');
            Route::get('pedidos/{pedido}/comprobante', [PedidoController::class, 'comprobante'])->whereNumber('pedido');

            Route::get('pedidos/{pedido}/pagos', [PagoPedidoController::class, 'index'])->whereNumber('pedido');
            Route::get('pedidos/{pedido}/pagos/{intento}', [PagoPedidoController::class, 'show'])
                ->whereNumber(['pedido', 'intento']);
        });
    });
});
