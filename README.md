# CompuStack - Sistema de Gestión - Tienda Informática

Migración a Laravel 12 del sistema de gestión desarrollado en Laboratorio de
Programación. Proyecto Integrador, **Etapas 1 y 2**: el sistema de gestión (Blade) y
la API de la tienda móvil (Flutter, en su propio repositorio).

Laboratorio de Desarrollo de Aplicaciones · Ingeniería en Sistemas
Universidad Nacional de la Patagonia Austral — Unidad Académica Caleta Olivia

---

## Estado

| Fase | Contenido                                    | Estado     |
| ---- | -------------------------------------------- | ---------- |
| 0    | Repositorio y línea base                     | Completada |
| 1    | Proyecto Laravel, esquema de datos y seeders | Completada |
| 2    | Autenticación, roles y permisos              | Completada |
| 3    | Catálogo: categorías, marcas, productos      | Completada |
| 4    | Usuarios, clientes y personal                | Completada |
| 5    | Proveedores, compras y stock                 | Completada |
| 6    | Ventas y pagos                               | Completada |
| 7    | Panel de métricas y exportación a PDF        | Completada |
| 8    | Cierre: pruebas, seguridad, documentación    | Pendiente  |

**Etapa 2 — la tienda móvil.** Este repositorio suma la API `/api/v1` que consume la app:
catálogo público sin precios, registro e inicio de sesión con credencial, carrito con reserva
de stock, confirmación del pedido como venta online, pagos con **Mercado Pago de prueba**
(Checkout Pro, verificado por consulta al proveedor) y consulta de los pedidos. Se hizo por
tramos (A a I, con el K entre el F y el G); el estado de cada uno está en
`docs/plan-etapa-2.md` §14.4. La Fase 8 de la Etapa 1 no se hizo: sus tareas quedaron
como dice la §8 de `docs/plan-fase-7-5.md`.

El sistema administra el catálogo y las personas, y compra: proveedores con los
productos que provee cada uno y a qué precio, órdenes de compra con máquina de estados
y aprobación, recepción total o parcial de mercadería con kardex, ajustes de inventario,
PDF del pedido al proveedor y reposición automática de lo que cae por debajo del mínimo.

La Fase 6 —ventas y pagos— está completa. El mostrador emite un **presupuesto** con los
precios leídos del catálogo y congelados en la línea junto a la alícuota y el costo, lo
edita conservando esos precios, lo **recotiza** como acción explícita que informa qué
cambió, aplica un descuento con tope por rol, o lo **cancela** sin borrar nada. Cuando
se **cobra**, el saldo se valida después de bloquear la venta, se registran los medios
de pago —uno o varios, por el total exacto— y se descuenta el stock de cada producto
exactamente una vez, dejando su asiento en el kardex. Después se puede **entregar**, y
después **devolver**, total o parcialmente: la devolución repone el stock, le pone al
cobro un pago en contra por la parte que corresponde, y deja la venta en
`devuelta_parcial` o `devuelta` según cuánto quedó sin devolver — el estado se deriva de
las líneas, no lo elige el operador.

Toda transición pasa por una **máquina de estados explícita** que rechaza lo que no está
declarado, y el estado destino nunca viaja en una petición: cada transición es un método
con su nombre, su ruta y su permiso. **Nada se borra**: una venta, una línea y un pago no
tienen forma de borrarse, y lo que reemplaza al borrado es el estado para el presupuesto
y la devolución para la venta cobrada.

La Fase 7 cierra el **panel** y la **exportación a PDF**. El panel está diferenciado por
rol en dos rutas con un permiso cada una: el vendedor y el cajero ven lo suyo —lo que
vendí y lo que cobré, que son dos preguntas distintas en dos columnas de dos tablas— y
el administrativo ve el conjunto, con los rankings, el margen, el stock crítico y las
órdenes de compra abiertas. **Cada número es una consulta de agregación** y ninguna trae
filas al servidor de aplicación: hay un test que lo afirma sobre el log de consultas, y
es el cierre de A-26. Los gráficos son Chart.js y reciben los números ya agregados; sin
JavaScript las tablas muestran lo mismo.

