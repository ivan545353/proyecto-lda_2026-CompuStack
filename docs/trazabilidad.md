# Tabla de trazabilidad — Sistema original → Laravel

Documento de seguimiento de la Etapa 1. Se actualiza en el mismo commit que migra cada componente.

**Estados:** Pendiente · En proceso · Migrado · Descartado · Sin equivalente

---

## 1. Infraestructura y arranque

| Componente original                    | Función                                    | Componente Laravel                       | Estado    |
| -------------------------------------- | ------------------------------------------ | ---------------------------------------- | --------- |
| `public/index.php`                     | Punto de entrada, arma el Pipeline         | `public/index.php` + `bootstrap/app.php` | Pendiente |
| `public/.htaccess`                     | Reescritura `controller/action/id`         | `routes/web.php`                         | Pendiente |
| `app/App.php`                          | Registra middlewares y ejecuta el Pipeline | `bootstrap/app.php` (`withMiddleware`)   | Pendiente |
| `app/config/AppConfig.php`             | Constantes globales y `JWT_SECRET`         | `.env` + `config/app.php`                | Pendiente |
| `app/config/DBConfig.php`              | Credenciales de base                       | `.env` + `config/database.php`           | Pendiente |
| `app/libs/database/Connection.php`     | Singleton PDO                              | Capa de base de Laravel (nativa)         | Pendiente |
| `app/libs/pipeline/Pipeline.php`       | Encadenado de middlewares                  | Middleware stack de Laravel (nativo)     | Pendiente |
| `app/libs/pipeline/middlewares/base/*` | Contrato de middleware                     | Contrato de Laravel (nativo)             | Pendiente |
| `app/libs/http/Request.php`            | Parseo de query, body y headers            | `Illuminate\Http\Request` (nativo)       | Pendiente |
| `app/libs/http/Response.php`           | Envelope JSON de respuesta                 | Vistas Blade + `redirect()`              | Pendiente |

## 2. Middlewares

| Componente original               | Función                                      | Componente Laravel                                  | Estado     |
| --------------------------------- | -------------------------------------------- | --------------------------------------------------- | ---------- |
| `RouterHandlerMiddleware`         | Resuelve controlador y acción por convención | `routes/web.php` con rutas explícitas y verbos HTTP | Pendiente  |
| `AuthenticationHandlerMiddleware` | Valida el JWT del header                     | Middleware `auth` con guard de sesión               | Migrado    |
| `AuthorizationHandlerMiddleware`  | Consulta `permisos` por módulo y acción      | Policies + Gates contra permisos nombrados          | Migrado    |
| `ExceptionHandlerMiddleware`      | Captura excepciones y arma el JSON de error  | `bootstrap/app.php` (`withExceptions`)              | Migrado    |
| `CorsHandlerMiddleware`           | Cabeceras CORS para Angular                  | Descartado: con Blade el origen es el mismo         | Descartado |

> `AuthorizationHandlerMiddleware` es el origen del hallazgo crítico C-2 de la auditoría (fallback a `can_update` para toda acción no mapeada). Su reemplazo debe ser deny-by-default.

## 3. Excepciones

| Componente original       | Función              | Componente Laravel                                  | Estado    |
| ------------------------- | -------------------- | --------------------------------------------------- | --------- |
| `HttpException`           | Base con código HTTP | `Symfony\...\HttpException` (nativo)                | Pendiente |
| `AuthenticationException` | 401                  | `Illuminate\Auth\AuthenticationException`           | Pendiente |
| `AuthorizationException`  | 403                  | `Illuminate\Auth\Access\AuthorizationException`     | Pendiente |
| `NotFoundException`       | 404                  | `ModelNotFoundException` (nativo, vía `findOrFail`) | Pendiente |
| `ValidationException`     | 400                  | `Illuminate\Validation\ValidationException`         | Pendiente |

## 4. Controladores

