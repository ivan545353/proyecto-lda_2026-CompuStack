# Auditoría técnica — Sistema de gestión de tienda informática

Alcance: backend PHP (MVC + Pipeline + DAO/DTO), frontend Angular 21, dump SQL `lp_2025`.
Objetivo: identificar qué **no** repetir en la migración a Laravel + Flutter.

Severidad: **C** crítico · **A** alto · **M** medio · **B** bajo

---

## 1. Seguridad

### C-1 · Se filtran los hashes de contraseña de todos los usuarios
`UserDto::toArray()` incluye la clave `"clave"`. `UserController::getCurrent()` hace `unset($data["clave"])`, pero `load()` **no**. Peor: `UserDao::list()` hace `SELECT SQL_CALC_FOUND_ROWS u.*` y el service devuelve las filas crudas.

Resultado: `GET /user/list` devuelve el hash bcrypt de **todos** los usuarios a cualquiera con `can_read` sobre el módulo `user`. `GET /user/load/{id}` hace lo mismo por usuario.

El bug de fondo no es el `unset` faltante, es que **el DTO de escritura y el de lectura son el mismo objeto**. En Laravel esto se corrige separando `FormRequest` (entrada) de `JsonResource` (salida) y marcando `password` en `$hidden`.

### C-2 · Fallback permisivo en el middleware de autorización
```php
$columna = self::MAPA_PERMISOS[$action] ?? "can_update";
```
Toda acción fuera del CRUD (`cobrar`, `updateEstado`, `enable`, `disable`, `reset`, `perfiles`) cae en `can_update`. Con los permisos del dump, el perfil **Vendedor** tiene `can_update = 1` sobre el módulo `sale`, así que puede ejecutar `cobrar` y `updateEstado` — incluido anular ventas ya cobradas, pese a que su `can_delete` es 0.

Además el módulo se deduce del nombre del controlador (`$controller` → `modulos.nombre`), lo que acopla el routing a datos de la tabla `modulos`: renombrar un controlador rompe silenciosamente los permisos, sin error.

### C-3 · Escalada de privilegios vía `user/update`
`UserController::update()` construye el `UserDto` directamente desde el body, que incluye `perfil_id`, y no compara el `id` del body contra el `usuarioID` del token. Un usuario con `can_update` sobre `user` puede reasignarse a sí mismo (o a cualquiera) el perfil Administrador. No hay separación entre "editar mi cuenta" y "editar a otro".

### A-4 · Secretos versionados
- `JWT_SECRET = '2896bd45d7c219ccec38199c54734628'` en `AppConfig.php`, commiteado.
- Credenciales de base hardcodeadas en `Connection.php`: usuario `root`, password vacío.
- No hay `.gitignore` en el repo del backend → `app/vendor/` completo versionado.

Rotar el secreto hoy implica invalidar todas las sesiones y editar código. En Laravel esto va a `.env` + `config/`.

### A-5 · El token no se puede revocar
`AuthenticationService::logout()` es un no-op con comentario explicando que el logout es del lado del cliente. No hay `jti`, ni blacklist, ni refresh token. Consecuencias:
- Deshabilitar un usuario (`disable`) **no lo desloguea**: su token sigue siendo válido hasta 1 hora.
- Cambiar el perfil de un usuario no surte efecto hasta que expire el token, porque el perfil viaja *dentro* del JWT y `AuthorizationHandlerMiddleware` lo lee de ahí.
- No se revalida `estado` ni `resetPass` en cada request, sólo en el login.

Con clientes y pagos reales esto pasa de molestia a riesgo.

### A-6 · Sin límite de intentos en el login
`POST /authentication/login` no tiene rate limiting, captcha ni bloqueo por intentos. Fuerza bruta libre contra bcrypt.

### M-7 · CORS con origen hardcodeado
`Access-Control-Allow-Origin: http://localhost:4200` fijo en el código. No configurable por entorno, y con Flutter (web/móvil) el origen cambia.

### M-8 · Token en `localStorage`
`AuthService` guarda el JWT en `localStorage`, accesible desde cualquier script. En Flutter esto se resuelve con `flutter_secure_storage` (Keychain/Keystore), que es un cambio de plataforma a favor.

---

## 2. Lógica de negocio y concurrencia

