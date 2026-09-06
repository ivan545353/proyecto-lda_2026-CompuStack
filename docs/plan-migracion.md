# Plan de migración — Etapa 1

Migración del backend PHP a Laravel 12, con la interfaz renderizada desde Blade.

**Alcance:** módulos de clientes, proveedores, ventas, pagos, roles y permisos, más los módulos existentes (categorías, productos, usuarios) y los que hacen falta para que funcionen (marcas, empleados, movimientos de stock, órdenes de compra).

**Fuera de alcance:** tienda pública, carrito, Mercado Pago, facturación AFIP, Zipnova, vales, banners y Flutter. Se abordan en las Etapas 2 y 3.

---

## 1. Correspondencia con los hitos de la consigna

| Hito de la consigna | Fases de este plan | Estado |
|---|---|---|
| Relevar y ejecutar el sistema original | — | Completado (auditoría) |
| Definir y aprobar alcance, modelo de datos y plan de migración | — | Completado (modelo de datos y este documento) |
| Crear la base Laravel e implementar autenticación, roles y persistencia | Fases 0 a 2 | Pendiente |
| Migrar los casos de uso acordados e integrar la interfaz web | Fases 3 a 7 | Pendiente |
| Completar pruebas, seguridad, documentación y demostración | Fase 8 (y trabajo continuo) | Pendiente |

Las pruebas, la documentación y la seguridad **no se dejan para la Fase 8**. Cada fase entrega sus propios tests y actualiza el README y la tabla de trazabilidad. La Fase 8 cierra lo transversal: auditoría final, README completo y ensayo de la demostración.

---

## 2. Orden de las fases y por qué ese orden

El orden no es arbitrario: cada fase depende de las anteriores.

```
Fase 0  Repositorio y línea base
   │
Fase 1  Proyecto Laravel + esquema de datos + seeders
   │
Fase 2  Autenticación + roles y permisos
   │        (todo lo demás necesita saber quién es el usuario y qué puede hacer)
   ├──────────────┬──────────────┐
Fase 3         Fase 4         Fase 5
Catálogo       Usuarios,      Proveedores,
(categorías,   clientes y     compras y
marcas,        empleados      stock
productos)                        │
   └──────────────┴──────────────┘
   │
Fase 6  Ventas y pagos
   │        (necesita productos, clientes, usuarios y stock)
Fase 7  Panel de métricas y PDF
   │        (necesita que existan datos de ventas)
Fase 8  Cierre: pruebas, seguridad, documentación, demostración
```

Las Fases 3, 4 y 5 son independientes entre sí y pueden reordenarse. La Fase 3 va primero porque es la más simple y establece el patrón que las demás repiten.

---

## 3. Fases

### Fase 0 — Repositorio y línea base

**Objetivo.** Dejar el repositorio en condiciones antes de escribir una línea de Laravel.

**Tareas.**
- Crear el repositorio en GitHub.
- Importar el sistema original en `/legacy/backend` y `/legacy/frontend`, sin `vendor/`, sin `.angular/` y sin el zip que quedó adentro.
- **Reemplazar el `JWT_SECRET` de `AppConfig.php` y las credenciales de `Connection.php` por marcadores.** La consigna prohíbe versionar credenciales; el sistema original las tiene escritas en el código.
- `README.md` mínimo y `/legacy/README.md` explicando que esa carpeta está congelada y qué se le modificó.
- Commit inicial en `main` y tag `v0-original`.

**Entregable.** Repositorio con la versión original disponible e inalterada en lo sustantivo.

**Criterio de terminado.** `git log` muestra un solo commit, el tag existe, y una búsqueda de secretos en el repositorio no devuelve nada.

---

### Fase 1 — Proyecto Laravel y esquema de datos

**Objetivo.** Base de datos completa y poblada, sin lógica todavía.

**Tareas.**
- Crear el proyecto Laravel 12 y configurar `.env` (con `.env.example` versionado y `.env` ignorado).
- Reemplazar Tailwind por Bootstrap según la guía de la cátedra.
- Escribir las 17 migraciones de la Etapa 1, en orden de dependencia.
- Declarar los `ENUM` con **todos** sus valores, incluidos los que sólo usarán etapas siguientes.
- Seeders: roles, permisos, asignación de permisos por rol, y un usuario administrador.
- Factories y seeders de datos ficticios para la demostración (la consigna los exige).

**Entregable.** `php artisan migrate:fresh --seed` levanta una base funcional con datos de prueba.

**Criterio de terminado.** Las migraciones corren de cero sin errores y el rollback también funciona.