| Componente original            | Función                                     | Componente Laravel                      | Estado    |
| ------------------------------ | ------------------------------------------- | --------------------------------------- | --------- |
| `base/BaseController.php`      | Contrato común de controlador               | `App\Http\Controllers\Controller`       | Pendiente |
| `base/InterfaceController.php` | Interfaz CRUD                               | Convención de resource controller       | Pendiente |
| `AuthenticationController.php` | Login, logout, `getCurrent`                 | `AuthController` con sesión             | Migrado   |
| `CategoryController.php`       | CRUD de categorías                          | `CategoriaController` (resource)        | Migrado   |
| `ItemController.php`           | CRUD de productos                           | `ProductoController` (resource)         | Migrado   |
| `UserController.php`           | CRUD de usuarios, cambio de clave, perfiles | `PersonalController + CuentaController` | Migrado   |
| `SaleController.php`           | Ventas, cobros, cambio de estado            | `VentaController` + `PagoController`    | Migrado   |

> `UserController::update` acepta `perfil_id` del body sin verificar identidad (hallazgo C-3, escalada de privilegios). El cambio de rol debe quedar en una acción separada, restringida y con prohibición de autoasignación.
> El listado de usuarios se partió en dos pantallas: `PersonalController` lista
> las cuentas de ámbito gestión y `ClienteController` las fichas de clientes.
> Antes el ámbito era un filtro opcional del mismo listado, y eso ponía al
> cajero y a un cliente de la tienda en la misma lista: un filtro que hay que
> acordarse de aplicar no separa nada. `UsuarioService` conserva el nombre
> porque administra cuentas —contraseña propia, cambio de rol, enlace de
> restablecimiento— y lo usan también `CuentaController` y
> `RestablecerPasswordController`.
> `SaleController` se partió en dos y se migró en dos mitades, que es como se trabajó la
> Fase 6. `VentaController` tiene once acciones: listado, ficha, alta, edición,
> recotización, cancelación, entrega y la devolución —formulario y registro—.
> `PagoController` tiene dos: la pantalla de cobro y el registro del cobro. Vive aparte
> porque el recurso que crea es un pago, con su Form Request, su pantalla y su permiso
> propios.
>
> El `updateEstado` original **no tiene equivalente y no va a tenerlo**: el estado
> destino no es un parámetro que viaje en la petición. Cada transición es una ruta con
> su verbo, su nombre y su permiso —`venta.cobrar`, `venta.entregar`, `venta.anular`,
> y `venta.editar` para cancelar un presupuesto—, y los tres primeros son permisos
> distintos justamente porque en el original las tres acciones caían en `can_update`
> por descarte.
>
> La tarjeta de acciones de la ficha tuvo que reestructurarse al aparecer el cobro:
> estaba envuelta en un solo `@can('venta.editar')`, y el rol Cajero —que cobra y no
> edita— no veía ningún botón. Un permiso por acción en la ruta exige un permiso por
> acción en la pantalla, y hay un test en las dos direcciones.

## 5. Servicios

| Componente original         | Función                                | Componente Laravel                                  | Estado    |
| --------------------------- | -------------------------------------- | --------------------------------------------------- | --------- |
| `base/InterfaceService.php` | Contrato de servicio                   | Sin equivalente directo                             | Pendiente |
| `AuthenticationService.php` | Verifica credenciales y emite JWT      | `AuthController` + guard de sesión                  | Migrado   |
| `CategoryService.php`       | Validaciones de categoría              | `CategoriaService` (adelgazado)                     | Migrado   |
| `ItemService.php`           | Validaciones de producto               | `ProductoService` (adelgazado)                      | Migrado   |
| `UserService.php`           | Validaciones de usuario, hash de clave | `UsuarioService` (adelgazado)                       | Migrado   |
| `SaleService.php`           | Totales, descuentos, cobros, estados   | `VentaService` + `PagoService` + máquina de estados | Migrado   |