El panel nombra con precisión lo que muestra: **«cobrado»** descuenta las devoluciones
porque el contra-asiento entra en la misma suma, **«facturado»** no, y **el margen es
bruto** —no resta el descuento de la cabecera— con los descuentos otorgados al lado. Y
hay dos PDF nuevos con comportamientos opuestos a propósito: el **comprobante de venta o
presupuesto** imprime la fecha del hecho y sólo datos congelados, así que regenerarlo
dentro de un año da el mismo papel; el **listado exportado** imprime la fecha de
generación y declara los filtros aplicados, porque es un informe de un momento. Los dos
dicen con palabras que no son comprobantes fiscales.

## Propósito

Gestión interna de una tienda de informática: catálogo de productos con control
de stock, ventas de mostrador con sus cobros, compras a proveedores con
reposición automática, y administración de usuarios con permisos por rol.

El sistema original era un backend PHP con arquitectura MVC propia y un frontend
Angular separado que lo consumía como API. Esta etapa reemplaza el backend por
Laravel y las pantallas por vistas Blade, amplía el modelo de datos y corrige los
defectos relevados en la auditoría.

## Alcance

**Etapa 1.** Roles y permisos, usuarios, clientes, empleados,
categorías, marcas, productos, proveedores, órdenes de compra, movimientos de
stock, ventas y pagos. Interfaz renderizada desde Laravel con Blade y Bootstrap.

**Etapa 2.** La tienda móvil en Flutter y la API de este backend, sobre los mismos
servicios de la Etapa 1: catálogo, cuenta, carrito con reserva, pedido, pago por
Mercado Pago de prueba y consulta de compras. Los módulos de gestión conservan su
interfaz Blade.

**Fuera de alcance (Etapa 4 del plan, §5).** Facturación con ARCA, módulo de envíos,
vales y banners: la consigna no los pide.

**App móvil de la tienda.** Vive en otro repositorio:
[`ivan545353/compustack-app`](https://github.com/ivan545353/compustack-app) (Flutter, Android,
`ar.compustack.tienda`). Consume la API de este backend; el contrato compartido es `docs/api.md`.

El esquema de la Etapa 1 ya declara las columnas y los valores de `ENUM` que las
etapas siguientes necesitan, aunque queden sin usar: agregar una columna nullable
es barato, modificar un `ENUM` reescribe la tabla entera.

## Arquitectura

```
Ruta ──► Middleware ──► Form Request ──► Controlador ──► Servicio ──► Eloquent
                        (validación)     (traduce HTTP)  (negocio)    (persistencia)
                                              │
                                              └──► Vista Blade
```

Cuatro reglas sostienen el diseño:

1. **La lógica de negocio vive en `app/Services`.** Los controladores traducen
   entre HTTP y el servicio, sin reglas propias. Desde la Etapa 2 los controladores
   de la API (`Api/V1`) llaman a los mismos servicios y devuelven JSON en vez de
   vistas; si la lógica hubiera quedado en el controlador, habría que haberla reescrito.
2. **La validación de entrada vive en Form Requests.** Una capa que puede
   rechazar, no un setter que vacía el valor en silencio.
3. **Los permisos son claves nombradas y se deniegan por defecto.** No hay
   permisos por descarte ni valores por omisión permisivos.
4. **Todo importe es `decimal(12,2)`.** Nunca punto flotante.

La correspondencia entre cada componente del sistema original y su reemplazo en
Laravel está en `docs/trazabilidad.md`.

## Modelo de datos

Dieciocho tablas en esta etapa:

| Área               | Tablas                                                                              |
| ------------------ | ----------------------------------------------------------------------------------- |
| Identidad y acceso | `users`, `roles`, `permisos`, `rol_permiso`, `empleados`, `clientes`, `direcciones` |
| Catálogo           | `categorias`, `marcas`, `productos`                                                 |
| Stock              | `movimientos_stock`                                                                 |
| Compras            | `proveedores`, `producto_proveedor`, `ordenes_compra`, `orden_compra_lineas`        |
| Ventas             | `ventas`, `venta_lineas`, `pagos`                                                   |

