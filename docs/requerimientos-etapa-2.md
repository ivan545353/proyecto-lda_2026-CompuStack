# Etapa 2 — Requerimientos de la aplicación móvil

Versión 2, al cierre de la Etapa 1 · @Gordo

**Reemplaza a la versión del 22 de octubre de 2026**, que se escribió antes de tener la
consigna y sin conocer el estado final de la Fase 7.5. Lo que cambió está en la primera
sección. Si este documento y la consigna dicen cosas distintas, manda la consigna
(`docs/Proyecto - Etapa 02.pdf`); si dicen cosas distintas del código, manda el código.

## Qué cambió respecto de la versión anterior

| Tema | Versión 1 | Versión 2 |
| --- | --- | --- |
| Capa del backend | Un puente JSON mínimo, sin versionar, con arreglos armados a mano | **Una API con forma de API desde el primer día**: `/api/v1`, API Resources, contrato único. La Etapa 3 probablemente se fusione con ésta, y lo provisorio sale caro |
| Pagos | La venta queda pendiente y se cobra en el mostrador; Mercado Pago a la Etapa 3 | **Flujo de pago completo**, que la consigna exige: simulador y Mercado Pago en modo de prueba, intentos registrados, estados pendiente, aprobado y rechazado, reintento sin duplicar |
| Precio de la venta online | No estaba definido | **Contado** para efectivo o transferencia; **lista** para Mercado Pago |
| Carrito | Anónimo, con fusión al iniciar sesión | **Sólo con sesión**: la consigna hace exclusivo de quien inició sesión el «agregar al carrito» |
| Seguimiento | Línea de tiempo del envío con su tabla `envios` | **Estado del pedido y estado del pago**, por separado. El módulo de envíos es Etapa 4 |
| Ingreso con Google, banners, vales, direcciones, repetir compra, descargar comprobante, caché sin red | Entraban | **Etapa 4**: la consigna no los pide |
| Filtro por marca, rango de precio, más vendidos | Entraban | **Etapa 4**: la consigna pide categoría, búsqueda por nombre y ordenamiento |
| Facturación con ARCA | Tablas creadas vacías | **Etapa 4**: las tablas no se crean |
| Novedades, idempotencia, revalidación de precios, vaciar el carrito, orden por fecha de incorporación, contrato documentado, seis estados de interfaz, riesgo de seguridad | No estaban | **Entran**: los pide la consigna |
| Disponibilidad | Sólo con sesión | **También para el visitante**: la consigna la pone en la ficha sin restricción |
| Estado de la Fase 7.5 | «Todavía no está terminada» | **Cerrada**, verificada contra el código (§2) |

## 1. Alcance

La Etapa 2 construye **la tienda móvil**: el catálogo público, la cuenta del cliente, el
carrito, la confirmación del pedido, el pago y la consulta de compras. La escribe en
Flutter y la conecta contra el backend Laravel de la Etapa 1, que corre en la misma red.

Es la segunda etapa del proyecto integrador: **migración del backend → aplicación móvil →
API e integración**. La consigna pide que cada etapa se presente, se demuestre y **se
justifique**: no alcanza con entregar una aplicación que funcione, hay que explicar las
decisiones de arquitectura, implementación, prueba y seguridad.

### Qué entra

| Área | Qué incluye |
| --- | --- |
| **Catálogo público** | Listado con nombre, imagen y descripción breve; navegación y filtro por categoría (con sus subcategorías); búsqueda por nombre; ordenamiento por nombre y por fecha de incorporación; paginado; **novedades** y **destacados**. Ficha con galería, descripción completa, categoría, condición de novedad o destacado y disponibilidad. **Sin precios** |
| **Cuenta** | Registro con los datos mínimos, inicio y cierre de sesión, consulta de los datos básicos, manejo de la sesión vencida |
| **Precios** | Sólo con la sesión iniciada: los dos, contado y lista, cada uno con su rótulo. Orden por precio ascendente y descendente |
| **Carrito** | Agregar, consultar, modificar cantidades, quitar, **vaciar**; precios unitarios, subtotales y total calculados por el servidor; se conserva entre usos |
| **Pedido** | Revisar productos y cantidades, verificar los datos del cliente, elegir retiro o envío, ver el importe definitivo, elegir el medio de pago y confirmar. Revalidación y confirmación idempotente |
| **Pago** | Contado (pendiente hasta el mostrador) o pasarela (simulador y Mercado Pago de prueba), con intentos registrados y reintento |
| **Consulta de compras** | Listado y detalle de los pedidos propios, con el estado del pedido y el del pago |
| **Backend** | La capa de API, los módulos de carrito y de pagos, y las extensiones de los servicios de la Etapa 1 |

### Qué no entra, y por qué

| Fuera de alcance | Por qué | Va a |
| --- | --- | --- |
| **Facturación con CAE** | Necesita certificados de ARCA y homologación, y la consigna no la pide | Etapa 4 |
| **Ingreso con Google** | La consigna pide reutilizar las cuentas del backend | Etapa 4 |
| **Módulo de envíos**: tabla `envios`, costo calculado, seguimiento | Entra sólo el mínimo de entrega (§6) | Etapa 4 |
| **Banners y vales** | No figuran en la consigna | Etapa 4 |
| **Carrito anónimo** | La consigna exige sesión para el carrito | Etapa 4 |
| **Varias direcciones, editar el perfil** | La consigna pide indicar los datos de entrega y consultar los datos básicos | Etapa 4 |
| **Filtro por marca, rango de precio, más vendidos** | No figuran | Etapa 4 |
| **Repetir una compra, descargar el comprobante, notificaciones push, caché sin red** | No figuran | Etapa 4 |
| **Que la web consuma la misma API** | Hoy la web es Blade y sigue así | Etapa 3 |
| **Publicación en las tiendas** | La app se demuestra en emulador o en un teléfono en la misma red | — |

