# CompuStack - Sistema de Gestión - Tienda Informática

Migración a Laravel 12 del sistema de gestión desarrollado en Laboratorio de
Programación. Proyecto Integrador, **Etapa 1**.

Laboratorio de Desarrollo de Aplicaciones · Ingeniería en Sistemas
Universidad Nacional de la Patagonia Austral — Unidad Académica Caleta Olivia

---

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| 0 | Repositorio y línea base | Completada |
| 1 | Proyecto Laravel, esquema de datos y seeders | Completada |
| 2 | Autenticación, roles y permisos | Pendiente |
| 3 | Catálogo: categorías, marcas, productos | Pendiente |
| 4 | Usuarios, clientes y empleados | Pendiente |
| 5 | Proveedores, compras y stock | Pendiente |
| 6 | Ventas y pagos | Pendiente |
| 7 | Panel de métricas y exportación a PDF | Pendiente |
| 8 | Cierre: pruebas, seguridad, documentación | Pendiente |

Al cierre de la Fase 1 el sistema tiene **base de datos y datos ficticios, sin
interfaz**: las pantallas empiezan en la Fase 2. `php artisan serve` levanta el
proyecto pero todavía no hay rutas propias.

## Propósito

Gestión interna de una tienda de informática: catálogo de productos con control
de stock, ventas de mostrador con sus cobros, compras a proveedores con
reposición automática, y administración de usuarios con permisos por rol.

El sistema original era un backend PHP con arquitectura MVC propia y un frontend
Angular separado que lo consumía como API. Esta etapa reemplaza el backend por
Laravel y las pantallas por vistas Blade, amplía el modelo de datos y corrige los
defectos relevados en la auditoría.

## Alcance

**Etapa 1 — esta entrega.** Roles y permisos, usuarios, clientes, empleados,
categorías, marcas, productos, proveedores, órdenes de compra, movimientos de
stock, ventas y pagos. Interfaz renderizada desde Laravel con Blade y Bootstrap.

**Etapa 2.** Tienda pública, carrito con reserva de stock, Mercado Pago,
facturación electrónica AFIP, envíos con Zipnova, vales y banners.

**Etapa 3.** API REST sobre los mismos servicios, e integración con la
aplicación Flutter.

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
   entre HTTP y el servicio, sin reglas propias. En la Etapa 3 los controladores
   de la API llaman a los mismos servicios y devuelven JSON en vez de vistas; si
   la lógica quedara en el controlador habría que reescribirla.
2. **La validación de entrada vive en Form Requests.** Una capa que puede
   rechazar, no un setter que vacía el valor en silencio.
3. **Los permisos son claves nombradas y se deniegan por defecto.** No hay
   permisos por descarte ni valores por omisión permisivos.
4. **Todo importe es `decimal(12,2)`.** Nunca punto flotante.

La correspondencia entre cada componente del sistema original y su reemplazo en
Laravel está en `docs/trazabilidad.md`.

## Modelo de datos

Diecisiete tablas en esta etapa:

| Área | Tablas |
|---|---|
| Identidad y acceso | `users`, `roles`, `permisos`, `rol_permiso`, `empleados`, `clientes`, `direcciones` |
| Catálogo | `categorias`, `marcas`, `productos` |
| Stock | `movimientos_stock` |
| Compras | `proveedores`, `ordenes_compra`, `orden_compra_lineas` |
| Ventas | `ventas`, `venta_lineas`, `pagos` |

El esquema completo, con la justificación de cada decisión, está en
`docs/modelo-datos.md`, y el diagrama entidad-relación en `docs/der.puml`.

## Requisitos

| | Versión |
|---|---|
| PHP | 8.2 o superior, con `pdo_mysql`, `mbstring`, `bcmath`, `intl` y `zip` |
| Composer | 2.x |
| Node.js | 20 o superior |
| MariaDB | 10.4 o superior (o MySQL 8) |
| Git | 2.x |

## Instalación

```bash
git clone <url-del-repositorio> sistema-gestion
cd sistema-gestion

composer install
npm install
npm run build

cp .env.example .env
php artisan key:generate
```

Crear las dos bases de datos:

