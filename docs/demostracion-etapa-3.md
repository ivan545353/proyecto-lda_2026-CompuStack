# Guion de la demostración — Etapa 3

El recorrido de la defensa, en el orden de lo que la consigna pide en la presentación (plan §2). Cada punto dice
**qué se hace**, **qué se ve** y **qué demuestra**. Se corre con los datos de los seeders, **sin tocar código**. La API
se demuestra sin las interfaces con **Postman** (`docs/demostracion/compustack-etapa-3.postman_collection.json`). Las
peticiones de cada tramo siguen en `docs/demostracion/api-etapa-3.http`, para probar un endpoint suelto. Nada de lo que
está acá es un secreto: la contraseña del administrador y el token de Mercado Pago viven en `.env`.

## 0. Antes de empezar

| Qué | Cómo |
| --- | --- |
| Base con los datos de demostración | `php artisan db:seed --class=TiendaDemoSeeder` (los destacados y las fechas de alta). Nunca `migrate:fresh`: la base tiene datos de volumen |
| Servidor alcanzable por el teléfono | `php artisan serve --host=0.0.0.0`. En la app, *Perfil → Servidor*: la IP de la máquina en la red (`http://192.168.x.x:8000`), o `http://10.0.2.2:8000` en el emulador |
| La administración | `npm run build` (o `npm run dev`), y el navegador en `http://localhost:8000/login` |
| **La tarea programada corriendo** | `php artisan schedule:work` en otra terminal: sin ella, una reserva vencida no se suelta |
| Fotos | `php artisan storage:link` |
| Mercado Pago de prueba | `MERCADOPAGO_ACCESS_TOKEN` con el token **de prueba** del vendedor de prueba; `TIENDA_SIMULADOR=false` (D-23) |
| Postman | *Import* → `compustack-etapa-3.postman_collection.json`. En *Variables*, `claveAdministrador` con la `ADMIN_PASSWORD` de tu `.env` **sólo en el valor actual** (no se exporta ni se sincroniza): nunca en el repositorio. Si el servidor no está en `localhost:8000`, cambiar `baseUrl`. *View → Show Postman Console* abierta, para `urlPago` |
| El cliente y el producto | «Estudio Contable Austral S.R.L.» (`PersonasDemoSeeder`) y el G505: si no están, cambiar `clienteBusqueda` y `productoBusqueda` en las variables |
| Dos navegadores | Uno con la administración del Administrativo y otro (o una ventana privada) con la del Administrador, para el punto 4 |
| Las herramientas del navegador | La pestaña *Red* abierta, filtrada por `api/v1` (puntos 3 y 7) |
| La app con el registro a la vista | `flutter run` en modo depuración: la consola muestra cada pedido, con `Authorization` enmascarado |
| Catálogo limpio | Ningún producto de prueba activo que salga primero por nombre |

**Cuentas** (contraseña `demo1234`, de `PersonasDemoSeeder`; la del administrador es la de `ADMIN_PASSWORD`):

| Cuenta | Rol | Para qué |
| --- | --- | --- |
| `cliente@sistema.local` | Cliente | Entra en la app, compra y recibe el 403 (puntos 2 a 6) |
| `vendedor@sistema.local` | Vendedor | Emite el presupuesto y no puede cobrarlo (punto 2) |
| `cajero@sistema.local` | Cajero | Cobra, entrega e imprime el comprobante (punto 2) |
| `administrativo@sistema.local` | Administrativo | Ve el costo, el stock y el kardex; entra en la administración y modifica el producto (puntos 2 a 5) |
| `admin@sistema.local` | Administrador | Quita y devuelve un permiso desde *Roles* (puntos 2 y 4) |

**Producto** de la demostración: **Mouse Logitech G505 inalámbrico** (`G505`), destacado, con foto.

**Tarjeta de prueba** de Mercado Pago: Visa `4509 9535 6623 3704`, código `123`, vencimiento `11/30`, DNI `12345678`.
El **titular** decide el resultado: `APRO` aprueba, `OTHE` rechaza.

## 1. La arquitectura final y qué se reutilizó

- Un dibujo de tres cajas: la **app Flutter** y la **administración web** son dos clientes de **`/api/v1`**, con el
  mismo `Authorization: Bearer`, los mismos permisos y los mismos códigos de error. Detrás de cada endpoint, el
  **servicio de siempre**: `StockService`, `PagoService`, `CarritoService`, `PrecioService`, `VentaService`… Los
  controladores de la API sólo traducen: validan con su Form Request, llaman al servicio y devuelven un Resource.