### Una restricción que ordena todo lo demás

**Las consultas son locales.** El backend corre en la máquina de desarrollo y la app lo
consulta por la red local. No hay despliegue, ni dominio, ni certificado. Eso simplifica
mucho, y trae cuatro consecuencias concretas:
- la dirección del servidor es **configurable**;
- el emulador de Android no alcanza `localhost`;
- el tráfico es HTTP en claro, que Android bloquea por omisión desde la versión 9;
- **ningún aviso de un servicio externo llega al backend**: el webhook de Mercado Pago no
  tiene a dónde llegar (§5).

## 2. De qué se cuelga

**La Etapa 1 dejó la tienda prevista en el modelo de datos**, y la verificación del código
al cerrarla confirma la mayor parte. Pero también encontró que **los servicios no alcanzan
tal como están**: la Etapa 2 los extiende, no sólo los llama.

### Lo que ya funciona y se reusa

| Pieza | Qué aporta a la app |
| --- | --- |
| `ventas.canal` (`mostrador`, `online`) | Una venta de la app entra con `canal = 'online'` |
| `ventas.modo_entrega`, `costo_envio` | Retiro o envío ya son una columna |
| `ventas.usuario_id` nullable | La venta online no tiene vendedor |
| La máquina de estados de la venta | **Once** estados (el tramo H agregó `entregada_parcial`). `pendiente_pago → pagada | cancelada` ya está declarada |
| Dos precios por producto | `precio_contado` (efectivo, transferencia) y `precio_lista` («tarjeta y cuotas», decisión 7 de la Fase 7.5) |
| `VentaService::recotizar()` | Ya revalida los precios de una venta contra los vigentes |
| `PagoService::cobrar()` | Bloqueo antes de leer, saldo exacto, e **idempotencia por `mp_payment_id`** |
| `pagos` con `mp_payment_id` (UNIQUE), `mp_status`, `comision`, `neto_acreditado`, `cuotas` | Nulas hasta que llegue Mercado Pago |
| `productos.stock_reservado` | Existe desde el inicio para los checkouts |
| `productos.destacado`, `created_at` | El dato del destacado y el de la novedad |
| El rol **Cliente** | Sembrado, de ámbito `tienda`, sin permisos |
| `UsuarioService::crear()` | Ya crea `user` y `cliente` para ámbito tienda, en una transacción |
| La frontera de gestión | **Doble**: el login web rechaza las cuentas de tienda, y todo el grupo de gestión lleva el middleware `gestion`, además del `Gate` con denegación por omisión |
| Cobro y entrega en la web | Las pantallas de la Fase 6 y del tramo H sirven igual para un pedido de la app |

### Lo que hay que extender o crear

| Qué | Por qué |
| --- | --- |
| **Un alta de venta online en `VentaService`** | `crear()` exige un vendedor, nace en `presupuesto` y siempre cotiza a contado. La online nace en `pendiente_pago`, sin vendedor, y a la lista que corresponde al medio de pago |
| **El cobro sin cajero en `PagoService`** | `cobrar()` exige un usuario, que viaja hasta el kardex. Un pago aprobado por la pasarela no tiene cajero |
| **`StockService::reservar()` y `liberar()`**, y un `descontar` que consuma la reserva | Hoy no existen. Es la primera escritura de stock que llega desde fuera del mostrador |
| **La tarea que libera las reservas vencidas, cada minuto** | Una reserva de veinte minutos no se libera una vez por día |
| **Una tabla de intentos de pago** | `pagos` es el libro de la plata: el panel la suma y el saldo sale de ahí. Un intento rechazado no puede estar ahí |
| **Un valor para el simulador** en `pagos.metodo`, o una decisión equivalente | Registrar un pago simulado como `mercadopago` afirmaría algo falso |
| **`carritos` y `carrito_lineas`** | Diseñadas en `modelo-datos.md` §4, sin `session_token` |
| **Columnas en `ventas`** para la dirección de entrega congelada, la lista de precios y la idempotencia | El envío mínimo no usa la tabla `envios`; la confirmación necesita una clave contra el doble toque |
| **La capa de API** | Sanctum no está instalado y no existe `routes/api.php` |
| **El renderer de errores de `api/*`** | `bootstrap/app.php` traduce las reglas de negocio con una redirección |
| **Las URL absolutas de imágenes** | `APP_URL` es `localhost`, que el teléfono no alcanza |
| **La casilla «destacado»** en el formulario del producto | La columna existe y ningún formulario la ofrece |
| **El canal en el listado de ventas** de la web | El cajero tiene que encontrar el pedido de la app para cobrarlo en efectivo o entregarlo |

**Las dos últimas son las únicas ampliaciones de la web**, y las vuelve necesarias la
consigna: los destacados tienen que administrarse en el backend, y el pago de contado se
cobra en el mostrador.

### Lo que esto implica

Una venta hecha desde el teléfono y una hecha en el mostrador son **la misma fila en la
misma tabla**, con distinto `canal`. No hay un sistema de tienda paralelo: el panel cuenta
las dos, el kardex registra las dos, y el comprobante de la Fase 7 sirve para las dos. Es el
argumento más fuerte para la defensa.

Una consecuencia que conviene tener escrita: el **ranking de vendedores** excluye las
ventas sin vendedor, así que con ventas online su fila de totales deja de ser «todas las
ventas» y pasa a ser «las del mostrador». El panel general sí las suma.