```sql
CREATE DATABASE gestion         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE gestion_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Y levantar el esquema con datos de demostración:

```bash
php artisan migrate --seed
```

## Configuración

Toda la configuración sensible vive en `.env`, que **no se versiona**.
`.env.example` documenta las claves con sus valores por defecto y sin ningún
valor real.

| Clave | Para qué |
|---|---|
| `DB_CONNECTION` | `mariadb` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a la base |
| `APP_LOCALE`, `APP_TIMEZONE` | `es` y `America/Argentina/Buenos_Aires` |
| `APP_FAKER_LOCALE` | `es_AR`, para los datos ficticios |
| `ADMIN_EMAIL` | Cuenta del administrador que crea el seeder |
| `ADMIN_PASSWORD` | Si queda vacía, el seeder genera una al azar y la imprime una sola vez |

### Cuentas de demostración

`DatabaseSeeder` sólo siembra los datos ficticios **fuera de producción**. Las
cuentas de demostración comparten la contraseña `demo1234`:

| Correo | Rol |
|---|---|
| `administrativo@sistema.local` | Administrativo |
| `vendedor@sistema.local` | Vendedor |
| `cajero@sistema.local` | Cajero |
| `cliente@sistema.local` | Cliente (ámbito tienda) |

La cuenta de administrador es aparte y su contraseña nunca se escribe en el
código.

## Ejecución

```bash
php artisan serve      # http://localhost:8000
npm run dev            # recarga de assets en desarrollo
```

Para volver a una base limpia con datos ficticios:

```bash
php artisan migrate:fresh --seed
```

## Pruebas

```bash
php artisan test
```

Las pruebas usan **MariaDB**, no sqlite en memoria: verifican tipos de columna y
valores de `ENUM` consultando `information_schema`, y sqlite no tiene `ENUM` ni
distingue `decimal` de `float`. La base de pruebas tiene que ser el mismo motor
que la de producción. Se configura en `phpunit.xml` (`DB_DATABASE=gestion_testing`).

Cubierto hasta ahora:

- Existencia de las diecisiete tablas.
- Ninguna columna de la base en punto flotante.
- `ventas.estado` declara sus diez valores y `movimientos_stock.tipo` los cinco.
- `pagos.mp_payment_id` tiene índice único (idempotencia de webhooks).
- Toda tabla del dominio lleva marcas de tiempo.
- Asignación de permisos por rol, incluida la regresión del hallazgo C-2.

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
  aparece una columna en punto flotante.

Hallazgos de la auditoría cerrados hasta esta fase:

| Hallazgo | Cómo se cierra |
|---|---|
| A-4 · Secretos versionados | `.env` ignorado; credenciales del legacy reemplazadas por marcadores |
| A-17 · Dinero en `float` | Todas las columnas monetarias en `decimal(12,2)`, con test |
| A-18 · Numeración de ventas frágil | `venta_numeracion` descartada; la numeración fiscal la gobierna AFIP |
| M-19 · Sin trazabilidad temporal | `created_at` / `updated_at` en toda tabla del dominio |
| M-20 · Permisos CRUD insuficientes | Permisos como claves nombradas con pivote a roles |
| C-1 · Hashes de contraseña expuestos | `$hidden` en `User` (se completa en la Fase 4) |
| A-13 · Stock sin kardex | Tabla `movimientos_stock` creada (el servicio llega en la Fase 5) |

El listado completo de hallazgos está en `docs/auditoria.md`.

## Estructura del proyecto

```
app/
├── Models/                 Eloquent, con $table declarado explícitamente
└── ...                     Http/, Services/ y Support/ se pueblan desde la Fase 2

database/
├── migrations/             17 migraciones de la Etapa 1, en orden de dependencia
├── seeders/                roles y permisos, administrador, datos ficticios
└── factories/

docs/                       auditoría, modelo de datos, planes, trazabilidad, DER
legacy/                     sistema original congelado (ver legacy/README.md)
tests/
├── Feature/
└── Unit/
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

## Documentación

| Documento | Contenido |
|---|---|
| `docs/auditoria.md` | Hallazgos del sistema original, por severidad |
| `docs/modelo-datos.md` | Esquema completo y justificación de cada decisión |
| `docs/der.puml` | Diagrama entidad-relación |
| `docs/plan-migracion.md` | Fases, orden y criterios de terminado |
| `docs/plan-accion.md` | Implementación paso a paso |
| `docs/trazabilidad.md` | Componente original → componente Laravel |
| `legacy/README.md` | Procedencia y estado del sistema congelado |

---

Autor: Daniel Iván Reales · Laboratorio de Desarrollo de Aplicaciones, 2026.