### C-9 · No existe máquina de estados en ventas
`SaleDao::updateEstado()` acepta **cualquier** transición. La única lógica es:
```php
if ($actual === "presupuesto" && $nuevoEstado === "confirmada")  → descontar stock
elseif (in_array($actual, ["confirmada","cobrada"]) && $nuevo === "anulada") → reponer
// cualquier otro caso: sólo UPDATE estado
```
Agujeros concretos:

| Transición | Qué pasa | Qué debería pasar |
|---|---|---|
| `presupuesto → cobrada` | Se marca cobrada **sin descontar stock** y sin pago registrado | Rechazar |
| `anulada → confirmada` | Revive la venta sin volver a descontar stock | Rechazar |
| `cobrada → confirmada` | Deja pagos registrados sobre una venta no cobrada | Rechazar |
| `confirmada → confirmada` | Descuenta stock de nuevo | Rechazar (idempotencia) |

`SaleService::updateEstado()` sólo valida que el estado destino esté en la lista de válidos; nunca valida el origen. Este es el defecto más grave del módulo que hoy "funciona perfectamente".

### C-10 · Sobrepago posible (TOCTOU)
La validación del monto está en el service, **fuera** de la transacción:
```php
// SaleService::cobrar()
if ($monto > (float)$venta["saldo"] + 0.001) throw ...
$dao->registrarPago(...);   // recién acá abre transacción y hace FOR UPDATE
```
Dos requests concurrentes leen el mismo saldo, ambos pasan la validación, ambos insertan. Con Mercado Pago reintentando webhooks esto deja de ser teórico. Falta además **clave de idempotencia** en `pagos`: no hay forma de distinguir un reintento de un pago nuevo.

### A-11 · Anular no revierte los pagos
`confirmada/cobrada → anulada` repone stock pero deja las filas de `pagos` intactas. No hay nota de crédito, ni devolución, ni marca de reversión. Contablemente queda plata cobrada sobre una venta inexistente.

### A-12 · Descuento sin control por rol
`descuentoPorcentaje` lo fija el cliente y se valida sólo `0 ≤ pct ≤ 100`. Cualquier vendedor puede cargar 100% de descuento. En el dump ya hay una venta al 50% (`id 19`). No hay tope por perfil ni flujo de autorización.

### A-13 · Stock sin libro de movimientos
`productos.stock` es una columna mutable que se pisa con `UPDATE productos SET stock = stock - :cant`. No hay kardex. No se puede: auditar quién movió qué, reconstruir el stock a una fecha, distinguir una venta de un ajuste o de una recepción de mercadería, ni implementar reservas.

Esto es bloqueante para los requerimientos 5 (carrito), 9 (venta online) y 11 (compra automática): sin reserva de stock, dos clientes compran la última unidad.

### M-14 · Precio recalculado al editar un presupuesto
`resolverPreciosYTotales()` relee el precio desde `productos` en cada `save` **y** en cada `update`. Un presupuesto emitido cambia de total si el precio del producto cambia antes de confirmarlo. `detalle_ventas.precio_unit` guarda el precio congelado, pero `SaleDao::update()` borra todas las líneas y las reinserta con el precio nuevo.

Nota positiva: **releer el precio del servidor y nunca confiar en el que manda el cliente es correcto** y hay que conservarlo. Lo que falta es distinguir "cotizar" de "recotizar".

### M-15 · `moverStock` en modo reponer no bloquea la fila
El modo `descontar` hace `SELECT ... FOR UPDATE`; el modo `reponer` va directo al `UPDATE`. Inconsistente.

### M-16 · Borrado físico de documentos financieros
`SaleDao::delete()` es un `DELETE` plano y `detalle_ventas` tiene `ON DELETE CASCADE`. Borrar una venta borra su historial completo. Lo mismo en productos y usuarios. Con facturación real esto es inadmisible: una vez emitido un comprobante, nada se borra.

---

## 3. Modelo de datos

### A-17 · Dinero en punto flotante
`productos.precio` es `float(12,2)`. El resto del esquema usa `decimal(12,2)` correctamente. `float` acumula error de redondeo: un producto a 435345.00 se lee como `435344.99999...` según el caso. Todo importe debe ser `decimal` (o entero en centavos).