## 3. Cómo consume la app el backend

**Hoy el backend devuelve Blade renderizado**, HTML que Flutter no puede leer, y la
consigna prohíbe depender de él. La Etapa 2 agrega una capa JSON, y como la Etapa 3
probablemente se fusione con ésta, **se construye con forma de API desde el primer día**.

| Se hace ahora | Por qué |
| --- | --- |
| Ruta versionada, `/api/v1/...` | Cuesta nada hoy y es irreversible cuando haya un cliente instalado |
| API Resources para serializar | Arreglos armados a mano en el controlador son exactamente lo que habría que tirar |
| Un contrato único de respuesta, error y paginado | La consigna lo exige documentado |
| Controladores que sólo traducen | Validan, llaman al servicio, devuelven |
| El contrato documentado a medida que se escribe | Es entregable de esta etapa |

**No se hace:** ampliar la web Blade (salvo las dos excepciones del §2), escribir endpoints
«de paso», ni poner lógica de negocio en un controlador de API.

### La regla que hace esto honesto

**La API no reimplementa ninguna regla de negocio.** Una venta desde el teléfono pasa por
`VentaService` y `StockService` igual que una del mostrador: mismo bloqueo pesimista, misma
máquina de estados, mismo kardex. **Si un servicio no alcanza, se extiende el servicio**,
nunca el controlador.

### Qué se consume

Un inventario inicial: el plan lo confirma o lo corrige.

| Método y ruta | Qué hace | Acceso |
| --- | --- | --- |
| `GET /api/v1/productos` | Catálogo paginado: categoría, texto, orden y sección (novedades, destacados). **Sin precio si no hay token válido** | Público; con sesión agrega precios y el orden por precio |
| `GET /api/v1/productos/{id}` | Ficha. Inexistente o inactivo, 404 | Público, ídem |
| `GET /api/v1/categorias` | Árbol de categorías activas | Público |
| `POST /api/v1/registro` | Crea `user` y `cliente`, y devuelve la credencial | Público |
| `POST /api/v1/sesion` | Verifica credenciales y devuelve la credencial | Público |
| `DELETE /api/v1/sesion` | Cierra la sesión y **revoca** la credencial | Con sesión |
| `GET /api/v1/perfil` | Datos básicos del usuario | Con sesión |
| `GET /api/v1/carrito` | El carrito con importes calculados por el servidor y avisos de disponibilidad | Con sesión |
| `POST`, `PATCH`, `DELETE /api/v1/carrito/lineas…` | Agregar, cambiar cantidad, quitar | Con sesión |
| `DELETE /api/v1/carrito` | Vaciar | Con sesión |
| `POST /api/v1/carrito/checkout` | Revalida y reserva | Con sesión |
| `POST /api/v1/pedidos` | Confirma: genera la venta. **Idempotente** | Con sesión |
| `GET /api/v1/pedidos`, `GET /api/v1/pedidos/{id}` | Listado y detalle, con estado del pedido y del pago. **Un pedido ajeno es 404** | Con sesión |
| `POST /api/v1/pedidos/{id}/pagos` | Inicia un intento por la pasarela elegida | Con sesión |
| `GET /api/v1/pedidos/{id}/pagos` | Los intentos y el estado del pago, verificado contra el proveedor | Con sesión |

Más las rutas del simulador, **identificadas como tales** en la ruta y en la pantalla, para
aprobar o rechazar un intento a voluntad.

### El contrato mínimo que hay que fijar primero

1. **Toda respuesta es JSON**, también los errores. Nunca una página de error de Laravel.
2. **Los errores tienen una forma única**: `{ "mensaje": "...", "errores": { "campo": ["..."] } }`,
   con el código correcto: 422 validación, 401 sin sesión o sesión vencida, 403 sin
   permiso, 404 inexistente o ajeno, 409 regla de negocio (`ReglaDeNegocioException` y sus
   hijas `StockInsuficienteException` y `TransicionInvalidaException`).
3. **Los listados paginan siempre**, con la misma envoltura:
   `{ "datos": [...], "pagina": 1, "ultima_pagina": 7, "total": 134 }`.
4. **El dinero viaja como cadena decimal**: `"60500.00"`, no `60500.0`. Es la misma razón
   por la que la base usa `decimal(12,2)`.
5. **Un campo protegido no viaja**: sin token, `precio_contado` y `precio_lista` **no
   están** en la respuesta. No están en null: no están.

### Las consecuencias de trabajar en red local

- **La dirección del servidor es configurable**: entra por `--dart-define`, y hay una
  pantalla de ajustes escondida para cambiarla en la demostración sin recompilar.
- **El emulador de Android no alcanza `localhost`**: la máquina anfitriona es `10.0.2.2`, y
  en un teléfono real es la IP de la máquina. **Las URL que arma el backend también**: las
  imágenes no pueden salir de `APP_URL=localhost`.
- **Android bloquea el tráfico HTTP en claro**: se habilita con una política de seguridad
  de red **acotada a la IP de desarrollo**, nunca general, y se quita para cualquier
  compilación de entrega. Se explica en la defensa.

## 4. Autenticación

Sin sesión no hay precios, ni carrito, ni compra, ni consulta: no hay Etapa 2. Lo que se
evita no es el login, es **atar la app a cómo se emite la credencial**.

| | Ahora | Si cambia el mecanismo | ¿Cambia la app? |
| --- | --- | --- | --- |
| Cómo viaja | `Authorization: Bearer <token>` | Igual | **No** |
| Dónde se guarda | Almacenamiento seguro del dispositivo | Igual | **No** |
| Qué hace la app con un 401 | Borra la credencial y manda al login | Igual | **No** |
| Qué contiene | Depende del mecanismo | Depende | Sólo `AuthService` |