> Las validaciones de campo pasan a Form Requests. Los servicios conservan sólo reglas de negocio, para que la Etapa 3 los reutilice desde la API sin cambios.
>
> `SaleService` es el de mayor reescritura: hoy valida el monto del pago fuera de la transacción (hallazgo C-10) y permite transiciones de estado arbitrarias (C-9).
> Las cuatro responsabilidades de `SaleService` están migradas. Tres en `VentaService`
> —totales derivados de las líneas, descuento con su tope por rol (A-12), y cambios de
> estado a través de `App\Support\MaquinaEstadosVenta` (C-9)— y los cobros en
> `PagoService` (C-10). `resolverPreciosYTotales()` se partió en dos operaciones que el
> original confundía: cotizar y recotizar (M-14).
>
> **La dependencia entre los dos servicios va en una sola dirección**, y tiene que
> seguir yendo en una sola dirección: `PagoService` recibe `VentaService` porque el
> cobro termina en `marcarPagada()`, que es donde se descuenta el stock. Lo inverso no
> se puede: si `VentaService` recibiera `PagoService`, el contenedor entraría en
> recursión construyendo los dos. Por eso la devolución —que cambia el estado y por lo
> tanto vive en `VentaService`— escribe sus contra-asientos con el modelo `Pago`
> directo, igual que ya escribe `VentaLinea` directo.

## 6. Acceso a datos

| Componente original     | Función                                 | Componente Laravel                                       | Estado    |
| ----------------------- | --------------------------------------- | -------------------------------------------------------- | --------- |
| `base/BaseDao.php`      | Conexión, transacciones, `lastInsertId` | `Illuminate\Database\Eloquent\Model`                     | Pendiente |
| `base/InterfaceDao.php` | Contrato CRUD                           | Convención de Eloquent                                   | Pendiente |
| `CategoryDao.php`       | SQL de categorías                       | Modelo `Categoria`                                       | Migrado   |
| `ItemDao.php`           | SQL de productos                        | Modelo `Producto` + scopes de filtro                     | Migrado   |
| `UserDao.php`           | SQL de usuarios, login                  | Modelo `User` (con `$hidden`)                            | Migrado   |
| `SaleDao.php`           | SQL de ventas, stock, pagos, numeración | Modelos `Venta`, `VentaLinea`, `Pago`, `MovimientoStock` | Migrado   |

> `UserDao::list` hace `SELECT u.*`, lo que expone el hash de contraseña de todos los usuarios (hallazgo C-1). En Eloquent se cierra con `$hidden = ['password']`.
>
> Los filtros de `ItemDao`, `CategoryDao` y `UserDao` no coinciden con lo que envían sus controladores y nunca funcionaron (hallazgo A-24). Al migrarlos hay que definir el juego de filtros real y cubrirlo con tests.
> Los cuatro modelos están migrados, los tres de ventas con la guarda que impide
> borrarlos (M-16): `Venta`, `VentaLinea` con `cantidad_devuelta` como contador de lo
> devuelto, y `Pago`, que nació en la segunda mitad con su guarda de no-borrado desde el
> primer día porque es un documento financiero. `MovimientoStock` se migró en la Fase 5.
> La **numeración** no tiene equivalente y es a propósito: el número es el `id` y
> `venta_numeracion` se descartó (A-18).

## 7. DTOs

| Componente original     | Función                 | Componente Laravel          | Estado    |
| ----------------------- | ----------------------- | --------------------------- | --------- |
| `base/InterfaceDto.php` | Contrato de DTO         | Sin equivalente directo     | Pendiente |
| `LoginDto.php`          | Credenciales de acceso  | `LoginRequest`              | Migrado   |
| `CategoryDto.php`       | Validación y transporte | `CategoriaRequest` + modelo | Migrado   |
| `ItemDto.php`           | Validación y transporte | `ProductoRequest` + modelo  | Migrado   |
| `UserDto.php`           | Validación y transporte | `PersonalRequest` + modelo  | Migrado   |
| `SaleDto.php`           | Validación y transporte | `VentaRequest` + modelo     | Migrado   |