### A-18 · Numeración de ventas frágil
- `ventas.numero` **no tiene índice UNIQUE**. Nada impide duplicados.
- `venta_numeracion` es una tabla de una sola fila **sin clave primaria**. `SELECT numero FROM venta_numeracion FOR UPDATE` sin `WHERE` funciona por accidente.
- Un solo contador global. Con facturación AFIP vas a necesitar numeración **por punto de venta y por tipo de comprobante**, correlativa y sin huecos.

### M-19 · Sin trazabilidad temporal
Ninguna tabla tiene `created_at` / `updated_at` / `created_by`. `usuarios.fechaAlta` es lo único, y es `date` (sin hora). Para el dashboard del requerimiento 14 y para cualquier auditoría, esto hace falta en todas las tablas.

### M-20 · El modelo de permisos no llega
`permisos` son 4 flags CRUD por (perfil, módulo). No modela acciones que no son CRUD — cobrar, anular, autorizar descuento, aprobar orden de compra, emitir nota de crédito. El fallback a `can_update` (C-2) es el síntoma de esa limitación, no la causa.

Con el nuevo esquema de perfiles (cliente / administrador / empleado con subtipos vendedor-cajero-administrativo + proveedor), un modelo de flags por módulo se vuelve inmanejable. Corresponde pasar a permisos nombrados (`sale.void`, `purchase_order.approve`, `discount.override`).

### M-21 · `resetPass` bloquea sin salida
`AuthenticationService` rechaza el login si `resetPass != 0` con "Su clave ha caducado", pero no hay endpoint público para restablecerla. El usuario queda bloqueado hasta que un admin lo destrabe. Falta el flujo de recuperación por email — que con clientes reales pasa a ser obligatorio.

### M-22 · Faltan campos que los nuevos requerimientos exigen
El catálogo actual (`nombre, codigo, descripcion, categoriaId, precio, stock`) no soporta:
- imágenes (requerimiento 5, 9 — una tienda sin fotos no vende),
- peso y dimensiones (requerimiento 18 — Zipnova los necesita para cotizar),
- costo de compra (requerimiento 14 — sin costo no hay margen),
- alícuota de IVA (requerimiento 8 — sin alícuota no hay factura),
- stock mínimo / punto de reposición (requerimiento 11),
- marca, atributos técnicos, variantes.

`categorias` es una lista plana (`id`, `nombre`), sin jerarquía ni orden ni imagen.

### B-23 · Datos basura en el dump
`productos` contiene `asdasdas` y `afsadgasdgsdfg`; `ventas` tiene clientes `dasdasdasdasd` y `kjkhejkrg`. Sin soft-delete ni validación de contenido, la base de pruebas y la de producción son la misma. La `unique key` sobre `(nombre, categoriaId)` además impide dos productos homónimos de marcas distintas en la misma categoría.

---

## 4. Arquitectura y calidad

### A-24 · Filtros que no filtran
Tres desajustes silenciosos entre controller y DAO:

| Controller manda | DAO espera | Efecto |
|---|---|---|
| `ItemController::list` → `categoriaId`, `limit` | `codigo`, `nombre`, `categoria`, `stock`, `limit`+`offset` | Ningún filtro se aplica |
| `CategoryController::list` → `estado`, `limit` | `nombre`, `limit`+`offset` | `estado` no existe ni como columna |
| `UserController::list` → `nombres`, `limit` | `perfil_id`, `estado`, `limit` | El filtro por nombre se ignora |

Además `ItemDao::list` y `CategoryDao::list` sólo aplican `LIMIT` si vienen `limit` **y** `offset`, y ningún controller manda `offset` → el límite nunca se aplica.

Que estos bugs convivan con la versión "final" es el indicador más claro de la falta de tests.

### A-25 · Sin paginación
Todos los listados devuelven la tabla completa. Se emite `SQL_CALC_FOUND_ROWS` pero `foundRows()` **nunca se llama** desde ningún service — y es una cláusula deprecada desde MySQL 8.0.17. Con catálogo real y tienda pública esto no sobrevive.