**El mecanismo es una pregunta abierta: Sanctum o JWT.** La consigna no pide JWT, y Sanctum
viene con Laravel (`php artisan install:api`) y se hace en horas. JWT no tiene paquete
oficial, así que es una dependencia nueva; si la Etapa 3 se fusiona seguro, hacerlo dos
veces es trabajo perdido. El plan lo plantea, no lo resuelve solo.

**Cómo se aísla en la app.** Tres piezas, y sólo la primera sabe cómo se emite la
credencial:
1. `AuthService` habla con el servidor.
2. `TokenStorage` guarda y lee la credencial del almacenamiento seguro.
3. `AuthInterceptor` pega la cabecera y reacciona al 401.

El resto de la app **nunca ve un token**: le pregunta al `SessionRepository`.

**Lo que queda dicho desde ahora:**
- **El registro crea dos filas**, un `user` con rol Cliente y su `cliente`, por
  `UsuarioService`. `clientes.tipo_doc` y `condicion_iva` son obligatorios: o se piden, o
  se ponen valores por omisión explícitos (DNI, consumidor final).
- **Un cliente de mostrador que se registra** con el mismo correo o documento podría quedar
  duplicado. La vinculación no está implementada, y si entra en esta etapa lo decide el
  plan.
- **El rol Cliente no entra a la gestión.** Ya lo garantizan el login web y el middleware
  `gestion`. Hay que escribir un test que lo afirme sobre las rutas: es la frontera de
  seguridad que esta etapa agrega.
- **La contraseña no se guarda en el dispositivo**, nunca. Sólo la credencial.
- **La sesión vencida o inválida** devuelve 401, y la app vuelve a pedir el inicio de sesión
  conservando a dónde iba.

## 5. Pagos

### Dos caminos, y el precio de cada uno

| Medio | Precio | Qué pasa al confirmar |
| --- | --- | --- |
| **Efectivo o transferencia** | `precio_contado` | El pedido queda `pendiente_pago` con el pago pendiente, y se cobra en el mostrador con la pantalla de cobro que ya existe |
| **Mercado Pago** (o el simulador) | `precio_lista` | Se abre un intento contra la pasarela; el pedido pasa a `pagada` cuando el backend verifica la aprobación |

**El precio lo decide el medio de pago elegido, y lo calcula el servidor.** La app muestra
los dos precios desde el catálogo, con su rótulo, para que la diferencia no sea una
sorpresa en la confirmación.

### La abstracción de pasarela

El flujo se escribe contra una **interfaz con dos implementaciones**:
- **El simulador**, en el backend, **claramente identificado como tal** en la ruta, en la
  respuesta y en la pantalla. Aprueba o rechaza a voluntad, porque la defensa pide mostrar
  un pago rechazado. Es lo que garantiza la aprobación.
- **Mercado Pago en modo de prueba**, que es lo que suma la promoción. El backend crea una
  preferencia con los ítems y una `external_reference` que ata el pago al pedido, la app
  abre el `init_point`, y el usuario paga con una cuenta de prueba. Hacen falta dos cuentas
  de prueba (vendedor y comprador), y **cada una con su propia aplicación** en el panel de
  desarrolladores.

Si la integración real no llega o falla en la demostración, se cambia la implementación y
la etapa sigue aprobada. Del lado del pedido no cambia nada.

### Cómo se verifica, sin webhook

La consigna exige que *«el backend deberá verificar el resultado mediante el mecanismo
proporcionado por el proveedor»* y que *«el regreso del usuario a la aplicación no
constituirá, por sí solo, una confirmación de pago»*. El mecanismo natural es el webhook,
pero es una llamada **entrante**, y el backend corre en una red local sin dirección
pública: no llegaría nunca.

**La vía es consultarle a Mercado Pago** por el estado del pago, con la
`external_reference` o el `payment_id`: cumple la consigna al pie y sólo necesita salida a
internet. Un túnel (ngrok, cloudflared) habilitaría el webhook, pero suma una pieza frágil el
día de la defensa.

### Lo que el backend registra

- **Cada intento** queda en su tabla, asociado al pedido, con su estado: pendiente, aprobado
  o rechazado.
- **Sólo el intento aprobado** se convierte en una fila de `pagos`, por `PagoService`, con
  su `mp_payment_id`. El índice único hace que verificar dos veces no acredite dos veces.
- **El estado del pago se deriva de los intentos; el del pedido es `ventas.estado`.** Se
  consultan por separado.
- **Reintentar es otro intento sobre el mismo pedido**, nunca otro pedido.
- **Ningún dato de tarjeta pasa por el backend ni por la app**, y las claves privadas de la
  integración quedan en `config/`, leídas de `.env`.

### Lo que el plan tiene que definir

La consigna pide **definir cuándo se reserva o descuenta el stock y cómo se actúa ante una
cancelación o un pago fallido**. El punto de partida:
- el stock se **reserva** al pasar al checkout, por veinte minutos, y se **descuenta** al
  cobrar, una sola vez;
- una reserva vencida se libera sola.

Queda por decidir, con su porqué:
- si un rechazo mantiene la reserva hasta su vencimiento;
- si un pedido sin pago se cancela solo al vencer;
- qué pasa si el cliente cambia de medio de pago, porque cambia la lista de precios.

## 6. Requerimientos — visitante sin registrar