> Los DTOs actuales cumplen doble función de entrada y salida, que es la causa de fondo de C-1. Se separan: Form Request para entrada, modelo con `$hidden` para salida.
>
> Los setters vacían el valor cuando no valida en vez de rechazarlo (hallazgo M-31). Las reglas de Form Request rechazan.

## 8. Frontend

| Componente original              | Función                              | Componente Laravel                                          | Estado     |
| -------------------------------- | ------------------------------------ | ----------------------------------------------------------- | ---------- |
| `features/auth/`                 | Pantalla de login                    | `resources/views/auth/`                                     | Migrado    |
| `features/layout/`               | Barra de navegación y estructura     | `resources/views/layouts/app.blade.php`                     | Migrado    |
| `features/home/`                 | Panel con contadores                 | `resources/views/panel/` con datos agregados en el servidor | Migrado    |
| `features/category/`             | Listado y formulario de categorías   | `resources/views/categorias/`                               | Migrado    |
| `features/item/`                 | Listado y formulario de productos    | `resources/views/productos/`                                | Migrado    |
| `features/user/`                 | Listado y formulario de usuarios     | `resources/views/personal/`                                 | Migrado    |
| `features/sale/`                 | Ventas, detalle, cobro               | `resources/views/ventas/`                                   | Migrado    |
| `features/account/`              | Cambio de clave propia               | `resources/views/cuenta/`                                   | Migrado    |
| `core/pdf/pdf.service.ts`        | Genera PDF con jsPDF en el navegador | `barryvdh/laravel-dompdf` + vistas Blade                    | Migrado    |
| `core/auth/auth.guard.ts`        | Protege rutas si hay token           | Middleware `auth`                                           | Pendiente  |
| `core/auth/token.interceptor.ts` | Inyecta el header Authorization      | Descartado: sesión en cookie                                | Descartado |
| `core/api/api.constants.ts`      | URL base de la API                   | Descartado                                                  | Descartado |
| `core/*/​*.service.ts`           | Clientes HTTP por módulo             | Descartado: los controladores devuelven vistas              | Descartado |

> `HomeComponent` descarga las tablas completas y cuenta en el navegador (hallazgo A-26). El panel Blade recibe los números ya agregados.
> El PDF pasó al servidor porque en Blade no hay jsPDF, y porque la Etapa 2 va a
> necesitar CAE y código QR en la factura, que no se pueden generar en el cliente.
> El pedido al proveedor (`compras/pdf.blade.php`) está hecho en la Fase 5; el
> comprobante de venta y la exportación de listados son de la Fase 7.
> Las tres pantallas de `features/sale/` están migradas, y son cinco: el listado con sus
> seis filtros, la ficha del documento —con sus líneas, sus importes congelados y el
> libro de movimientos de dinero—, el formulario de alta y edición con el armador de
> líneas (`resources/js/componentes/lineas-venta.js`), la pantalla de **cobro** y la de
> **devolución**.
>
> Las dos últimas **no llevan JavaScript**, y es deliberado: el cobro manda una fila por
> medio de pago ofrecido y el servidor descarta las que llegan sin monto, y la devolución
> manda una cantidad por línea igual que la recepción de mercadería. Sin armador de filas
> que mantener, y sin JS funcionan igual.

---

## 9. Componentes nuevos de la Fase 6

Sin equivalente en el sistema original: no son migraciones, son piezas que el defecto
original no tenía.