### A-26 · El dashboard se calcula en el navegador
`HomeComponent` hace `forkJoin` de categorías + productos + ventas + usuarios **completos** y luego cuenta con `.length` y `.filter` en el cliente:
```ts
this.sinStock.set(res.productos.filter(p => p.stock === 0).length);
this.ventasHoy.set(res.ventas.filter(v => v.fecha.slice(0,10) === hoy && v.estado !== 'anulada').length);
```
Descarga toda la base para mostrar cinco números, y de paso expone datos que el usuario no debería ver. El requerimiento 14 (dashboard con métricas por rol) es exactamente donde este patrón hay que reemplazarlo por endpoints de agregación en el servidor.

### M-27 · Routing por convención, sin verbos HTTP
El `.htaccess` mapea `^([a-zA-Z]+)/([a-zA-Z]+)/([a-zA-Z0-9]+)$` a `controller/action/id`, y `RouterHandlerMiddleware` hace `ucfirst($controller) . "Controller"` + `method_exists`. El método HTTP nunca se valida: `save` responde a GET igual que a POST. Tres segmentos máximo, ids sólo alfanuméricos, sin rutas anidadas. El router de Laravel resuelve esto de fábrica.

### M-28 · Sin inyección de dependencias
Cada método instancia lo suyo: `new SaleService()` en el controller, `new SaleDao(Connection::get())` dentro de cada método del service. `SaleService::resolverPreciosYTotales()` crea su propio `ItemDao`. No hay forma de sustituir dependencias → los services son intesteables sin base de datos real.

### M-29 · Contrato de DAO inconsistente
`ItemDao::load()` y `CategoryDao::load()` devuelven `(new Dto($data))->toArray()`; `UserDao::load()` y `SaleDao::load()` devuelven el array crudo de PDO. El DAO a veces conoce el DTO y a veces no. Además `SaleDao` guarda `$lastVentaId` propio porque `BaseDao::getLastInsertId()` es global a la conexión y no sirve tras insertar los detalles.

### M-30 · Excepciones sin tipar
Existen `NotFoundException`, `ValidationException`, `AuthorizationException` — pero `ItemDao`, `CategoryDao`, `UserDao`, `SaleDao` y `ItemService` lanzan `\Exception` plana. `ExceptionHandlerMiddleware` la mapea a **400**, así que "producto no encontrado" responde 400 en vez de 404. El frontend no puede distinguir un error de validación de un recurso inexistente.

### M-31 · Setters que corrompen en silencio
```php
public function setNombre(string $n): void { $this->nombre = (strlen(trim($n)) <= 100) ? trim($n) : ""; }
public function setCorreo(string $c): void { $this->correo = filter_var(...) ? trim($c) : ""; }
```
Un nombre de 101 caracteres se convierte en cadena vacía. Un email inválido se convierte en cadena vacía, y el service después informa *"El correo es obligatorio"* — mensaje que no describe el problema real. La validación pertenece a una capa que pueda **rechazar**, no a un setter que pueda **vaciar**.

Extras del mismo tipo: `ItemDto` usa `precio = 9999999` como default si falta el campo, y trunca `descripcion` a 255 cuando la columna es `TEXT`.

### A-32 · Cobertura de tests ≈ 0
- `tests/` del backend son scripts procedurales con `echo` y `require_once '../../app/...'`, **rotos**: referencian `CategoriaDao`, `ProductoDto`, `UsuarioDto`, `PerfilUsuario` — clases renombradas a `CategoryDao`, `ItemDto`, `UserDto`. No compilan.
- Frontend: un solo `.spec.ts`, el generado por el CLI.

Sin tests, los bugs C-9, A-24 y C-1 pasaron once commits sin detectarse.

### M-33 · Frontend sin control de acceso por rol
`app.routes.ts` aplica sólo `authGuard` (¿hay token vigente?). No hay guard por perfil: cualquier usuario logueado puede navegar a `/user` o `/user/create`; el backend rechaza, pero la UI queda rota. Y el control de visibilidad es un string mágico:
```ts
readonly esAdmin = computed(() => this._payload()?.perfil === 'Administrador');
```
La tabla `permisos` existe en la base pero **el frontend nunca la consulta**: no hay endpoint que devuelva los permisos del usuario. Los permisos son dinámicos en el backend y hardcodeados en el front.

### M-34 · Sin entornos ni lazy loading
`API_URL` hardcodeado en `api.constants.ts`, sin `environment.ts` → no hay build de producción posible sin editar código. Todas las rutas importan sus componentes de forma eager. `.angular/` (caché de build) y `FRONT IVANSITO.zip` están versionados.