El esquema completo, con la justificación de cada decisión, está en
`docs/modelo-datos.md`, y el diagrama entidad-relación en `docs/der.puml`.

## Requisitos

|          | Versión                                                               |
| -------- | --------------------------------------------------------------------- |
| PHP      | 8.2 o superior, con `pdo_mysql`, `mbstring`, `bcmath`, `intl` y `zip` |
| Composer | 2.x                                                                   |
| Node.js  | 20 o superior                                                         |
| MariaDB  | 10.4 o superior (o MySQL 8)                                           |
| Git      | 2.x                                                                   |

## Instalación

```bash
git clone <url-del-repositorio> compustack
cd compustack

composer install
npm install
npm run build

cp .env.example .env
php artisan key:generate
```

Crear las dos bases de datos:

```sql
CREATE DATABASE lda_2026       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE lda_2026_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Y levantar el esquema con datos de demostración y enlazar el almacenamiento público:

```bash
php artisan migrate --seed

php artisan storage:link
```

## Configuración

Toda la configuración sensible vive en `.env`, que **no se versiona**.
`.env.example` documenta las claves con sus valores por defecto y sin ningún
valor real.

| Clave                                       | Para qué                                                               |
| ------------------------------------------- | ---------------------------------------------------------------------- |
| `DB_CONNECTION`                             | `mariadb`                                                              |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a la base                                                     |
| `APP_LOCALE`, `APP_TIMEZONE`                | `es` y `America/Argentina/Buenos_Aires`                                |
| `APP_FAKER_LOCALE`                          | `es_AR`, para los datos ficticios                                      |
| `ADMIN_EMAIL`                               | Cuenta del administrador que crea el seeder                            |
| `ADMIN_PASSWORD`                            | Si queda vacía, el seeder genera una al azar y la imprime una sola vez |
| `SANCTUM_EXPIRATION`                        | Vigencia de la credencial de la API, en minutos. Vacía: 10080 (7 días) |
| `TIENDA_SIMULADOR`                          | Simulador de pagos del backend. **`false`**: los pagos son Mercado Pago de prueba (D-23) |
| `MERCADOPAGO_ACCESS_TOKEN`                  | Token **de prueba** del vendedor de prueba. Vacío: Mercado Pago no se ofrece |

Datos de la empresa que imprime el pedido al proveedor. Están en `.env` y no en una
tabla porque cambian al instalar el sistema, no durante su uso:

    EMPRESA_NOMBRE, EMPRESA_CUIT, EMPRESA_DIRECCION,
    EMPRESA_LOCALIDAD, EMPRESA_TELEFONO, EMPRESA_EMAIL

### Cuentas de demostración

`DatabaseSeeder` sólo siembra los datos ficticios **fuera de producción**. Las
cuentas de demostración comparten la contraseña `demo1234`:

| Correo                         | Rol                     | Entra a la gestión |
| ------------------------------ | ----------------------- | ------------------ |
| `administrativo@sistema.local` | Administrativo          | Sí                 |
| `vendedor@sistema.local`       | Vendedor                | Sí                 |
| `vendedor2@sistema.local`      | Vendedor                | Sí                 |
| `cajero@sistema.local`         | Cajero                  | Sí                 |
| `cliente@sistema.local`        | Cliente (ámbito tienda) | No                 |

La cuenta de cliente es de la tienda: entra por la app (la API), no por la web, que la
rechaza porque no es una cuenta de gestión. Al revés, las cuentas de gestión no entran por
la API. La app también permite registrar cuentas de cliente nuevas.

La cuenta de administrador es aparte y su contraseña nunca se escribe en el
código.

## Ejecución

```bash
php artisan serve      # http://localhost:8000
npm run dev            # recarga de assets en desarrollo
```

Con la app móvil hacen falta dos cosas más:

```bash
php artisan serve --host=0.0.0.0   # que el teléfono lo alcance por la IP de la red (HL-12)
php artisan schedule:work          # la tarea programada, en otra terminal (HL-10)
```

En la app, *Perfil → Servidor* recibe la dirección: `http://<IP de la máquina>:8000` desde un
teléfono, `http://10.0.2.2:8000` desde el emulador. Las fotos del catálogo necesitan
`php artisan storage:link`.