El visitante **ve el catálogo, las novedades, los destacados y la disponibilidad, y no ve
ningún precio.**

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **V-1** | Ver el catálogo paginado con imagen, nombre y descripción breve | Con 20.000 productos, ninguna página trae más que su tamaño, y las consultas no crecen con el catálogo (lo fija un test) |
| **V-2** | Filtrar por categoría, con sus subcategorías | Una categoría padre trae también sus hijas, igual que en la web (`Categoria::idsDescendientes()`) |
| **V-3** | Buscar por nombre | Con espera entre teclas, y cancelando el pedido anterior |
| **V-4** | Ordenar por nombre y por fecha de incorporación | — |
| **V-5** | Ver las **novedades** | Criterio documentado: fecha de alta dentro de una ventana, con tope, en `config/` |
| **V-6** | Ver los **destacados** | Los marca el administrativo en la web, con la casilla nueva del producto |
| **V-7** | Que el paginado se reinicie al cambiar filtros u ordenamiento | Y que los resultados sean siempre los de los criterios a la vista |
| **V-8** | Ver la ficha: galería, descripción completa, categoría, novedad o destacado, **disponibilidad** | Sin precio, sin selector de cantidad y sin «agregar» |
| **V-9** | Donde iría el precio, ver una invitación a iniciar sesión | Al volver del login, **sigue en el mismo producto** |
| **V-10** | Que un producto inexistente, inactivo o sin disponibilidad no ofrezca acciones imposibles | Inexistente o inactivo: 404 y su pantalla; sin disponibilidad: se ve, sin «agregar» |

### La regla del precio, donde se cumple de verdad

**El precio se oculta en el servidor, no en la app.** Si el endpoint lo devolviera y la
pantalla no lo dibujara, cualquiera con un proxy lo lee. Sin token válido, el campo **no
está** en la respuesta. Es la misma lógica que el permiso `precio.ver_costo` de la Fase 7.5:
lo que no se puede ver, no se manda.

### Novedad y destacado son dos cosas distintas

| | De dónde sale | Por qué |
| --- | --- | --- |
| **Destacado** | `productos.destacado`, marcado a mano | La consigna pide que sea información administrada en el backend |
| **Novedad** | `productos.created_at`, en una ventana con tope | La consigna pide definir y documentar el criterio. El seeder de volumen pone **la misma fecha a los 20.012 productos**, así que una ventana sola da todos o ninguno: hace falta el tope, y fechas repartidas en el seeder de demostración |

## 7. Requerimientos — cliente con sesión

### Cuenta

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **U-1** | Registrarse con los datos mínimos | Crea `user` con rol Cliente y su `cliente`, por `UsuarioService` |
| **U-2** | Iniciar sesión | La credencial queda en almacenamiento seguro |
| **U-3** | Seguir con la sesión entre usos | Al abrir la app no vuelve a pedir credenciales mientras la sesión sirva |
| **U-4** | Consultar sus datos básicos | — |
| **U-5** | Cerrar sesión | **Revoca** la credencial en el servidor, no sólo la borra del teléfono |
| **U-6** | Ante una sesión vencida, volver a iniciarla | La app lo dice con palabras y conserva a dónde iba |

### Catálogo con sesión

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **U-7** | Ver los dos precios en el listado y en la ficha | **Contado** (efectivo o transferencia) y **lista** (Mercado Pago), cada uno con su rótulo |
| **U-8** | Ordenar por precio, ascendente y descendente | Con qué precio se ordena lo decide el plan |
| **U-9** | Ver la disponibilidad sin el número exacto | «Disponible», «últimas unidades», «sin stock», con los umbrales en `config/` |

### Carrito

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **U-10** | Agregar un producto con cantidad | UNIQUE(carrito, producto): agregar dos veces suma cantidad |
| **U-11** | Consultar el carrito | Precios unitarios, subtotales y total **calculados por el servidor** |
| **U-12** | Cambiar cantidades y quitar renglones | — |
| **U-13** | **Vaciar** el carrito | Acción propia, con confirmación o con deshacer |
| **U-14** | Que el carrito se conserve al cerrar la app | Vive en el servidor, asociado a la cuenta. En el teléfono sólo queda la credencial, así que otro usuario del dispositivo no llega al carrito ajeno. **Se documenta**: la consigna lo pide |
| **U-15** | Enterarse si un precio o la disponibilidad cambiaron | Al entrar al carrito y al confirmar, con la opción de revisarlo antes de seguir |

**El carrito no guarda precios**: se recalculan contra `productos` al mostrar y al
confirmar. Un carrito de hace tres días no vende a precio viejo.

### Pedido

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **U-16** | Revisar productos y cantidades | — |
| **U-17** | Verificar los datos del cliente | — |
| **U-18** | Elegir retiro o envío | Retiro: costo cero. Envío: una dirección **congelada en el pedido** y un costo fijo o cero desde `config/`. Si cuesta más de media jornada, queda sólo retiro, y se declara |
| **U-19** | Elegir el medio de pago | Y ver el importe definitivo con la lista que le corresponde |
| **U-20** | Confirmar | El servidor **revalida** existencia, disponibilidad, cantidades y precios; si algo cambió, avisa y deja revisar. La venta entra con `canal = 'online'`, sin vendedor y en `pendiente_pago` |
| **U-21** | Que dos toques o un reintento no generen dos pedidos | La confirmación es **idempotente** |
| **U-22** | Que el stock quede reservado durante el checkout | Por `StockService`, con vencimiento, y liberado por la tarea de cada minuto |

### Pago

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **P-1** | Pagar por la pasarela | Simulador o Mercado Pago de prueba, a precio de lista |
| **P-2** | Conocer el resultado | Pendiente, aprobado o rechazado, **determinado y persistido por el backend** |
| **P-3** | Reintentar un pago rechazado | Otro intento sobre el mismo pedido, sin duplicarlo |
| **P-4** | Elegir contado | El pedido queda con el pago pendiente, y la app lo dice con todas las letras: el pedido está registrado y se paga en el local. **No finge que cobró** |