**Riesgo.** Es la fase donde un error cuesta más caro. Un `ENUM` incompleto obliga a reescribir la tabla más adelante. Revisar contra el modelo de datos antes de dar por cerrada la fase.

---

### Fase 2 — Autenticación, roles y permisos

**Objetivo.** Saber quién entra y qué puede hacer. Es la base de todo lo demás y el punto donde se corrigen tres de los cinco hallazgos críticos.

**Tareas.**
- Login y logout con sesión. Sin registro público: los usuarios los crea un administrador.
- Modelo `User` con `$hidden` sobre `password`.
- Resolución de permisos vía `Gate`, contra la tabla `permisos`, con caché por rol.
- Middleware de autorización en las rutas y directiva Blade para ocultar acciones sin permiso.
- Módulo de administración de roles y permisos, con protección de los roles de sistema.
- Verificación de `activo` en cada request, no sólo en el login.

**Entregable.** Un usuario sin permiso recibe 403 y no ve el botón.

**Criterio de terminado.** Test que verifica que cada rol accede exactamente a lo que le corresponde, y que una acción sin permiso definido se deniega.

**Hallazgos que cierra.** C-2 (fallback permisivo), A-5 (el rol viajaba dentro del token y no se revalidaba), y parcialmente C-1.

---

### Fase 3 — Catálogo: categorías, marcas, productos

**Objetivo.** Migrar los dos módulos más simples y **fijar el patrón** que replican los demás.

**Tareas.**
- Vertical completa de un módulo: migración, modelo, Form Request, servicio, controlador, vistas Blade, rutas, tests.
- Categorías con jerarquía (`parent_id`).
- Marcas (módulo nuevo).
- Productos con los campos nuevos: dos precios, alícuota de IVA, costo promedio, stock mínimo, cantidad de reposición, imágenes.
- **Paginación en todos los listados** y filtros que efectivamente filtren.

**Entregable.** Tres CRUD funcionando, con el patrón documentado para reusar.

**Criterio de terminado.** Los filtros de cada listado tienen un test que verifica que devuelven lo que deben.

**Hallazgos que cierra.** A-24 (filtros rotos en tres módulos), A-25 (sin paginación), M-31 (validación que vaciaba en vez de rechazar), A-17 (precio en `float`).

---

### Fase 4 — Usuarios, clientes y empleados

**Objetivo.** Módulo de usuarios corregido, más los dos satélites.

**Tareas.**
- CRUD de usuarios que **nunca** expone el hash de contraseña.
- Alta de usuario que crea la fila satélite correspondiente en la misma transacción, según el ámbito del rol.
- **Cambio de rol como acción separada**, restringida, con prohibición de autoasignación.
- Cambio de contraseña propia.
- Módulo de clientes con datos fiscales y direcciones.
- Módulo de empleados integrado a la pantalla de usuarios.

**Entregable.** Gestión de personas completa.

**Criterio de terminado.** Test que confirma que un usuario con permiso de edición no puede elevarse a administrador, y que ninguna respuesta contiene el campo de contraseña.

**Hallazgos que cierra.** C-1 (hashes expuestos), C-3 (escalada de privilegios).

---

### Fase 5 — Proveedores, compras y stock

**Objetivo.** El módulo más nuevo, sin equivalente en el sistema original.

**Tareas.**
- CRUD de proveedores con el canal de pedido.
- Servicio de stock con kardex: toda variación pasa por él y deja movimiento.
- Órdenes de compra con líneas, aprobación y recepción parcial.
- Recepción que ingresa stock y recalcula el costo promedio ponderado.
- Tarea programada que detecta stock bajo el mínimo y genera órdenes en borrador.
- Envío de la orden por mail con PDF, según el canal del proveedor.

**Entregable.** Ciclo completo de reposición, desde la detección hasta la recepción.

**Criterio de terminado.** Test del ciclo entero, y test que verifica que no se genera una orden duplicada si ya hay una abierta para el mismo producto.

**Hallazgos que cierra.** A-13 (stock sin trazabilidad).

---

### Fase 6 — Ventas y pagos

**Objetivo.** El módulo central, y el que más se reescribe.

**Tareas.**
- Presupuesto, venta y cambio de estado, con **máquina de estados explícita** que rechaza toda transición no declarada.
- Descuento de stock exactamente una vez, al pasar a `pagada`, con bloqueo de fila.
- Precios recalculados en el servidor y congelados en la línea, junto con la alícuota y el costo.
- Registro de pagos con **validación del saldo dentro de la transacción**, después de bloquear la venta.
- Tabla de pagos creada con las columnas de Mercado Pago desde ahora, aunque queden nulas.
- Anulación que repone stock, sin borrado físico.

