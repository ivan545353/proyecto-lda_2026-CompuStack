# Tabla de trazabilidad — Sistema original → Laravel

Documento de seguimiento de la Etapa 1. Se actualiza en el mismo commit que migra cada componente.

**Estados:** Pendiente · En proceso · Migrado · Descartado · Sin equivalente

---

## 1. Infraestructura y arranque

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `public/index.php` | Punto de entrada, arma el Pipeline | `public/index.php` + `bootstrap/app.php` | Pendiente |
| `public/.htaccess` | Reescritura `controller/action/id` | `routes/web.php` | Pendiente |
| `app/App.php` | Registra middlewares y ejecuta el Pipeline | `bootstrap/app.php` (`withMiddleware`) | Pendiente |
| `app/config/AppConfig.php` | Constantes globales y `JWT_SECRET` | `.env` + `config/app.php` | Pendiente |
| `app/config/DBConfig.php` | Credenciales de base | `.env` + `config/database.php` | Pendiente |
| `app/libs/database/Connection.php` | Singleton PDO | Capa de base de Laravel (nativa) | Pendiente |
| `app/libs/pipeline/Pipeline.php` | Encadenado de middlewares | Middleware stack de Laravel (nativo) | Pendiente |
| `app/libs/pipeline/middlewares/base/*` | Contrato de middleware | Contrato de Laravel (nativo) | Pendiente |
| `app/libs/http/Request.php` | Parseo de query, body y headers | `Illuminate\Http\Request` (nativo) | Pendiente |
| `app/libs/http/Response.php` | Envelope JSON de respuesta | Vistas Blade + `redirect()` | Pendiente |

## 2. Middlewares

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `RouterHandlerMiddleware` | Resuelve controlador y acción por convención | `routes/web.php` con rutas explícitas y verbos HTTP | Pendiente |
| `AuthenticationHandlerMiddleware` | Valida el JWT del header | Middleware `auth` con guard de sesión | Pendiente |
| `AuthorizationHandlerMiddleware` | Consulta `permisos` por módulo y acción | Policies + Gates contra permisos nombrados | Pendiente |
| `ExceptionHandlerMiddleware` | Captura excepciones y arma el JSON de error | `bootstrap/app.php` (`withExceptions`) | Pendiente |
| `CorsHandlerMiddleware` | Cabeceras CORS para Angular | Descartado: con Blade el origen es el mismo | Descartado |

> `AuthorizationHandlerMiddleware` es el origen del hallazgo crítico C-2 de la auditoría (fallback a `can_update` para toda acción no mapeada). Su reemplazo debe ser deny-by-default.

## 3. Excepciones

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `HttpException` | Base con código HTTP | `Symfony\...\HttpException` (nativo) | Pendiente |
| `AuthenticationException` | 401 | `Illuminate\Auth\AuthenticationException` | Pendiente |
| `AuthorizationException` | 403 | `Illuminate\Auth\Access\AuthorizationException` | Pendiente |
| `NotFoundException` | 404 | `ModelNotFoundException` (nativo, vía `findOrFail`) | Pendiente |
| `ValidationException` | 400 | `Illuminate\Validation\ValidationException` | Pendiente |

## 4. Controladores

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `base/BaseController.php` | Contrato común de controlador | `App\Http\Controllers\Controller` | Pendiente |
| `base/InterfaceController.php` | Interfaz CRUD | Convención de resource controller | Pendiente |
| `AuthenticationController.php` | Login, logout, `getCurrent` | `AuthController` con sesión | Pendiente |
| `CategoryController.php` | CRUD de categorías | `CategoriaController` (resource) | Pendiente |
| `ItemController.php` | CRUD de productos | `ProductoController` (resource) | Pendiente |
| `UserController.php` | CRUD de usuarios, cambio de clave, perfiles | `UsuarioController` + `PerfilController` | Pendiente |
| `SaleController.php` | Ventas, cobros, cambio de estado | `VentaController` + `PagoController` | Pendiente |

> `UserController::update` acepta `perfil_id` del body sin verificar identidad (hallazgo C-3, escalada de privilegios). El cambio de rol debe quedar en una acción separada, restringida y con prohibición de autoasignación.

## 5. Servicios

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `base/InterfaceService.php` | Contrato de servicio | Sin equivalente directo | Pendiente |
| `AuthenticationService.php` | Verifica credenciales y emite JWT | `AuthController` + guard de sesión | Pendiente |
| `CategoryService.php` | Validaciones de categoría | `CategoriaService` (adelgazado) | Pendiente |
| `ItemService.php` | Validaciones de producto | `ProductoService` (adelgazado) | Pendiente |
| `UserService.php` | Validaciones de usuario, hash de clave | `UsuarioService` (adelgazado) | Pendiente |
| `SaleService.php` | Totales, descuentos, cobros, estados | `VentaService` + `PagoService` + máquina de estados | Pendiente |

> Las validaciones de campo pasan a Form Requests. Los servicios conservan sólo reglas de negocio, para que la Etapa 3 los reutilice desde la API sin cambios.
>
> `SaleService` es el de mayor reescritura: hoy valida el monto del pago fuera de la transacción (hallazgo C-10) y permite transiciones de estado arbitrarias (C-9).

## 6. Acceso a datos

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `base/BaseDao.php` | Conexión, transacciones, `lastInsertId` | `Illuminate\Database\Eloquent\Model` | Pendiente |
| `base/InterfaceDao.php` | Contrato CRUD | Convención de Eloquent | Pendiente |
| `CategoryDao.php` | SQL de categorías | Modelo `Categoria` | Pendiente |
| `ItemDao.php` | SQL de productos | Modelo `Producto` + scopes de filtro | Pendiente |
| `UserDao.php` | SQL de usuarios, login | Modelo `User` (con `$hidden`) | Pendiente |
| `SaleDao.php` | SQL de ventas, stock, pagos, numeración | Modelos `Venta`, `VentaLinea`, `Pago`, `MovimientoStock` | Pendiente |