### Consulta de compras

| # | Requerimiento | Criterio de aceptación |
| --- | --- | --- |
| **H-1** | Ver sus pedidos, del más nuevo al más viejo | Con fecha, identificador, importe y estado |
| **H-2** | Ver el detalle de un pedido | Los renglones **congelados**, con el precio de ese día; el **estado del pedido** y el **estado del pago**, por separado |
| **H-3** | No poder ver un pedido ajeno | Verificado en Laravel aunque se pida el identificador directo: 404, que no revela si existe |

El nombre en pantalla no es el del ENUM: `pendiente_pago` se lee **«Esperando el pago»**. Es
la regla de la Etapa 1: lenguaje del negocio, nunca del programador.

## 8. Requerimientos no funcionales

### Escala

| # | Requerimiento | Cómo se cumple |
| --- | --- | --- |
| **NF-1** | El catálogo pagina siempre | Scroll infinito que pide la página siguiente. Nunca se traen 20.000 productos |
| **NF-2** | Las listas se construyen por demanda | `ListView.builder` |
| **NF-3** | Las imágenes se cachean y se piden al tamaño que se muestran | Las miniaturas, si se generan sin librería nueva; si no, es pregunta abierta |
| **NF-4** | La búsqueda espera antes de consultar | 300 ms entre teclas, y se cancela el pedido anterior |
| **NF-5** | La API no crece con los datos | **No se mide con `escala:medir`**, que la Etapa 1 cerró. Lo fijan tests, como en la Fase 7.5: las consultas de una página no crecen con las filas, y el peso de la respuesta no crece con el catálogo |

### Conexión y errores

| # | Requerimiento | Cómo se cumple |
| --- | --- | --- |
| **NF-6** | Distinguir «sin conexión» de «el servidor falló» | Dos mensajes y dos acciones |
| **NF-7** | Nada que escriba funciona sin red | No se encola una compra para después |
| **NF-8** | Todo error ofrece recuperación | Reintentar, revisar, volver a iniciar sesión |
| **NF-9** | No hay envíos repetidos | El botón se deshabilita mientras la operación está en curso, y el servidor es idempotente igual |

### Seguridad

| # | Requerimiento | Cómo se cumple |
| --- | --- | --- |
| **NF-10** | La credencial va en almacenamiento seguro | Llavero y Keystore, nunca preferencias compartidas |
| **NF-11** | Ningún secreto en el repositorio | `--dart-define` en la app; `.env` y `config/` en el backend |
| **NF-12** | El tráfico en claro sólo para la IP de desarrollo | Nunca general, y se quita para la entrega |
| **NF-13** | El servidor decide qué se ve | El precio se omite sin sesión; el rol Cliente no entra a la gestión; cada uno ve lo suyo |
| **NF-14** | Los registros no imprimen credenciales | El interceptor enmascara `Authorization` |
| **NF-15** | La app no decide reglas de negocio | Valida para responder rápido, y el servidor vuelve a validar todo |
| **NF-16** | **Un riesgo de seguridad detectado y su solución** | La defensa lo pide: se elige y se documenta durante el desarrollo, no la noche anterior |

### Interfaz y accesibilidad

| # | Requerimiento | Cómo se cumple |
| --- | --- | --- |
| **NF-17** | **Seis estados por pantalla** | Carga en curso, sin resultados, operación completada, error de validación, sin conexión o servidor caído, y **sesión vencida**. El vacío por filtro dice algo distinto del vacío por falta de datos |
| **NF-18** | Toda espera muestra un esqueleto | `skeletonizer` sobre el layout real |
| **NF-19** | Modo claro y oscuro | `FThemes.zinc` en sus dos variantes, atadas al sistema |
| **NF-20** | Ningún estado se comunica sólo con color | La insignia lleva texto |
| **NF-21** | Contraste y área táctil | 4.5:1 en texto, 48 px de lado mínimo |
| **NF-22** | Etiquetas semánticas en imágenes y botones | Para que el lector de pantalla sirva |
| **NF-23** | Distintos tamaños de pantalla | La consigna lo pone entre sus objetivos: la grilla y la ficha se acomodan |
| **NF-24** | Dinero en formato argentino | `$ 60.500,00` |

### Mantenimiento

| # | Requerimiento | Cómo se cumple |
| --- | --- | --- |
| **NF-25** | Analizador sin advertencias | `flutter_lints`, antes de cada entrega |
| **NF-26** | Todo en español | Igual que el backend |
| **NF-27** | Las decisiones quedan escritas | El porqué en los documentos; en el código, una línea por método |
| **NF-28** | Android | iOS queda fuera por falta de equipo. **Se dice, no se omite** |

## 9. Arquitectura de la app

**Se sigue la arquitectura que recomienda el equipo de Flutter: MVVM con capa de datos por
repositorios.** Está en la documentación oficial, con una aplicación de ejemplo, y eso la
vuelve defendible: *«seguimos las guías del equipo de Flutter»* es mejor respuesta que
*«elegí Clean Architecture»*. Y calza con el backend: **controlador → servicio → modelo** de
un lado, **vista → modelo de vista → repositorio → servicio** del otro.

| Capa | Qué hace | Qué **no** hace |
| --- | --- | --- |
| **Vista** | Dibuja y manda eventos al modelo de vista | No tiene lógica |
| **Modelo de vista** | Convierte datos en estado de pantalla y expone comandos | No conoce HTTP ni JSON |
| **Repositorio** | La fuente de verdad de un tipo de dato | No conoce a los otros repositorios |
| **Servicio** | Envuelve una fuente externa | No guarda estado |