| Componente                             | Qué resuelve                                                                 | Estado  |
| -------------------------------------- | ---------------------------------------------------------------------------- | ------- |
| `App\Support\MaquinaEstadosVenta`      | La tabla de transiciones que el original no tenía (C-9)                      | Migrado |
| `VentaFiltroRequest`                   | Declara qué filtros acepta el listado (A-24)                                 | Migrado |
| `VentaService::recotizar()`            | La distinción entre cotizar y recotizar, con el informe de qué cambió (M-14) | Migrado |
| `config/venta.php`                     | El tope de descuento como parámetro del negocio (A-12)                       | Migrado |
| `venta.autorizar_descuento`            | El permiso que levanta el tope (A-12)                                        | Migrado |
| `PagoService::cobrar()`                | El cobro con el saldo validado dentro del lock, y la idempotencia (C-10)     | Migrado |
| `PagoRequest`                          | La forma del cobro, y el saldo deliberadamente **fuera** (C-10)              | Migrado |
| `VentaService::marcarPagada()`         | El pase a `pagada`: el único llamador de `StockService::descontar()`         | Migrado |
| `VentaService::devolver()`             | Devolución total y parcial: repone stock y revierte los pagos (A-11)         | Migrado |
| `DevolucionRequest`                    | El monto y el estado declarados `prohibited`: los decide el servidor (A-11)  | Migrado |
| El contra-asiento de pago              | Revertir sin editar ni borrar: una fila con monto negativo (A-11, M-16)      | Migrado |
| `VentaService::entregar()`             | La transición que hace alcanzable `entregada`                                | Migrado |
| `venta.entregar`                       | Un permiso por acción: entregar no es editar ni cobrar (C-2)                 | Migrado |
| `MaquinaEstadosVenta::puedeDevolver()` | Los dos destinos preguntados juntos, derivados de la tabla                   | Migrado |

> El contra-asiento es la pieza que más conviene poder explicar, porque reemplaza a algo
> que no existía: el original no tenía forma de deshacer un cobro. Una fila de pago con
> monto negativo —y no una edición, ni un borrado, ni una columna de reversión— hace que
> `SUM(monto)` siga respondiendo cuánta plata quedó de la venta sin filtrar nada, que una
> devolución parcial produzca una reversión parcial sin entidades nuevas, y que la ficha
> muestre los dos hechos uno debajo del otro. Es el mismo criterio que
> `movimientos_stock.cantidad` con signo.

## 10. Pruebas

| Componente original                             | Función                                            | Componente Laravel                            | Estado     |
| ----------------------------------------------- | -------------------------------------------------- | --------------------------------------------- | ---------- |
| `tests/dao/*`, `tests/dto/*`, `tests/service/*` | Scripts con `echo`, referencian clases renombradas | `tests/Feature` + `tests/Unit` (PHPUnit/Pest) | Reescrito  |
| `tests/database/databaseTest.php`               | Prueba de conexión manual                          | Cubierto por el entorno de pruebas            | Descartado |
| `tests/password.php`                            | Generador de hash suelto                           | `php artisan tinker`                          | Descartado |

> Los tests actuales no compilan: referencian `CategoriaDao`, `ProductoDto` y `PerfilUsuario`, renombradas a `CategoryDao`, `ItemDto` y sin equivalente. Se reescriben de cero.

## 11. Sin equivalente en el sistema original

Módulos nuevos de la Etapa 1. No hay componente que migrar; se construyen desde cero.

| Módulo                     | Componentes Laravel                                                                                                                                                               | Estado  |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| Clientes                   | Migración, modelo `Cliente`, `Direccion`, controlador, Form Requests, vistas                                                                                                      | Migrado |
| Empleados                  | Migración, modelo `Empleado`, integrado al módulo de usuarios                                                                                                                     | Migrado |
| Proveedores                | Migración, modelo `Proveedor`, controlador, vistas                                                                                                                                | Migrado |
| Proveedores de un producto | Migración `producto_proveedor`, `Producto::proveedores()`, `ProductoProveedorService`, `ProductoProveedorRequest`, `ProductoProveedorController`, pantalla comparadora de precios | Migrado |
| Órdenes de compra          | Migraciones, modelos `OrdenCompra` y `OrdenCompraLinea`, servicio de reposición                                                                                                   | Migrado |
| Roles y permisos           | Migraciones `roles`, `permisos`, `rol_permiso`, controlador, vistas, Gates                                                                                                        | Migrado |
| Marcas                     | Migración, modelo `Marca`, controlador, vistas                                                                                                                                    | Migrado |
| Movimientos de stock       | Migración, modelo `MovimientoStock`, servicio de kardex                                                                                                                           | Migrado |
| Categorías jerárquicas     | Ampliación de `Categoria` con `parent_id`                                                                                                                                         | Migrado |