- Lo que se reutilizó: los servicios de la Etapa 1 y los endpoints de la tienda de la Etapa 2, sin cambiar ninguna forma
  que la app lee (regla 40: `/api/v1` se extiende, no se reescribe). La app anda con la **r20** del contrato sin cambiar
  sus modelos.
- Lo que cambió: la administración dejó de escribir por formularios de Blade. Cada pantalla es un shell sin datos de
  negocio (regla 37) que pide todo por la API a través de un **módulo central** (`resources/js/api/cliente.js`), que
  trata cada error una sola vez.
- El relevamiento: **108 operaciones**, todas en «Finalizado» (`relevamiento-etapa-3.md` §12).

**Demuestra**: requisitos 2 y 3. Una operación tiene **un solo camino** (regla 35): no hay reglas duplicadas por
cliente ni llamadas HTTP de Laravel a su propia API.

## 2. La API sin las interfaces, en Postman

Postman, con `docs/demostracion/compustack-etapa-3.postman_collection.json` importado. Cada pedido tiene su test, así que
al enviarlo la pestaña *Test Results* dice en verde qué se comprobó: el código, el estado y las claves que **no** viajan.
Se envían en orden, uno por uno, explicando cada respuesta. Las variables (`ventaId`, `saldo`, `urlDocumento`…) las
llenan los scripts.

**Carpeta 0 — Sesiones.** Las cinco cuentas inician sesión por el **mismo** `POST /sesion`, cada una con su credencial.
`GET /yo` trae el rol y los permisos; sin credencial, 401 `sin_sesion` con el sobre `{ mensaje, codigo, errores }`.

**Carpeta 1 — La venta completa de mostrador** (lo central):

1. El vendedor busca el G505 en el listado de gestión: **no vienen `costo_promedio` ni `stock`**, porque no tiene
   `precio.ver_costo` ni `stock.ver`. El mismo producto pedido por el administrativo los trae: un recurso, representación
   por permiso (regla 36).
2. Busca al cliente («Estudio Contable») y **emite el presupuesto**: 201. El precio no viajó en el pedido, lo puso el
   servidor. Es la operación **permitida** (`venta.crear`).
3. **El vendedor intenta cobrarlo: 403 `sin_permiso`.** Es la operación **rechazada**: `venta.cobrar` es de la caja.
4. La cajera lo cobra por el saldo exacto: 201, `pagada`, saldo `0.00`. El stock sale acá (regla 5). Después lo
   **entrega**: sin cuerpo, todo lo pendiente.
5. El comprobante: la API devuelve una **URL firmada** de cinco minutos (D-22). Abierta sin credencial, Postman muestra
   el PDF. Con una letra de más en la firma, 403.
6. El kardex, con el administrativo: el último movimiento del G505 es la salida de esta venta, `-1`, con el stock
   anterior y el resultante. `StockService` es la única puerta al stock (regla 4).

**Carpeta 2 — La compra de la tienda, sin la app** (la funcionalidad breve). Vaciar el carrito → el catálogo de la tienda
(vienen `precios` y `disponibilidad`, **nunca** `stock`) → agregar → checkout (reserva 20 minutos) → confirmar el pedido
(`pendiente_pago`) → **iniciar el pago** con Mercado Pago. Acá se para: `urlPago` (también en la consola de Postman) se
abre en el navegador, y se paga con el comprador de prueba, titular `APRO`. De vuelta en Postman, **consultar el intento**:
antes de responder, el servidor le pregunta a Mercado Pago, y dice `aprobado`. El pedido queda `pagada`, y el
administrativo ve la misma venta en `/ventas/{id}` con canal `online`: un modelo, dos alcances.

**El OpenAPI** (la promoción): `docs/openapi.yaml` tiene las 142 operaciones, cada una con su seguridad, su permiso
(`x-permiso`) y la sección de `api.md` que la explica. Postman también lo importa (*Import* → el archivo) como una
colección con todas las rutas: comprobarlo en el primer ensayo. `Api/OpenApiTest` lo compara con el enrutador en las dos
direcciones, así que el documento no puede afirmar una ruta o un permiso que el servidor no tiene.

**Demuestra**: requisitos 4, 5, 6 y 7, y la promoción. Lo que la colección afirma lo fija `Api/GuionEtapa3Test`, que
recorre las mismas secuencias (en el test, el simulador reemplaza al checkout de Mercado Pago).

## 3. Iniciar sesión desde Flutter y desde la administración

- **En la app**: *Perfil → Iniciar sesión* con `cliente@sistema.local`. En la consola: `POST /api/v1/sesion`, y después
  cada pedido con `Authorization: Bearer ****`.