Para volver a una base limpia con datos ficticios:

```bash
php artisan migrate:fresh --seed
```
La aplicación queda en `http://localhost:8000` y redirige al login. No hay
registro público: las cuentas las crea un administrador.

### Tarea programada

La reposición automática corre todos los días a las 07:00 y deja una orden de
compra en borrador por proveedor con todo lo que esté por debajo del stock
mínimo. En el servidor hace falta **una sola** entrada de cron, la del
planificador de Laravel:

    * * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1

Para verla o ejecutarla a mano:

```bash
php artisan schedule:list                  # qué está programado y cuándo corre
php artisan compras:generar-reposicion     # la misma tarea, ahora
```

El comando genera **borradores**, no pedidos: nadie gasta plata sin que una
persona con `compra.aprobar` autorice el monto.

La tienda suma `tienda:vencer-reservas`, **cada minuto** y sin encimarse: suelta los
checkouts vencidos, pregunta a Mercado Pago por los intentos que siguen en el aire y
cancela los pedidos cuya reserva venció sin pago. Sin el planificador corriendo, la
mercadería reservada no vuelve al disponible y un pedido vencido sigue «Esperando el
pago» (HL-77). En desarrollo y en la demostración, en lugar del cron:

```bash
php artisan schedule:work                  # corre el planificador en primer plano
php artisan tienda:vencer-reservas         # una pasada, ahora
```

## Pruebas

```bash
php artisan test
```


Las pruebas usan **MariaDB**, no sqlite en memoria: verifican tipos de columna y
valores de `ENUM` consultando `information_schema`, y sqlite no tiene `ENUM` ni
distingue `decimal` de `float`. La base de pruebas tiene que ser el mismo motor
que la de producción. Se configura en `phpunit.xml` (`DB_DATABASE=lda_2026_testing`).

Cubierto hasta ahora:

- Existencia de las dieciocho tablas, y que productos.proveedor_id ya no existe.
- Ninguna columna de la base en punto flotante.
- `ventas.estado` declara sus once valores, y la constante del modelo dice lo mismo
  que la base. `movimientos_stock.tipo` declara los cinco.
- `pagos.mp_payment_id` tiene índice único (idempotencia de webhooks).
- Toda tabla del dominio lleva marcas de tiempo.
- Asignación de permisos por rol, incluida la regresión del hallazgo C-2.
- Login con credenciales válidas, inválidas, cuenta inactiva y rol de ámbito tienda.
- Desconexión automática al deshabilitar un usuario en curso.
- Límite de intentos y regeneración del identificador de sesión.
- Denegación por defecto: una acción sin permiso definido no se concede a nadie.
- Módulo de roles: permiso implícito de ver, rol de gestión sin permisos,
  protección de los roles de sistema y del rol propio.
- Catálogo: filtros de cada listado, jerarquía de categorías, precios en formato
  argentino, gestión de imágenes y bajas lógicas.
- Personal: las nueve rutas con su permiso, probadas en las dos direcciones; el
  hash de contraseña ausente de la respuesta **y** de la consulta; el rol
  imposible de enviar en la edición y de autoasignarse; la independencia entre
  la baja laboral y el acceso al sistema.
- Cuenta propia: cambio de contraseña sin permiso alguno, y enlace de
  restablecimiento de un solo uso, con vencimiento y sin que el administrador
  conozca la contraseña.
- Clientes: las once rutas con su permiso, la dirección de otro cliente
  respondiendo 404, las reglas fiscales por condición frente al IVA y por tipo
  de documento, y la invariante de la dirección predeterminada.
