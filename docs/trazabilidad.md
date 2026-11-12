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
> El PDF pasó al servidor porque en Blade no hay jsPDF, y porque la Etapa 4 va a
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
| `venta_numeracion`        | Una fila sin clave primaria. La numeración fiscal la gobierna ARCA (Etapa 4) |
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

## 14. Componentes nuevos de la Fase 7.5

Van agrupados por tramo. Ninguno migra nada: el sistema original no tenía forma de
medirse ni estos circuitos, así que acá el estado es **Nuevo** y no «Migrado».

### Tramo A — escala medible

| Componente                                 | Qué resuelve                                                                            | Estado |
| ------------------------------------------ | --------------------------------------------------------------------------------------- | ------ |
| `VolumenSeeder`                            | Orquesta la carga a escala y la niega en producción, sin indispensables o ya cargada    | Nuevo  |
| `CatalogoVolumenSeeder`                    | 20.000 productos en 36 hojas de tres niveles, con su pivote y su inventario asentado    | Nuevo  |
| `PersonasVolumenSeeder`                    | 5.000 clientes y los diez operadores que firman las operaciones                         | Nuevo  |
| `OperacionesVolumenSeeder`                 | Compras, ventas, cobros y kardex en un solo hilo cronológico                            | Nuevo  |
| `datos:volumen`                            | Comando propio: el volumen se carga cuando se mide, no en cada `migrate:fresh`          | Nuevo  |
| `escala:medir`                             | Tiempo, consultas, peso del HTML y memoria de las pantallas que crecen con los datos    | Nuevo  |
| `User::$clavesMemorizadas`                 | Una lectura de permisos por petición en lugar de una por `@can` (pendiente #53)         | Nuevo  |
| índice `(fecha, metodo, monto)` en `pagos` | La agregación del panel deja de recorrer la tabla (pendiente #51)                       | Nuevo  |
| `EscalaTest`                               | Fija que las consultas no crecen con las filas, con la caché en la base como producción | Nuevo  |
| `contarConsultas()` / `consultasSobre()`   | Cuentan consultas con `DB::listen()`, sin agregar ninguna librería                      | Nuevo  |
| `docs/escala.md`                           | Qué se midió, con cuántos datos, qué se decidió hacer y qué no                          | Nuevo  |

> **El seeder de volumen no pasa por los servicios, y el de demostración sí.** No es
> una inconsistencia: son dos documentos distintos. `OperacionesDemoSeeder` existe para
> que alguien mire el sistema y reconozca lo que ve, así que cada fila tiene que ser una
> que el sistema pueda producir, y por eso llama a `VentaService` y a `PagoService`.
> El de volumen existe para lo contrario: para que el sistema deje de verse bien, y diez
> mil transacciones con `lockForUpdate()` sobre las mismas filas tardarían más de lo que
> nadie va a esperar para medir. Lo que no se negocia es el fondo: ningún stock se
> escribe sin su asiento en el kardex, las operaciones van en orden cronológico para que
> `stock_resultante` cierre, y ningún producto quedó con stock negativo.

### Tramo B — arreglos cortos

| Componente                                      | Qué resuelve                                                                     | Estado |
| ----------------------------------------------- | -------------------------------------------------------------------------------- | ------ |
| `MovimientoStock::stock_anterior`               | El saldo que había antes del asiento, sin columna ni migración (punto 8)         | Nuevo  |
| `Producto::scopeDeProveedor()`                  | Filtra el catálogo por el vínculo, no por la columna que se eliminó en la Fase 5 | Nuevo  |
| `?proveedor_id=` en `ProductoFiltroRequest`     | Declara el filtro nuevo en el contrato del listado (A-24)                        | Nuevo  |
| Columnas «Productos» y «Órdenes» de proveedores | Dos preguntas distintas, cada una enlazada a su listado filtrado (punto 9)       | Nuevo  |

> **El stock anterior no necesitó migración y eso es lo que conviene poder explicar.**
> `stock_resultante − cantidad` es exacto porque `cantidad` va con signo y las dos
> columnas las escribe un solo lugar, `StockService::registrar()`, que es privado.
> Incluso `ajustar()`, que recibe un conteo, guarda la diferencia. Una columna
> `stock_anterior` habría agregado un dato que puede contradecir a los otros dos.
>
> El renombre a «Sin identificar» no entra en la tabla porque no agrega ningún
> componente: cambia lo que devuelve `Venta::clienteTexto()` y la opción del selector.
> Lo que sí queda fijado por un test es lo que **no** se renombró,
> `Cliente::CONDICIONES_IVA`, que es una categoría de ARCA y no un rótulo de pantalla.

### Tramo C — motor de precios

| Componente                                                                                            | Qué resuelve                                                                                 | Estado |
| ----------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ------ |
| `MotorPrecios`                                                                                        | Las tres etapas del cálculo, sin base. Lo comparten el simulador, el recálculo y el tramo D  | Nuevo  |
| `PrecioService`                                                                                       | La cascada de ganancia, la parametrización vigente y el asiento de cada cambio de precio     | Nuevo  |
| `impuestos`                                                                                           | Lo que se aplica sobre el neto y se itemiza, con su clase de comprobante de la Ley 27.743    | Nuevo  |
| `factores_costo`                                                                                      | Lo que forma el neto, con `modo` declarado: un recupero divide, no multiplica                | Nuevo  |
| `politica_precios`                                                                                    | Ganancia global, recargo de lista y redondeo, en una fila que la base garantiza con un CHECK | Nuevo  |
| `historial_precios`                                                                                   | Qué precio tuvo, cuál pasó a tener, por qué y quién. Habilita deshacer en el tramo D         | Nuevo  |
| `venta_linea_impuestos`                                                                               | El desglose del renglón congelado, con nombre y alícuota copiados (punto 5)                  | Nuevo  |
| Módulo **Precios**                                                                                    | El noveno: `PrecioController`, tres Form Requests, pantalla de factores y simulador          | Nuevo  |
| `productos.ganancia` y `categorias.ganancia`                                                          | Los dos niveles inferiores de la cascada; null es «heredá», distinto de cero                 | Nuevo  |
| `productos.precio_manual`                                                                             | Saca un producto del recálculo. Los 20.011 existentes entraron marcados                      | Nuevo  |
| Permisos `precio.ver`, `.editar`, `.recalcular`, `.ver_costo`                                         | Un permiso por acción (C-2). `ver_costo` gobierna el costo en ficha, listado y PDF           | Nuevo  |
| `productos.recalcular`                                                                                | Recalcula un producto desde los factores y asienta el cambio                                 | Nuevo  |
| `Producto::margen()`                                                                                  | El margen contra el neto y no contra el precio final, que daría veinte puntos menos          | Nuevo  |
| `Importe::numero()`                                                                                   | Porcentajes y multiplicadores, sin los dos decimales fijos que lleva un importe              | Nuevo  |
| `PrecioEsquemaTest`, `MotorPreciosTest`, `PrecioServiceTest`, `PrecioModuloTest`, `VentaDesgloseTest` | Esquema, cálculo puro, servicio, módulo sobre HTTP y congelamiento del renglón               | Nuevo  |

> **Qué reemplaza este tramo, aunque no migre nada.** El sistema original no tenía motor
> de precios: tenía un campo de precio que alguien tipeaba. Lo que sí se reemplaza es de
> la propia Etapa 1: la constante `Producto::ALICUOTAS_IVA` —una lista de alícuotas fija
> en el código— pasa a ser la tabla `impuestos`, y el campo «precio de lista» sale del
> formulario del producto y se vuelve un recargo de la política. Dos tests de la Fase 3
> se borraron con ese campo: fijaban que el precio de lista servía de respaldo del de
> contado y que uno no podía superar al otro, y las dos reglas sólo existen cuando hay
> dos precios tipeados. Los otros seis que lo nombraban siguen corriendo sobre
> `precio_contado`, con las mismas afirmaciones.
>
> **Por qué el motor calcula en punto flotante y no en decimal.** El invariante A-17 es
> sobre las **columnas**: ninguna se declara `float`, y el esquema completo lo verifica
> `EsquemaDatosTest`. El cálculo intermedio es otra cosa, y sigue el camino que
> `VentaService::importesDe()` ya usaba desde la Fase 6: se opera en `float` y se redondea
> a dos decimales en cada frontera que se persiste. Con importes por debajo de 10^10 y
> media docena de multiplicaciones, el error de un `double` queda unos diez órdenes de
> magnitud por debajo del centavo. Usar `bcmath` habría sido una extensión de PHP más en
> los requisitos para una precisión que ningún precio necesita, y dos aritméticas
> conviviendo en el mismo sistema son peores que una.
>
> **El comprobante en PDF no muestra el bloque de Transparencia Fiscal, y es a
> propósito.** Su docblock ya decía que no discrimina IVA porque discriminar es lo que
> hace una Factura A, y éste no es ese comprobante. El plan pide que el **modelo** quede
> listo para la Ley 27.743, no que un documento no fiscal imprima un bloque que la ley
> le exige a uno fiscal. El desglose se ve en la ficha de la venta, que es donde se
> consulta, y la facturación de la Etapa 4 ya tiene el dato que va a necesitar.

### Tramo D — ajuste masivo de precios

| Componente                                                                     | Qué resuelve                                                                                                               | Estado   |
| ------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- | -------- |
| `lotes_ajuste`                                                                 | El ajuste como hecho con nombre: criterio congelado, conteos del momento y reversión derivada                              | Nuevo    |
| `historial_precios.lote_id`                                                    | Qué filas del historial pertenecen al mismo acto. Es lo que hace posible deshacer                                          | Nuevo    |
| `historial_precios.costo_*` y `.ganancia_*`                                    | Lo que el ajuste movió además del precio, para que deshacer sea exacto y no aproximado                                     | Nuevo    |
| `LoteAjuste`                                                                   | El modelo del lote, con `ultimoReversible()` y el scope `reversibles()` derivado de los datos                              | Nuevo    |
| `AjusteMasivoService`                                                          | `previsualizar()`, `proyectar()`, `aplicar()` y `deshacer()`, en tandas y sobre una sola instancia de `PrecioService`      | Nuevo    |
| `AjusteMasivoRequest`                                                          | Un solo contrato para la previsualización y el ajuste: con dos, la pantalla mostraría un conjunto y el POST aplicaría otro | Nuevo    |
| `AjusteMasivoController`                                                       | La pantalla por GET y el ajuste por POST, más el historial de lotes y el deshacer                                          | Nuevo    |
| `StockService::fijarCosto()`                                                   | La quinta hoja de la puerta al costo: escribe `costo_promedio` sin mover stock y sin asiento                               | Nuevo    |
| Seis scopes de recorte en `Producto`                                           | `conIds`, `conCostoDesde/Hasta`, `conPrecioDesde/Hasta`, `conPrecioManual`; los cruzados salen de encadenarlos             | Nuevo    |
| `PrecioService::fijarPrecios()` con lote y economía                            | El historial de precios sigue teniendo un solo escritor                                                                    | Ampliado |
| Permisos `precio.ajustar_masivo` y `precio.deshacer_ajuste`                    | Un permiso por acción (C-2): cambiar cinco mil precios y revertirlos son dos autoridades                                   | Nuevo    |
| `precios/ajuste.blade.php` y `precios/lotes.blade.php`                         | La pantalla en tres pasos con su previsualización paginada, y el historial con el deshacer                                 | Nuevo    |
| Casillas de selección en `productos/index.blade.php`                           | El séptimo recorte: tres productos puntuales, asociados al formulario con el atributo `form`                               | Nuevo    |
| `permisosDelModulo()` en `tests/Pest.php`                                      | El conjunto de «los otros» de C-2 sale del seeder y no de una lista copiada en un test                                     | Nuevo    |
| `AjusteMasivoFiltrosTest`, `AjusteMasivoServiceTest`, `AjusteMasivoModuloTest` | Los recortes, el servicio con sus tres salvaguardas, y el cableado por HTTP                                                | Nuevo    |

> **Qué reemplaza este tramo, aunque no migre nada.** El sistema original no tenía ajuste
> masivo: un aumento de rubro se cargaba producto por producto, y el pendiente que el
> revisor levantó en el punto 6 era exactamente eso. Lo que sí se reemplaza es de la propia
> Fase 7.5: el tramo C dejó `PrecioService::recalcular()` para un producto, y este tramo lo
> vuelve una operación sobre un conjunto sin reescribir ni una línea del cálculo.
>
> **Por qué el ajuste corre en una sola transacción.** Un ajuste a medias no se puede
> deshacer: el lote diría que alcanzó a 3.000 productos cuando escribió 1.200, y la segunda
> salvaguarda del tramo dejaría de ser cierta. El precio son unos segundos de bloqueo sobre
> las filas alcanzadas, y es aceptable para una operación que se corre una vez por mes: una
> venta simultánea espera, que es mejor que perderse. Es la decisión más discutible del
> tramo y la que conviene poder defender.
>
> **Por qué el costo se escribe por `StockService` y no por `PrecioService`.** El invariante
> queda intacto en su letra: `productos.costo_promedio` sigue teniendo un solo escritor. Lo
> que cambia es que esa clase ahora tiene un método que no deja asiento en el kardex, y su
> docblock dice por qué: un asiento con cantidad cero no registra ningún movimiento. La
> alternativa —que el ajuste escribiera la columna por su cuenta— habría vuelto falsa la
> primera afirmación del docblock de `StockService`, que es la que mantiene cerrado A-13.
>
> **Dos defectos que el tramo encontró en sí mismo, y conviene contarlos.** El primero: un
> aumento de costo que, por redondeo, deja el precio igual, no dejaba ningún asiento, así que
> ese producto quedaba fuera del «deshacer» en silencio; lo cerró la condición de
> `fijarPrecios()` que mira también la economía, y lo fija un test. El segundo: la ayuda del
> campo prometía «-7,5 baja» y el servidor lo rechazaba, porque `Importe::normalizar()` no
> conoce el signo —y está bien que no lo conozca, en el resto del sistema no hay importes
> negativos—; lo encontró el test del módulo, que es exactamente para lo que existe el
> segundo nivel: el servicio estaba bien y lo que estaba mal era el cableado.
>
> **Una lección de método, que vale anotar.** Cuatro ayudas de prueba vivían dentro de
> archivos de test y se compartían entre ellos, apoyadas en que Pest carga todo en un mismo
> proceso. Eso sólo es cierto corriendo la suite completa: `php artisan test` con una ruta
> concreta carga únicamente ese archivo, y la ayuda desaparece. Pasaron a `tests/Pest.php`,
> que es el único archivo que se carga siempre, y de paso el conjunto de permisos de C-2 dejó
> de ser una lista copiada y se lee del seeder.

### Tramo E — buscador remoto

| Componente                                                  | Qué resuelve                                                                                        | Estado    |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | --------- |
| `BusquedaController`                                        | El único endpoint JSON del sistema: cuatro búsquedas, cada una con su permiso y su tope             | Nuevo     |
| `BusquedaRequest`                                           | El contrato: `q` de dos caracteres, `estado`, `proveedor_id`, `con_proveedor`, y 422 en JSON        | Nuevo     |
| Rutas `busqueda.*`                                          | Cuatro, cada una con el permiso de **ver** la entidad que busca, no uno propio                      | Nuevo     |
| Modo remoto de `selects-buscables.js`                       | `data-buscable-url` con `shouldLoad`, `loadThrottle` y sin puntaje local                            | Ampliado  |
| `data-buscable-minimo` y `data-buscable-detalle`            | La vista declara el mínimo que impone el servidor y qué muestra el desplegable                      | Nuevo     |
| `.ts-pie`, `.ts-detalle`, `.ts-pista`                       | El desplegable dice en qué estado está en lugar de quedar vacío sin explicar                        | Nuevo     |
| `Producto::rotulo()`                                        | Cómo se nombra un producto al elegirlo, en un solo lugar para los cinco que lo arman                | Nuevo     |
| `Producto::proveedoresParaElegir()`                         | El orden de conveniencia —preferido, costo, razón social— que estaba en el controlador              | Nuevo     |
| `Cliente::razonConDocumento()` y `Cliente::documento()`     | Lo mismo para el cliente, con el documento que distingue dos razones sociales iguales               | Nuevo     |
| Las tres ayudas de filas de `VentaController`               | Sólo los productos de las filas rendidas, en lugar del catálogo entero                              | Nuevo     |
| Las tres de `CompraController`                              | Lo mismo para el armador y para la edición de la orden                                              | Nuevo     |
| `exists()` en lugar del catálogo                            | «¿Hay algo que vender?» se contesta sin traer con qué: una consulta que no crece                    | Nuevo     |
| `data-data` en la opción elegida                            | Lo que rinde el servidor y lo que trae el buscador tienen la misma forma, así que el JS no ramifica | Nuevo     |
| `PesoHtmlTest`                                              | El test que no existía a propósito: el HTML pesa lo mismo con cinco productos que con sesenta       | Nuevo     |
| `BusquedaTest`                                              | El contrato del endpoint, con C-2 en las dos direcciones y el costo según permiso                   | Nuevo     |
| Las cuatro pantallas de precios en `escala:medir`           | El pendiente #54, medido de paso porque el comando se corría igual                                  | Ampliado  |
| `VentaController::clientesDisponibles()`                    | Traía los 5.008 del padrón para armar un desplegable                                                | Eliminado |
| `VentaController::productosVendibles()` y `mapaDePrecios()` | Traían los 20.011 activos y su precio y disponible como JSON                                        | Eliminado |
| `CompraController::productosDisponibles()`                  | Traía los productos del proveedor; lo hace el recorte `proveedor_id` del endpoint                   | Eliminado |
| `CompraController::proveedoresPorProducto()`                | El mapa completo en el HTML; lo hace el endpoint con el método del modelo                           | Eliminado |
| Islas `preciosDelCatalogo` y `proveedoresPorProducto`       | Los dos JSON que viajaban en cada carga de pantalla (pendientes #39 y #32)                          | Eliminado |

> **Qué reemplaza este tramo, aunque no migre nada.** El sistema original no tenía
> buscador: tenía un `<select>` con todo adentro, y con once productos de demostración
> eso funcionaba. Lo que se reemplaza es de la propia Etapa 1: tres pantallas que
> mandaban el catálogo completo al navegador en cada carga. El propio
> `selects-buscables.js` lo tenía anotado desde la Fase 6 —«listas de cientos de filas
> se van a buscar en el servidor con un modo remoto (`data-buscable-url`). Esta función
> queda preparada para recibirlo»—, así que el tramo no inventó el diseño: cobró una
> deuda que el archivo se había escrito a sí mismo.
>
> **La excepción a la mejora progresiva, que es la decisión más grande del tramo.** En
> todo el resto del sistema el JavaScript agrega comodidad y no habilita funciones: si
> no carga, el formulario funciona igual. Acá no: sin JavaScript no se puede elegir un
> producto. Se decidió asumirlo y escribirlo, en lugar de dejar una lista corta de
> respaldo que habría ofrecido cincuenta de veinte mil productos sin poder decir cuáles
> faltan. Las cuatro pantallas lo dicen con un `<noscript>` que nombra qué se pierde y
> qué sigue funcionando. Lo que decidió el canje es el número: el armador usaba 392 MB
> contra un límite de 512, así que la alternativa no era «una pantalla menos cómoda»
> sino «una pantalla que a cuarenta mil productos no abre».
>
> **El desplegable distingue tres estados y los dice con tres textos distintos:** no
> escribiste lo suficiente, no hay coincidencias, y no se pudo buscar. El tercero es el
> que importa y es el invariante 16 aplicado a un componente: si el endpoint no contesta
> —sesión vencida, red caída—, un desplegable que dijera «no hay coincidencias» estaría
> afirmando que el producto no existe. Dice que no pudo buscar, y además borra el
> recuerdo de esa búsqueda para que volver a tipearla la reintente.
>
> **El costo viaja sólo con `precio.ver_costo`, y cuando no corresponde la clave NO
> ESTÁ.** Ausente y null son dos cosas distintas: null es «este proveedor no tiene costo
> cargado» y ausente es «no te corresponde verlo». Si se confundieran, el armador le
> diría «sin costo cargado» a un vendedor, que es afirmar un hecho que el servidor no
> contestó. Eso cerró además una filtración que ya existía: `compras/_linea.blade.php`
> ponía `data-costo-sugerido` con el `costo_promedio` de cada producto para cualquiera
> con `compra.crear`, sin mirar ese permiso.
>
> **Lo que se decidió NO pasar a remoto, con su número.** Los selectores de proveedor
> —el de la orden de compra, el filtro del catálogo y los recortes del ajuste masivo—
> se quedan locales: son 303 opciones que crecen con el padrón y no con el catálogo, y
> el criterio del tramo es el catálogo. Además el filtro del catálogo **necesita**
> ofrecer los inactivos, con su sufijo «(inactivo)», porque filtrar por un proveedor al
> que se dejó de comprar es el pendiente #31; y el endpoint devuelve sólo activos a
> propósito, porque su pregunta es «a quién le puedo pedir» y una orden es un
> compromiso de plata. Son dos preguntas distintas y resolverlas con un parámetro más,
> apurado, habría repetido mal la decisión que el tramo tomó con cuidado para los
> productos. Queda como pendiente #57. `/buscar/proveedores` queda entonces **sin
> pantalla que lo use en este tramo**, probado y documentado: el tramo E existe para
> dejar el contrato que reusan F, G, I y J, y el plan le pide a I el alta de proveedor
> desde la orden con inyección en el buscador y a J elegir productos **y** proveedores.
>
> **Dos tests de la Fase 5 que el tramo cambió, y se pidió permiso antes de tocarlos.**
> El primero, `'solo ofrece productos activos que algun proveedor activo provea'`,
> verificaba la regla mirando `assertViewHas('productos', ...)`, o sea el conjunto
> completo que la pantalla rendía. La regla no cambió —no se ofrece lo que el Form
> Request va a rechazar— pero ahora la impone el endpoint con `con_proveedor`, así que
> el test pregunta ahí. El segundo, `'el mapa de proveedores por producto llega a la
> pantalla con el costo y el preferido'`, **se borró**: su regla la fija mejor
> `'los proveedores de un producto salen en el orden en que conviene elegirlos'` de
> `BusquedaTest`, que agrega el caso del proveedor sin costo cargado y corre contra el
> único lugar que ahora decide ese orden. Dejar los dos habría sido dos fuentes para la
> misma pregunta, que es el defecto del pendiente #45.
>
> **Un 500 latente que el tramo arregló de paso.** El `@php` de `ventas/form` hacía
> `max(array_keys(old('lineas')))` sobre lo que llegó en la petición y no sobre lo que
> pasó la validación: una petición con `lineas=texto` fallaba la validación —bien— y
> después reventaba al re-rendir el formulario. Al mover ese cálculo al controlador,
> donde tenía que estar porque es lo que decide qué productos se consultan, quedó con
> su `is_array` y su `array_filter`. Lo mismo en las dos pantallas de compras.

### Tramo F — carga de renglones

| Componente                                                 | Qué resuelve                                                                               | Estado    |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------ | --------- |
| `excluir[]` en `BusquedaRequest` y `BusquedaController`    | El único parámetro que este tramo le agrega al contrato del tramo E                        | Nuevo     |
| `Producto::scopeSinIds()`                                  | El recorte. Su caso vacío significa «no excluyas nada», al revés que el de `conIds()`      | Nuevo     |
| `data-buscable-excluir`                                    | La lista que el componente lee **en cada consulta**, no al iniciar                         | Nuevo     |
| `olvidarBusquedas()`                                       | La otra mitad de la exclusión: Tom Select memoriza por texto tipeado                       | Nuevo     |
| `exactoPrimero()`                                          | El lector de código de barras, resuelto en el cliente para no reabrir el contrato          | Nuevo     |
| `data-buscable-url` leída en cada consulta                 | El buscador de la orden de compra sigue al proveedor **elegido**, no al guardado           | Ampliado  |
| El buscador único de `ventas/form` y `compras/form`        | Un gesto en lugar de dos: se escribe, se elige, y el renglón ya está                       | Nuevo     |
| El renglón con el producto en texto y su id oculto         | No hay selector que mantener, y por eso el pendiente #30 deja de existir                   | Nuevo     |
| `data-precio-hoy` y `data-disponible` en la fila           | Reemplazan al objeto de la opción de Tom Select, que desaparece con el selector            | Nuevo     |
| El recorrido con teclado                                   | Enter avanza —cantidad, costo, y vuelta al buscador— en lugar de enviar el formulario      | Nuevo     |
| `Venta::faltantesDeStock()`                                | Una sola definición de «este renglón pide más de lo que hay», para tres consumidores       | Nuevo     |
| `VentaLinea::scopeSuperanElDisponible()`                   | La misma regla en SQL, para que el listado cuente sin un N+1 por fila                      | Nuevo     |
| `Producto::DISPONIBLE` pública                             | La resta sigue en un solo lugar, ahora con un segundo consumidor legítimo                  | Ampliado  |
| `withCount(['lineas as faltantes_count'])`                 | El conteo del listado, en una subconsulta del mismo SELECT (A-26)                          | Nuevo     |
| La insignia «sin stock suficiente»                         | El punto 2 en la ficha y en el listado, sólo en presupuestos                               | Nuevo     |
| `VentaController::avisoDeStock()`                          | El mensaje de guardado pasa de verde a azul cuando hay faltantes                           | Nuevo     |
| El rótulo oculto y el `aria-label` que nombran el producto | Con un selector por fila el nombre no existía hasta elegirlo; con el producto en texto, sí | Nuevo     |
| El `<select>` de producto por renglón                      | El del pendiente #30, y el que el tramo E había dejado con una sola opción                 | Eliminado |
| El botón «Agregar producto» de las dos pantallas           | Agregaba una fila vacía con un selector que había que llenar después                       | Eliminado |
| El `data-data` con el costo de `compras/_linea`            | El costo sugerido ya no viaja en el HTML: llega con el resultado de la búsqueda            | Eliminado |
| El manejador que sugería el costo desde el selector        | La sugerencia pasó al único momento en que la fila nace, así que no hay nada que pisar     | Eliminado |
| La rama `$esEdicion === false` de `compras/form`           | No existe `compras.store`: el título y el botón de alta no se renderizaban nunca           | Eliminado |
| `PagoController::problemasQueVanAFallar()`                 | Sigue diciendo lo mismo, pero el número lo pide al modelo en lugar de restar por su cuenta | Ampliado  |

> **Qué reemplaza este tramo.** El sistema original cargaba un renglón con un
> `<select>` de todo el catálogo por fila, y la Etapa 1 lo heredó con Tom Select
> encima. El tramo E le quitó el peso dejando que el servidor rinda una sola opción;
> este tramo le quita el **gesto**: el selector ya no existe, y el renglón se agrega
> desde un buscador que está una sola vez en la pantalla. Lo que se reemplaza es, de
> nuevo, algo de la propia Etapa 1 y no del sistema viejo.
>
> **La decisión de diseño que condicionó todo lo demás, y se tomó antes de escribir
> la vista.** Mientras el renglón tenía un selector, el precio y el disponible vivían
> en el objeto de la opción de Tom Select, y eso es lo que había dejado a
> `lineas-venta.js` sin mapa global. Con el producto en texto fijo ese objeto
> desaparece, así que los dos datos pasaron a atributos `data-` de la fila: el id en
> un campo oculto —que es lo único que se manda— y el precio y el disponible en el
> `<tr>`. El resto del archivo sigue hablando de «datos» y no sabe de dónde salen,
> que es lo que permitió que el cambio fuera una función y no el archivo entero.
> `VentaRequest` no se tocó: el renglón manda **sólo producto y cantidad**, y
> `lineas.*.precio_unitario` sigue declarado `prohibited`.
>
> **El lector de código de barras se resolvió en el cliente, y con intención.** El
> endpoint ordena por nombre, que es el orden en que un humano quiere leer un
> desplegable, así que un código tipeado entero no cae primero. Se eligió mover esa
> fila al frente en el componente en lugar de agregarle un orden nuevo al servidor:
> el contrato lo comparten cuatro pantallas y el atajo es una propiedad del gesto, no
> de los datos. Sin puntaje local Tom Select respeta el orden de inserción y deja
> activa la primera opción, así que Enter la elige — y como las búsquedas de clientes
> y proveedores no traen `codigo`, nunca coinciden y el código no ramifica por
> entidad. Se preselecciona en lugar de agregar sola: la mayoría de los lectores
> manda Enter, así que el lector sigue siendo gratis y nadie agrega un renglón que
> todavía no confirmó.
>
> **Las dos mitades de la exclusión van juntas, igual que en el tramo E.** El
> atributo dice qué no ofrecer, y `olvidarBusquedas()` borra lo ya buscado: sin eso,
> volver a tipear el mismo texto mostraría el producto que acaba de quedar cargado,
> porque Tom Select memoriza cada búsqueda por su texto. Es la misma estructura que
> el par `score`/`clearOptions()` que el tramo E dejó documentado, y por el mismo
> motivo: una optimización que esconde la mitad del mecanismo. Se compara antes de
> escribir porque el refresco corre en cada tecla de cada cantidad, y olvidar ahí
> vaciaría el desplegable mientras se usa.
>
> **Por qué «este renglón pide más de lo que hay» está escrito dos veces.** La ficha
> tiene las líneas cargadas y puede preguntarlo en PHP; el listado no puede, porque
> hacerlo por fila sería un N+1 y `EscalaTest` existe para eso. Así que la regla vive
> en `Venta::faltantesDeStock()` y se repite en `VentaLinea::scopeSuperanElDisponible()`.
> Lo que sí quedó en un solo lugar es la **resta**: `Producto::DISPONIBLE` del lado
> SQL y el accesor `stock_disponible` del lado PHP, que es el invariante 6. Lo único
> duplicado es la comparación, y un test obliga a los dos lenguajes a dar el mismo
> número. Esa fue la condición para aceptar la duplicación.
>
> **El `<noscript>` de las dos pantallas se mantuvo cierto, y quedó mejor.** Sigue
> siendo verdad que sin JavaScript no se pueden agregar productos, y la frase se
> corrigió para decir eso en lugar de «no se puede elegir un producto». Lo que cambió
> para bien: el producto de cada renglón ahora **se lee**, porque es texto, en lugar
> de ser un `<select>` con una sola opción. Y Enter en la cantidad deja de enviar el
> formulario sólo cuando el JavaScript corre; sin él, Enter envía como en cualquier
> formulario.
>
> **Un defecto que el tramo encontró en su propia entrega.** El fragmento que
> reemplazó el bloque de constantes de `lineas-orden.js` se quedó corto y se llevó
> consigo el formateador de decimales, que en el archivo viejo estaba declarado
> **adentro** del manejador que el tramo borró. El archivo completo pasaba
> `node --check` —que valida sintaxis, no identificadores— y la partición en
> fragmentos no se había verificado contra el original. Desde ahí el procedimiento es
> reconstruir el archivo aplicando los fragmentos sobre el original y comparar las
> declaraciones contra el objetivo, que es lo que encontró el faltante y confirmó que
> `lineas-venta.js` estaba completo.

### Tramo G — sugeridos de reposición

| Componente                                                              | Qué resuelve                                                                                   | Estado    |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | --------- |
| `Producto::scopeParaReponer()`                                          | Una sola definición de «lo que hay que pedir», para sus tres consumidores                      | Nuevo     |
| `Producto::scopeConProveedorActivo()` y `scopeSinProveedorActivo()`     | «Hay a quién pedirle», en un scope en lugar de un `whereHas` por pantalla                      | Nuevo     |
| `Producto::scopeSinProveedorParaReponer()`                              | Los huérfanos del aviso: hay que pedirlos y no hay a quién                                     | Nuevo     |
| `?stock=sin_proveedor` en `ProductoFiltroRequest`                       | El enlace del aviso cae en el mismo conjunto que el aviso cuenta                               | Nuevo     |
| `CompraService::lineasSugeridas()`                                      | Los ids elegidos, convertidos en renglones con el proveedor y el costo que decide el servidor  | Nuevo     |
| `PedidoCompraRequest::MAXIMO_LINEAS`                                    | El tope del documento en una constante, en lugar de escrito a mano en la regla y en el mensaje | Ampliado  |
| `PedidoCompraFiltroRequest`                                             | Con qué entra el armador: una lista de ids y nada más                                          | Nuevo     |
| `CompraController::sugeridos()` y `estimadoDe()`                        | La pantalla y su estimado, sin una consulta más que la del listado                             | Nuevo     |
| `compras/sugeridos.blade.php`                                           | Qué hay que reponer, agrupado por proveedor, con el botón de cada grupo                        | Nuevo     |
| `compras/_pestanas-pedido.blade.php`                                    | Las dos formas de armar un pedido, como enlaces y no como pestañas de JavaScript               | Nuevo     |
| El bloque de selección de `app.js`, generalizado                        | El catálogo y los sugeridos eligen de a página con el mismo código                             | Ampliado  |
| `.casilla-fila` en `app.scss`                                           | La casilla y la fila marcada se ven: el gesto principal dejó de ser un detalle de 16 píxeles   | Nuevo     |
| La insignia del menú de Compras                                         | Lo que espera aprobación se ve sin entrar a `/compras`: media respuesta al pendiente #36       | Nuevo     |
| `View::composer('partials.navbar')` en `AppServiceProvider`             | El dato de la insignia, que ningún controlador puede pasarle a un parcial global               | Nuevo     |
| El conteo «Stock crítico» del panel                                     | Pasa a la definición única, y de paso cambia de rótulo: contaba lo que no se repone solo       | Ampliado  |
| Las tres consultas encadenadas de `GenerarOrdenesReposicion`            | Las reemplaza `paraReponer()`: la definición dejó de vivir dentro del comando                  | Eliminado |
| El `whereHas` de `hayPedibles` en `CompraController::armar()`           | Lo reemplaza `conProveedorActivo()`, que es el mismo ORM en el modelo                          | Eliminado |
| Los ids `seleccionarTodos`, `contadorSeleccion`, `ajustarSeleccionados` | El bloque generalizado no los necesita: alcanzan el formulario y sus atributos `data-`         | Eliminado |

> **Qué reemplaza este tramo.** Nada del sistema viejo: el sistema original no tenía
> reposición. Lo que reemplaza es un hueco de la Etapa 1, que dejó la reposición
> automática de las 07:00 usando tres scopes que el armador manual no usaba. El
> resultado era que el sistema sabía qué faltaba y la persona que armaba un pedido no:
> tenía que recorrer el catálogo producto por producto para enterarse de algo que ya
> estaba calculado.
>
> **La decisión que condicionó todo lo demás, y se tomó antes de escribir una línea.**
> El plan pedía una pestaña «Sugeridos» dentro del armador. No se puede: los sugeridos
> son un listado de productos críticos, y `PesoHtmlTest` exige que `/compras/pedido`
> pese **exactamente lo mismo** con cinco productos que con sesenta. Y no es un riesgo
> teórico: `ProductoFactory` deja `stock = 0` con un mínimo entre 2 y 10, así que **cada
> producto que ese test crea es un sugerido**, y cualquier forma de rendirlos en esa
> pantalla —paginados, topeados con aviso de recorte— la hace crecer. Así que los
> sugeridos viven en su propia pantalla, y las dos se presentan como pestañas **que son
> enlaces**: el `<h1>` dice «Armar pedido» en las dos, cada una es una URL, y el peso del
> armador no depende de nada que crezca. Paginar de a 20 es lo que hace el resto del
> sistema; el tope de 50 quedó donde es verdad, en el documento.
>
> **Los sugeridos elegidos viajan por GET, en la URL del armador.** Se evaluó un POST que
> dejara la canasta en la sesión y se descartó: un `flash` se consume, así que recargar el
> armador la perdería, y el botón «atrás» dejaría de funcionar. Con la canasta en la URL la
> pantalla queda enlazable, sobrevive a un refresco, y los botones por proveedor son
> enlaces en lugar de formularios —que además no podrían vivir dentro de la tabla—. Es la
> misma forma que ya usaban los filtros y las casillas del catálogo del tramo D.
>
> **La pantalla manda ids y nada más.** La cantidad, el proveedor y el costo los resuelve
> el servidor con `proveedorParaReponer()` y el costo del vínculo, que es exactamente lo
> que hace `generarBorrador()` a las 07:00. Es la decisión del renglón de venta del tramo
> F —donde `precio_unitario` sigue `prohibited`— aplicada acá: no hay forma de que la
> pantalla afirme un costo que el sistema no calculó. Y lo que entre la carga de la
> pantalla y el clic dejó de hacer falta **no entra y se avisa**, en lugar de descartarse
> en silencio (M-31).
>
> **Por qué el aviso de los huérfanos y su enlace son el mismo conjunto.** El aviso cuenta
> `sinProveedorParaReponer()` y el enlace lleva a `?stock=sin_proveedor`, que es ese mismo
> scope. Si el filtro hubiera sido «cualquier reponible sin proveedor», el aviso diría tres
> y el catálogo mostraría nueve, y el usuario tendría que decidir a cuál creerle. El valor
> vive en `stock=` aunque no describa un estado de stock, porque lo que describe es un
> recorte del stock crítico: «de lo que hay que pedir, lo que no se le puede pedir a nadie».
>
> **La insignia cuesta una consulta por pantalla, y está medida.** Se la ve sólo quien
> tiene `compra.aprobar`: un número que avisa de algo que no podés hacer es ruido, y así la
> consulta no se paga en las pantallas de quien no la necesita. No se guarda en caché a
> propósito —un conteo cacheado un minuto afirma algo que puede ya no ser cierto, y el
> invariante 16 dice que el sistema no afirma hechos que no puede verificar—, así que el
> costo se asume y se anota en `escala.md` §7.
>
> **La carga cognitiva como criterio de aceptación, no como adorno.** La pantalla se diseñó
> con siete decisiones explícitas: una sola decisión por pantalla —acá se elige, en el
> armador se revisa—, los dos pasos numerados arriba, sin filtros que haya que entender
> antes de poder trabajar, agrupada por proveedor con el botón en el encabezado del grupo
> —el caso real es «hoy le pido a Austral», y eso es un clic—, el proveedor **no** repetido
> en cada fila porque ya está en el encabezado, dos acciones y no tres, y un badge de stock
> **sólo** cuando el disponible está en cero: si todas las filas están en stock bajo, un
> badge en todas no informa nada. La explicación conceptual va plegada y la definición del
> agrupamiento en el tooltip del `(i)`, como manda `patron-modulo.md` §7.
>
> **Un test de la Fase 7 cambió de forma de verificar, sin cambiar lo que verifica.**
> `PanelServiceTest::ninguna consulta del panel trae filas completas` buscaba el texto
> `select *` en cada consulta del panel, y el conteo unificado usa `whereDoesntHave()`, que
> genera `not exists (select * from …)`: la forma canónica de un EXISTS, que no trae ninguna
> columna ni materializa ninguna fila. La consulta es un `COUNT` y cumple A-26; lo que
> fallaba era la heurística de texto. El test ahora recorta las subconsultas de existencia
> antes de mirar, y sigue exigiendo las dos cosas que exigía: que cada consulta sea una
> agregación y que ninguna descargue filas. Se verificó contra el SQL real que un
> `select * from productos` lo sigue haciendo fallar. Es el mismo criterio con el que el
> tramo E reescribió dos tests de la Fase 5: la regla se conserva, cambia dónde se mira.
>
> **Lo que el tramo dejó afuera a propósito.** El gesto del tramo F **no** se llevó al
> armador: su unicidad es el par (producto, proveedor), así que excluir por id sería más
> restrictivo que el servidor, y excluir pares es un parámetro nuevo y más grande. Queda
> como pendiente #65, y los sugeridos no lo necesitan porque no agregan filas con un
> buscador. Tampoco se agregó un filtro por proveedor a la pantalla: el proveedor habitual
> lo decide una regla de PHP y no se puede ordenar ni filtrar por eso en SQL sin recorrer
> todo el conjunto, así que el agrupamiento es de la página y la pantalla lo dice —pendiente
> #64—. Y los sugeridos **reemplazan** lo que el armador tuviera cargado en lugar de
> sumarse, porque el armador no guarda estado entre pantallas: pendiente #63.


### Tramo H — entregas y remito del proveedor

| Componente                                                                             | Qué resuelve                                                                            | Estado      |
| -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ----------- |
| `movimientos_stock.remito_numero` y `remito_fecha`                                     | El remito del proveedor, congelado en el asiento de cada recepción                      | Nuevo       |
| `MovimientoStock::remitoTexto()`                                                       | El remito en palabras, para el kardex; null si no consta                                | Nuevo       |
| `StockService::recibirCompra()` y `CompraService::recibir()`                           | Reciben el remito; número y fecha juntos o ninguno, también sin pasar por el formulario | Ampliado    |
| `RecepcionCompraRequest`                                                               | El remito validado: completo, hasta 30 caracteres, sin fecha futura                     | Ampliado    |
| `compras/recepcion`, `compras/show` y `stock/index`                                    | El campo junto a las cantidades, y el número en la ficha de la orden y en el kardex     | Ampliado    |
| `venta_lineas.cantidad_entregada`                                                      | El saldo de la entrega en dos veces; null en las líneas anteriores, «no consta»         | Nuevo       |
| `entregas`, `entrega_lineas`, `Entrega` y `EntregaLinea`                               | Cada entrega como un hecho de sólo agregado, que su comprobante puede regenerar         | Nuevo       |
| `Venta::estadoDeEntrega()` y `VentaLinea::cantidadPendienteDeEntregar()`               | Parcial o total en un solo lugar, derivado de los renglones (invariante 9)              | Nuevo       |
| `MaquinaEstadosVenta`: transiciones a `entregada_parcial` y `puedeEntregar()`          | El estado declarado en la Fase 6 pasa a ser alcanzable, con sus dos orígenes            | Ampliado    |
| `VentaService::entregar()`                                                             | Toda o en parte, con la venta bloqueada; sin cantidades entrega todo lo pendiente       | Ampliado    |
| `EntregaRequest`                                                                       | Las cantidades, con quién entregó y el estado declarados `prohibited`                   | Nuevo       |
| `VentaController::entrega()` y `entregaPdf()`, `ventas/entrega` y `ventas/entrega-pdf` | La pantalla de la entrega parcial y el comprobante interno con su pie                   | Nuevo       |
| `ventas/show`                                                                          | Las entregas con su comprobante, la columna «Entregadas» y los dos botones de entrega   | Ampliado    |
| `MaquinaEstadosVentaTest`: «entregada_parcial … ninguna transición lo alcanza»         | Reemplazado, con permiso, por el test de sus dos orígenes y el de `puedeEntregar()`     | Reemplazado |
| `OperacionesDemoSeeder` y `OperacionesVolumenSeeder`                                   | La demostración registra quién entregó; el volumen deja `cantidad_entregada` en null    | Ampliado    |

> **Qué reemplaza este tramo.** Del sistema viejo, nada: no registraba ni el remito que
> entra ni la entrega que sale. Lo que reemplaza es la entrega de la Fase 6, que era un
> cambio de estado de todo o nada sin ningún papel.
>
> **La decisión que condicionó el tramo: el remito en el asiento, no en la orden.** El
> plan lo ponía en `ordenes_compra`, y una recepción parcial habría pisado el remito de la
> anterior (invariante 1). Se consultó antes de escribir código y se movió al asiento, que
> ya era el registro de cada recepción. La segunda decisión de fondo fue `cantidad_entregada`
> en null para las líneas viejas, en lugar del default 0 que preveía `modelo-datos.md`, para
> no afirmar «cero entregadas» en ventas ya entregadas (invariante 16). Y un test de la Fase 6
> se reemplazó con permiso: fijaba que `entregada_parcial` era inalcanzable, y estaba escrito
> para fallar el día que existiera la entrega parcial.


### Tramo I — usabilidad de carga

| Componente                                    | Qué resuelve                                                                                        | Estado   |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------- | -------- |
| `Cliente::resultadoDeBusqueda()`              | La fila con la que un cliente llega al buscador; la usan la búsqueda y el alta                      | Nuevo    |
| `BusquedaController::clientes()`              | Arma la fila con el método del modelo; el contrato del tramo E no cambia                            | Ampliado |
| `ClienteController::store()`                  | Contesta 201 con la fila si la petición pide JSON; el alta normal sigue redirigiendo                | Ampliado |
| `clientes/_campos`                            | Los campos del cliente, una sola vez para `clientes/form` y para la ventana de la venta             | Nuevo    |
| `ventas/form`: «Nuevo cliente» y la ventana   | Un enlace a `clientes/crear` que Bootstrap convierte en ventana; vive afuera del `<form>`           | Ampliado |
| `componentes/alta-rapida.js`                  | Postea pidiendo JSON, pone cada error del 422 en su campo y deja elegido al cliente nuevo           | Nuevo    |
| `selects-buscables.js`: `elegirOpcion()`      | Mete una fila del servidor en el buscador con el mismo `aOpcion()` de las búsquedas                 | Ampliado |
| `config/venta.php`: `cobro.billetes`          | Los billetes del cobro como dato, no escritos en la vista                                           | Nuevo    |
| `ventas/cobro` y `ventas/_medio-cobro`        | El efectivo propuesto en el value que rinde Blade, y «dividir el pago» en un `<details>`            | Ampliado |
| `componentes/cobro.js`                        | «Total» por medio, billetes con el vuelto en pantalla y la suma en vivo; nada de eso viaja          | Nuevo    |
| `ClienteAltaRapidaTest` y `CobroPantallaTest` | Las mismas reglas por las dos vías, el permiso, la ventana fuera del form, la propuesta y su retiro | Nuevo    |

> **Qué reemplaza este tramo.** Nada del sistema viejo: son dos fricciones de las pantallas
> de la Fase 6. Crear un cliente obligaba a salir de la venta, y cobrar obligaba a tipear un
> importe que la pantalla ya conocía.
>
> **La decisión que condicionó el tramo: un solo alta, con dos respuestas.** La ventana
> postea a `clientes.store` y pasa por el mismo `ClienteRequest`. Si la petición pide JSON,
> `store()` contesta con `expectsJson()`, que es el mismo criterio con el que Laravel ya
> devuelve el 422. La fila sale de `Cliente::resultadoDeBusqueda()`, el método que usa el
> buscador. Así las reglas, los mensajes y el formato del cliente existen una sola vez.
> En el cobro, la propuesta del efectivo es del servidor y no del JavaScript, y se retira
> cuando la pantalla vuelve de un rechazo. Lo que el plan pedía y no se hizo, el alta de
> proveedor desde la orden, está en el pendiente #69 con su porqué.


### Tramo K — impresión configurable

| Componente                                                      | Qué resuelve                                                                                                  | Estado    |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | --------- |
| `App\Impresion\Listado` y sus ocho definiciones                 | Cada módulo declara sus columnas, cuáles piden permiso, sus conteos, sus cortes y cómo nombra sus filtros     | Nuevo     |
| `Columna`, `Agrupacion`, `Opciones`                             | La columna con su tipo y su permiso; el corte, ordenado por subconsulta y sin join; lo pedido, ya validado    | Nuevo     |
| `ImpresionService`                                              | El motor único: valida las opciones, arma el PDF con tope y escribe el CSV sin tope, de a tandas              | Nuevo     |
| `Controllers\Concerns\ImprimeListados`                          | El `imprimir()` de los ocho controladores: PDF o CSV, y una opción mal pedida vuelve al listado con el motivo | Nuevo     |
| `filtradas()` en siete controladores                            | `index()` e `imprimir()` filtran con la misma cadena, como ya hacía Ventas                                    | Ampliado  |
| `VentaController::exportar()`                                   | Pasa al motor; `TOPE_EXPORTACION` y `filtrosAplicados()` se mudan a `ImpresionService` y a `ListadoVentas`    | Reescrito |
| `impresion/listado-pdf`                                         | Cualquier listado en papel: alcance, emisor, grupos, subtotales, total, aviso de recorte y «Hoja X de Y»      | Nuevo     |
| `ventas/listado-pdf`                                            | Reemplazada por la vista compartida                                                                           | Borrado   |
| `<x-imprimir>`, `componentes/impresion.js`, `@stack('modales')` | El botón de cada listado y su diálogo, que se rinde al final del `<body>` y no adentro de los filtros         | Nuevo     |
| Las rutas `*.imprimir`                                          | Una por módulo, con el permiso de ver; Ventas conserva `ventas.exportar`                                      | Nuevo     |
| `Impresion{Service,Pdf,Dialogo}Test` y ocho `*ImpresionTest`    | El motor, el papel, el diálogo y cada módulo; `VentaModuloTest` reescribe tres tests con las mismas reglas    | Nuevo     |

> **Qué reemplaza este tramo.** Los tres PDF que había para ocho módulos, y el tope de 500
> filas sin salida (pendiente #50).
>
> **La decisión que condicionó el tramo: dos salidas, y ninguna medición.** El plan pedía
> medir el catálogo con dompdf, pero `escala.md` §8 ya había cerrado las mediciones. dompdf
> arma el documento entero en memoria antes de escribirlo, así que el PDF conserva su tope
> de 500 y lo declara. El listado completo sale como planilla CSV, escrita por filas con
> `chunkById()`: memoria constante por construcción, sin librería y sin medición. El escritor
> de PDF por filas se descartó porque pedía una librería nueva. El costo de la decisión: el
> CSV avanza por una columna única (el código en el catálogo, el id en los demás) y no sigue
> el orden de la pantalla. **Imprimir pide el permiso de ver**, porque el papel no muestra
> nada que la pantalla no muestre, y el costo se cuida columna por columna con
> `precio.ver_costo`. Sin el permiso, la columna no existe; no aparece vacía.


### Tramo L — recorte del panel

| Componente                                                                                               | Qué resuelve                                                                                      | Estado    |
| -------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | --------- |
| `PanelService`                                                                                           | Cada número del período, contado otra vez con las fechas corridas; ya no calcula los rankings     | Ampliado  |
| `PanelService::periodoAnterior()`                                                                        | El período de igual largo pegado al elegido, sin mover las fechas que recibe                      | Nuevo     |
| `App\Support\Comparacion`                                                                                | La frase «12 % más que…», y «Sin datos del período anterior» cuando no hay base                   | Nuevo     |
| `panel/_tarjeta`                                                                                         | El número es enlace dentro del `@can` de su destino, y lleva su comparación                       | Ampliado  |
| `panel/general`                                                                                          | Cinco tarjetas, «Requiere atención», un gráfico, dos tablas en `<details>` y el acceso a informes | Reescrito |
| `panel/propio`                                                                                           | Comparación en sus cinco números del período; los presupuestos abiertos enlazan a los propios     | Ampliado  |
| `componentes/graficos.js`                                                                                | Un solo gráfico: cobrado por medio de pago pasó a tabla plegada                                   | Recortado |
| `OrdenCompra::ABIERTAS`, `ESTADOS_DEL_FILTRO`, `ORIGENES` y `scopeDeOrigen()`                            | `?estado=abiertas` y `?origen=` en el listado de compras: los destinos de la zona de atención     | Nuevo     |
| `InformeVentasService` e `InformeVentasController`                                                       | El ranking de vendedores y los más vendidos, paginados, con la fila de totales del conjunto       | Nuevo     |
| `ventas/vendedores`, `ventas/productos` y sus rutas                                                      | Los dos listados con su período, con `panel.ver_global`                                           | Nuevo     |
| `PanelFiltroRequest`                                                                                     | El mismo contrato de período, ahora para los dos paneles y los dos informes                       | Ampliado  |
| `Comparacion`, `PanelComparacion`, `PanelAtencion`, `PanelGrafico` e `InformeVentas{Filtros,Modulo}Test` | La comparación, los enlaces con su permiso, la tabla del gráfico y los dos listados               | Nuevo     |

> **Qué reemplaza este tramo.** Nada del sistema viejo: son catorce bloques de la Fase 7 en
> una pantalla que no contestaba una pregunta.
>
> **La decisión que condicionó el tramo: dos listados, como pedía el plan.** Se consultó
> antes de leer código, y era la parte más cara. Llevarlos a listados cambió lo que fijaban
> cuatro tests de la Fase 7, que se mudaron con permiso y con las mismas reglas. El resto
> salió de dos criterios. Un número de atención es un enlace sólo para quien puede ver su
> destino, con los filtros que ese listado acepta. Y una comparación que el sistema no puede
> verificar no se escribe: sin base, dice «sin datos»; en una instantánea, no compara.

### Cierre de la Etapa 1

La Etapa 1 cerró con la Fase 7.5. Ningún componente quedó en estado «Pendiente» sin su
motivo: lo que no se migró está en la §12 («Descartado del modelo original») o en
`pendientes-usabilidad.md`, con su número.

---

## 15. Componentes nuevos de la Etapa 2

Se llena tramo por tramo, con el mismo formato que la §14: una tabla por tramo y un bloque
corto con la decisión que lo condicionó. La consigna de la Etapa 2 recomienda «una tabla que
relacione los componentes originales con los nuevos componentes»: es ésta. La matriz de
seguimiento —funcionalidad × pantalla de la app × endpoint × acceso × estado— es una
herramienta propia y vive en el plan de la Etapa 2.


### Tramo A — Contrato y capa de API

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `laravel/sanctum` 4.3 y `personal_access_tokens` | Una fila por credencial: es lo que permite revocar (A-5) | Nuevo |
| `config/sanctum.php` | Vence a los 7 días; `guard` vacío, para que la sesión del panel no autentique contra `api/*` | Nuevo |
| `config/tienda.php` | Todas las claves de la Etapa 2 desde el principio: paginado, reservas, pagos, carrito, novedades | Nuevo |
| `CredencialService` | Emite y revoca; es la única clase que sabe que es Sanctum | Nuevo |
| `ForzarJson` | Ninguna respuesta de `api/*` es HTML ni redirección | Nuevo |
| `SesionOpcional` | Sin credencial, visitante; con una inválida, 401 y no un visitante silencioso | Nuevo |
| `VerificarCuentaTienda` | La mitad API de `VerificarUsuarioActivo`: cuenta activa y de tienda, en cada pedido | Nuevo |
| grupos `tienda.sesion_opcional` y `tienda.protegida` | La lista de middlewares de cada tipo de ruta vive en un lugar; rutas y tests nombran el grupo | Nuevo |
| `RespuestaDeError` y su renderer en `bootstrap/app.php` | Toda excepción de `api/*` sale con `{ mensaje, codigo, errores }`; el 500 sin traza | Nuevo |
| `RateLimiter::for('tienda-acceso')` | 5 por minuto por correo e IP, y 30 por IP sola | Nuevo |
| `Paginado::respuesta()` | La envoltura única de los listados | Nuevo |
| `Importe::json()` | El dinero viaja como cadena con dos decimales | Nuevo |
| `Imagenes::url()` y `producto-sin-imagen.svg` | URL con el host del pedido; nunca `null` (HL-13) | Nuevo |
| `TextosTienda` | Un solo diccionario con lo que se le dice al cliente | Nuevo |
| `UsuarioService::actualizar()` y `eliminar()` | Desactivar o dar de baja una cuenta revoca sus credenciales | Modificado |
| `User` | `HasApiTokens` | Modificado |
| `routes/api.php` | `v1` con sus tres grupos, vacíos | Nuevo |
| `docs/api.md` | Revisión 1: convenciones y registro de cambios | Nuevo |
| `tests/Feature/Api/` y `ImporteJsonTest` | El contrato, la credencial y las piezas puras | Nuevo |

> **El renderer decide por la URL y no por lo que el cliente pide.** Una ruta inexistente de
> `api/*` no corre ningún middleware de grupo, así que `ForzarJson` no la alcanza y
> `expectsJson()` daría falso: devolvería la página HTML de 404 a una app que no la puede leer.
> Por eso el renderer mira `$request->is('api/*')`. Va registrado antes que el de la web, porque
> `StockInsuficienteException` y `TransicionInvalidaException` extienden `ReglaDeNegocioException`
> y, sin esto, una regla de negocio de la API volvería como una redirección.
>
> **Revocar una credencial borra su fila.** No choca con la regla de no borrar hechos del
> negocio: una credencial es un permiso de acceso, no un hecho. La historia que importa (quién
> vendió, quién cobró) está en `ventas` y `pagos`.


### Tramo B — Cimientos de la app

La app es un componente nuevo con **repositorio propio** (`compustack-app`, Flutter, Android). Esta
tabla relaciona sus piezas con la capa del sistema original a la que sirven. El detalle de la
arquitectura está en su `ARQUITECTURA.md`.

| Componente (en `compustack-app`) | Qué resuelve | Estado |
| --- | --- | --- |
| Proyecto `ar.compustack.tienda` | La tienda como app móvil: otro cliente del mismo sistema | Nuevo |
| MVVM con repositorios (`ui/`, `data/`, `core/`) | Vista sin lógica, modelo de vista sin HTTP, repositorios que no se conocen | Nuevo |
| `crearClienteHttp` y sus tres interceptores | Un solo `Dio`: credencial pegada, registro enmascarado (NF-14), errores traducidos | Nuevo |
| `Falla` y `ErrorInterceptor` | El formato de error de `docs/api.md` §3 llega a la app como una clase sellada; manda el `codigo`, no el estado HTTP | Nuevo |
| `TokenStorage` y `SessionRepository` | La credencial en el almacén seguro; la app sólo pregunta si hay sesión | Nuevo |
| `pesos()` | El dinero llega como cadena (`"60500.00"`) y se muestra `$ 60.500,00` sin pasar por `double` | Nuevo |
| `AjustesRepository`, `ConexionRepository`, `ConexionService` y pantalla de ajustes | La dirección del servidor nunca está en el código; se guarda, se prueba y se cambia | Nuevo |
| `go_router` con guarda y `StatefulShellRoute` | Cuatro destinos; una ruta con sesión requerida manda a `/login?desde=` | Nuevo |
| `ui/comun/` (seis estados, `VistaAsincronica`, `BotonAccion`, `Precio`, `InsigniaDisponibilidad`, `TarjetaProducto`) | Los seis estados de pantalla y el botón que se bloquea, antes de la primera pantalla | Nuevo |
| Tema Zinc de `forui` | Claro y oscuro según el sistema | Nuevo |
| `android/app/src/debug/` y `generarRedDebug` | Tráfico en claro sólo en depuración; el release no lo admite por construcción (HL-11) | Nuevo |
| `test/arquitectura_test.dart` | Las reglas 22, 24 y 25 comprobadas leyendo los `import` | Nuevo |

> **Manda el `codigo` del error, no el estado HTTP.** El 409 lo comparten `stock_insuficiente`,
> `carrito_cambio`, `checkout_vencido`, `transicion_invalida` y `regla_de_negocio`, y cada uno pide
> otra reacción. El estado HTTP sólo desempata cuando el código no se conoce.
>
> **La prueba de conexión no depende de `GET /api/v1/categorias`.** Ese endpoint es del tramo C y hoy
> responde 404 `no_encontrado`; cualquier respuesta con la forma del contrato prueba que el servidor
> está. La prueba va sin credencial: la dirección que se prueba todavía no es de confianza.
>
> **Un 401 sólo borra la credencial que viajó en ese pedido.** Si no, un 401 tardío de un pedido
> viejo cerraría una sesión recién abierta.
>
> **`forui` 0.27 no trae el tema Zinc**: sólo Neutral. Zinc se generó con el CLI de `forui`
> (`-p cabbbc`) y vive en `lib/config/tema/`.


### Tramo C — Catálogo y detalle

Servidor (`compustack`) y app (`compustack-app`). La app pasó de 145 a **442 tests** (297 nuevos).

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Categoria::idsVisiblesEnTienda()` | Las categorías activas **con todos sus ancestros activos**: una hija activa bajo un padre inactivo no se muestra. Una consulta por pedido | Nuevo |
| `Categoria::arbolEnTienda()` | El árbol visible, paginado por raíz, con las mismas consultas aunque crezca | Nuevo |
| `Producto`: `scopeEnTienda`, `scopeNovedades`, `scopeDestacados`, `scopeOrdenTienda`, `ORDENES_TIENDA` | Qué se ve y en qué orden (siempre desempata por `id`); una sola lista de órdenes para validar y ordenar | Modificado |
| `Producto::idsNovedades()` y `esNovedad()` | La única definición de novedad: ventana y tope. La sección y la marca de cada fila no se pueden contradecir | Nuevo |
| `Producto::descripcionBreve()` y `disponibilidad()` | Derivadas, sin columna (D-12): las primeras palabras del texto (y nunca más de 250 caracteres) y tres niveles sin el número | Nuevo |
| `Producto::paginaEnTienda()` y `fichaEnTienda()` | Arman la consulta con los datos leídos una sola vez; el controlador no sabe de scopes | Nuevo |
| `PaginadoRequest` y `CatalogoRequest` | `pagina`, `por_pagina`, `q`, `categoria_id`, `orden`, `seccion`; rechazan, no corrigen; los mensajes no dependen de `APP_LOCALE`; `criterios()` normaliza lo que vuelve en la respuesta | Nuevo |
| `CategoriaResource`, `ProductoListadoResource`, `ProductoFichaResource` | Cada clave enumerada: lo que no está escrito no viaja | Nuevo |
| `Api\V1\CategoriaController` y `ProductoController` | Sólo traducen: validan, llaman al modelo, devuelven | Nuevo |
| `Recurso::respuesta()` | La envoltura `{ "datos": … }` de un recurso, gemela de `Paginado`: la de Laravel usa `data` | Nuevo |
| `routes/api.php` | `categorias` en el grupo público; `productos` y `productos/{id}` (`whereNumber`) en sesión opcional | Modificado |
| Casilla «destacado» (`productos/form.blade.php`, `ProductoRequest`, `ProductoService`) | La web puede por fin escribir `destacado`; sólo con `producto.editar`, en la vista y en el servidor | Modificado |
| `TiendaDemoSeeder`, `DatabaseSeeder` y `database/seeders/imagenes/` | Descripciones reales, altas repartidas, destacados y fotos de los 11 productos de la demo; idempotente, no pisa lo editado | Nuevo |
| `docs/api.md` | Revisión 2: los tres endpoints, el criterio de novedad y su porqué | Modificado |
| `tests/Feature/Api/Catalogo{Modulo,Filtros,Escala}Test`, `TiendaDemoSeederTest` y `ProductoModuloTest` | 217 tests nuevos: modelo (45), endpoints (116), escala y peso (16), seeder (29) y casilla (11, dentro de `ProductoModuloTest`) | Nuevo |

> **Novedad = ventana + tope, y por qué los dos.** Con el catálogo de volumen (20.012 productos creados
> en dos días) la ventana sola da «todos»; el tope solo, en un negocio quieto, daría «novedad» a lo de
> hace un año. Combinar la sección con una categoría filtra *dentro* del tope.
>
> **Destacar es una acción de edición y el servidor la hace cumplir.** El formulario es uno solo para
> el alta (`producto.crear`) y la edición (`producto.editar`). Esconder la casilla no alcanza: quien sólo
> crea podría mandar `destacado=1` a mano, así que `ProductoRequest` descarta el campo si el usuario no
> tiene `producto.editar`.
>
> **El árbol de categorías se pagina por raíz** porque «un listado pagina siempre» no admite
> excepciones (regla 15); cada raíz trae su rama entera, así que el árbol no se corta a la mitad.
>
> **La galería nunca viene vacía**: sin fotos trae la imagen por omisión. La app no tiene un caso
> especial de «no hay imágenes».
>
> **Medido con los 20.012 productos reales:** 5 consultas por página sea cual sea su tamaño, de 7 a
> 40 ms con la base caliente (unos 70 en una página muy profunda); 19 KB una página de 50. El test de escala quita el `with('categoria')` y
> falla (54 consultas contra 9): detecta el N+1 que dice vigilar.
>
> **`GET /categorias` existe desde este tramo**: la prueba de conexión de Ajustes (tramo B) ya recibe 200
> en lugar del 404 que aceptaba.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Criterios`, `Pagina<T>`, `Categoria` y `CategoriaResumen`, `Marca`, `Producto`, `ProductoFicha`, `DisponibilidadConverter` y `build.yaml` | Los modelos `freezed` del contrato (`snake_case`), probados contra el JSON de `api.md` §7.1. `Criterios.resueltos` copia lo que el servidor aplica por omisión | Nuevo |
| `ApiService` y `CatalogoRepository` | Atrapan `DioException` y lanzan `e.falla`; el repositorio descarta la respuesta cuyos `criterios` no coinciden con los pedidos (1.12) | Nuevo |
| `Cancelacion` y `Cancelado` | Un pedido viejo se cancela y su resultado no pisa al nuevo | Nuevo |
| `CatalogoViewModel`, `InicioViewModel`, `BusquedaViewModel`, `FichaViewModel` y `sinReintento` | Criterios inmutables; al cambiar uno se descarta lo cargado y se cancela el pedido anterior; la página siguiente se pide una sola vez; 300 ms de espera en la búsqueda; reintento automático de Riverpod apagado | Nuevo |
| `CascaraDestinos`, `MenuCategorias`, `PanelLateral`, `HojaInferior` y `FondoHoja` | Barra Inicio · Menú · Carrito · Perfil sobre los botones del sistema; menú de categorías por niveles en un panel con fondo; filtros en una hoja con fondo | Modificado y nuevo |
| `EncabezadoBusqueda` y `PantallaConBusqueda` | El encabezado es sólo la barra de búsqueda (regla 31), como botón en Inicio y Catálogo y como campo en Búsqueda | Nuevo |
| `TarjetaProducto`, `ImagenProducto`, `EtiquetasProducto`, `InsigniaDisponibilidad` y `ListadoProductos` | Tarjeta de altura pareja con foto (`cached_network_image`, `memCacheWidth`), marcas con color y texto, y la grilla con sus seis estados que comparten Catálogo y Búsqueda | Nuevo y modificado |
| `InicioVista`, `CatalogoVista`, `BusquedaVista`, `FichaVista`, `GaleriaProducto`, `AvisoPrecio` y `NoEncontradoVista` | Las pantallas del tramo: dos carruseles, catálogo con filtros, búsqueda en vivo, ficha con galería y `Hero`, y una sola pantalla de «No encontrado» | Nuevo |
| Router: `/catalogo`, `/buscar`, `/producto/:id`, `errorBuilder` y los `PopScope` | Rutas hijas de Inicio; un id inválido ni se pide; «atrás» vuelve a Inicio desde Carrito y Perfil y desde el login de la guarda | Modificado |
| Splash nativo y `SplashMarca` | El único logo de la app, con fondo crema claro y oscuro | Nuevo |
| Reglas 29 a 34 de `CLAUDE.md` y `ARQUITECTURA.md` | Las pautas de interfaz (Nielsen, KISS, pocos pasos, encabezado y barra) que rigen desde este tramo | Nuevo |

> **La ficha no muestra precio todavía.** El contrato no manda `precios` hasta el tramo D: la ficha y
> las tarjetas dicen «Iniciá sesión» (regla 21), incluso con sesión. El tramo D cambia `AvisoPrecio` por `Precio`.
>
> **El encabezado es sólo la barra de búsqueda y el único logo es el del splash** (reglas 31 y 32).
> Notificaciones no está en la barra: las push son de la Etapa 4.
>
> **Se probó en un teléfono real** (Android 17, edge-to-edge): de ahí salieron seis correcciones de
> interfaz (HL-47 a HL-51 y la dirección del servidor, HL-46) que los tests con falsos no podían ver.

### Tramo D — Cuenta y precios

Servidor (`compustack`) y app (`compustack-app`). La app pasó de 442 a **546 tests** (104 nuevos).

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `RolPermisoSeeder` | Módulo `tienda` (`ver_precios`, `comprar`, `ver_pedidos`); el Cliente recibe `tienda.*` (D-6) | Modificado |
| `RegistroRequest` y `Api\V1\RegistroController` | Alta de la cuenta de tienda con su cliente (DNI sin número, consumidor final) y la credencial, 201. El rol se busca por ámbito y `es_sistema`, no por nombre | Nuevo |
| `SesionRequest`, `Api\V1\SesionController` y `CredencialService::iniciar()` | Inicio de sesión sin sesión web; incorrecta, inexistente, inactiva y de gestión dan el mismo 422. Cierre: revoca la credencial de este dispositivo, 204 | Nuevo |
| `Api\V1\PerfilController` y `PerfilResource` | La cuenta de quien tiene la sesión, sin datos fiscales | Nuevo |
| `App\Support\Api\Precios` y `TextosTienda::PRECIO` | La forma de `precios` (§5), un solo lugar para listado y ficha | Nuevo |
| `ProductoListadoResource` | `precios` con `$this->when(...)`: sin el permiso la clave no está (regla 21); la ficha lo hereda | Modificado |
| `Producto::ORDENES_TIENDA`, `ORDENES_CON_PRECIO` y `scopeOrdenTienda` | `precio_asc` y `precio_desc` por `precio_contado`, desempate por `id` (D-14) | Modificado |
| `Api\V1\ProductoController@index` | El orden por precio da 401 sin sesión y 403 sin permiso **antes de consultar** | Modificado |
| `UsuarioService::cambiarPassword()` | Cambiar o restablecer la contraseña revoca todas las credenciales de la API | Modificado |
| `bootstrap/app.php` | `VerificarAmbitoGestion` corre antes de `SubstituteBindings` (HL-54) | Modificado |
| `routes/api.php` | `registro` y `sesion` públicos con `throttle:tienda-acceso`; `DELETE sesion` y `perfil` protegidos | Modificado |
| `docs/api.md` | Revisión 3: §7.2 cuenta, precios y orden por precio | Modificado |
| `tests/Feature/Api/{Cuenta,Precios,FronteraGestion}Test` y `CredencialTest` | 58 tests nuevos: cuenta (34), precios y orden (15), frontera y permisos (9); y 3 de revocación por contraseña | Nuevo |

> **El orden por precio exige el permiso aunque no muestre precios**: ordenar deja deducirlos. Por eso
> responde antes de consultar, y con 401 o 403, no con 422: el orden existe, lo que falta es poder usarlo.
>
> **El mismo 422 para cuatro casos** (contraseña, correo, inactiva, gestión) y un hash calculado aunque
> el correo no exista: ni el mensaje ni el tiempo dicen qué correos tienen cuenta.
>
> **La frontera se lee del router.** `FronteraGestionTest` recorre cada ruta con el middleware `gestion`,
> con sesión web y con credencial de un cliente: una ruta nueva queda cubierta sin tocar el test. Así
> apareció HL-54.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Precios`, `Credencial`, `Usuario` y `Perfil`; `precios` en `Producto` y `ProductoFicha` | Los modelos del contrato rev. 3, probados contra su JSON; `precios` ausente es `null` y no se escribe (regla 21) | Nuevo y modificado |
| `AuthService` | Registro, inicio y cierre de sesión y perfil; guarda la credencial y devuelve sólo el `Usuario` (regla 22). Un 401 al cerrar cuenta como cerrada | Nuevo |
| `SessionRepository` y `traducirFallas` | Abre, cierra y consulta sin ver la credencial (ya no conoce `TokenStorage`); los repositorios comparten la traducción a `Falla` | Modificado y nuevo |
| `cambiosDeSesionProvider` | Los listados y la ficha se vuelven a pedir al abrir o cerrar la sesión (con o sin precios), **no al vencer** | Nuevo |
| `FormularioCuentaViewModel`, `Validar`, `LoginVista` y `RegistroVista` | Validan antes de enviar (regla 26), un solo envío, cada error del 422 junto a su campo, cuenta regresiva del 429; al entrar vuelven a `desde` aunque el login esté apilado (HL-56) | Nuevo |
| `CuentaVista` (Perfil), `CuentaViewModel`, `MiCuentaVista`, `Iniciales` y `FilaDato` | Perfil según la regla 34 con cerrar sesión (sin red falla y lo dice, regla 27); Mi cuenta de sólo lectura en bloques | Nuevo y modificado |
| `PreciosFicha`, `ahorro()` y la tarjeta con el de contado | La ficha muestra los dos precios con el de contado destacado y cuánto se ahorra; la tarjeta, el de contado | Nuevo y modificado |
| `Paginador` y `CatalogoViewModel.irA` | El catálogo y la búsqueda se paginan con números en lugar del scroll infinito del tramo C (D-21) | Nuevo y modificado |
| `HojaFiltros` y `TextosCatalogo.ordenesPara` | «Menor precio» y «Mayor precio» sólo con sesión; sin ella el orden se descarta antes de pedir | Modificado |
| `idiomaApp` y `flutter_localizations` | La app fijada en español; los textos de Material del SDK además de los de forui (HL-55) | Nuevo |
| Router: `/registro` y `/cuenta/datos` | La misma guarda que `/login`; Mi cuenta pide sesión | Modificado |

> **La sesión vencida se prueba de punta a punta** (`sesion_vencida_test.dart`) con el `Dio` real sobre un
> servidor de mentira: el 401 del catálogo borra la credencial y lo dice, el login vuelve al catálogo y
> el catálogo ya trae los precios.
>
> **Al vencer no se recarga**: la pantalla muestra la falla que lo explica, y una recarga sin credencial
> la taparía con una lista sin precios, justo lo que el 401 de las rutas de sesión opcional evita (`api.md` §4).
>
> **Se probó en un teléfono real**: de ahí salieron la jerarquía de la ficha (los precios antes que los
> datos de referencia), «Disponible» en gris visible y Mi cuenta en bloques.

### Tramo E — Carrito y reserva

Servidor (`compustack`) y app (`compustack-app`). La app pasó de 546 a **673 tests** (127 nuevos).

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| Migraciones `carritos` y `carrito_lineas` | El carrito en el servidor, de la cuenta; `abandonado` declarado sin uso; dos referencias de precio por renglón (D-11); UNIQUE(`carrito_id`, `producto_id`) | Nuevo |
| `Carrito` y `CarritoLinea` | `ESTADOS`, `vigente()`, `enCheckoutVigente()`. Sin `venta()` hasta el F, que trae `ventas.carrito_id` (HL-57) | Nuevo |
| `StockService::reservar()` y `liberar()` | La reserva por la única puerta, con la fila bloqueada y sin asiento (D-5); `liberar()` nunca baja de cero ni falla (HL-4) | Nuevo |
| `StockService::descontar(..., $deReserva)` | Usa `stock_disponible` en lugar de la resta escrita; con `$deReserva`, consume la reserva propia. Sin él, el mostrador no cambia | Modificado |
| `CarritoService` | Un carrito vigente por cuenta (fila del usuario bloqueada); agregar, cambiar, quitar, vaciar sobre lo propio; resumen sin escribir; checkout que guarda las referencias, confirma y recién lanza; vencimiento | Nuevo |
| `CarritoCambioException` y `CheckoutVencidoException` | 409 `carrito_cambio` (con `cambios`) y `checkout_vencido` en `RespuestaDeError`, antes de la madre (HL-17) | Nuevo |
| `CarritoController`, `CarritoLineaController`, `CheckoutController` | Las seis rutas, con `can:tienda.comprar`. Cada escritura devuelve el carrito entero | Nuevo |
| `CarritoCantidadRequest`, `CarritoLineaRequest`, `CheckoutRequest` | Enteros; `cantidad` hasta `carrito.cantidad_maxima`; todo importe `prohibited` (M-31) | Nuevo |
| `CarritoResource` y `ResumenCheckoutResource` | Renglones con disponibilidad por nivel, dos importes, totales y avisos; el checkout suma total por medio y modos de entrega. Sin `tienda.ver_precios`, ningún importe (regla 21) | Nuevo |
| `TextosTienda::CARRITO` y `ENTREGA` | Estado del carrito y modos de entrega con su texto | Modificado |
| `tienda:vencer-reservas` (`VencerReservasTienda`) | Suelta los checkouts vencidos, cada minuto y sin encimarse; el F y el G le suman pasos | Nuevo |
| `config/tienda.php` | `carrito.cantidad_maxima` 10 confirmado (Q-1); `envio.habilitado` (HL-58) | Modificado |
| `docs/api.md` | Revisión 4: §7.3 carrito y checkout, dónde se guarda el carrito | Modificado |
| `tests/Feature/{CarritoEsquema,StockReserva,CarritoService}Test`, `Api/{Carrito,Checkout}Test` | 97 tests nuevos: esquema (5), reserva (15), servicio (34), carrito (31) y checkout (12) | Nuevo |

> **La concurrencia se prueba antes del bloqueo.** En `reservar()` el bloqueo y la lectura son la misma
> consulta, así que la reserva competidora se inyecta con `DB::beforeExecuting`, justo antes del
> `FOR UPDATE`: `DB::listen` corre después y lo inyectado no entraría en la cuenta de ninguna implementación.
>
> **La invariante de HL-4 se verifica en seis puntos** de una secuencia con cuatro cuentas: agregar,
> iniciar, escribir (suelta), volver a iniciar, vencer y vaciar. Con el F, la suma agrega los pedidos.
>
> **Los mensajes que llegan a la tienda no dicen cuántas unidades hay**: el de `reservar()` lo omite; el
> de `descontar()`, que lee el mostrador, lo sigue diciendo.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Carrito`, `Renglon`, `AvisoCarrito` y `ResumenCheckout` | Los modelos de §7.3 contra su JSON; sin permiso, sin precios ni totales (regla 21); un tipo de aviso desconocido es servidor caído | Nuevo |
| `CarritoService` y `CarritoRepository` | Las seis rutas; el carrito como flujo que publica cada escritura; un mismo pedido no sale dos veces; se descarta con cualquier cambio de sesión; el 409 trae `cambios` leídos | Nuevo |
| `CarritoViewModel` y `CarritoVista` | Paso 1 de 5; renglones con imagen, nombre, dos precios con ícono, «− cantidad +» y quitar (botón o deslizar) con deshacer; vaciar con confirmación; relacionados; barra fija con el total y «Realizar pedido» | Nuevo |
| `TarjetaReserva` y `CuentaRegresiva` | Al reservar: la cuenta regresiva y mosaicos de igual alto con medios y entrega | Nuevo |
| `AgregarViewModel`, barra de compra de `FichaVista` y `conCargandoMarca` | Cantidad y «Agregar» fijos en la ficha; sin sesión, al login apilado; sin stock, no se ofrece; el cargador con el logo mientras se agrega | Nuevo y modificado |
| `AgregadoVista` y `relacionadosProvider` | Lo agregado, más de su categoría con «Agregar», «Ver artículos en el carrito» y «Seguir comprando» | Nuevo |
| Tope de stock aprendido del 409 | En el carrito «+» se frena en el stock exacto; en la ficha, una unidad menos que la rechazada (HL-59) | Nuevo |
| `CascaraDestinos` y router | Carrito deja de ser rama: abre `/carrito` y `/agregado/:id` sin barra inferior, con la insignia de unidades (HL-60) | Modificado |
| `PreciosCompactos`, `LeyendaPrecios`, `InsigniaAhorro`, `IndicadorPasos`, `RenglonCarrito`, `SelectorCantidad`, `BarraPedido`, `AvisosCarrito` | Las piezas de la compra; el rótulo de cada precio una sola vez | Nuevo |

> **El tope de stock no se conoce antes de pedir**: el número no viaja (requisito 2.3), así que la app lo aprende
> del 409 `stock_insuficiente`, que no se muestra como error sino como un aviso chico junto a la cantidad.
>
> **Del carrito al pago no hay barra inferior** (regla 32) y **el logo aparece en el cargador al agregar** (regla
> 31): son decisiones de interfaz del tramo, pedidas al revisar las pantallas.
>
> **Los relacionados son de la misma categoría** porque es lo único que el contrato tiene; los títulos no dicen
> «otros compraron» (regla del tramo J).

### Tramo F — Confirmación del pedido

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| Migración `add_tienda_to_ventas_table` | `carrito_id` FK UNIQUE (D-8), `medio_pago`, `reserva_vence` y la dirección congelada, todo nullable; índices (`estado`, `reserva_vence`) y `canal` | Nuevo |
| `Venta` | `carrito()`, `CANALES` con su texto, `deCanal()`, `esOnline()`, `canalTexto()`; `ESTADOS_EN_USO` suma `pendiente_pago` (D-2); `faltantesDeStock()` cuenta la reserva propia (§1.3 C) | Modificado |
| `Carrito::venta()` | El pedido en que se convirtió (HL-57) | Nuevo |
| `VentaService::crearOnline()` | El pedido nace en `pendiente_pago`, sin vendedor, con la lista del medio, el envío y la reserva del carrito, sin moverla (HL-4) | Nuevo |
| `VentaService::marcarPagada(?User)`, `cancelar()`, `cancelarVencidos()` | Cobrar consume la reserva (HL-10); cancelar y vencer la liberan, un pedido por transacción. Sin usuario para la pasarela del G | Modificado y nuevo |
| `VentaService::escribirLineas(..., $lista)` | Cotiza a contado o a lista (HL-14); el mostrador no cambia | Modificado |
| `CarritoService::confirmar()` y `revalidar()` | Bloquea por id y dueño (ajeno: 404); convertido devuelve su pedido; vencido o con cambios, suelta y lanza después de confirmar la liberación | Nuevo |
| `tienda:vencer-reservas` | Suma la cancelación de los pedidos vencidos (D-1) | Modificado |
| `PedidoController`, `ConfirmarPedidoRequest`, `PedidoResource` | `POST /pedidos`: 201 al crear, 200 en el reintento; importes `prohibited`; envío con dirección, retiro sin ella; sin `tienda.ver_precios`, sin importes | Nuevo |
| `CarritoResource` (`id`) y `PerfilResource` (`direccion`) | El `id` que nombra la confirmación (HL-64); la dirección predeterminada, de sólo lectura | Modificado |
| `VentaFiltroRequest`, `VentaController`, `ventas/index` y `show`, `ListadoVentas` | Ampliación 2: columna y filtro «Canal»; «Cancelar el pedido» avisa que libera la reserva | Modificado |
| `config/tienda.php` | `envio.costo` 8.000 (Q-1) | Modificado |
| `docs/api.md` | Revisión 5: §7.4 pedidos, `id` del carrito, `direccion` del perfil | Modificado |
| `tests/Feature/{VentaOnlineService,CarritoService,CarritoEsquema,VentaFiltros,VentaModulo}Test`, `Api/{PedidoConfirmacion,Cuenta,Carrito,Checkout}Test` | El pedido, la idempotencia, sin parciales, el cobro con el stock justo, el vencimiento, la invariante con dos dueños, el canal en los dos niveles | Nuevo y modificado |

> **La confirmación suelta antes de lanzar.** Vencido o con cambios, el carrito vuelve a `activo` y
> recién después sale el 409: adentro de la transacción, el rollback desharía la liberación. Con cambios
> se suelta además porque, si no, el próximo checkout vería la reserva vigente y no revalidaría.
>
> **Sin registros parciales se prueba con una falla inyectada** en el segundo `INSERT` de renglones
> (`DB::beforeExecuting`): no queda venta, ni renglones, ni carrito convertido, y las dos reservas siguen.
>
> **El ranking de vendedores no cuenta la venta online, y el panel general sí**: el ranking se arma con
> un `join` a `users` sobre `ventas.usuario_id`, que en el pedido es nulo. Se fija con un test.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Direccion`, `Pedido`, `LineaPedido`, `ImportesPedido`; `Carrito.id` y `Perfil.direccion` | Los modelos de §7.4 y lo nuevo de §7.2 y §7.3 contra su JSON; sin `tienda.ver_precios`, sin importes | Nuevo y modificado |
| `PedidoService` y `PedidoRepository` | `POST /pedidos` sin importes, la dirección sólo con envío; 201 o 200; la misma confirmación no sale dos veces mientras corre | Nuevo |
| `traducirConCambios` | La lectura de los `cambios` del 409, compartida por los dos repositorios sin que se conozcan (regla 25) | Nuevo (salió de `CarritoRepository`) |
| `CarritoRepository.resumen` | El resumen del checkout mientras el carrito siga reservado: la confirmación no reserva otra vez | Modificado |
| `CheckoutViewModel` y `CheckoutVista` | Pasos 2 a 5: revisión con la reserva, tus datos, entrega (dirección propuesta, editable sólo para el pedido) y pago con el total de cada medio; «Confirmar» una vez, el reintento con el mismo `carrito_id`; cada 409 vuelve al carrito | Nuevo |
| `PedidoRegistradoVista` y `PagoProvisorioVista` | Contado: «Pedido V-… registrado. Lo pagás en el local antes del…», sin decir que se pagó (P-4). Pasarela: el lugar del pago hasta el G | Nuevo |
| `CarritoViewModel` y `CarritoVista` | «Realizar pedido» reserva y pasa a la confirmación; el motivo de un 409 se muestra al volver | Modificado |
| Router y `Rutas` | `/checkout` y sus hermanas `/checkout/pedido/:id` y `/checkout/pago/:id`, con sesión y sin barra inferior | Modificado |
| `OpcionElegible`, `sumar`, `fechaHoraLocal` | La opción de un grupo excluyente (no sólo color); la suma en centavos; la fecha y la hora locales | Nuevo |

> **El motivo del 409 viaja como `extra` de la ruta del carrito**, no como estado compartido: el carrito lo
> muestra una vez y se recarga, porque el servidor ya soltó la reserva.
>
> **El cierre y el pago son hermanas de `/checkout`, no hijas**: se llega con `go` y «atrás» va al inicio, así no
> se vuelve a una confirmación ya hecha.

### Tramo K — Cuenta editable

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `PerfilResource` | Suma `tipo_doc` + `_texto`, `nro_doc` (los tres en `null` sin número, HL-65) y `condicion_iva` + `_texto`, de sólo lectura (D-22) | Modificado |
| `ActualizarPerfilRequest` | `PATCH /perfil` parcial: nombre y apellido (juntos 150, contra lo guardado si viene uno), teléfono, documento juntos o ninguno, dirección anidada; lo fiscal, el correo y los importes, `prohibited`; quitar el documento con RI o Monotributo, 422 (HL-67) | Nuevo |
| `App\Rules\DocumentoTienda` | DNI de 7 u 8; CUIT y CUIL de 11 con dígito verificador. Sólo en la API: la ficha del cajero no cambia (HL-66) | Nuevo |
| `PerfilService::actualizar()` | Una transacción con la ficha bloqueada: `users`, `clientes` (la razón social sigue al nombre sólo si cambió) y la predeterminada por `DireccionService` (HL-68); el choque del documento al guardar, 422 | Nuevo |
| `PerfilController::update` y la ruta | `PATCH /perfil`, protegida como el `GET`, sin permiso aparte; devuelve el perfil con `mensaje` | Nuevo |
| `ReglasFiscales::EXIGEN_DOCUMENTO`, `ConfirmarPedidoRequest::CAMPOS_DIRECCION` | Pasan a públicas para reusarlas, sin cambiar lo que hacen | Modificado |
| `docs/api.md` | Revisión 6: `PATCH /perfil` y los datos fiscales del `GET` | Modificado |
| `ActualizarPerfilRequest` y `ConfirmarPedidoRequest` (`direccion.provincia`) | `Rule::in(Direccion::PROVINCIAS)`, como la web: una de las 24, 422 «Elegí una provincia de la lista.» (HL-69). Filas sumadas en el tramo G | Modificado |
| `docs/api.md` (revisión 7) | La provincia de lista cerrada en §5 y en los dos endpoints que mandan dirección | Modificado |
| `tests/Feature/Api/PerfilEdicionTest`, `Api/CuentaTest` | Lectura, validación en cada campo, guardado, la razón social, lo que no vino no se toca, el pedido ya hecho no cambia, el cajero ve lo mismo, 401 y la cuenta de gestión | Nuevo y modificado |

> **El `PATCH` es parcial a propósito**: el paso 4 de la compra guarda sólo la dirección, y un CUIT que el
> cajero cargó sin verificador no le impide al cliente cambiar su teléfono.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `Perfil` (documento y condición frente al IVA) y `CambiosPerfil` | Lo nuevo de §7.2 contra su JSON (sin número, los tres del documento en `null`); el cuerpo del `PATCH` sólo con lo que cambió, `Nuevo(null)` para quitar | Nuevo y modificado |
| `AuthService.actualizarPerfil` y `SessionRepository.actualizarPerfil` | `PATCH /perfil` con el perfil guardado y el `mensaje`; sin red falla (regla 27) | Modificado |
| `Validar` (`documento`, `datosPersonales`, `direccion`) y `Cambios` | Valida como el servidor: DNI 7 u 8, CUIT y CUIL con dígito verificador, juntos 150, largos de `direcciones` y la provincia de la lista (HL-69); qué cambió respecto de lo guardado | Nuevo y modificado |
| `FormularioDatos` y `FormularioDireccion` | El mismo formulario en Mi cuenta y en la compra; el correo, la razón social y la condición se ven y no se editan (D-22) | Nuevo |
| `SelectorOpciones`, `SelectorLista` y `Casilla` | DNI, CUIT o CUIL sin depender del color; la provincia de las 24 de `api.md` §5, sin texto libre; «Guardarla en mi cuenta» | Nuevo |
| `MiCuentaViewModel` y `MiCuentaVista` | Mi cuenta editable: datos y dirección predeterminada (cargarla, cambiarla o quitarla); «Guardar» una vez, cada 422 junto a su campo | Modificado |
| `CheckoutViewModel` y `CheckoutVista` | Paso 3 editable que guarda si cambió y no toca el carrito; en el paso 4, «Guardarla en mi cuenta» manda sólo la dirección | Modificado |

> **Mi cuenta también edita y quita la dirección predeterminada**: se sumó al hacer el lado de app (el contrato
> ya lo admitía, `direccion` objeto o `null`). Sin ella, la dirección sólo se podía cargar desde una compra y nunca
> quitar.
>
> **Una provincia guardada antes de la revisión 7 que no está en la lista llega sin elegir** (HL-69): la app no la
> da por buena ni la manda tal cual.

### Tramo G — Pagos

**Servidor**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| Migraciones `create_intentos_pago_table`, `add_intento_pago_id_to_pagos_table`, `add_simulador_to_pagos_metodo` | Los intentos aparte de `pagos`; `pagos.intento_pago_id` FK UNIQUE (HL-2); `simulador` al final del ENUM (D-13) | Nuevo |
| Migración `fix_timestamps_sin_on_update` | `intentos_pago.vence_at` y `pagos.fecha` sin el `ON UPDATE CURRENT_TIMESTAMP` implícito de MariaDB (HL-73); los valores guardados no cambian | Nuevo |
| `IntentoPago` | Estados y pasarelas; no se borra; `aprobadoSinAcreditar()`, `acreditable()` e `IMPORTE_DISTINTO` (HL-72) | Nuevo |
| `Pago` | `simulador` en `METODOS`; `METODOS_EN_USO` sin cambios (§1.3 G); `intentoPago()`; el motivo de por qué el mostrador no ofrece `mercadopago` | Modificado |
| `App\Pagos\{Pasarela, Inicio, Consulta, PasarelaNoDisponible, Pasarelas}` | El contrato; `Consulta` distingue «contestó pendiente» de «no contestó» (`respondida`); `Pasarelas` resuelve por el contenedor y dice cuáles están habilitadas (Mercado Pago, sólo con credencial) | Nuevo |
| `PasarelaSimulador` | Sin URL; la consulta devuelve lo guardado | Nuevo |
| `PasarelaMercadoPago` | Checkout Pro con `Http`, sin SDK: preferencia con `expires` y vencimiento (HL-71), `X-Idempotency-Key`; búsqueda por `external_reference` con la regla de varios pagos y el importe (HL-72); sin red, pendiente | Nuevo |
| `PagoService::acreditarIntento()` | El pago de la pasarela sin cajero, idempotente por `intento_pago_id`; comparte con `cobrar()` `exigirQueSePuedaCobrar()` y `escribirPago()`; `cobrar()` no cambia (HL-3) | Nuevo y modificado |
| `IntentoPagoService` | `iniciar` (uno pendiente por pedido, estira la reserva), `verificar` (HTTP afuera, aplicar adentro, aprobado en su transacción), `resolverSimulado`, `verificarVencidos`, `reintentarAcreditaciones`, `pedidoDe` e `intentoDe` (ajeno: 404) | Nuevo |
| `Venta` | `intentosPago()`, `ultimoIntentoPago()`, `delCliente()` (HL-70), `situacionDelPago()` y `estadoDelPago()` (regla 9) | Modificado |
| `VentaService::cancelarVencidos()` y `tienda:vencer-reservas` | No cancela con un intento pendiente ni con un aprobado sin acreditar (HL-63, §1.3 E); el comando resuelve lo que está en el aire antes de cancelar | Modificado |
| `PagoPedidoController`, `SimuladorController`, `IniciarPagoRequest`, `IntentoPagoResource` y las rutas | Los cinco endpoints de §7.5; el simulador, sólo con su llave; el intento lleva el estado del pago del pedido; sin `tienda.ver_precios`, sin `importe` | Nuevo |
| `TextosTienda` | `SITUACION_PAGO` e `INTENTO`, con sus textos | Modificado |
| `config/tienda.php`, `config/services.php`, `.env.example`, `phpunit.xml` | `intento_minutos` 20 (Q-1); el token de Mercado Pago sólo desde `.env`; los tests no leen el token real | Modificado |
| `docs/api.md` | Revisión 8: §7.5 pagos, la tabla del estado del pago y la política ante pago fallido (D-9) | Modificado |
| `tests/Feature/{IntentoPagoEsquema,Pasarelas,PasarelaMercadoPago,AcreditarIntento,IntentoPagoService}Test`, `Api/PagoTest`, `VentaOnlineServiceTest` | El esquema, la traducción de cada estado del proveedor, la acreditación una sola vez, el rechazo sin pago y con la reserva, el reintento, lo que queda en el aire, el vencimiento, lo ajeno 404 | Nuevo y modificado |

> **Ninguna llamada HTTP con la venta bloqueada.** Se consulta afuera; adentro se bloquea la venta, se relee el
> intento y se aplica sólo si sigue pendiente. El aprobado se guarda en su transacción y se acredita en otra: si
> la acreditación falla, la aprobación queda y el comando la reintenta.
>
> **Un intento sólo vence si el proveedor contestó.** Sin respuesta, la plata pudo haber entrado: el intento
> sigue pendiente y el pedido no se cancela (regla 16).
>
> **La prueba contra el sandbox real** (antes del paso 4): una preferencia creada con la credencial de prueba
> volvió con la misma `external_reference`, `expires: true` y el vencimiento exacto del intento.

**App**

| Componente | Qué resuelve | Estado |
| --- | --- | --- |
| `IntentoPago` y `PedidoDelIntento` | La forma del intento de §7.5 contra su JSON, con el estado del pago del pedido; sin `tienda.ver_precios`, sin `importe` | Nuevo |
| `PagoService` | `POST /pedidos/{id}/pagos` (siempre `mercadopago`, D-23; 201 nuevo, 200 el pendiente que ya había), `GET …/pagos` y `GET …/pagos/{intento}`. No usa `/simulador/…` | Nuevo |
| `PagoRepository` | `traducirFallas`; la misma operación no sale dos veces mientras corre; `ultimo()` para retomar; `abrirPago()` abre `url_pago` con `NavegadorExterno` (D-19, regla 24) | Nuevo |
| `PagoViewModel` (`EtapaPago`, `PedidoAPagar`) | Pagar, esperar, aprobado, en revisión, rechazado («Reintentar», otro intento sobre el mismo pedido) y sin cobrar, derivados del intento y de `estado_pago`; `vencido` y `error` no son un rechazo (regla 16); al volver al frente consulta (6.9) | Nuevo |
| `PagoVista` | El total y una sola acción, «Pagar con Mercado Pago»; la espera con «Volver a consultar» y «Abrir Mercado Pago»; escucha `AppLifecycleListener.onResume`; los seis estados | Nuevo |
| `ImporteDestacado`, `Aviso` (tipo `info`), `Iconos` | El total grande con su etiqueta; un aviso que no es error ni éxito; los íconos de abrir afuera y de consultar | Nuevo y modificado |
| `router.dart`, `PedidoRegistradoVista` | `/checkout/pago/:id` abre `PagoVista` (sin la confirmación, retoma el último intento); se quitan `PagoProvisorioVista` y `PantallaProvisoria` | Modificado |
| `test/data/{models/intento_pago,services/pago_service,repositories/pago_repository}_test`, `test/ui/pago/*`, `PagoServiceFalso`, `NavegadorExternoFalso` | Serialización contra `api.md`; doble envío; volver de Mercado Pago consulta; el reintento crea otro intento; los seis estados; la ruta reabierta | Nuevo |

> **La app no ofrece el simulador** (D-23): con «simulador» se quería decir las cuentas de prueba de Mercado Pago.
> El backend lo sigue teniendo hasta que se quite; la app manda siempre `"pasarela": "mercadopago"`.