> `producto_proveedor` no reemplaza a ningún componente original: el sistema
> original no tenía proveedores. Reemplaza a una decisión **propia** —
> `productos.proveedor_id`, un producto con un solo proveedor— que se tomó al
> cerrar el modelo de datos y se revirtió en la Fase 5 cuando apareció el
> requerimiento de comprar al mejor precio. La columna se eliminó con disciplina
> expand/contract y `EsquemaDatosTest` afirma que ya no existe.
> La **reposición automática** no reemplaza ningún componente original: el
> sistema original no tenía compras ni stock mínimo, y la reposición se hacía
> mirando el listado de productos a ojo. Es el requerimiento 11, y el hallazgo
> A-13 la nombraba como bloqueada por la falta de kardex. Es además el único
> componente del sistema que corre **sin usuario**: por eso la orden que genera
> queda en borrador, con `usuario_creo_id` en null, y espera una aprobación
> humana antes de comprometer plata.

## 12. Descartado del modelo original

| Tabla original            | Motivo                                                                         |
| ------------------------- | ------------------------------------------------------------------------------ |
| `venta_numeracion`        | Una fila sin clave primaria. La numeración fiscal la gobierna AFIP (Etapa 2/3) |
| `modulos`                 | Los permisos pasan a ser acciones nombradas, no módulos                        |
| `perfiles`                | Reemplazada por `roles` con permisos granulares                                |
| `permisos` (4 flags CRUD) | Reemplazada por permisos nombrados con pivote a roles                          |

## 13. Componentes nuevos de la Fase 7

`HomeComponent` descargaba categorías, productos, ventas y usuarios completos y
contaba con `.filter()` (A-26). No es una migración del componente: es su reemplazo
por consultas de agregación.

| Componente                        | Qué resuelve                                                              | Estado  |
| --------------------------------- | ------------------------------------------------------------------------- | ------- |
| `PanelService`                    | Una consulta de agregación por métrica; ninguna trae filas (A-26)         | Migrado |
| `Venta::ESTADOS_VENDIDOS`         | Qué cuenta como venta efectiva, con `devuelta_parcial` adentro            | Migrado |
| `PanelFiltroRequest`              | Declara el contrato de filtros del panel: el período (A-24)               | Migrado |
| `PanelController::propio()`       | Lo que vendí y lo que cobré, dos columnas de dos tablas (M-19)            | Migrado |
| `PanelController::general()`      | El conjunto, con los rankings y el margen                                 | Migrado |
| `PanelController::inicio()`       | La pantalla de entrada según permisos, para que el panel pueda exigir uno | Migrado |
| `panel.ver_propio` / `ver_global` | Un permiso por ruta: `/panel` ya no es un `Route::view` sin `can:` (C-2)  | Migrado |
| `componentes/graficos.js`         | Chart.js con los números ya agregados; sin JS quedan las tablas           | Migrado |
| `ventas/pdf.blade.php`            | El comprobante o el presupuesto, con datos congelados                     | Migrado |
| `Venta::sePuedeImprimir()`        | Una venta cancelada no tiene documento que entregar                       | Migrado |
| `ventas/listado-pdf.blade.php`    | El listado exportado, declarando sus filtros y su recorte                 | Migrado |
| `VentaController::filtradas()`    | La cadena de filtros compartida por el listado y la exportación (A-24)    | Migrado |

> El comprobante y el listado se comportan **al revés a propósito**, y es lo que más
> conviene poder explicar. El comprobante imprime la fecha del hecho y **no** imprime
> el estado, los pagos ni `cantidad_devuelta`: regenerarlo dentro de un año tiene que
> dar el mismo papel, igual que el PDF del pedido imprime `cantidad_pedida` y nunca
> `cantidad_recibida`. El listado es un informe de un momento, así que imprime la fecha
> de generación, el estado de cada venta y los filtros aplicados — sin eso, el PDF de
> un listado filtrado sería indistinguible del completo.