- Stock: el kardex con sus cinco filtros y el ajuste de inventario; el bloqueo de
  fila verificado en los cuatro métodos del servicio leyendo el `query log`; el
  descuento validado contra el stock disponible y no contra el de depósito; el
  costo promedio ponderado; el motivo obligatorio del ajuste; el conflicto de
  concurrencia cuando el stock cambió mientras se contaba; y el kardex que no se
  puede editar ni borrar.
- La reposición automática: a quién se le pide cada producto, qué productos no
  entran, que no vuelve a pedir lo que ya está en una orden abierta y que dos
  corridas seguidas no duplican nada.
- La tabla de transiciones de la venta: cada transición declarada se permite, cada una
  que no está declarada se rechaza —incluidos los cuatro agujeros que la auditoría
  encontró en el original—, los estados finales no tienen salida, y `entregada_parcial`
  está declarado en la base pero ninguna transición lo alcanza.
- Que el estado destino no sea una puerta pública del servicio, verificado por
  reflexión: `cambiarEstado()` es privado.
- Que una venta no se pueda borrar, ni con `delete()` ni con un `update()` masivo sobre
  el estado o los importes.
- Que el precio se lea del catálogo y no del formulario, en tres niveles: el servicio
  lo ignora, el modelo no lo puede escribir, y el Form Request lo rechaza con un
  mensaje.
- Que el neto y el IVA de cada línea sumen exactamente el total, con las tres alícuotas.
- Que editar un presupuesto conserve el precio congelado de las líneas que ya estaban
  —afirmado sobre el **id** de la fila, no sobre el precio— y que recotizar lo releve e
  informe qué cambió conservando el porcentaje de descuento.
- El tope de descuento por rol, en el Form Request y en el servicio.
- Los filtros del listado de ventas en dos niveles: que el scope filtre y que el
  parámetro de la URL llegue al scope.
- Los permisos de las trece rutas de ventas y pagos en los dos sentidos; que un vendedor
  pueda cancelar el presupuesto que él mismo cargó; y que quien cobra sin poder editar
  **sí** vea el botón de cobrar —la dirección que faltaba, y cuya ausencia dejó pasar un
  bug real en la ficha—.
- El cobro: que el saldo se lea **después** del lock, verificado comparando la posición
  de las dos consultas en el `query log`; que salga de los pagos registrados y no del
  total; y que un pago que entra en el instante en que se toma el lock entre en la cuenta
  y haga rechazar el cobro.
- La idempotencia en sus dos niveles: que cobrar dos veces se rechace por el estado, y
  que el mismo `mp_payment_id` dos veces no acredite ni descuente dos veces.
- Que el pase a `pagada` descuente el stock exactamente una vez, rechace un producto dado
  de baja, valide contra el **disponible** y revierta entero si una línea no alcanza
  —incluido el pago que ya se había escrito—.
- Que los productos se bloqueen en orden de `id` al descontar y al reponer, para que dos
  operaciones que comparten productos no se bloqueen en cruz.
- La devolución: que reponga stock, revierta el pago con un contra-asiento, derive el
  estado de las líneas, cierre en cero cuando se completa, devuelva la parte proporcional
  del descuento cuando es parcial, y no permita devolver más de lo vendido ni de una vez
  ni en dos tandas.
- Que una línea de otra venta no se pueda devolver, y que ni el monto ni el estado de una
  devolución se puedan enviar desde el formulario.
- Que un pago no se pueda borrar, y que las columnas que acredita el webhook de Mercado
  Pago se rechacen en el formulario del mostrador — también cuando vienen en un medio sin
  monto.
- Que el formulario de cobro acepte el cuerpo real de la pantalla, con los medios sin usar
  llegando vacíos. Es la regresión del único bug de esta fase: los treinta casos del
  servicio pasaban y el formulario estaba roto, porque el Form Request descartaba «filas
  vacías» y ninguna fila del cobro es nunca vacía. A-24 otra vez — la pieza funcionaba y
  el cableado no.