```
lib/
  main.dart
  config/          entorno, dirección del servidor, tema
  core/            cliente HTTP, interceptores, errores, utilidades
  data/
    services/      ApiService, TokenStorage
    repositories/  ProductoRepository, CarritoRepository, PedidoRepository, SessionRepository
    models/        modelos inmutables + serialización
  ui/
    catalogo/  producto/  carrito/  checkout/  pedidos/  cuenta/
    comun/         los componentes propios, los únicos que usan forui adentro
  routing/         rutas y guardas de sesión
```

**Cuatro reglas que se afirman con tests:** una vista, un modelo de vista; ningún widget
importa nada de `data/services/`; los modelos son inmutables; el flujo de datos va en un
sentido. Y la que importa para la Etapa 3: **la app no sabe cómo es el backend**. Si cambian
las rutas o la credencial, cambian `ApiService`, `AuthService` y los repositorios.

| Qué se prueba | Cómo |
| --- | --- |
| Endpoints | En Pest, en los dos niveles del proyecto, con el dataset de permisos en las dos direcciones |
| Repositorios y modelos de vista | Unitarios con **falsos** |
| Vistas | Tests de widget sobre los seis estados |
| Rutas y guardas | Que una pantalla con sesión requerida redirija al login |
| Serialización | Un test por modelo contra un JSON de ejemplo, que documenta el contrato |

## 10. Librerías

**Cada dependencia se justifica o no entra.**

| Para | Paquete | Por qué |
| --- | --- | --- |
| Estado e inyección | `flutter_riverpod` 3.x | Las dos cosas a la vez, con verificación en compilación |
| Navegación | `go_router` | Lo recomienda el equipo de Flutter; sus guardas son el lugar de «sin sesión no se entra» |
| HTTP | `dio` | Interceptores de verdad, para la credencial y el 401 |
| Modelos | `freezed` + `json_serializable` | Igualdad, copia y serialización generadas |
| Credencial | `flutter_secure_storage` | Llavero y Keystore |
| Componentes | `forui` | Ver §11 |
| Imágenes | `cached_network_image` | Un catálogo que vuelve a descargar las fotos se siente roto |
| Esperas | `skeletonizer` 3.x | El layout real como esqueleto |
| Movimiento | `flutter_animate` | Transiciones encadenadas en una línea |
| Grilla | `flutter_staggered_grid_view` | Fichas de alto distinto sin huecos |
| Vacíos y éxito | `lottie` | Carrito vacío, compra confirmada, sin resultados |
| Lints | `flutter_lints` | Recomendado por el equipo de Flutter |

**Salen respecto de la versión 1:** `google_sign_in`, con el ingreso con Google, y `drift`
o `hive_ce`, con la caché sin red. Los dos van a la Etapa 4. Antes de fijar el
`pubspec.yaml`, se confirma que cada paquete tiene publicación reciente y es compatible con
la versión estable de Flutter, y **`pubspec.lock` se versiona** y no se actualiza cerca de
una entrega.

## 11. Interfaz

Se toman de las apps de compras conocidas **los patrones de interacción**, que la gente ya
tiene aprendidos; no se toma **la identidad visual**.

| Patrón | Por qué funciona |
| --- | --- |
| Barra inferior de cuatro destinos: Inicio, Categorías, Carrito, Cuenta | El carrito lleva insignia con la cantidad |
| Buscador fijo arriba | En una tienda, buscar es la acción principal |
| Grilla de dos columnas | Entran más productos sin volverse ilegible |
| Filtros en hoja inferior, con los activos como fichas | Se ve qué está aplicado sin abrir el panel |
| Galería a pantalla completa con zoom | En insumos informáticos la foto decide la compra |
| Barra inferior fija con precio y «Agregar» | La acción principal nunca queda fuera de la pantalla |
| Checkout en pasos con indicador de avance | Saber cuántos pasos faltan baja el abandono |
| Deslizar para quitar del carrito, con deshacer | El deshacer lo vuelve seguro |
| Traer para actualizar | — |

**Lo que es de CompuStack:** blanco y negro con `forui` y el tema **Zinc**, en
`FThemes.zinc.light` y `.dark`, atados al modo del sistema (forui no maneja el brillo solo).
El énfasis va por contraste, y el color sólo cuando significa algo: error, éxito,
advertencia, sin stock. La jerarquía, con tamaño, grosor y aire. El vocabulario, el de la
web.

**Tres decisiones visuales que valen la pena:**
1. **La ficha del listado es el componente más importante**: foto con relación fija, nombre
   a dos líneas, y abajo el precio o la invitación a iniciar sesión.
2. **La transición del listado a la ficha es compartida**, con un `Hero`.
3. **Cada acción confirma con movimiento**, no con un cartel que hay que cerrar.

**forui se monta encima de Material**: el árbol sigue siendo `MaterialApp`, y lo que forui
no tenga sale de Material. **Está antes de la 1.0**: se fija la versión exacta y se usa sólo
dentro de los componentes propios de `ui/comun/`.

## 12. Lo que hay que agregar al backend

**Entre un tercio y la mitad de la etapa es Laravel.**