### B-35 · Envelope de respuesta con metadatos de routing
```json
{ "controller": "...", "action": "...", "error": "", "message": "", "result": "" }
```
`controller` y `action` son detalles internos que el cliente no necesita. `result` se inicializa como `""` y según el endpoint devuelve `""`, un objeto o un array → el cliente no puede tipar la respuesta sin defensas. Los errores viajan con HTTP 200 en algunos flujos porque `send()` se llama sin `setStatus`.

### B-36 · Logging inexistente
`APP_FILE_LOG_ERRORS` y `APP_FILE_LOG_ACCESS` están definidos pero **nunca se usan**. El `error_log()` del `ExceptionHandlerMiddleware` está comentado. `log.txt` en la raíz es una nota manual de una línea. Ante un 500 en producción no queda rastro.

---

## 5. Qué conviene conservar

No todo se tira. Estas decisiones están bien y hay que llevarlas:

1. **Recalcular precios y totales en el servidor**, ignorando lo que manda el cliente (`resolverPreciosYTotales`). Es la defensa correcta contra manipulación de precios; en Laravel se mantiene igual.
2. **Congelar `precio_unit` y `subtotal` en `detalle_ventas`**. La línea de venta guarda el precio del momento, no una referencia al producto. Correcto — sólo falta que el `update` no lo pise.
3. **Pagos como tabla separada con múltiples filas por venta**. Ya soporta pagos parciales y multi-método (la venta 25 tiene transferencia + mercadopago). Es una buena base para Mercado Pago.
4. **Permisos en base de datos, no en código.** La idea de que un administrador ajuste permisos sin deploy es correcta; lo que falla es la granularidad (M-20).
5. **`ExceptionHandlerMiddleware` no filtra detalles de `PDOException` al cliente.** Buen reflejo.
6. **Separación de capas controller → service → DAO** y la disciplina de DTOs. Se traduce a Laravel casi 1:1: Controller → FormRequest → Service/Action → Eloquent → JsonResource.
7. **El flujo `presupuesto → confirmada → cobrada`** es el modelo de negocio correcto. Lo que falta es formalizarlo como máquina de estados con transiciones explícitas.
8. **El `Pipeline` de middlewares** es conceptualmente el middleware stack de Laravel. El concepto se conserva; el código se descarta porque el framework ya lo trae.

---

## 6. Resumen por prioridad

| # | Hallazgo | Sev. | Impacto en la migración |
|---|---|---|---|
| C-1 | Hashes de contraseña expuestos en `/user/list` y `/user/load` | C | Separar DTO entrada/salida desde el día 1 |
| C-2 | Autorización con fallback `can_update` | C | Rediseñar permisos como acciones nombradas |
| C-3 | Escalada de privilegios vía `perfil_id` en el body | C | Autorización a nivel de política, no de módulo |
| C-9 | Transiciones de estado de venta sin validar | C | Máquina de estados formal, bloqueante para facturación |
| C-10 | Sobrepago por validación fuera de transacción | C | Bloqueante para webhooks de Mercado Pago |
| A-4 | Secretos y credenciales versionados | A | `.env` desde el inicio |
| A-5 | JWT irrevocable, perfil embebido en el token | A | Sanctum + permisos consultados en cada request |
| A-11 | Anulación no revierte pagos | A | Notas de crédito en el nuevo modelo |
| A-12 | Descuento del 100% sin autorización | A | Tope por rol + vales controlados |
| A-13 | Stock sin kardex ni reservas | A | Bloqueante para carrito y compra automática |
| A-17 | `productos.precio` en `float` | A | Todo `decimal` en el esquema nuevo |
| A-18 | `ventas.numero` sin UNIQUE, contador único | A | Numeración por punto de venta y tipo (AFIP) |
| A-24 | Filtros rotos en tres módulos | A | Tests desde el inicio |
| A-25 | Sin paginación | A | Paginación por defecto en toda colección |
| A-26 | Dashboard calculado en el cliente | A | Endpoints de agregación por rol |
| A-32 | Cobertura de tests ≈ 0 | A | Definir mínimo obligatorio |
| M-20 | Permisos CRUD insuficientes | M | Rediseño del modelo de perfiles |
| M-22 | Faltan campos para tienda, envíos y facturación | M | Entra en el modelado nuevo |