- Panel: los dos juegos de estados parten `Venta::ESTADOS` sin superponerse, el panel
  propio separa lo vendido de lo cobrado, el facturado no descuenta devoluciones y lo
  cobrado sí, el margen es bruto y los descuentos van aparte, y **ninguna consulta del
  panel trae filas completas** —se verifica sobre el log de consultas—.
- Panel: un permiso por ruta en las dos direcciones, y el inicio manda a cada rol a la
  pantalla que puede ver en lugar de a un 403 sin salida.
- Los gráficos reciben los números ya agregados: dos ventas del mismo día llegan como
  un solo punto.
- El comprobante de venta imprime lo congelado y no el estado, los pagos ni lo
  devuelto, así que regenerarlo da el mismo documento. Descargarlo no cambia nada.
- El listado exportado declara los filtros aplicados y avisa cuando recortó, diciendo
  que el total es de todas las coincidencias y no de las filas impresas.

El rollback se verifica a mano, porque `RefreshDatabase` envuelve cada prueba en
una transacción y el DDL de MySQL provoca commits implícitos:

```bash
php artisan migrate:rollback --step=100
php artisan migrate
```

## Seguridad

Prácticas aplicadas:

- **Ningún secreto en el repositorio.** `.env` ignorado desde el primer commit,
  `.env.example` sin valores reales, `APP_KEY` generada por instalación. La
  contraseña del administrador se toma del entorno o se genera al azar.
- **Denegación por defecto en los permisos.** Los permisos son acciones
  nombradas (`venta.anular`, `compra.aprobar`). Lo que no está asignado, no se
  puede hacer.
- **El hash de contraseña nunca sale del modelo.** `User::$hidden`.
- **El stock no es asignable en masa.** `Producto::$guarded` deja fuera `stock`,
  `stock_reservado` y `costo_promedio`: sólo los modifica el servicio de stock,
  y siempre dejando movimiento en el kardex.
- **Todo importe en `decimal`.** Un test recorre la base entera y falla si
- **El estado de la cuenta se revalida en cada request**, no sólo al ingresar.
  Deshabilitar a un usuario lo desconecta en su próxima acción.
- **El ámbito se lee de la tabla `roles`, nunca del nombre del rol.** Renombrar un
  rol no cambia lo que puede hacer nadie.
- **El login no revela qué cuentas existen.** Mismo mensaje para correo
  inexistente y contraseña incorrecta, con límite de cinco intentos por minuto.
  aparece una columna en punto flotante.

Hallazgos de la auditoría cerrados hasta esta fase:

| Hallazgo                                               | Cómo se cierra                                                                                                |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| A-4 · Secretos versionados                             | `.env` ignorado; credenciales del legacy reemplazadas por marcadores                                          |
| A-17 · Dinero en `float`                               | Todas las columnas monetarias en `decimal(12,2)`, con test                                                    |
| A-18 · Numeración de ventas frágil                     | `venta_numeracion` descartada; la numeración fiscal la gobierna AFIP                                          |
| M-19 · Sin trazabilidad temporal                       | `created_at` / `updated_at` en toda tabla del dominio                                                         |
| M-20 · Permisos CRUD insuficientes                     | Permisos como claves nombradas con pivote a roles                                                             |
| C-1 · Hashes de contraseña expuestos                   | `$hidden` en `User`; entrada por Form Request y salida por el modelo (se completa en la Fase 4)               |
| C-2 · Fallback permisivo en la autorización            | `Gate::before` concede sólo lo asignado; sin regla definida, deniega. Con test de regresión                   |
| A-5 · El token no se podía revocar                     | Sesión en servidor y revalidación de `activo` en cada request                                                 |
| A-6 · Sin límite de intentos en el login               | `throttle:5,1`                                                                                                |
| M-33 · Control de acceso por nombre de rol en el front | Las vistas usan `@can` sobre permisos; el ámbito sale de `roles.ambito`                                       |
| C-1 · Hashes de contraseña expuestos                   | `$hidden` en `User`, entrada por Form Request y salida por el modelo, y el listado no selecciona la columna   |
| C-3 · Escalada de privilegios                          | El rol no es campo del formulario de edición; cambiarlo es una acción con permiso propio y sin autoasignación |
| M-21 · Contraseña bloqueada sin salida                 | Cambio propio sin permiso, más enlace de un solo uso generado por un administrador                            |