> `UserDao::list` hace `SELECT u.*`, lo que expone el hash de contraseña de todos los usuarios (hallazgo C-1). En Eloquent se cierra con `$hidden = ['password']`.
>
> Los filtros de `ItemDao`, `CategoryDao` y `UserDao` no coinciden con lo que envían sus controladores y nunca funcionaron (hallazgo A-24). Al migrarlos hay que definir el juego de filtros real y cubrirlo con tests.

## 7. DTOs

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `base/InterfaceDto.php` | Contrato de DTO | Sin equivalente directo | Pendiente |
| `LoginDto.php` | Credenciales de acceso | `LoginRequest` | Pendiente |
| `CategoryDto.php` | Validación y transporte | `CategoriaRequest` + modelo | Pendiente |
| `ItemDto.php` | Validación y transporte | `ProductoRequest` + modelo | Pendiente |
| `UserDto.php` | Validación y transporte | `UsuarioRequest` + modelo | Pendiente |
| `SaleDto.php` | Validación y transporte | `VentaRequest` + modelo | Pendiente |

> Los DTOs actuales cumplen doble función de entrada y salida, que es la causa de fondo de C-1. Se separan: Form Request para entrada, modelo con `$hidden` para salida.
>
> Los setters vacían el valor cuando no valida en vez de rechazarlo (hallazgo M-31). Las reglas de Form Request rechazan.

## 8. Frontend

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `features/auth/` | Pantalla de login | `resources/views/auth/` | Pendiente |
| `features/layout/` | Barra de navegación y estructura | `resources/views/layouts/app.blade.php` | Pendiente |
| `features/home/` | Panel con contadores | `resources/views/dashboard/` con datos agregados en servidor | Pendiente |
| `features/category/` | Listado y formulario de categorías | `resources/views/categorias/` | Pendiente |
| `features/item/` | Listado y formulario de productos | `resources/views/productos/` | Pendiente |
| `features/user/` | Listado y formulario de usuarios | `resources/views/usuarios/` | Pendiente |
| `features/sale/` | Ventas, detalle, cobro | `resources/views/ventas/` | Pendiente |
| `features/account/` | Cambio de clave propia | `resources/views/cuenta/` | Pendiente |
| `core/pdf/pdf.service.ts` | Genera PDF con jsPDF en el navegador | `barryvdh/laravel-dompdf` + vistas Blade | Pendiente |
| `core/auth/auth.guard.ts` | Protege rutas si hay token | Middleware `auth` | Pendiente |
| `core/auth/token.interceptor.ts` | Inyecta el header Authorization | Descartado: sesión en cookie | Descartado |
| `core/api/api.constants.ts` | URL base de la API | Descartado | Descartado |
| `core/*/​*.service.ts` | Clientes HTTP por módulo | Descartado: los controladores devuelven vistas | Descartado |

> `HomeComponent` descarga las tablas completas y cuenta en el navegador (hallazgo A-26). El panel Blade recibe los números ya agregados.

## 9. Pruebas

| Componente original | Función | Componente Laravel | Estado |
|---|---|---|---|
| `tests/dao/*`, `tests/dto/*`, `tests/service/*` | Scripts con `echo`, referencian clases renombradas | `tests/Feature` + `tests/Unit` (PHPUnit/Pest) | Pendiente |
| `tests/database/databaseTest.php` | Prueba de conexión manual | Cubierto por el entorno de pruebas | Descartado |
| `tests/password.php` | Generador de hash suelto | `php artisan tinker` | Descartado |

> Los tests actuales no compilan: referencian `CategoriaDao`, `ProductoDto` y `PerfilUsuario`, renombradas a `CategoryDao`, `ItemDto` y sin equivalente. Se reescriben de cero.

## 10. Sin equivalente en el sistema original

Módulos nuevos de la Etapa 1. No hay componente que migrar; se construyen desde cero.

| Módulo | Componentes Laravel | Estado |
|---|---|---|
| Clientes | Migración, modelo `Cliente`, `Direccion`, controlador, Form Requests, vistas | Pendiente |
| Empleados | Migración, modelo `Empleado`, integrado al módulo de usuarios | Pendiente |
| Proveedores | Migración, modelo `Proveedor`, controlador, vistas | Pendiente |
| Órdenes de compra | Migraciones, modelos `OrdenCompra` y `OrdenCompraLinea`, servicio de reposición | Pendiente |
| Roles y permisos | Migraciones `roles`, `permisos`, `rol_permiso`, controlador, vistas, Gates | Pendiente |
| Marcas | Migración, modelo `Marca`, controlador, vistas | Pendiente |
| Movimientos de stock | Migración, modelo `MovimientoStock`, servicio de kardex | Pendiente |
| Categorías jerárquicas | Ampliación de `Categoria` con `parent_id` | Pendiente |

## 11. Descartado del modelo original

| Tabla original | Motivo |
|---|---|
| `venta_numeracion` | Una fila sin clave primaria. La numeración fiscal la gobierna AFIP (Etapa 2/3) |
| `modulos` | Los permisos pasan a ser acciones nombradas, no módulos |
| `perfiles` | Reemplazada por `roles` con permisos granulares |
| `permisos` (4 flags CRUD) | Reemplazada por permisos nombrados con pivote a roles |