**Entregable.** Módulo de ventas correcto, que es lo que el sistema original no llegó a tener.

**Criterio de terminado.** Tests que verifican que `presupuesto → pagada` descuenta stock, que hacerlo dos veces no lo descuenta dos veces, que una transición no declarada se rechaza, y que dos pagos concurrentes no exceden el saldo.

**Hallazgos que cierra.** C-9 (transiciones arbitrarias), C-10 (sobrepago por validación fuera de transacción), A-11, A-12, M-14, M-16.

---

### Fase 7 — Panel de métricas y exportación a PDF

**Objetivo.** Reemplazar el panel que contaba en el navegador, y recuperar la exportación a PDF del lado del servidor.

**Tareas.**
- Consultas de agregación en el servidor, una por métrica.
- Panel diferenciado por rol: el vendedor ve lo suyo, el administrativo ve el conjunto.
- Gráficos simples con Chart.js.
- Exportación a PDF de listados y del comprobante de venta o presupuesto, con Dompdf.

**Entregable.** Panel y exportación funcionando.

**Criterio de terminado.** Ninguna consulta del panel trae filas completas al servidor de aplicación; todas agregan en la base.

**Hallazgos que cierra.** A-26 (panel calculado en el cliente).

---

### Fase 8 — Cierre

**Objetivo.** Dejar todo presentable.

**Tareas.**
- Repaso de la tabla de trazabilidad: ningún componente en estado Pendiente sin justificación.
- Revisión de seguridad contra la lista de hallazgos de la auditoría.
- Verificación de que no hay credenciales en el repositorio, en ningún commit del historial.
- README completo: propósito, alcance, arquitectura, requisitos, instalación, configuración, ejecución.
- Resumen ejecutivo.
- Datos ficticios coherentes para la demostración.
- Ensayo de la presentación.
- Tag de la entrega.

---

## 4. Estrategia de Git

**Ramas.** `main` sólo recibe integraciones. Cada fase trabaja en su rama:

```
main
 ├── feat/fase-1-esquema-datos
 ├── feat/fase-2-auth-permisos
 ├── feat/fase-3-catalogo
 └── ...
```

La integración es por pull request, aunque trabajes solo. El profesor va a ser colaborador: el PR es donde puede leer qué cambió y por qué, sin reconstruirlo del historial.

**Commits.** Conventional Commits:

```
feat(ventas): agrega maquina de estados con transiciones explicitas
fix(usuarios): oculta el hash de contrasena en las respuestas
test(stock): cubre el descuento concurrente con bloqueo de fila
docs(readme): documenta instalacion y configuracion
refactor(catalogo): extrae filtros a scopes de Eloquent
chore(deps): agrega barryvdh/laravel-dompdf
```

Un commit por cambio con sentido propio. Ni un commit gigante por fase, ni veinte commits de "avance".

**Tags.** `v0-original` en la línea base y `v1.0-etapa1` en la entrega.

**Nunca versionar.** `.env`, `/vendor`, `/node_modules`, `/storage/*.key`, ni ningún archivo con credenciales.

---

## 5. Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| `ENUM` incompleto en la Fase 1 | Reescribir tablas en la Etapa 2 | Declarar todos los valores desde el inicio y revisar contra el modelo antes de cerrar la fase |
| El módulo de ventas se lleva más tiempo del previsto | Llegar sin margen a la entrega | Es la Fase 6, con las 3, 4 y 5 ya cerradas; si aprieta, el panel de la Fase 7 se recorta antes que las ventas |
| Quitar Tailwind rompe el layout del starter kit | Reescribir vistas a mitad de camino | Resolverlo en la Fase 1, antes de escribir una sola vista propia |
| Los tests quedan para el final | Incumplir el requisito de pruebas | Cada fase entrega sus tests; una fase sin tests no está terminada |
| Un secreto queda en el historial de Git | Incumplimiento explícito de la consigna | Se limpia en la Fase 0, antes del primer commit |

---

## 6. Criterios de aprobación de la etapa

Los requisitos de la consigna y dónde se cumplen:

| Requisito | Dónde |
|---|---|
| Resumen ejecutivo | Fase 8 |
| Modelo de datos actualizado | Ya entregado; migraciones en la Fase 1 |
| Repositorio en GitHub | Fase 0 |
| Backend Laravel funcional con los módulos nuevos | Fases 2 a 7 |
| Front integrado | Blade en cada fase; reemplaza a Angular |
| Documentación y README | Continuo, cierra en la Fase 8 |
| Presentación y defensa | Fase 8 |
| **Promoción:** vistas renderizadas desde Laravel con Blade | Todas las fases desde la 2 |