El listado completo de hallazgos está en `docs/auditoria.md`.

## Estructura del proyecto

```
app/
├── Console/Commands/       compras:generar-reposicion
├── Exceptions/             ReglaDeNegocio, StockInsuficiente, TransicionInvalida
├── Http/
│   ├── Controllers/        traducen HTTP ↔ servicio, sin lógica; Api/V1: los 11 de la API
│   ├── Middleware/         VerificarUsuarioActivo, VerificarAmbitoGestion; Api/: ForzarJson,
│   │                       SesionOpcional, VerificarCuentaTienda
│   ├── Requests/           Form Requests: validación de entrada y de filtros; Api/: los de la API
│   └── Resources/Api/      la forma JSON de cada recurso (docs/api.md)
├── Models/                 Eloquent, con $table declarado y un scope por filtro
├── Providers/              registro de Gates (deny-by-default)
├── Services/               22 servicios: LA lógica de negocio, la misma para la web y la API
└── Support/                MaquinaEstadosVenta, MaquinaEstadosCompra, Importe,
                            ReglasFiscales, Like, Slug

database/
├── migrations/             23 archivos: las 18 tablas de la Etapa 1 y los cambios
│                           posteriores, en orden de dependencia
├── seeders/                roles y permisos, administrador, datos ficticios
└── factories/

resources/
├── js/
│   ├── app.js              arranque y comportamientos cortos de formulario
│   └── componentes/        líneas repetibles, selects buscables, gestor de
│                           imágenes, recepción, gráficos del panel
├── scss/                   Bootstrap + estilos propios (sin Tailwind)
└── views/                  16 carpetas
    ├── layouts/            app (navbar y pie) y auth (pantalla completa)
    ├── partials/           navbar, footer, alertas, resumen de errores
    ├── components/         opciones de categoría, gestor de imágenes
    ├── auth/ · cuenta/     login, restablecimiento, contraseña propia
    ├── panel/              panel propio y general, con el filtro de período
    ├── categorias/ · marcas/ · productos/            catálogo
    ├── personal/ · clientes/                         personas
    ├── proveedores/ · compras/ · stock/              compras y kardex
    ├── ventas/             listado, ficha, formulario, cobro, devolución y los
    │                       dos PDF: comprobante y listado exportado
    └── roles/              index y formulario de permisos

docs/                       auditoría, modelo de datos, planes, trazabilidad, DER,
                            patrón de módulo, pendientes de usabilidad
legacy/                     sistema original congelado (ver legacy/README.md)
tests/
├── Feature/                rutas, permisos, filtros y flujos completos
└── Unit/                   máquinas de estado, formato de importes, reglas fiscales
```

## Migración desde el sistema original

El sistema previo a la migración está conservado sin modificaciones en
`legacy/`, junto con el detalle de qué se le cambió al importarlo y desde qué
commit sale cada carpeta. Ver `legacy/README.md`.

El seguimiento componente por componente —qué archivo del sistema original
reemplaza cada pieza de Laravel y en qué estado está— vive en
`docs/trazabilidad.md` y se actualiza en el mismo commit que migra cada
componente.

Decisiones de esta fase que conviene tener presentes al leer el código:

- **La migración de usuarios que trae Laravel fue reemplazada.** `users.rol_id`
  es clave foránea a `roles`, y la original tiene fecha `0001_01_01`: siempre
  correría antes de que exista la tabla destino.
- **Las tablas `modulos`, `perfiles` y `venta_numeracion` del esquema original no
  se migran.** Las dos primeras se rediseñaron como `roles` y `permisos`
  nombrados; la tercera desaparece porque la numeración fiscal la gobierna AFIP.