| Qué | Detalle |
| --- | --- |
| Capa de API | `install:api`, `routes/api.php` versionado, API Resources, renderer de errores de `api/*`, credencial (Sanctum o JWT) |
| Catálogo | Endpoints públicos con el precio omitido sin token; novedades y destacados; URL absolutas de imágenes con una imagen por omisión; la casilla «destacado» en la web |
| Cuenta | Registro por `UsuarioService`, inicio y cierre con revocación, perfil |
| Carrito | `carritos` y `carrito_lineas`, `CarritoService`, reserva por `StockService`, la tarea de cada minuto |
| Pedido | El alta de venta online en `VentaService`, con la lista por medio de pago, revalidación, idempotencia y la entrega mínima; el canal en el listado web |
| Pagos | La abstracción de pasarela, el simulador, Mercado Pago de prueba con su consulta, la tabla de intentos, el cobro sin cajero en `PagoService` |
| Consulta | Los pedidos propios, con su autorización por dueño |
| Documentos | El contrato de la API, la §15 de `trazabilidad.md`, `modelo-datos.md` con cada tabla nueva y su porqué |

**Tests que importan más que el resto:**
- `GET /api/v1/productos` sin token **no devuelve ningún precio**;
- un usuario con rol Cliente recibe 403 en **cualquier** ruta de gestión;
- un cliente pide el pedido de otro y recibe 404;
- dos confirmaciones del mismo carrito generan **una** venta;
- una venta online pasa por `VentaService` y `StockService`, con su asiento en el kardex;
- un intento rechazado no aparece en `pagos`.

## 13. Riesgos

| Riesgo | Por qué | Cómo se acota |
| --- | --- | --- |
| **La etapa se calcula como «hacer una app»** | La mitad es Laravel, y los servicios hay que extenderlos | Primero el backend de cada funcionalidad, con sus tests |
| **La API se vuelve un sistema paralelo** | Es tentador poner la lógica en el controlador | Los controladores sólo traducen; si un servicio no alcanza, se extiende el servicio |
| **La reserva rompe el stock** | Es la primera escritura de stock desde fuera del mostrador | Por `StockService`, con bloqueo, y con su test de concurrencia |
| **Mercado Pago no llega o falla en la defensa** | Integración externa, cuentas de prueba, red local | El simulador detrás de la misma interfaz |
| **El webhook no llega** | No hay dirección pública | Consulta al proveedor, no aviso |
| **Las imágenes rompen la escala o no cargan** | Un solo tamaño, y `APP_URL=localhost` | Resolver la URL y la imagen por omisión antes de la primera pantalla de catálogo |
| **forui está antes de la 1.0** | Un cambio menor puede romper código | Versión fija, y envuelta en `ui/comun/` |
| **Flutter se mueve rápido** | Las versiones son de hoy | `pubspec.lock` versionado, sin actualizar cerca de una entrega |

## 14. Decisiones

### Tomadas

| # | Decisión |
| --- | --- |
| 1 | Los endpoints tienen forma de API desde el primer día |
| 2 | El precio se omite en el servidor sin sesión |
| 3 | Con sesión se ven los dos precios; el medio de pago decide cuál se cobra: contado para efectivo o transferencia, lista para Mercado Pago |
| 4 | El flujo de pago va contra una abstracción: simulador y Mercado Pago de prueba |
| 5 | El backend verifica el pago consultándole al proveedor, sin webhook |
| 6 | Pedido y pago son dos estados; los intentos tienen su tabla y `pagos` sólo el aprobado |
| 7 | El carrito es de la cuenta y vive en el servidor |
| 8 | El stock se reserva en el checkout y se descuenta al cobrar |
| 9 | No se muestra el stock exacto |
| 10 | Novedad es la fecha de alta en una ventana con tope; destacado se marca en el backend |
| 11 | La entrega es el mínimo: retiro o envío con dirección congelada y costo fijo |
| 12 | La web sólo se amplía con la casilla «destacado» y el canal en el listado de ventas |
| 13 | Android solamente |

### Abiertas, para el plan

| # | Pregunta |
| --- | --- |
| 1 | Sanctum o JWT |
| 2 | La descripción breve: columna nueva o derivada de la completa |
| 3 | El simulador en `pagos.metodo` |
| 4 | El mecanismo de idempotencia de la confirmación |
| 5 | La política ante rechazo, cancelación y reserva vencida |
| 6 | Cambiar de medio de pago con el pedido ya confirmado |
| 7 | Con qué precio se ordena «por precio» |
| 8 | Las miniaturas de imagen |
| 9 | La vinculación de cuentas con clientes de mostrador |
| 10 | El nombre y el ícono de la app |

## 15. Las fronteras con las otras etapas

| Queda para la Etapa 3 (si no se fusiona) | Qué va a tocar |
| --- | --- |
| Que la web consuma la misma API | El trabajo grande de esa etapa |
| JWT, si esta etapa usa Sanctum | La emisión en Laravel y `AuthService` en Flutter. **Nada más** |
| La documentación formal de la API | Sobre el contrato que deja esta etapa |

| Queda para la Etapa 4 | Qué tiene listo |
| --- | --- |
| Facturación con ARCA | El diseño de `comprobantes` en `modelo-datos.md` §6, y la alícuota congelada en cada renglón |
| Envíos calculados y seguimiento | El diseño de `envios` y la pregunta del peso volumétrico (`pendientes-usabilidad.md` #27) |
| Vales y banners | Su diseño en `modelo-datos.md` |
| Ingreso con Google, carrito anónimo, direcciones, perfil editable | — |
| Notificaciones push, caché sin red, repetir compra, descargar el comprobante | — |

## La etapa, en una frase

Que una compra hecha desde el teléfono termine siendo **la misma fila en `ventas`** que una
hecha en el mostrador, pasando por los mismos servicios —extendidos, no esquivados— y
dejando los mismos asientos en el kardex. Si eso se cumple, la Etapa 2 no agregó un sistema
paralelo: agregó una puerta nueva al mismo sistema.