- **En la administración**: `http://localhost:8000/login` con `administrativo@sistema.local`. En *Red*: el **mismo**
  `POST /api/v1/sesion`. La credencial la guarda el módulo central, que la manda como `Bearer` en cada pedido a la API: las
  pantallas nunca la ven.
- Cada inicio emite **su propia** credencial: cerrar sesión en la web (`DELETE /sesion`) no cierra la de la app.

**Demuestra**: requisito 6. La autenticación es una sola para los dos clientes; sólo `CredencialService` sabe que
detrás está Sanctum.

## 4. Una operación permitida y otra rechazada por falta de permisos, desde las dos interfaces

**Desde la administración.**

- Con el Administrativo: *Productos* → el G505 → *Editar*. Cambiar la descripción y **Guardar**: «Producto … actualizado.»
  Es la operación **permitida** (`producto.editar`).
- **Sin cerrar ese formulario**: en el otro navegador, con el Administrador, *Roles* → *Administrativo* → destildar
  **Editar producto** → *Guardar*.
- De vuelta en el formulario del Administrativo, cambiar algo y **Guardar**. Arriba del formulario: «No tenés permiso
  para hacer esto.». Es el **403** del servidor, mostrado por el módulo central. La pantalla ya estaba abierta y el
  botón seguía ahí: lo que decidió fue el permiso del pedido, no la interfaz.
- Devolverle **Editar producto** al Administrativo.

**Desde la app.**

- Con el Administrador: *Roles* → *Cliente* → destildar **Comprar tienda** → *Guardar*.
- En la app, con la sesión de `cliente@sistema.local` abierta: abrir el **carrito** (o arrastrar para actualizar). Aparece
  «No tenés permiso», con el mensaje del servidor. Mis pedidos sigue andando: es otro permiso.
- Devolverle **Comprar tienda** al Cliente y volver al carrito: anda de nuevo, **con la misma credencial**.

**Demuestra**: requisito 7. La autorización se resuelve en Laravel en **cada** pedido y un cambio vale en el pedido
siguiente, sin volver a iniciar sesión. Los permisos que viajan en `/yo` son ayuda de interfaz, no autoridad. Lo mismo,
sin interfaces, es la **carpeta 3** de Postman (si el tiempo no da para las dos interfaces, es el plan B), y lo fijan `Api/GuionEtapa3Test` y `CredencialWebTest`
(«quitado un permiso, el pedido siguiente lo refleja sin volver a ingresar»).

## 5. Modificar un producto desde la administración y consultarlo desde Flutter

- En la app: *Inicio* o la búsqueda «G505» → la ficha. Anotar el nombre y el precio.
- En la administración, con el Administrativo: *Productos* → el G505 → *Editar*. Cambiar el **nombre** (por ejemplo,
  sumarle «— edición especial») y **Guardar**.
- En la app: volver al catálogo y **arrastrar para actualizar**, o volver a abrir la ficha: el nombre nuevo.
- En la administración: devolverle el nombre de antes.

**Demuestra**: requisitos 4 y 8. El producto es **un recurso para los dos clientes** (regla 36): `GET /productos/{id}`
es el mismo endpoint, y lo que cambia es la representación según los permisos de quien pregunta. La administración ve
costo y stock; la tienda, precio y disponibilidad por niveles.

## 6. Un recorrido de compra completo

- **En la app**, con `cliente@sistema.local`: el G505 al carrito → **Continuar** (el servidor revalida y reserva por 20
  minutos) → retiro en el local, **Mercado Pago** → *Confirmar* («Pedido V-… registrado») → **Pagar con Mercado Pago** →
  comprador de prueba, tarjeta de prueba, titular **`APRO`** → volver a la app: la pantalla **consulta al servidor**, y el
  servidor le pregunta a Mercado Pago. Recién entonces dice «Pagado».
- **En la administración**, con el Administrativo: *Ventas*, filtro **Canal: Tienda online**: el pedido es una venta
  más. *Stock*: su salida por venta, igual que una de mostrador.
- **Sin la app**, la misma compra es la **carpeta 2** de Postman (punto 2). Si el teléfono falla en vivo, es el plan B.

**Demuestra**: requisitos 4 y 9. El pedido de la app es una venta del sistema de gestión; el stock salió al cobrar, no
al confirmar (regla 5).

## 7. Las operaciones de negocio pasan por la API

- `php artisan route:list --except-vendor --except-path=api --method=POST`: **ninguna** ruta web escribe. Sólo queda
  la redirección de `/`. `routes/web.php` entrega páginas y descargas firmadas.