- **Los datos ficticios reproducen el catálogo del sistema original**, descartando
  las filas de prueba que el original no validaba.
  - **El identificador de acceso pasó de `cuenta` a `email`.** La tabla original
  tenía las dos columnas y el login usaba la primera. El modelo nuevo unifica en
  `email`, que ya era único y sirve además para recuperar la contraseña.
- **La autorización dejó de deducirse del nombre del controlador.** El middleware
  original resolvía el módulo con `ucfirst($controller)` contra la tabla
  `modulos`: renombrar un controlador rompía los permisos en silencio. Ahora cada
  ruta declara el permiso que exige.

## Trabajo pendiente

Decisiones tomadas a conciencia, con su motivo:

- **Las imágenes nuevas se pierden si el formulario vuelve con un error en
  otro campo.** El navegador no permite volver a completar un campo de
  archivo. Resolverlo requiere subir cada imagen apenas se elige, a un
  almacenamiento temporal, con un endpoint propio y una tarea que limpie lo
  abandonado. El formulario avisa que hay que volver a elegirlas.
- **Los selectores con búsqueda cargan todas las opciones en el HTML**, y el
  formulario de venta además manda el precio y el disponible de cada producto como
  JSON para la vista previa de los importes. Sirve con el catálogo y el padrón de la
  demostración; con miles de productos o clientes hace falta buscar contra el
  servidor. El componente está preparado para recibir ese modo (`data-buscable-url`),
  y el punto está anotado como pendiente #39.
- **El bloque de campo se repite en los tres formularios**: etiqueta, campo,
  ayuda y error. Corresponde un componente Blade `<x-campo>`. No se hizo en la
  Fase 3 para no mezclar un refactor con módulos nuevos.
- **El filtro por categoría no recorre subcategorías en el listado de
  categorías**, a diferencia del de productos. Es deliberado: uno es
  navegación y el otro es búsqueda.
- **Las pantallas de cobro y devolución no calculan nada en el navegador.** La de cobro
no suma los medios en vivo ni ofrece un «todo en efectivo»; la de devolución no
muestra cuánta plata va a volver antes de enviar. Las dos funcionan sin JavaScript a
propósito, y en el segundo caso hay un motivo de fondo: el monto lo calcula el
servidor con la parte proporcional del descuento, y duplicar una fórmula de dinero en
el cliente es más riesgo que el que la vista previa del formulario de venta ya tiene
asumido. Están anotados como pendientes #41 y #42.

## Documentación

| Documento                       | Contenido                                                   |
| ------------------------------- | ----------------------------------------------------------- |
| `docs/auditoria.md`             | Hallazgos del sistema original, por severidad               |
| `docs/modelo-datos.md`          | Esquema completo y justificación de cada decisión           |
| `docs/der.puml`                 | Diagrama entidad-relación                                   |
| `docs/plan-migracion.md`        | Fases, orden y criterios de terminado                       |
| `docs/plan-accion.md`           | Implementación paso a paso                                  |
| `docs/trazabilidad.md`          | Componente original → componente Laravel                    |
| `legacy/README.md`              | Procedencia y estado del sistema congelado                  |
| `docs/pendientes-usabilidad.md` | Puntos de usabilidad y consistencia a resolver en el cierre |
| `docs/plan-etapa-2.md`          | Plan de la Etapa 2: decisiones, hallazgos, tramos y su estado |
| `docs/api.md`                   | El contrato de la API que comparten el backend y la app      |
| `docs/pruebas-etapa-2.md`       | Qué se probó en la Etapa 2, dificultades y pendientes        |
| `docs/seguridad-etapa-2.md`     | El riesgo de seguridad detectado y su solución               |
| `docs/demostracion-etapa-2.md`  | El guion de la defensa de la Etapa 2                         |

---

Autor: Daniel Iván Reales · Laboratorio de Desarrollo de Aplicaciones, 2026.