- En la administración, con *Red* abierta: guardar un cliente, cobrar una venta o filtrar un listado. Cada acción es un
  `fetch` a `/api/v1/…` con `Bearer`. Las páginas llegan sin datos de negocio: los datos vienen después, por la API.
- Un PDF (el presupuesto de una venta, *Imprimir*): la API devuelve una **URL firmada** de cinco minutos que no lleva la
  credencial (D-22). Al abrirla se vuelve a exigir el permiso.

**Demuestra**: requisitos 3, 5 y 8. Lo fijan los tests de «el shell no trae datos de negocio» de cada pantalla y el
dataset de rutas retiradas (`pruebas-etapa-3.md`).

## Recorrido manual del JS del tramo I (antes de los ensayos)

El JS de Usuarios y accesos no tiene test automático (`estado-etapa-3.md`). Se recorre a mano una vez, y si algo falla
se anota en la tabla de ensayos.

| Qué | Qué tiene que pasar |
| --- | --- |
| El menú de la cuenta → *Cambiar mi contraseña*, con el Administrativo, y seguir navegando | Cambiar la contraseña **rota la credencial** (HL-132): el módulo central guarda la nueva y el siguiente clic anda sin volver a ingresar. Devolverla a `demo1234` |
| *Personal* → una cuenta → *Editar* → **Generar enlace de restablecimiento** | Aparece el enlace de un solo uso. Abierto en una ventana privada, fijar la contraseña: «Tu contraseña quedó configurada. Ya podés iniciar sesión.» Repetir el mismo enlace: no sirve |
| *Clientes* → uno con correo y sin cuenta → **Darle acceso** | El enlace de la tienda: al fijar la contraseña, «Ya podés entrar desde la app…». Entrar en la app con ese correo |
| *Roles* → uno propio → las casillas por módulo | Tildar una acción tilda «ver» del módulo; destildar «ver» destilda el módulo entero; la casilla del módulo y el contador acompañan. Al guardar, el rol vuelve con los mismos permisos |
| *Roles* → el rol propio | Guardar un cambio en sus permisos da 409: «No podés modificar los permisos de tu propio rol. Pedíselo a otro administrador.» |

## Los dos ensayos

Se hacen de punta a punta, con el reloj, **sin tocar código**. Si algo hay que cambiar, se anota acá y se vuelve a
ensayar.

| Ensayo | Fecha | Duración | Qué falló | Qué se cambió |
| --- | --- | --- | --- | --- |
| 1 | | | | |
| 2 | | | | |

## Para las preguntas

| Si preguntan por… | Dónde está |
| --- | --- |
| La lectura de la consigna y qué tramo cierra cada requisito | `plan-etapa-3.md` §2 |
| El relevamiento y la matriz de migración | `relevamiento-etapa-3.md` |
| El contrato: qué se conservó y qué se extendió | `plan-etapa-3.md` §6, `api.md` y `openapi.yaml` |
| Las decisiones (D-n) y los hallazgos (HL-n) | `plan-etapa-3.md` §11 y §3 |
| La evolución desde la Etapa 2, componente por componente | `trazabilidad.md` §16 |
| La arquitectura de la app | `ARQUITECTURA.md` de la app |
| Las pruebas | `pruebas-etapa-3.md` |
| Por qué la app no cambió de forma | Regla 40 y `README.md` de la app (anda con la r20) |

## Si algo falla en vivo

- **Mercado Pago no responde o no hay red**: el intento queda «Esperando la confirmación» y la app lo dice. El sistema
  no afirma lo que no puede verificar. Se muestra el pedido ya pagado en el ensayo y se sigue con el punto 7.
- **El teléfono no alcanza el servidor**: revisar la IP en *Perfil → Servidor* y que `serve` esté con `--host=0.0.0.0`.
  Si no, el emulador.
- **La administración pide ingresar a cada rato**: la credencial venció o se revocó (por ejemplo, al cambiar la
  contraseña en otra pestaña). Ingresar de nuevo; es lo que tiene que pasar.
- **Un permiso quedó sacado después del punto 2 o del 4**: *Roles* con el Administrador, o Postman, carpeta 3,
  «Devolverle el permiso». Como último recurso, `php artisan db:seed --class=RolPermisoSeeder`, que es idempotente.
- **Postman da 401 en una carpeta**: las credenciales se guardan al correr la carpeta 0; si se reinició Postman o
  venció alguna, volver a correrla.
- **Un pedido de Postman falla a mitad de la venta** (por ejemplo, el cobro se envió dos veces): empezar la carpeta 1
  de nuevo desde «emite el presupuesto», que crea una venta nueva.
