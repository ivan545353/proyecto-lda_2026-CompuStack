# API de la tienda — contrato

**Revisión 20** · tramo I de la Etapa 3 (la 10 fue la final de la Etapa 2). Base: `/api/v1`.

Es el único documento que comparten el backend (este repositorio) y la app móvil (su propio
repositorio). Cada endpoint que se agrega se documenta acá, con su método y ruta, autenticación,
parámetros, respuesta con el JSON completo, errores y notas. **Un cambio de forma sube la
revisión** y se anota en el registro de cambios.

La revisión 1 fijó las convenciones, el formato de error, la credencial y el paginado. La 2 suma el
catálogo público (§7.1). La 3, la cuenta (§7.2), los precios y el orden por precio. La 4, el carrito y
el checkout con la reserva (§7.3). La 5, la confirmación del pedido (§7.4) y la dirección del perfil. La 6,
la cuenta editable (`PATCH /perfil`) y los datos fiscales en el perfil. La 7, la provincia de lista cerrada (§5).
La 8, los pagos (§7.5): intentos por Mercado Pago y por el simulador. La 9, la consulta de compras (§7.6):
mis pedidos, el detalle y el comprobante por enlace firmado. La 10 no cambia ninguna forma: es la revisión
final, con cada endpoint pedido al servidor y sus ejemplos comparados con las respuestas reales (§7, al
principio). La 11 suma `GET /tienda` (§7.1), lo que la tienda ofrece, para el Inicio del tramo J. La 12, la
transferencia como tercer medio (§7.7). La 13, con qué entregas vale cada medio (D-28): el efectivo, sólo con retiro. La 14 abre la Etapa 3: la sesión es
común a la app y a la administración, con `GET /yo`, vigencia por ámbito y `401 sesion_expirada`. La 15 suma la
gestión del catálogo (§7.8): marcas, categorías, productos y sus proveedores, por representación según el permiso,
sin cambiar ninguna forma de la tienda. La 16, los terceros (§7.9): clientes, direcciones y proveedores. La 17, precios y stock (§7.10): la parametrización, el ajuste masivo con su historial, el kardex y el ajuste por conteo. La 18, las compras (§7.11): órdenes, armador, edición, transiciones, recepción y el pedido en PDF. La 19, las ventas de la administración, los dos paneles y los dos informes (§7.12). La 20, el personal, los roles y las dos contraseñas (§7.13): con ella ninguna ruta web escribe.

## 1. Reglas generales

| Regla | Qué fija |
| --- | --- |
| **Versión en la ruta** | `/api/v1/...` |
| **Siempre JSON** | Ninguna respuesta de `api/*` es HTML ni redirección, tampoco los errores ni una ruta inexistente. El servidor responde JSON aunque no se envíe `Accept` |
| **Claves en español y `snake_case`** | `precio_contado`, `ultima_pagina` |
| **Dinero como cadena** | `"60500.00"`: dos decimales, punto, sin separador de miles. Un número JSON es `double` en Dart. La app sólo formatea (`$ 60.500,00`) |
| **Cantidades enteras** | `"cantidad": 2` |
| **Fechas ISO 8601 UTC** | `"2026-11-08T07:00:00Z"`. La app convierte a la hora local |
| **Identificadores enteros**, con su forma de pantalla | `"id": 42, "numero": "V-00042"`. La app no arma números |
| **Estados con su texto** | `"estado": "pendiente_pago", "estado_texto": "Esperando el pago"`. La app no traduce ENUM |
| **Un campo protegido no viaja** | Sin el permiso `tienda.ver_precios`, la clave `precios` **no está**: `null` dice «no tiene valor»; ausente, «no es para vos» |
| **Lo que no se publica nunca** | `costo_promedio`, `ganancia`, `precio_manual`, `stock`, `stock_reservado`, `stock_minimo`, proveedores. La disponibilidad viaja por niveles |

El mismo contrato está en **OpenAPI 3.1**, en `docs/openapi.yaml` (tramo K): una operación por cada ruta de `/api/v1`,
con su seguridad, su permiso (`x-permiso`, el `can:` de la ruta) y la sección de este documento que la explica
(`x-api-md`). Lo verifica `tests/Feature/Api/OpenApiTest.php` contra el enrutador, en las dos direcciones. Este documento
sigue siendo la fuente: el OpenAPI no agrega formas, y su versión es la revisión de acá.

## 2. Respuesta correcta

Un recurso:

```json
{ "datos": { "id": 42, "numero": "V-00042", "estado": "pendiente_pago", "estado_texto": "Esperando el pago" } }
```

Una operación puede sumar `mensaje`, en lenguaje del negocio:

```json
{ "datos": { "...": "..." }, "mensaje": "Pedido V-00042 registrado. Lo pagás en el local." }
```

Un listado pagina siempre:

```json
{
  "datos": [ { "...": "..." } ],
  "pagina": 1,
  "por_pagina": 20,
  "ultima_pagina": 7,
  "total": 134,
  "criterios": { "categoria_id": 3, "q": "ssd", "orden": "nombre", "seccion": null }
}
```

- `criterios` devuelve lo que el servidor aplicó, normalizado. Es siempre un objeto (`{}` si no hay).
  La app descarta una respuesta cuyos criterios no coinciden con los de su pantalla.
- Una página después de la última devuelve `datos` vacío con los metadatos, no 404.
- Un listado sin resultados tiene `ultima_pagina: 1` y `total: 0`.
- El orden siempre desempata por `id`.
- `por_pagina` va de 1 al tope (`config/tienda.php` → `paginado.tope`, 50); más que el tope es 422.
  Por omisión, `paginado.por_pagina` (20).

| Código | Cuándo |
| --- | --- |
| 200 | Lectura, o una operación que no creó nada (incluido el reintento idempotente) |
| 201 | Registro, pedido o intento de pago creados |
| 204 | Cerrar sesión |

## 3. Respuesta de error

```json
{ "mensaje": "No hay stock disponible de «Disco SSD WD Blue SN580 1TB»: hay 1 y se piden 2.", "codigo": "stock_insuficiente", "errores": {} }
```

- `mensaje` se muestra tal cual. Nunca lleva nombres de clase, rutas ni SQL.
- `codigo` le dice a la app qué hacer **sin leer el texto**: varios errores comparten el 409 y
  piden reacciones distintas.
- `errores` trae los campos del 422, con sus mensajes en español; en los demás, objeto vacío.

| HTTP | `codigo` | Cuándo | Qué hace la app |
| --- | --- | --- | --- |
| 401 | `sin_sesion` | Falta la credencial en una ruta protegida | Al login, conservando a dónde iba |
| 401 | `sesion_expirada` | Credencial que existía y venció (r14). Mismo `WWW-Authenticate: Bearer` | Borra la credencial, «Tu sesión venció», al login |
| 401 | `sesion_invalida` | Credencial revocada o desconocida, cuenta desactivada, o cuenta de gestión en una ruta de la tienda | Borra la credencial, «Tu sesión venció», al login |
| 403 | `sin_permiso` | Falta un permiso de tienda | Explica qué no se puede |
| 404 | `no_encontrado` | Inexistente, inactivo **o ajeno** (no se distingue) | Pantalla de «no existe» |
| 405 | `metodo_no_permitido` | Verbo no admitido en esa ruta. Trae `Allow` | Error de programación |
| 409 | `stock_insuficiente` | No hay unidades suficientes | Vuelve al carrito |
| 409 | `carrito_cambio` | La revalidación encontró cambios; trae `cambios` | Muestra los cambios y deja revisar |
| 409 | `checkout_vencido` | La reserva del checkout venció antes de confirmar | Vuelve al carrito para iniciar el checkout de nuevo |
| 409 | `transicion_invalida` | El pedido no admite esa transición de estado | Recarga el pedido |
| 409 | `regla_de_negocio` | Cualquier otra regla del negocio | Muestra el mensaje |
| 422 | `validacion` | Datos mal formados; trae `errores` | Cada error junto a su campo |
| 429 | `demasiados_intentos` | Límite de inicio de sesión o registro; trae `reintentar_en` (segundos) y `Retry-After` | Bloquea el botón con la cuenta regresiva |
| 500 | `error_interno` | Cualquier otra | «Algo falló del lado del servidor», con reintento |

`stock_insuficiente` y `carrito_cambio` se emiten desde la revisión 4 (tramo E); `checkout_vencido`, desde el F.

**El 500 nunca muestra la traza**, aunque el servidor tenga `APP_DEBUG=true`: la respuesta se escribe
entera en el renderer y la excepción queda en el log. Un estado HTTP que no figura en la tabla
(413, 503…) sale también como 500 `error_interno`.

**Sin conexión no hay respuesta**: ese estado lo arma la app cuando `dio` no llega.

## 4. Autenticación

| Tipo de ruta | Qué exige | Credencial inválida |
| --- | --- | --- |
| **Pública** | Nada | No se lee |
| **Credencial si sirve** (`GET /categorias`, r15) | Nada; con una credencial válida y `categoria.ver`, la gestión pide `forma=lista` | No es error: sigue como visitante. El árbol no depende de quién mira |
| **Sesión opcional** (catálogo, de los dos clientes desde la r15) | Nada; con credencial válida y `tienda.ver_precios`, agrega los precios; con `producto.ver`, la gestión pide `forma=gestion` | **401 `sesion_invalida`**: si no, los precios desaparecerían sin explicación |
| **Protegida común** (`api.protegida`, r14) | Credencial válida y cuenta activa, de gestión o de tienda | 401 |
| **Protegida** (`tienda.protegida`) | Credencial válida, cuenta activa y de tienda, y el permiso de la ruta | 401 |

- Viaja en `Authorization: Bearer <credencial>`. Cualquier otro valor de `Authorization` cuenta
  como una credencial que no sirve (`sesion_invalida`).
- Sólo la credencial `Bearer` autentica: una sesión de navegador abierta en el panel **no** sirve
  contra `api/*`.
- **La administración web guarda la credencial en una cookie** (D-30, tramo C) y la manda como `Bearer`.
  La cookie sirve sólo para que la navegación entre pantallas la lleve. **La API no la lee**: un pedido
  con la cookie y sin `Authorization` es 401 `sin_sesion`. No cambia ninguna forma y la revisión sigue en 14.
- **Vigencia, según el ámbito de la cuenta** (D-31, r14). La decide un dato de la cuenta y no el cliente que
  dice ser:
  - **Tienda: 7 días** (`SANCTUM_EXPIRATION`, en minutos; vacío = 10080). Un teléfono no se comparte, y
    escribir la contraseña en un celular es caro.
  - **Gestión: 8 horas, un turno** (`SANCTUM_EXPIRATION_GESTION`; vacío = 480). La pantalla del mostrador
    se comparte: lo que no se cerró a mano no sobrevive al día siguiente.
  - Vencida, la credencial da **401 `sesion_expirada`**; revocada o inventada, **401 `sesion_invalida`**.
    El cliente las explica distinto. Una fila vencida que la limpieza ya borró cuenta como desconocida.
- Al desactivar o dar de baja una cuenta, y al cambiar o restablecer su contraseña, se **revocan** todas sus
  credenciales; además cada pedido revalida que la cuenta siga activa.
- **Entrar es de toda cuenta activa; lo que puede hacer lo deciden sus permisos** (HL-98, r14). Hasta la r13
  las cuentas de gestión no entraban. Siguen sin entrar a las rutas propias de la tienda (perfil, carrito,
  pedidos online): no tienen ficha de cliente, y ahí reciben 401 `sesion_invalida`.
- El inicio de sesión y el registro devuelven una forma propia, no la de Sanctum:

```json
{
  "datos": {
    "credencial": "…",
    "tipo": "Bearer",
    "vence": "2026-11-15T07:00:00Z",
    "usuario": { "nombre": "Camila", "apellido": "Herrera", "email": "cliente@sistema.local" }
  }
}
```

La app trata `credencial` como una cadena opaca.

- Credenciales incorrectas e inactiva dan **el mismo 422 con el mismo mensaje**:
  decir cuál falló permitiría enumerar cuentas.
- **Límite de intentos** (`tienda-acceso`) en inicio de sesión y registro: 5 por minuto por correo e
  IP, y 30 por minuto por IP sola. Es el mismo para gestión: el endpoint es uno.
- **Ni la credencial ni la contraseña se escriben en el log**, tampoco cuando el pedido termina en 500
  (`SesionComunTest`). El correo se compara sin distinguir mayúsculas ni espacios.

## 5. Las formas que se repiten

**Disponibilidad** (también para el visitante): `disponible`, `ultimas_unidades`, `sin_stock`, con
su texto. Nunca el número de unidades.

```json
"disponibilidad": "ultimas_unidades", "disponibilidad_texto": "Últimas unidades"
```

El umbral está en `config/tienda.php` (`disponibilidad.ultimas_unidades`).

**Precios** (sólo con `tienda.ver_precios`): un objeto, para que omitirlo sea quitar **una** clave.

```json
"precios": {
  "contado": { "importe": "54450.00", "texto": "Efectivo o transferencia" },
  "lista":   { "importe": "60500.00", "texto": "Mercado Pago" }
}
```

**Imagen**: URL absoluta con el host del pedido (`/storage/…`), de modo que la misma respuesta sirve
al emulador (`10.0.2.2`) y a un teléfono por la IP de la red. Sin foto, la URL de la imagen por
omisión (`/img/producto-sin-imagen.svg`), nunca `null`.

**Provincia** (desde la revisión 7): en toda dirección que se manda (`PATCH /perfil`, `POST /pedidos`),
`provincia` es **una de esta lista cerrada**, escrita igual (mayúsculas y tildes). Es la lista con la que
la web valida y arma su selector (`Direccion::PROVINCIAS`), en este orden:

```json
["Buenos Aires", "Ciudad Autónoma de Buenos Aires", "Catamarca", "Chaco", "Chubut", "Córdoba",
 "Corrientes", "Entre Ríos", "Formosa", "Jujuy", "La Pampa", "La Rioja", "Mendoza", "Misiones",
 "Neuquén", "Río Negro", "Salta", "San Juan", "San Luis", "Santa Cruz", "Santa Fe",
 "Santiago del Estero", "Tierra del Fuego", "Tucumán"]
```

Fuera de la lista es 422 en `direccion.provincia` con «Elegí una provincia de la lista.», el mismo texto
que la web. No hay endpoint de provincias: son 24 fijas y la app las lleva en el código. Una dirección
guardada antes de la revisión 7 puede traer en `GET /perfil` una provincia que no está en la lista: el
cliente la tiene que **elegir de nuevo** antes de mandarla.

## 6. Textos para el cliente

Salen de un solo diccionario del backend (`App\Support\Api\TextosTienda`): estados del pedido, del
pago, niveles de disponibilidad y pasarelas. El simulador de pagos se dice siempre como lo que es:
«Simulador de pagos — no es un cobro real».

## 7. Endpoints

Tres grupos de rutas (`routes/api.php`): **pública** (no lee la credencial), **sesión opcional**
(sin credencial es visitante; con una inválida, 401 `sesion_invalida`) y **protegida**. El registro y
el inicio de sesión son públicos, con el límite `tienda-acceso`; cerrar sesión y el perfil, protegidos.

**Verificación de la revisión 10** (12/11/2026). Las 24 rutas de `php artisan route:list --path=api/v1` son
las de este documento. Con `php artisan serve` y la base de demostración se pidió cada una con `curl`, con
una cuenta registrada para la prueba: catálogo (con y sin sesión, orden por precio, novedades), registro,
sesión, perfil y su edición, carrito, checkout y su reintento, pedido y su reintento (mismo número), intento
de Mercado Pago de prueba y su repetición (el mismo intento), consulta del intento, Mis pedidos, detalle,
comprobante sin pagar (409), el PDF sin firma (403), el pedido ajeno (404 **idéntico byte a byte** al
inexistente), el 405 con `Allow`, la ruta inexistente y el cierre de sesión. Formas, códigos y mensajes
coinciden; los ejemplos se alinearon con los ids y las imágenes del catálogo de demostración. Dos casos no se
pidieron a mano y los fija un test: el 429 (`Api/ContratoTest`, `Api/CuentaTest`) y el 200 del comprobante de
un pedido pagado (`Api/PedidoConsultaTest`, «el comprobante de un pedido pagado da una url firmada…»), que se
recorre en el ensayo del guion.

### 7.1 Catálogo (tramo C)

**Precios.** Con una credencial válida cuyo rol tiene `tienda.ver_precios`, cada producto (listado y
ficha) suma la clave `precios` (§5). Sin credencial, o con un rol sin el permiso, la clave **no está**:
ni `null` ni vacía. Nunca viajan `costo_promedio`, `ganancia`, `precio_manual`, `stock`, `stock_reservado`,
`stock_minimo` ni proveedores: un test recorre todo el JSON del listado y de la ficha buscándolos.

**Qué se ve.** Un producto está en la tienda si está **activo** y su categoría es **visible**: activa
y con todos sus ancestros activos. `CategoriaService` puede desactivar un padre sin tocar a sus hijas,
y una hija activa bajo un padre inactivo no se muestra (ni ella ni sus productos).

#### `GET /api/v1/categorias`

Pública. El árbol de categorías visibles, con `hijas` anidadas (hasta tres niveles).

| Parámetro | Tipo | Notas |
| --- | --- | --- |
| `pagina` | entero ≥ 1 | 1 por omisión |
| `por_pagina` | entero 1–50 | 20 por omisión; más que el tope es 422 |

```json
{
  "datos": [
    {
      "id": 1,
      "nombre": "Componentes",
      "hijas": [
        { "id": 2, "nombre": "Placas de video", "hijas": [] },
        { "id": 4, "nombre": "Memorias RAM", "hijas": [] }
      ]
    },
    {
      "id": 7,
      "nombre": "Almacenamiento",
      "hijas": [ { "id": 9, "nombre": "Discos SSD", "hijas": [] } ]
    }
  ],
  "pagina": 1,
  "por_pagina": 20,
  "ultima_pagina": 1,
  "total": 2,
  "criterios": {}
}
```

- **Se pagina por raíz** y cada raíz trae su rama entera: un listado pagina siempre (§2), también éste,
  y el árbol no se corta a la mitad. `total` cuenta **raíces**, no categorías. Con el catálogo de
  demostración (9 raíces) todo llega en la primera página.
- Una hoja trae `"hijas": []`, nunca la clave ausente. Las categorías vienen ordenadas por `orden`,
  después `nombre`, después `id`. No viajan `slug`, `ganancia` ni el peso.
- Errores: 422 `validacion` (`pagina` o `por_pagina` inválidos).

#### `GET /api/v1/productos`

Sesión opcional. El listado del catálogo, filtrado, ordenado y paginado.

| Parámetro | Tipo | Notas |
| --- | --- | --- |
| `q` | texto, hasta 100 | Nombre **o** código contiene. Los comodines de SQL (`%`, `_`) se buscan como texto |
| `categoria_id` | entero ≥ 1 | La categoría **y sus hijas y nietas**. Inexistente, inactiva u oculta: lista vacía, no el catálogo entero |
| `orden` | `nombre` · `recientes` · `precio_asc` · `precio_desc` | `nombre` por omisión. Con `seccion=novedades`, la omisión es `recientes`. Los de precio exigen sesión y `tienda.ver_precios` |
| `seccion` | `novedades` · `destacados` | Se combina con `q` y `categoria_id` (filtran **dentro** de la sección) |
| `pagina`, `por_pagina` | enteros | 1 y 20 por omisión; `por_pagina` hasta 50 |

La validación **rechaza y no corrige**: un `orden` o una `seccion` desconocidos, un `q` de más de 100
caracteres o un `por_pagina` sobre el tope son 422, no se recortan ni se reemplazan por un valor por
omisión.

**Orden por precio.** `precio_asc` y `precio_desc` ordenan por el precio de **contado** y desempatan
por `id`. Sin sesión son **401 `sin_sesion`**; con sesión y sin el permiso, **403 `sin_permiso`**. Las
dos respuestas salen **antes de consultar**: ordenar dejaría deducir los precios que no viajan. No son
422 porque el orden existe; lo que falta es poder usarlo.

```json
{
  "datos": [
    {
      "id": 4,
      "codigo": "SSD-WD1TB",
      "nombre": "Disco SSD WD Blue SN580 1TB",
      "descripcion_breve": "Unidad de estado sólido NVMe de 1 TB en formato M.2. Arranca el sistema y abre los programas en segundos, con velocidades de lectura de…",
      "imagen": "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.webp",
      "categoria": { "id": 9, "nombre": "Discos SSD" },
      "es_novedad": true,
      "es_destacado": true,
      "disponibilidad": "disponible",
      "disponibilidad_texto": "Disponible"
    }
  ],
  "pagina": 1,
  "por_pagina": 20,
  "ultima_pagina": 981,
  "total": 19610,
  "criterios": { "categoria_id": null, "q": null, "orden": "nombre", "seccion": null }
}
```

Con `tienda.ver_precios`, cada fila suma, después de `disponibilidad_texto`:

```json
"precios": {
  "contado": { "importe": "54450.00", "texto": "Efectivo o transferencia" },
  "lista":   { "importe": "60500.00", "texto": "Mercado Pago" }
}
```

- `criterios` trae siempre las cuatro claves, con lo que el servidor **aplicó** (`null` si no se pidió).
  `orden` ya viene resuelto: sin pedirlo, `nombre` (o `recientes` en novedades). La app descarta una
  respuesta cuyos `criterios` no coinciden con los de su pantalla.
- **Orden estable.** Todos los órdenes desempatan por `id`: una página nunca repite ni saltea
  filas, aunque haya muchos productos con el mismo nombre, la misma fecha o el mismo precio.
- **Descripción breve** (D-12): las primeras 25 palabras de la descripción (`descripcion_breve.palabras`
  en `config/tienda.php`), con «…» pegado a la última si se cortó, y **nunca más de 250 caracteres**
  (`descripcion_breve.caracteres`): un texto sin espacios es una sola «palabra» y, sin ese tope, viajaría
  entero. Es derivada: no hay columna.
- **Imagen**: la primera de la galería del producto, como URL absoluta con el host del pedido (§5).
  Sin foto, la imagen por omisión; nunca `null`.
- **Disponibilidad** (§5): `disponible`, `ultimas_unidades` (de 1 a 3 unidades, `disponibilidad.ultimas_unidades`)
  y `sin_stock`. Se calcula sobre lo disponible (`stock − stock_reservado`) y nunca viaja el número.
- Errores: 401 `sesion_invalida` (credencial inválida), 401 `sin_sesion` y 403 `sin_permiso` (orden por
  precio), 422 `validacion`.

**Criterio de novedad.** Un producto es novedad si está en la tienda, se creó dentro de los últimos
**30 días** (`novedades.dias`) y está entre los **10** más recientes de ésos (`novedades.tope`);
desempata `created_at` descendente y después `id` descendente. `es_novedad` de cada fila y
`seccion=novedades` salen de **la misma lista**, así que nunca se contradicen.

*Por qué los dos números.* La ventana sola no alcanza: el catálogo de volumen se cargó en dos días,
así que con sólo «30 días» los 20.000 productos serían novedad y la marca no distinguiría nada. El
tope sólo tampoco: en un negocio quieto, «lo más reciente» podría ser de hace un año. La ventana dice
cuándo deja de ser nuevo; el tope dice cuántos caben en el carrusel. Consecuencia: combinar
`seccion=novedades` con `categoria_id` o `q` filtra **dentro** de esos 10, y puede dar vacío aunque
la categoría tenga productos recientes que quedaron fuera del tope.

#### `GET /api/v1/productos/{id}`

Sesión opcional. La ficha de un producto: todo lo del listado (también `precios`, con el permiso) y
además la descripción completa, la galería y la marca.

```json
{
  "datos": {
    "id": 4,
    "codigo": "SSD-WD1TB",
    "nombre": "Disco SSD WD Blue SN580 1TB",
    "descripcion_breve": "Unidad de estado sólido NVMe de 1 TB en formato M.2. Arranca el sistema y abre los programas en segundos, con velocidades de lectura de…",
    "imagen": "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.webp",
    "categoria": { "id": 9, "nombre": "Discos SSD" },
    "es_novedad": true,
    "es_destacado": true,
    "disponibilidad": "ultimas_unidades",
    "disponibilidad_texto": "Últimas unidades",
    "descripcion": "Unidad de estado sólido NVMe de 1 TB en formato M.2. Arranca el sistema y abre los programas en segundos, con velocidades de lectura de hasta 4150 MB/s. Se instala directo en la placa madre, sin cables.",
    "galeria": [
      "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.webp",
      "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-2.webp"
    ],
    "marca": { "id": 9, "nombre": "Western Digital" }
  }
}
```

- **Galería**: en el orden en que el producto guarda sus fotos; la primera es `imagen`. **Nunca viene
  vacía**: sin fotos trae una sola entrada, la imagen por omisión, para que la app no tenga un caso
  especial de «no hay imágenes».
- `marca` es `null` si el producto no tiene (la clave siempre está). Una marca dada de baja se sigue
  nombrando: es un dato del producto.
- `descripcion` es una cadena vacía, no `null`, si no hay.
- **404 `no_encontrado`** si el producto no existe, está inactivo, o su categoría o alguna ancestra está
  inactiva. Las respuestas son **idénticas byte a byte**: no se revela que un producto existe pero no se
  ofrece. Un `{id}` que no es un número también es 404.
- Errores: 401 `sesion_invalida`, 404 `no_encontrado`.

```json
{ "mensaje": "No encontramos lo que buscás.", "codigo": "no_encontrado", "errores": {} }
```

Ejemplo de 422 de cualquiera de los dos listados (`GET /api/v1/productos?orden=azar`):

```json
{
  "mensaje": "Ese orden no existe. Podés usar: nombre, recientes, precio_asc, precio_desc.",
  "codigo": "validacion",
  "errores": { "orden": [ "Ese orden no existe. Podés usar: nombre, recientes, precio_asc, precio_desc." ] }
}
```

#### `GET /api/v1/tienda`

Pública (revisión 11, tramo J). Lo que la tienda ofrece hoy, para que el Inicio lo muestre sin escribirlo
en la app. Sin parámetros.

```json
{
  "datos": {
    "entrega": [
      { "modo": "retiro", "texto": "Retiro en el local" },
      { "modo": "envio", "texto": "Envío a domicilio" }
    ]
  }
}
```

- `entrega`: retiro siempre y envío sólo con `tienda.envio.habilitado`, en ese orden. Son los mismos modos
  y textos que ofrece el checkout (§7.3), y salen del mismo lugar (`TextosTienda::modosEntrega()`), así que
  no pueden contradecirse.
- **El costo del envío no viaja**, ni con sesión: el visitante no ve precios (regla 21). El checkout lo
  sigue trayendo. Con o sin credencial la respuesta es la misma, y la ruta no lee la credencial: una
  inválida no da 401.
- Es un recurso, no un listado: no pagina.
- Errores: ninguno propio.

### 7.2 Cuenta (tramo D)

Una cuenta de la tienda es un usuario con el rol **Cliente** (ámbito `tienda`, permisos `tienda.ver_precios`,
`tienda.comprar` y `tienda.ver_pedidos`) y su ficha de cliente. Las cuentas de gestión inician y cierran sesión
por los mismos endpoints (r14), pero no tienen registro ni perfil.

#### `POST /api/v1/registro`

Pública, con el límite `tienda-acceso` (§4). Crea la cuenta y deja la sesión abierta.

| Campo | Tipo | Notas |
| --- | --- | --- |
| `nombre`, `apellido` | texto, requeridos, hasta 100 | Juntos («nombre apellido») hasta 150: es la razón social del cliente |
| `email` | correo, requerido, hasta 150 | Único. Se guarda en minúsculas y sin espacios |
| `password` | texto, requerido | Al menos 8, con letras y números. Va con `password_confirmation` |
| `telefono` | texto, opcional, hasta 30 | |

**201**, con la forma de la credencial (§4):

```json
{
  "datos": {
    "credencial": "…",
    "tipo": "Bearer",
    "vence": "2026-11-17T14:00:00Z",
    "usuario": { "nombre": "Lucía", "apellido": "Paredes", "email": "lucia@correo.local" }
  }
}
```

- El cliente se crea con razón social «nombre apellido», documento DNI **sin número**, consumidor final, y
  el correo y el teléfono de la cuenta. El documento lo carga después con `PATCH /perfil`; la condición
  frente al IVA, el local.
- El rol y el estado no se eligen: `rol_id` o `activo` en el cuerpo se ignoran.
- Errores: 422 `validacion` (cada error en su campo; el correo repetido dice «Ya hay una cuenta con ese
  correo.»), 429 `demasiados_intentos`.

```json
{
  "mensaje": "Las dos contraseñas no coinciden.",
  "codigo": "validacion",
  "errores": { "password": [ "Las dos contraseñas no coinciden." ] }
}
```

#### `POST /api/v1/sesion`

Pública, con el límite `tienda-acceso`. Campos `email` y `password`, requeridos; el correo se compara sin
mayúsculas ni espacios. **200** con la misma forma que el registro. No abre una sesión de navegador.

- **La usan los dos clientes** (r14): una cuenta de gestión recibe la misma forma, con su vigencia de 8 horas
  (§4). Las credenciales se distinguen por cuenta; la forma, no.
- Contraseña incorrecta, correo inexistente y cuenta inactiva dan **el mismo 422**, con
  el error en `email`. Decir cuál falló permitiría saber qué correos tienen cuenta:

```json
{
  "mensaje": "El correo o la contraseña no son correctos.",
  "codigo": "validacion",
  "errores": { "email": [ "El correo o la contraseña no son correctos." ] }
}
```

- Errores: 422 `validacion`, 429 `demasiados_intentos` (tampoco entra la contraseña correcta mientras dura).

#### `DELETE /api/v1/sesion`

Protegida común (r14), sin permiso propio. Cierra la sesión **de este dispositivo**: la credencial con la que
llegó el pedido deja de servir (después da 401 `sesion_invalida`); las de otros dispositivos siguen. **204**
sin cuerpo. Errores: 401 `sin_sesion` / `sesion_invalida` / `sesion_expirada`.

#### `GET /api/v1/yo`

Protegida común (r14), sin permiso propio. Quién tiene la sesión, con su rol y sus permisos: los dos clientes
pintan la interfaz con esto. Pintar no es autorizar: cada endpoint vuelve a pedir su permiso.

```json
{
  "datos": {
    "nombre": "Sofía",
    "apellido": "Gutiérrez",
    "email": "vendedor@sistema.local",
    "rol": { "nombre": "Vendedor", "ambito": "gestion" },
    "permisos": [
      "categoria.ver", "cliente.crear", "cliente.ver", "panel.ver_propio", "producto.ver",
      "venta.crear", "venta.editar", "venta.entregar", "venta.ver"
    ]
  }
}
```

- `ambito` es `gestion` o `tienda`. Una cuenta de la tienda trae `tienda.ver_precios`, `tienda.comprar` y
  `tienda.ver_pedidos` (más `tienda.cargar_comprobante` si su rol la tiene).
- `permisos` viene ordenado y es la lista completa del rol: no se pagina (son decenas, no miles).
- No trae la ficha de cliente: eso es `GET /perfil`, sólo de la tienda.
- Errores: 401 `sin_sesion` / `sesion_invalida` / `sesion_expirada`.

#### `GET /api/v1/perfil`

Protegida, sin permiso propio. La cuenta de quien tiene la sesión. Se edita con `PATCH /perfil`.

```json
{
  "datos": {
    "nombre": "Lucía",
    "apellido": "Paredes",
    "email": "lucia@correo.local",
    "telefono": "297-4001122",
    "razon_social": "Lucía Paredes",
    "tipo_doc": "dni",
    "tipo_doc_texto": "DNI",
    "nro_doc": "38123456",
    "condicion_iva": "consumidor_final",
    "condicion_iva_texto": "Consumidor final",
    "direccion": {
      "calle": "San Martín",
      "numero": "1234",
      "piso_depto": "2 B",
      "codigo_postal": "9000",
      "localidad": "Comodoro Rivadavia",
      "provincia": "Chubut"
    }
  }
}
```

- `telefono` es `null` si no lo cargó.
- `tipo_doc` (`dni`, `cuit`, `cuil`) + `tipo_doc_texto` y `nro_doc` (desde la revisión 6): los tres en `null`
  si no hay número. Una cuenta recién registrada no tiene documento.
- `condicion_iva` + `condicion_iva_texto` (desde la revisión 6): **sólo para verse**. Decide el tipo de
  comprobante y la carga el local (D-22): la app la explica, no la edita.
- `direccion` (desde la revisión 5): la predeterminada, para proponerla en el envío; `null` si no tiene. En
  `POST /pedidos` se puede cambiar **sólo para ese pedido**; en la cuenta, con `PATCH /perfil`. Si es
  anterior a la revisión 7, su `provincia` puede no estar en la lista de §5: la app no la da por buena y
  pide elegirla de nuevo.
- Errores: 401 `sin_sesion` / `sesion_invalida`.

#### `PATCH /api/v1/perfil`

Protegida, sin permiso propio (revisión 6). Edita la cuenta propia. **Parcial**: se cambia sólo lo que viene
en el cuerpo; una clave que no viene no se toca (el paso 4 de la compra puede mandar sólo `direccion`).

| Campo | Tipo | Notas |
| --- | --- | --- |
| `nombre`, `apellido` | texto, hasta 100 | No vacíos. Juntos hasta 150 (la razón social); si viene uno, se mide con el otro guardado |
| `telefono` | texto, hasta 30, o `null` | `null` lo quita |
| `tipo_doc` + `nro_doc` | `dni` / `cuit` / `cuil` + texto | **Juntos o ninguno**. DNI de 7 u 8 dígitos; CUIT o CUIL de 11 con su dígito verificador. Puntos, guiones y espacios se sacan. Los dos en `null` lo quitan |
| `direccion` | objeto o `null` | `calle` (150), `numero` (10), `piso_depto` (20, opcional), `codigo_postal` (10), `localidad` (100), `provincia` (**una de la lista cerrada**, §5). `null` la quita |

```json
{
  "nombre": "Lucía",
  "apellido": "Paredes Ruiz",
  "tipo_doc": "cuit",
  "nro_doc": "20-12345678-6",
  "direccion": {
    "calle": "San Martín",
    "numero": "1234",
    "piso_depto": null,
    "codigo_postal": "9000",
    "localidad": "Comodoro Rivadavia",
    "provincia": "Chubut"
  }
}
```

**200**, con el perfil como lo devuelve el `GET` y un `mensaje`:

```json
{
  "datos": {
    "nombre": "Lucía",
    "apellido": "Paredes Ruiz",
    "email": "lucia@correo.local",
    "telefono": "297-4001122",
    "razon_social": "Lucía Paredes Ruiz",
    "tipo_doc": "cuit",
    "tipo_doc_texto": "CUIT",
    "nro_doc": "20123456786",
    "condicion_iva": "consumidor_final",
    "condicion_iva_texto": "Consumidor final",
    "direccion": {
      "calle": "San Martín",
      "numero": "1234",
      "piso_depto": null,
      "codigo_postal": "9000",
      "localidad": "Comodoro Rivadavia",
      "provincia": "Chubut"
    }
  },
  "mensaje": "Guardamos tus datos."
}
```

- Es la misma ficha que el cajero ve y corrige en la web. La razón social sigue a nombre y apellido **cuando
  cambian**; si no vienen, se conserva la que haya (también una que ajustó el cajero).
- `direccion` edita la predeterminada (o la crea). Con `null` se borra; si el cliente tenía otras, cargadas
  en el local, la más antigua pasa a ser la predeterminada y el perfil la propone. Un pedido ya hecho **no
  cambia**: su dirección está copiada.
- `email`, `condicion_iva`, `razon_social` y cualquier importe (`precio`, `total`…) se rechazan con 422 en su
  campo: no se corrigen ni se ignoran.
- Errores: 401 `sin_sesion` / `sesion_invalida`; 422 `validacion`, con las claves de la dirección anidadas
  (`direccion.calle`, `direccion.provincia`). Casos con mensaje propio: un documento de otro cliente («Ese documento ya está cargado
  en otra cuenta. Si es tuyo, avisanos.») y quitar el documento con Responsable inscripto o Monotributo.

```json
{
  "mensaje": "Ese CUIT no es válido: revisá el último dígito.",
  "codigo": "validacion",
  "errores": { "nro_doc": [ "Ese CUIT no es válido: revisá el último dígito." ] }
}
```

### 7.3 Carrito y checkout (tramo E)

**Dónde se guarda el carrito y cómo se asocia al usuario.** El carrito vive **en el servidor**, en las
tablas `carritos` y `carrito_lineas`, no en el teléfono. Cada carrito pertenece a una cuenta
(`carritos.user_id`), y el servidor lo encuentra por la credencial: ninguna ruta lleva el id del carrito,
así que no hay forma de pedir el de otro. Una cuenta tiene **un solo carrito vigente** (`activo` o
`en_checkout`); para que dos pedidos simultáneos no creen dos, el servidor bloquea la fila de la cuenta
antes de buscarlo. Por eso el carrito se conserva entre aperturas de la app y entre dispositivos, y
cerrar sesión no lo borra: la app sólo descarta su copia en memoria. Un carrito no se borra nunca:
cuando se confirma el pedido (tramo F) pasa a `convertido`, y el siguiente es nuevo.

**El carrito no guarda precios.** Cada renglón guarda el producto, la cantidad y dos **referencias**: los
precios que la persona vio al agregarlo. Los importes se calculan siempre con el precio vigente; la
referencia sólo sirve para avisar que cambió (D-11).

**La reserva ocurre al continuar, no al agregar** (D-16). Agregar sólo verifica que haya disponible.
`POST /carrito/checkout` reserva cada renglón, todo o nada, por 20 minutos
(`reservas.checkout_minutos`). **Cualquier escritura posterior al carrito suelta la reserva** y lo
vuelve a `activo`. Una tarea programada, cada minuto, suelta los checkouts vencidos.

Las seis rutas son **protegidas** y piden `tienda.comprar`. Cada escritura responde con el carrito entero
(la misma forma que `GET /carrito`), para que la app actualice totales, avisos y la insignia sin otro
pedido. El carrito es un documento con sus renglones, como una venta, y **no se pagina**: paginarlo
partiría los totales.

#### `GET /api/v1/carrito`

El carrito vigente. Sin uno, devuelve uno vacío **sin crearlo**: un `GET` no escribe nada, y su `id`
es `null`. El `id` (desde la revisión 5) es el que nombra la confirmación del pedido (§7.4).

```json
{
  "datos": {
    "id": 12,
    "estado": "activo",
    "estado_texto": "En armado",
    "reservado_hasta": null,
    "unidades": 3,
    "renglones": [
      {
        "id": 41,
        "cantidad": 2,
        "producto": {
          "id": 7,
          "codigo": "MOU-0007",
          "nombre": "Mouse Logitech G505",
          "imagen": "http://10.0.2.2:8000/storage/productos/demo-mouse-g505-1.webp",
          "en_tienda": true,
          "disponibilidad": "disponible",
          "disponibilidad_texto": "Disponible"
        },
        "precios": {
          "contado": { "unitario": "42000.00", "subtotal": "84000.00", "texto": "Efectivo o transferencia" },
          "lista":   { "unitario": "46200.00", "subtotal": "92400.00", "texto": "Mercado Pago" }
        }
      },
      {
        "id": 42,
        "cantidad": 1,
        "producto": {
          "id": 12,
          "codigo": "GAB-0012",
          "nombre": "Gabinete Corsair 4000D",
          "imagen": "http://10.0.2.2:8000/img/producto-sin-imagen.svg",
          "en_tienda": false,
          "disponibilidad": "disponible",
          "disponibilidad_texto": "Disponible"
        },
        "precios": {
          "contado": { "unitario": "98000.00", "subtotal": "98000.00", "texto": "Efectivo o transferencia" },
          "lista":   { "unitario": "107800.00", "subtotal": "107800.00", "texto": "Mercado Pago" }
        }
      }
    ],
    "totales": {
      "contado": { "importe": "84000.00", "texto": "Efectivo o transferencia" },
      "lista":   { "importe": "92400.00", "texto": "Mercado Pago" }
    },
    "avisos": [
      { "producto_id": 7, "descripcion": "Mouse Logitech G505", "tipo": "precio", "lista": "contado", "anterior": "40000.00", "actual": "42000.00" },
      { "producto_id": 12, "descripcion": "Gabinete Corsair 4000D", "tipo": "inactivo" }
    ]
  }
}
```

- `estado`: `activo` («En armado») o `en_checkout` («Reservado mientras confirmás»). `reservado_hasta`
  (ISO 8601 UTC) sólo en checkout; si no, `null`.
- `unidades` es la suma de las cantidades: lo que muestra la insignia de la barra inferior.
- `en_tienda: false`: el producto se desactivó o su categoría se ocultó. El renglón queda para que la
  persona lo vea y lo quite; **no suma a los totales** y frena el checkout.
- `avisos` tiene la misma forma que `cambios` del 409 `carrito_cambio` (abajo): `precio` (uno por lista que cambió, contra la
  referencia), `inactivo`, y `disponibilidad` (`pedida` y el nivel) cuando la cantidad supera el
  disponible. En checkout, lo reservado por el propio carrito cuenta como suyo.
- **Sin `tienda.ver_precios`** no viajan `precios`, `totales` ni los avisos de tipo `precio` (las claves
  no están, ni en `null`).
- Nunca viajan el stock ni las referencias.
- Errores: 401 `sin_sesion` / `sesion_invalida`, 403 `sin_permiso`.

#### `POST /api/v1/carrito/lineas`

Agrega un producto. Si ya está en el carrito, **suma** la cantidad en el mismo renglón.

| Campo | Tipo | Notas |
| --- | --- | --- |
| `producto_id` | entero, requerido | Un producto que esté en la tienda |
| `cantidad` | entero, requerido | De 1 a `carrito.cantidad_maxima` (10) |
| `precio`, `precios`, `importe`, `precio_contado`, `precio_lista`, `contado_referencia`, `lista_referencia`, `subtotal`, `total`, `totales` | — | **Prohibidos**: 422. El precio lo pone el servidor, y uno enviado se rechaza en lugar de ignorarse |

**201** si el renglón es nuevo; **200** si sumó a uno existente. Cuerpo: el carrito, con
`"mensaje": "Agregaste el producto al carrito."`. Las referencias se escriben con el precio vigente.

- Errores: 404 `no_encontrado` (el producto no existe o no está en la tienda), 409 `stock_insuficiente`
  (no hay disponible para la cantidad total del renglón; el mensaje no dice cuántas unidades hay),
  409 `regla_de_negocio` (sumando, el renglón pasaría de 10), 422 `validacion`.

#### `PATCH /api/v1/carrito/lineas/{linea}`

Fija la cantidad de un renglón. Cuerpo: `cantidad` (entero, de 1 a 10); los importes, prohibidos.
**200** con el carrito. Las referencias no cambian: si el precio cambió, el aviso sigue a la vista.

- Errores: 404 `no_encontrado` (el renglón no existe **o es de otra cuenta**: no se distingue),
  409 `stock_insuficiente`, 409 `regla_de_negocio` (el producto ya no está en la tienda: hay que
  quitarlo), 422 `validacion`.

#### `DELETE /api/v1/carrito/lineas/{linea}`

Quita un renglón. **200** con el carrito y `"mensaje": "Quitaste el producto del carrito."`. Para
«deshacer», la app vuelve a agregar el producto con la misma cantidad. Errores: 404 `no_encontrado`.

#### `DELETE /api/v1/carrito`

**Vacía** el carrito: quita todos los renglones y el carrito queda, vacío. **200** con el carrito y
`"mensaje": "Vaciaste el carrito."`. Vaciar uno vacío también es 200.

#### `POST /api/v1/carrito/checkout`

Revalida el carrito y **reserva** el stock. Sin cuerpo (los importes, prohibidos: 422).

1. Si algún producto salió de la tienda o cambió de precio respecto de la referencia: **409
   `carrito_cambio`** con `cambios`. Las referencias de precio **quedan actualizadas**, así que el
   segundo intento, ya revisado, pasa. Un producto fuera de la tienda sigue frenando hasta que se quite.
2. Si no, reserva cada renglón, **todo o nada**: si uno no alcanza, 409 `stock_insuficiente` y no se
   reserva ninguno.
3. Pasa a `en_checkout` y fija `reservado_hasta`, 20 minutos después.

**200**, también en el reintento: con la reserva en plazo, devuelve el mismo resumen y no reserva dos
veces. Si la reserva venció y la tarea todavía no la soltó, se suelta y se reserva de nuevo.

```json
{
  "datos": {
    "id": 12,
    "estado": "en_checkout",
    "estado_texto": "Reservado mientras confirmás",
    "reservado_hasta": "2026-11-11T15:20:00Z",
    "unidades": 2,
    "renglones": [ "… como en GET /carrito …" ],
    "totales": {
      "contado": { "importe": "84000.00", "texto": "Efectivo o transferencia" },
      "lista":   { "importe": "92400.00", "texto": "Mercado Pago" }
    },
    "avisos": [],
    "medios": [
      { "medio": "contado", "texto": "Efectivo en el local", "modos_entrega": ["retiro"], "total": "84000.00" },
      { "medio": "transferencia", "texto": "Transferencia bancaria", "modos_entrega": ["retiro", "envio"], "total": "84000.00" },
      { "medio": "pasarela", "texto": "Mercado Pago", "modos_entrega": ["retiro", "envio"], "total": "92400.00" }
    ],
    "entrega": [
      { "modo": "retiro", "texto": "Retiro en el local", "costo": "0.00" },
      { "modo": "envio", "texto": "Envío a domicilio", "costo": "8000.00" }
    ]
  }
}
```

- `medios`: cuánto sale por cada medio. El efectivo y la transferencia cobran el precio de contado; la
  pasarela, el de lista. El `texto` dice **cómo se paga**, no qué precio es (revisión 12, D-27): el del
  precio de contado sigue siendo «Efectivo o transferencia» en `precios` y `totales`. Sin `tienda.ver_precios`, sin `total`. Cuál se elige lo manda la confirmación (§7.4).
- `modos_entrega` (revisión 13, D-28): los `modo` de `entrega` con que vale ese medio. El efectivo se paga en
  el mostrador: `["retiro"]`. La transferencia y la pasarela, `["retiro", "envio"]`; con el envío apagado,
  `["retiro"]` también. Sale de `tienda.entregas_por_medio`, el mismo lugar que lee la confirmación: la app
  ofrece cada medio con las entregas de su lista y no escribe la regla. Viaja con y sin `tienda.ver_precios`.
- `entrega`: retiro siempre; envío sólo con `tienda.envio.habilitado`, con su costo fijo
  (`tienda.envio.costo`).
- Ejemplo de 409:

```json
{
  "mensaje": "Algunos productos de tu carrito cambiaron. Revisalos antes de seguir.",
  "codigo": "carrito_cambio",
  "errores": {},
  "cambios": [
    { "producto_id": 7, "descripcion": "Mouse Logitech G505", "tipo": "precio", "lista": "contado", "anterior": "40000.00", "actual": "42000.00" }
  ]
}
```

- Errores: 409 `carrito_cambio`, 409 `stock_insuficiente`, 409 `regla_de_negocio` (carrito vacío),
  422 `validacion`.

### 7.4 Pedidos (tramo F)

Un pedido es una **venta online**: la misma fila de `ventas` que el mostrador, con `canal = online`,
sin vendedor y con el cliente de la cuenta. Nace en `pendiente_pago` y se queda con la reserva del
checkout: las unidades no vuelven al disponible hasta que se cobra (salen del depósito), se cancela o
vence.

#### `POST /api/v1/pedidos`

Protegida, `tienda.comprar`. **Confirma** el checkout: convierte el carrito reservado en un pedido.

| Parámetro | Dónde | Tipo | Obligatorio | Regla |
| --- | --- | --- | --- | --- |
| `carrito_id` | cuerpo | entero | sí | El `id` que trae el resumen del checkout (y `GET /carrito`). Ajeno o inexistente: 404 |
| `medio_pago` | cuerpo | texto | sí | `contado` (efectivo, en el local), `transferencia` (al alias de la tienda, con comprobante, §7.7) o `pasarela`. Tiene que valer con `modo_entrega` (los `modos_entrega` del medio en el checkout, D-28): **`contado` con `envio` es 422** en `medio_pago` |
| `modo_entrega` | cuerpo | texto | sí | `retiro`; `envio` sólo con `tienda.envio.habilitado` |
| `direccion` | cuerpo | objeto | con `envio` | `calle` (150), `numero` (10), `piso_depto` (20, opcional), `codigo_postal` (10), `localidad` (100), `provincia` (**una de la lista cerrada**, §5). Con `retiro`, mandarla es 422 |
| importes | cuerpo | — | — | `precio`, `total`, `subtotal`, `costo_envio`, etc.: **prohibidos** (422). Los calcula el servidor |

1. Bloquea el carrito. Si ya es un pedido, devuelve **ese** pedido con **200** (D-8): el reintento
   tras un timeout recibe el mismo número, aunque cambie el medio.
2. Si no está en checkout o la reserva venció: la suelta y **409 `checkout_vencido`**.
3. Revalida como el checkout. Si algo cambió: suelta la reserva, deja las referencias nuevas y **409
   `carrito_cambio`** con `cambios`. Hay que volver a iniciar el checkout.
4. Crea el pedido con los renglones congelados **con la lista del medio** (contado y transferencia →
   precio de contado, pasarela → precio de lista), suma el costo del envío (`tienda.envio.costo`, hoy `8000.00`) y pasa el
   carrito a `convertido`. Todo en una transacción: o queda el pedido entero, o nada.

**201** si nace el pedido; **200** si ya existía.

```json
{
  "datos": {
    "id": 42,
    "numero": "V-00042",
    "estado": "pendiente_pago",
    "estado_texto": "Esperando el pago",
    "medio_pago": "contado",
    "medio_pago_texto": "Efectivo en el local",
    "modo_entrega": "retiro",
    "modo_entrega_texto": "Retiro en el local",
    "direccion": null,
    "reserva_vence": "2026-11-13T15:00:00Z",
    "creado": "2026-11-11T15:00:00Z",
    "lineas": [
      {
        "producto_id": 7,
        "descripcion": "Mouse Logitech G505",
        "cantidad": 2,
        "precio_unitario": "54450.00",
        "total": "108900.00"
      }
    ],
    "importes": {
      "subtotal": "108900.00",
      "costo_envio": "0.00",
      "total": "108900.00"
    }
  }
}
```

- `reserva_vence`: hasta cuándo se guarda la mercadería. De contado, 48 horas
  (`tienda.reservas.contado_horas`): **hay que pagar en el local antes de esa fecha**. Por pasarela,
  20 minutos (`tienda.reservas.pasarela_minutos`), para el pago del tramo G. Pasada la hora, la tarea
  de cada minuto cancela el pedido y libera el stock. `null` cuando ya se cobró o se canceló.
- `direccion`: la del pedido, **copiada**: editar después las direcciones del cliente no la cambia.
  `null` con retiro, como en el ejemplo. Con envío, las seis claves de la tabla (`calle`, `numero`,
  `piso_depto`, `codigo_postal`, `localidad`, `provincia`), y `costo_envio` es el de `tienda.envio.costo`.
  Desde la revisión 13 el ejemplo es de retiro: de contado, el envío ya no vale (D-28).
- Sin `tienda.ver_precios` no viajan `importes` ni `precio_unitario` y `total` de cada renglón.
- Nunca viajan el costo, el vendedor ni el stock.
- Que el pedido esté registrado **no dice que se pagó** (P-4): `estado` es `pendiente_pago` hasta que
  el local lo cobra o la pasarela lo acredita.
- `carrito_id` es la clave de la idempotencia: el reintento manda **el mismo**. Un carrito convertido no
  se reusa; el siguiente `GET /carrito` trae uno nuevo, con otro `id`.
- Errores: 401 `sin_sesion` / `sesion_invalida`, 403 `sin_permiso`, 404 `no_encontrado` (carrito
  ajeno o inexistente), 409 `checkout_vencido`, 409 `carrito_cambio`, 409 `regla_de_negocio` (cuenta
  sin ficha de cliente), 422 `validacion` (con envío, una provincia fuera de la lista de §5 va en
  `direccion.provincia`; de contado con envío, en `medio_pago`, revisión 13).
- Ejemplo del 422 por el medio (D-28):

```json
{
  "mensaje": "El pago en efectivo es sólo para retirar en el local.",
  "codigo": "validacion",
  "errores": {
    "medio_pago": ["El pago en efectivo es sólo para retirar en el local."]
  }
}
```

### 7.5 Pagos (tramo G)

Un pedido por **pasarela** se paga con **intentos**. Un intento es un pedido de cobro a una pasarela
—Mercado Pago (Checkout Pro) o el simulador— y **no es un pago**: el pago es la fila que el servidor
escribe cuando el proveedor confirma, una sola por intento. Un intento rechazado, vencido o que no se
pudo iniciar no deja ningún pago.

**Sólo la consulta al proveedor confirma.** La app abre `url_pago` en el navegador; cuando vuelve al
frente, consulta el intento (`GET …/pagos/{intento}`) y el servidor le pregunta a Mercado Pago. Que el
cliente haya vuelto, o lo que diga la pantalla de Mercado Pago, no cambia nada.

**El intento** (la forma que devuelven los cinco endpoints):

```json
{
  "id": 17,
  "pasarela": "mercadopago",
  "pasarela_texto": "Mercado Pago",
  "estado": "pendiente",
  "estado_texto": "Esperando la confirmación",
  "importe": "121000.00",
  "url_pago": "https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=1234-abcd",
  "vence": "2026-11-13T15:20:00Z",
  "resuelto": null,
  "creado": "2026-11-13T15:00:00Z",
  "pedido": {
    "id": 42,
    "numero": "V-00042",
    "estado": "pendiente_pago",
    "estado_texto": "Esperando el pago",
    "estado_pago": "pendiente",
    "estado_pago_texto": "Esperando la confirmación del pago"
  }
}
```

- `estado` del intento: `pendiente`, `aprobado`, `rechazado`, `vencido` (el plazo pasó **con respuesta**
  del proveedor y sin pago) o `error` (no se pudo iniciar). Ni `vencido` ni `error` afirman un rechazo.
- `url_pago`: sólo con Mercado Pago y sólo mientras está pendiente; con el simulador, siempre `null`.
- `vence`: 20 minutos desde que nace (`tienda.pagos.intento_minutos`). La preferencia de Mercado Pago
  vence a la misma hora.
- `pedido`: el estado del pedido y **del pago**, ya derivados. La app no necesita otro pedido para saber
  el resultado.
- Sin `tienda.ver_precios`, `importe` no viaja. Nunca viajan el identificador del pago del proveedor ni su detalle.

**Estado del pago** (`pedido.estado_pago`), derivado de los datos y no elegido por nadie:

| Situación | `estado_pago` | `estado_pago_texto` |
| --- | --- | --- |
| El pedido está pagado o en un estado posterior | `aprobado` | «Pagado» |
| Pendiente, de contado | `pendiente` | «Se paga en el local» |
| Pendiente, el último intento espera respuesta | `pendiente` | «Esperando la confirmación del pago» |
| Pendiente, el último intento se aprobó y no se acreditó todavía | `en_revision` | «Pago recibido, en revisión» |
| Pendiente, el último intento se rechazó | `rechazado` | «Pago rechazado: podés reintentar» |
| Pendiente, sin intentos, o el último vencido o con error | `pendiente` | «Falta pagar» |
| Cancelado | `sin_cobrar` | «No se cobró» |

**Qué pasa si el pago falla** (D-9). El rechazo **conserva la reserva**: el cliente reintenta, con otra
tarjeta o por la otra pasarela, sobre **el mismo pedido**, y la mercadería lo espera. La reserva dura 20
minutos y **nunca vence con un intento pendiente**: si el intento termina después, la reserva se estira
hasta él. Pasado el plazo sin un pago aprobado, la tarea de cada minuto primero le pregunta al proveedor
por última vez y, si no hay pago, cancela el pedido y devuelve el stock. Un pago aprobado que no se pudo
acreditar (por ejemplo, un producto dado de baja mientras tanto) **no se pierde ni cancela el pedido**:
queda «en revisión» y la tarea reintenta acreditarlo. Si Mercado Pago no contesta, el intento sigue
pendiente: la plata pudo haber entrado, y el sistema no afirma lo que no puede verificar.

#### `POST /api/v1/pedidos/{id}/pagos`

Protegida, `tienda.comprar`. **Inicia** un intento sobre un pedido propio por pasarela, pendiente y con
la reserva vigente.

| Parámetro | Dónde | Tipo | Obligatorio | Regla |
| --- | --- | --- | --- | --- |
| `id` | ruta | entero | sí | Un pedido de la cuenta. Ajeno o inexistente: 404 |
| `pasarela` | cuerpo | texto | sí | `mercadopago` o `simulador` |

**201** con el intento nuevo y `"mensaje": "Iniciaste el pago."`. Si el pedido ya tiene un intento
pendiente, **200** con **ese** intento y `"mensaje": "Ya tenías un pago en curso para este pedido."`
(§6.6 del plan): como mucho un intento pendiente por pedido, aunque el segundo pida la otra pasarela.

- Con Mercado Pago, `url_pago` es adonde mandar al cliente. Si la preferencia no se pudo crear, el
  intento vuelve con `estado: "error"` y se puede iniciar otro.
- Errores: 401, 403 `sin_permiso`, 404 `no_encontrado`, 409 `regla_de_negocio` (pedido de contado,
  cobrado, cancelado o con la reserva vencida; pasarela no disponible: el simulador apagado o Mercado
  Pago sin credencial), 422 `validacion` (`pasarela` fuera de la lista).

#### `GET /api/v1/pedidos/{id}/pagos`

Protegida, `tienda.ver_pedidos`. Los intentos del pedido, **el último primero** (desempata por `id`).
Paginado (§2: `pagina`, `por_pagina`); `criterios` vacío.

```json
{
  "datos": [
    { "id": 18, "pasarela": "simulador", "pasarela_texto": "Simulador de pagos — no es un cobro real", "estado": "pendiente", "...": "la forma del intento" },
    { "id": 17, "pasarela": "mercadopago", "pasarela_texto": "Mercado Pago", "estado": "rechazado", "...": "la forma del intento" }
  ],
  "pagina": 1,
  "por_pagina": 20,
  "ultima_pagina": 1,
  "total": 2,
  "criterios": {}
}
```

Errores: 401, 403, 404 (pedido ajeno), 422 (`por_pagina` sobre el tope).

#### `GET /api/v1/pedidos/{id}/pagos/{intento}`

Protegida, `tienda.ver_pedidos`. **El resultado.** Si el intento está pendiente, **antes de responder el
servidor consulta al proveedor** y aplica lo que dice: aprobado (y acredita el pago), rechazado, o
vencido si el plazo pasó. Es lo que la app llama al volver de Mercado Pago, y lo que ofrece como «Volver
a consultar». **200** con el intento. Un intento de otro pedido, aunque sea propio, es 404.

#### `POST /api/v1/simulador/intentos/{intento}/aprobar` y `…/rechazar`

Protegida, `tienda.comprar`. **Simulador de pagos — no es un cobro real.** Existen **sólo con
`tienda.pagos.simulador` encendido** (`TIENDA_SIMULADOR`); en producción no hay ruta (404). Resuelven un
intento pendiente del simulador por el mismo camino que Mercado Pago: aprobar deja el pedido pagado con un
pago de método «Simulador — no es cobro real»; rechazar no escribe ningún pago y conserva la reserva.

**200** con el intento y `"mensaje": "El simulador aprobó el pago."` (o «…rechazó el pago.»).

Errores: 401, 403, 404 (intento ajeno), 409 `regla_de_negocio` (ya resuelto, no es del simulador, o el
simulador se apagó).

### 7.6 Consulta de compras (tramo H)

**Sólo los pedidos de la cuenta, y sólo los de la tienda** (`canal = online`): las compras del mismo
cliente en el mostrador no son pedidos de la app y no aparecen. El filtro por dueño es la consulta misma
(HL-70): **un pedido ajeno es 404, igual que uno que no existe**, con la misma respuesta.

#### `GET /api/v1/pedidos`

Protegida, `tienda.ver_pedidos`. Mis pedidos, **del más nuevo al más viejo** (desempata por `id`).
Paginado (§2: `pagina`, `por_pagina`); `criterios` vacío.

```json
{
  "datos": [
    {
      "id": 42,
      "numero": "V-00042",
      "creado": "2026-11-11T15:00:00Z",
      "estado": "pendiente_pago",
      "estado_texto": "Esperando el pago",
      "estado_pago": "rechazado",
      "estado_pago_texto": "Pago rechazado: podés reintentar",
      "unidades": 2,
      "importes": { "total": "129000.00" }
    },
    {
      "id": 37,
      "numero": "V-00037",
      "creado": "2026-11-02T18:30:00Z",
      "estado": "entregada",
      "estado_texto": "Entregado",
      "estado_pago": "aprobado",
      "estado_pago_texto": "Pagado",
      "unidades": 1,
      "importes": { "total": "54450.00" }
    }
  ],
  "pagina": 1,
  "por_pagina": 20,
  "ultima_pagina": 1,
  "total": 2,
  "criterios": {}
}
```

- `unidades`: la suma de las cantidades de los renglones («2 productos»), no la cantidad de renglones.
- Los dos estados van **por separado** (`estado` del pedido, `estado_pago` del pago), con la tabla de §7.5.
- Sin `tienda.ver_precios`, `importes` no viaja.

Errores: 401, 403, 422 (`por_pagina` sobre el tope).

#### `GET /api/v1/pedidos/{id}`

Protegida, `tienda.ver_pedidos`. El detalle: **la misma forma que `POST /pedidos`** (§7.4: renglones y
dirección congelados, `reserva_vence`, `importes`), más cuatro claves.

```json
{
  "datos": {
    "id": 42,
    "numero": "V-00042",
    "estado": "pendiente_pago",
    "estado_texto": "Esperando el pago",
    "...": "el resto, como en POST /pedidos",
    "reserva_vence": "2026-11-11T15:20:00Z",
    "estado_pago": "rechazado",
    "estado_pago_texto": "Pago rechazado: podés reintentar",
    "ultimo_pago": {
      "id": 17,
      "pasarela": "mercadopago",
      "pasarela_texto": "Mercado Pago",
      "estado": "rechazado",
      "estado_texto": "Rechazado",
      "creado": "2026-11-11T15:03:00Z"
    },
    "comprobante_disponible": false,
    "transferencia": null
  }
}
```

- Los precios son **los del pedido**: si después cambia el precio del producto, el detalle no cambia.
- `reserva_vence` es el «Pagalo antes del…» mientras está pendiente; `null` cuando se cobró o se canceló.
- `ultimo_pago`: el intento que decide `estado_pago`, o `null` si no hubo ninguno. Los intentos completos
  salen de `GET /pedidos/{id}/pagos`, que pagina (HL-75). «Pagar» o «Reintentar» llevan a
  `POST /pedidos/{id}/pagos`.
- `comprobante_disponible`: `true` sólo si el pedido está cobrado (`estado_pago` = `aprobado`).
- `transferencia` (revisión 12): `null` salvo con `medio_pago` = `transferencia`; ahí trae a quién
  transferir y si llegó el comprobante (forma en §7.7).

Errores: 401, 403, 404 (ajeno o inexistente).

#### `GET /api/v1/pedidos/{id}/comprobante`

Protegida, `tienda.ver_pedidos`. Pide **el enlace del comprobante** (D-22): una URL firmada y temporal que
la app abre en el navegador. **La credencial no viaja en la URL**: la firma es la autorización.

```json
{
  "datos": {
    "url": "https://tienda.ejemplo/api/v1/pedidos/42/comprobante.pdf?expires=1762874100&signature=3f9a…",
    "vence": "2026-11-11T15:15:00Z"
  }
}
```

- `vence`: cinco minutos (`tienda.comprobante_minutos`). Vencido, se pide otro.
- La URL baja el **mismo PDF que la web** (el comprobante de la venta cobrada), sin costo ni vendedor.
  Abierta sin firma, con la firma vencida o tocada (otro pedido): **403** `sin_permiso`.

Errores: 401, 403, 404 (ajeno o inexistente), 409 `regla_de_negocio` (todavía sin pagar, o cancelado:
«El pedido V-00042 todavía no está pagado, así que no tiene comprobante.»).

### 7.7 Transferencia bancaria (tramo L)

Un pedido con `medio_pago` = `transferencia` (D-27) paga el precio de contado. El comprador transfiere el
total al alias de la tienda y carga el comprobante; **la tienda confirma el pago en la web**, con el cobro de
siempre (método `transferencia`), y recién ahí sale el stock (regla 5).

| Situación | `estado_pago` | `estado_pago_texto` |
| --- | --- | --- |
| Sin comprobante | `pendiente` | Falta cargar el comprobante de la transferencia |
| Con comprobante, sin cobrar | `en_revision` | Pago recibido, en revisión |
| Cobrado en la web | `aprobado` | Pagado |

La reserva: **48 horas** para cargar el comprobante (`tienda.transferencia.horas_para_comprobante`); al
cargarlo, se estira a **tres días hábiles** (`tienda.transferencia.dias_habiles_confirmacion`, de lunes a
viernes: los feriados no se conocen). Nunca se acorta.

En `GET /pedidos/{id}` (y en la respuesta de abajo), la clave `transferencia`:

```json
"transferencia": {
  "alias": "COMPUSTACK.TIENDA",
  "titular": "CompuStack S.R.L.",
  "comprobante_cargado": true,
  "aviso": "Recibimos tu comprobante. Dentro de las 72 horas hábiles confirmamos el pago y seguimos con tu pedido."
}
```

- `alias` y `titular` salen de `config/tienda.php`: no se escriben en la app.
- `aviso`: el texto para mostrar una vez cargado el comprobante; `null` si todavía falta o si ya se cobró.

#### `POST /api/v1/pedidos/{id}/comprobante-transferencia`

Protegida, `tienda.cargar_comprobante`. **`multipart/form-data`** (no JSON).

| Parámetro | Dónde | Tipo | Obligatorio | Regla |
| --- | --- | --- | --- | --- |
| `archivo` | cuerpo | archivo | sí | JPG, PNG o PDF, hasta 5 MB |

**201** con el detalle del pedido (la forma de `GET /pedidos/{id}`) y el aviso como `mensaje`:

```json
{
  "mensaje": "Recibimos tu comprobante. Dentro de las 72 horas hábiles confirmamos el pago y seguimos con tu pedido.",
  "datos": {
    "id": 42,
    "numero": "V-00042",
    "estado": "pendiente_pago",
    "estado_texto": "Esperando el pago",
    "medio_pago": "transferencia",
    "medio_pago_texto": "Transferencia bancaria",
    "...": "el resto, como en GET /pedidos/{id}",
    "reserva_vence": "2026-11-18T13:00:00Z",
    "estado_pago": "en_revision",
    "estado_pago_texto": "Pago recibido, en revisión",
    "ultimo_pago": null,
    "comprobante_disponible": false,
    "transferencia": {
      "alias": "COMPUSTACK.TIENDA",
      "titular": "CompuStack S.R.L.",
      "comprobante_cargado": true,
      "aviso": "Recibimos tu comprobante. Dentro de las 72 horas hábiles confirmamos el pago y seguimos con tu pedido."
    }
  }
}
```

- Un segundo comprobante **se suma** al primero, no lo reemplaza: los dos quedan para quien revisa.
- No es idempotente: la app no lo reintenta sola (`_unaVez`).

Errores: 401, 403, 404 (ajeno o inexistente), 422 `validacion` («El comprobante tiene que ser una foto (JPG o
PNG) o un PDF.», «El comprobante puede pesar hasta 5 MB.»), 409 `regla_de_negocio` (no es por
transferencia, ya se cobró o se canceló: «El pedido V-00042 no se paga por transferencia, así que no hace
falta un comprobante.»).

### 7.8 Gestión del catálogo (Etapa 3, tramo D, r15)

La administración web deja de escribir el catálogo por sus rutas propias: marcas, categorías, productos y
proveedores de un producto se leen y se escriben acá, con la misma credencial y los mismos permisos que
cualquier otro cliente (regla 35). **No hay espacio `/admin`** (regla 36): donde el recurso ya existía para la
tienda, la gestión pide **otra representación del mismo recurso** con `?forma=` y su permiso; sin `forma`, la
respuesta es la de siempre, byte a byte.

Reglas comunes a esta sección:

- **Grupo**: protegida común (§4) y un permiso por acción en la ruta (C-2), salvo donde se dice otra cosa.
  Sin el permiso, **403 `sin_permiso`**, nunca 401: la credencial sirve, lo que falta es poder.
- **Los listados paginan** (§2, A-25) con los `criterios` aplicados; un filtro inválido es 422, no se ignora.
- **Las escrituras** devuelven el recurso y un `mensaje` que dice lo que pasó de verdad (el primer proveedor
  queda elegido, la marca con productos se desactiva en lugar de eliminarse). El alta es **201**.
- **Una casilla viaja siempre** (`activo`, `precio_manual`, `es_preferido`): la API no adivina que una ausente
  quiere decir «no» (M-31).
- **Archivos** (el logo de una marca, las imágenes de un producto): `multipart/form-data`. Para editar con
  archivos se manda **`POST` con `_method=PATCH`**: PHP no lee los archivos de un `PATCH` multipart (HL-110).
  Es el mismo endpoint y la misma llamada al servicio.
- **Impresión** (D-22): `GET /<recurso>/impresion` valida filtros y opciones (422) y devuelve una URL firmada;
  el navegador la abre sin credencial. La firma cubre quién la pidió, los filtros y las opciones, y al abrirla
  se vuelve a exigir cuenta activa y el permiso del listado.

```json
{ "datos": { "url": "https://…/api/v1/impresiones/marcas?expires=…&formato=pdf&pedido_por=1&signature=…", "vence": "2026-11-20T16:05:00Z" } }
```

Opciones de impresión: `formato` (`pdf` · `csv`), `columnas[]`, `orientacion`, `hoja`, `densidad`, `agrupar`,
`salto`. Una columna que pide un permiso que no se tiene (el costo sin `precio.ver_costo`) es 422 en
`columnas.N`, no una columna vacía. La vigencia es `impresion.enlace_minutos` (5).

#### Marcas

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /marcas` | `marca.ver` | Listado. `q` (nombre contiene), `estado` (`activas` · `inactivas`); 15 por página |
| `GET /marcas/{id}` | `marca.ver` | La ficha, con `productos` (cuántos la usan) |
| `GET /marcas/impresion` | `marca.ver` | URL firmada del listado, con los mismos filtros |
| `POST /marcas` | `marca.crear` | Alta: `nombre` (2 a 100, único), `activo`, `logo` (jpg, png o webp, hasta 512 KB; sin SVG) |
| `PATCH /marcas/{id}` | `marca.editar` | Edición: lo del alta más `quitar_logo` y `desactivar_productos` |
| `DELETE /marcas/{id}` | `marca.eliminar` | Elimina; con productos, **la desactiva** (regla 2) y lo dice |

```json
{
  "datos": [
    { "id": 1, "nombre": "Logitech", "slug": "logitech", "logo": null, "activo": true, "productos": 1 }
  ],
  "pagina": 1, "por_pagina": 15, "ultima_pagina": 1, "total": 1,
  "criterios": { "q": null, "estado": "activas" }
}
```

```json
{ "datos": { "id": 1, "eliminada": false }, "mensaje": "La marca «Logitech» tiene productos asociados: se desactivó en lugar de eliminarse." }
```

- `logo`: URL absoluta o `null`. El alta devuelve la ficha sin `productos` (es 0) y el mensaje «Marca «X» creada.».
- `desactivar_productos` = 1 al desactivar la marca desactiva también sus productos activos. El mensaje dice
  qué pasó con ellos: ««Logitech» se desactivó junto con sus 3 producto(s).» o «… Sus 3 producto(s) siguen
  activos y a la venta; la marca ya no se va a ofrecer para productos nuevos.».
- Errores: 401, 403, 404, 422 `validacion`.

#### Categorías

`GET /categorias` sigue siendo el árbol de la tienda (§7.1). Desde la r15 está en el grupo **credencial si
sirve**: sin credencial, o con una que no sirve, es el árbol de siempre (antes no se leía la credencial; para la
app no cambia nada). La gestión pide además:

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /categorias?forma=lista` | `categoria.ver` | Listado plano: `q`, `parent_id` (`raiz` o un id), `estado` (`activas` · `inactivas`), `con_hijas`; 15 por página |
| `GET /categorias/{id}` | `categoria.ver` | La ficha, con `ancestros` y `contenido_activo` (para avisar antes de desactivar) |
| `GET /categorias/padres-posibles?categoria=` | `categoria.ver` | Dónde se puede poner: sin sí misma ni sus descendientes, y sin pasar de tres niveles |
| `GET /categorias/impresion` | `categoria.ver` | URL firmada, con los filtros de `forma=lista` |
| `POST /categorias` | `categoria.crear` | Alta: `nombre` (único entre hermanas), `parent_id`, `orden`, `activo` |
| `PATCH /categorias/{id}` | `categoria.editar` | Edición: `parent_id` tiene que venir (`null` es raíz); `desactivar_contenido` |
| `DELETE /categorias/{id}` | `categoria.eliminar` | Elimina; con subcategorías o productos, la desactiva y lo dice |

`forma=lista` **sin** `categoria.ver` es 403; sin credencial, 403 también (no hay sesión que pedir: el árbol
público no la necesita).

```json
{
  "datos": [
    {
      "id": 2, "nombre": "Teclados", "slug": "teclados", "parent_id": 1,
      "ruta": "Periféricos › Teclados", "dentro_de": "Periféricos", "nivel": 2, "orden": 14,
      "activo": true, "subcategorias": 0, "productos": 1
    }
  ],
  "pagina": 1, "por_pagina": 15, "ultima_pagina": 1, "total": 1,
  "criterios": { "forma": "lista", "q": null, "parent_id": "1", "estado": null, "con_hijas": false }
}
```

La ficha suma:

```json
"ancestros": [ { "id": 1, "nombre": "Periféricos" } ],
"contenido_activo": { "subcategorias": 0, "productos": 1 }
```

- `padres-posibles` devuelve la forma de la fila, sin `subcategorias` ni `productos`, paginada (20).
- La baja devuelve `{ "id", "eliminada", "parent_id" }`: la pantalla vuelve al grupo de donde salió.
- Errores: 401, 403, 404, 422 (nombre repetido entre hermanas, padre que formaría un ciclo o un cuarto nivel).

#### Productos

`GET /productos` y `GET /productos/{id}` siguen siendo el catálogo de la tienda (§7.1). Desde la r15 están en
el grupo **sesión opcional de los dos clientes** (HL-108): una credencial de gestión ya no es 401, y sin
`forma` la respuesta es la de la tienda aunque quien pregunte tenga `producto.ver`.

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /productos?forma=gestion` | `producto.ver` | Listado de gestión: también inactivos; 20 por página |
| `GET /productos/{id}?forma=gestion` | `producto.ver` | La ficha de gestión, de cualquier producto |
| `GET /productos/opciones?producto=` | `producto.ver` | Las alícuotas de IVA vigentes, más la del producto aunque se haya dado de baja |
| `GET /productos/impresion` | `producto.ver` | URL firmada, con los filtros de `forma=gestion` |
| `POST /productos` | `producto.crear` | Alta (201). Multipart si lleva `imagenes[]` |
| `PATCH /productos/{id}` | `producto.editar` | Edición. Con imágenes, `POST` + `_method=PATCH` |
| `DELETE /productos/{id}` | `producto.eliminar` | Elimina; con stock o movimientos, lo desactiva y lo dice |
| `POST /productos/{id}/recalcular` | `precio.recalcular` | Recalcula el precio desde el costo y la ganancia |

`forma=gestion` sin sesión es **401 `sin_sesion`** (lo que falta es ingresar); con una cuenta sin
`producto.ver` (la de tienda), **403 `sin_permiso`**.

Filtros de `forma=gestion`:

| Parámetro | Notas |
| --- | --- |
| `q` | Código o nombre contiene, hasta 100 |
| `categoria_id` | La categoría y sus subcategorías |
| `marca_id` | |
| `proveedor_id` | Los que se le compran a ese proveedor. Tiene que existir: uno que no está es 422, no el catálogo entero |
| `estado` | `activos` · `inactivos`; sin él, todos |
| `stock` | `disponible` · `agotado` · `critico` · `sin_proveedor` |
| `con_proveedor` | 1: sólo los que se le pueden pedir a un proveedor activo (el buscador de compras) |
| `excluir[]` | Ids que ya están en la pantalla, hasta 50 (el buscador de compras). Un id inexistente no recorta nada |

```json
{
  "datos": [
    {
      "id": 1,
      "codigo": "TEC-G505",
      "nombre": "Teclado mecánico G505",
      "texto": "TEC-G505 — Teclado mecánico G505",
      "imagen": null,
      "categoria": { "id": 2, "nombre": "Teclados", "ruta": "Periféricos › Teclados", "activo": true },
      "marca": { "id": 1, "nombre": "Logitech", "activo": true },
      "activo": true,
      "destacado": false,
      "precio_manual": false,
      "precios": {
        "contado": { "importe": "78650.00", "texto": "Efectivo o transferencia" },
        "lista":   { "importe": "87400.00", "texto": "Mercado Pago" }
      },
      "disponible": 12,
      "stock_minimo": 4,
      "cantidad_reposicion": 10,
      "sin_proveedor": false,
      "costo_promedio": "52000.00",
      "margen": "20.00",
      "stock": 12,
      "stock_reservado": 0
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1,
  "criterios": {
    "forma": "gestion", "q": "g505", "categoria_id": null, "marca_id": null, "proveedor_id": null,
    "estado": null, "stock": null, "con_proveedor": false, "excluir": []
  }
}
```

**Qué viaja con qué permiso** (regla 36; cada clave tiene su test en las dos direcciones):

| Claves | Permiso |
| --- | --- |
| Todo lo de arriba salvo lo que sigue; `precios`, `disponible` y `stock_minimo` incluidos | `producto.ver` |
| `costo_promedio`, `margen` | `precio.ver_costo` |
| `stock`, `stock_reservado` | `stock.ver` |
| En la ficha: `ganancia`, `ganancia_aplicada`, `historial` | `producto.editar` |
| En la ficha: `calculo` | `precio.ver_costo` |

- `texto` es cómo se nombra al elegirlo, el mismo rótulo de todo el sistema: lo usan los buscadores.
- `imagen`: la primera, URL absoluta, o `null` (la gestión no usa la imagen por omisión de la tienda).
- `sin_proveedor`: se repone (`stock_minimo` > 0) pero no se le puede comprar a nadie activo.

La ficha suma `descripcion`, `alicuota_iva`, `imagenes` (`[{ "ruta", "url" }]`, en orden: la primera es la
principal; `ruta` es lo que se manda en `imagenes_orden` para reordenar o quitar) y, con sus permisos:

```json
"ganancia": null,
"ganancia_aplicada": "1.000",
"calculo": {
  "costo_neto": "52000.00", "ganancia": "1.000", "margen": "0.00", "factores": [],
  "contado": { "neto": "52000.00", "final": "62920.00",
    "impuestos": [ { "nombre": "IVA 21 %", "alicuota": "21.00", "comprobante": "iva", "importe": "10920.00" } ] },
  "lista": { "neto": "52000.00", "final": "62920.00", "impuestos": [ "…" ] }
},
"historial": [
  { "fecha": "2026-11-20T16:00:00Z", "contado_anterior": "95000.00", "contado_nuevo": "99000.00",
    "motivo": "manual", "motivo_texto": "Precio cargado a mano", "usuario": "Bruno" }
]
```

`ganancia` es la propia del producto (`null`: hereda la de su categoría); `ganancia_aplicada`, la que se usa.
`historial` son los últimos cinco cambios de precio.

**Alta y edición.** Cuerpo: `codigo` (se pasa a mayúsculas, único), `nombre`, `descripcion`, `categoria_id`,
`marca_id`, `alicuota_iva` (una de `opciones`), `activo` y `precio_manual` (**obligatorios**), `precio_contado`
y `precio_lista` (con `precio_manual`; aceptan «25.000,50»), `ganancia`, `stock_minimo`, `cantidad_reposicion`,
`imagenes[]` (jpg, png o webp, hasta 1 MB cada una; 5 en total) e `imagenes_orden`: la lista final, en JSON, con
`"actual:<ruta>"` para cada imagen que se queda y `"nueva:<n>"` para el archivo n de `imagenes[]`
(`["nueva:0","actual:productos/a.webp"]`). Sin `imagenes_orden`, las guardadas no se tocan. `destacado` sólo con `producto.editar`, y es opcional:
**ausente quiere decir sin cambio** (el alta de quien no puede destacar no apaga nada). Devuelven la ficha de
gestión y «Producto «X» creado.» / «… actualizado.».

`opciones`:

```json
{ "datos": { "alicuotas": [ { "valor": "21.00", "texto": "IVA 21 %" }, { "valor": "10.50", "texto": "IVA 10,5 %" },
  { "valor": "27.00", "texto": "IVA 27 %" }, { "valor": "0.00", "texto": "Exento" } ] } }
```

`recalcular` devuelve qué pasó, con el texto de `PrecioService`:

```json
{ "datos": { "id": 1, "resultado": "aplicado", "aplicado": true }, "mensaje": "«Teclado mecánico G505»: Precio actualizado." }
```

`resultado` es `aplicado`, `sin_cambio`, `manual` o `sin_costo`; con los tres últimos, `aplicado: false`: el
precio no cambió y el mensaje dice por qué («Tiene el precio fijado a mano: quedó como estaba.»). La baja: `{ "id", "eliminado" }` y el mensaje.

Errores: 401, 403, 404, 422 `validacion`.

#### Proveedores de un producto

Recurso anidado: **el producto lo da la URL**, nunca el cuerpo (`producto_id` en el cuerpo es 422). Un
proveedor que no provee ese producto es 404.

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /productos/{id}/proveedores` | `proveedor.ver` | A quién se le puede pedir, en el orden en que conviene (compras) |
| `GET /productos/{id}/proveedores?forma=comparador` | `producto.editar` | El comparador: los vínculos, por costo |
| `GET /productos/{id}/proveedores?forma=disponibles` | `producto.editar` | Los proveedores activos que todavía no lo proveen, para el alta |
| `POST /productos/{id}/proveedores` | `producto.editar` | Agrega un proveedor (201) |
| `GET /productos/{id}/proveedores/{proveedor}` | `producto.editar` | Un vínculo, para editarlo |
| `PATCH /productos/{id}/proveedores/{proveedor}` | `producto.editar` | Cambia costo, código y preferencia |
| `POST /productos/{id}/proveedores/{proveedor}/preferido` | `producto.editar` | Elige a quién pedirle, sin tocar lo demás |
| `DELETE /productos/{id}/proveedores/{proveedor}` | `producto.editar` | Quita el vínculo |

El permiso del listado depende de la forma, así que lo decide el Form Request y no la ruta.

**A quién pedirle**: sólo activos; el elegido primero, después por costo, y sin costo cargado al final.
`costo_ultimo` viaja sólo con `precio.ver_costo`.

```json
{
  "datos": [
    { "id": 1, "razon_social": "Distribuidora Austral S.A.", "es_preferido": true,  "costo_ultimo": "52000.00" },
    { "id": 2, "razon_social": "Mayorista Sur S.R.L.",       "es_preferido": false, "costo_ultimo": "57200.00" }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 2, "criterios": { "forma": null }
}
```

**El comparador**: por costo, sin costo al final (el criterio de la reposición automática), con el más barato,
cuánto más caro es cada uno (`diferencia`, en %) y el pedido de compra vivo que impide quitarlo:

```json
{
  "datos": [
    {
      "proveedor": { "id": 1, "razon_social": "Distribuidora Austral S.A.", "activo": true, "plazo_entrega_dias": 3 },
      "costo_ultimo": "52000.00", "codigo_proveedor": "AUS-G505", "es_preferido": true,
      "mas_barato": true, "diferencia": null, "pedido_en_curso": null
    },
    {
      "proveedor": { "id": 2, "razon_social": "Mayorista Sur S.R.L.", "activo": true, "plazo_entrega_dias": 8 },
      "costo_ultimo": "57200.00", "codigo_proveedor": null, "es_preferido": false,
      "mas_barato": false, "diferencia": 10, "pedido_en_curso": null
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 2, "criterios": { "forma": "comparador" }
}
```

Con un pedido vivo: `"pedido_en_curso": { "id": 1, "numero": "OC-00001", "estado_texto": "Borrador" }`.

**Disponibles**: `[{ "id", "razon_social" }]`, por razón social.

**Alta y edición.** Cuerpo: `proveedor_id` (sólo en el alta; activo y que no esté ya), `costo_ultimo` y
`codigo_proveedor` (**tienen que venir**: vacío es «no lo sé», ausente es 422, para que omitirlos no blanquee
lo guardado), `es_preferido` (obligatorio). Devuelven el vínculo con su producto:

```json
{
  "datos": {
    "proveedor": { "id": 3, "razon_social": "Insumos del Valle S.A.", "activo": true, "plazo_entrega_dias": 14 },
    "costo_ultimo": "54900.00", "codigo_proveedor": "IV-505", "es_preferido": false,
    "mas_barato": false, "diferencia": null, "pedido_en_curso": null,
    "producto": { "id": 1, "nombre": "Teclado mecánico G505", "codigo": "TEC-G505" }
  },
  "mensaje": "Se agregó Insumos del Valle S.A.."
}
```

- El primer proveedor queda elegido aunque no se marque: «Se agregó X, y es a quien se le va a pedir la
  reposición.». Destildar al elegido no lo cambia, y el mensaje lo dice («Sigue siendo el elegido: para
  cambiarlo, marcá otro.»). En el vínculo suelto, `mas_barato` y `diferencia` no se calculan: son del comparador.
- La baja devuelve `{ "producto_id", "proveedor_id", "quitado": true }`. Si era el elegido, asciende el más
  antiguo de los que quedan, y el mensaje lo dice. Con un pedido de compra vivo es **409 `regla_de_negocio`**:
  «No se puede quitar a Distribuidora Austral S.A. de «Teclado mecánico G505»: la orden OC-00001 está
  «Borrador» y le pide ese producto a ese proveedor. Recibila o cancelala primero.».
- Errores: 401, 403, 404, 409, 422.

#### Proveedores y clientes (mínimos)

Lo que el catálogo y la venta necesitan elegir; el tramo E los completa (§7.9) sin cambiar estas claves.

| Método y ruta | Permiso | Parámetros | Fila |
| --- | --- | --- | --- |
| `GET /proveedores` | `proveedor.ver` | `q`, `estado` (`activos` · `inactivos`) | `{ "id", "razon_social", "activo" }` |
| `GET /clientes` | `cliente.ver` | `q` (razón social, documento, correo o teléfono) | `{ "id", "texto", "razon_social", "documento" }` |

```json
{
  "datos": [ { "id": 1, "texto": "Panadería Los Tilos — DNI 41556778", "razon_social": "Panadería Los Tilos", "documento": "DNI 41556778" } ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1, "criterios": { "q": "tilos" }
}
```

La fila del cliente tiene las claves de la que devuelve el alta rápida de la venta, y en el mismo orden. Ordenados
por razón social; 20 por página.

#### Los buscadores de la administración

Ya no tienen endpoint propio (las rutas `/buscar/…` de la web se retiraron): el selector con búsqueda pide la
primera página del listado de su entidad, con `q`, y si `ultima_pagina` > 1 pide que se afine. Los de venta y
compra mandan `estado=activos`; el del kardex, ninguno. El mínimo de dos caracteres antes de buscar es de la
interfaz: la API busca con cualquier texto de hasta 100.

### 7.9 Terceros: clientes, direcciones y proveedores (Etapa 3, tramo E, r16)

Los tres módulos pasan a escribirse por acá, con la misma credencial y los mismos permisos que cualquier otro
cliente (regla 35). **Se suma, no se cambia** (regla 40): `GET /clientes` y `GET /proveedores` (§7.8, «mínimos») conservan
sus claves y su orden, y lo nuevo va al final. Valen las reglas comunes de §7.8: un permiso por acción en la ruta,
403 `sin_permiso` sin él, listados paginados (20 por página, un filtro inválido es 422), el alta es **201** y las
casillas (`activo`, `es_predeterminada`) **viajan siempre** (M-31). Las escrituras son **JSON** (`PATCH`, sin
archivos: no hace falta el `POST` + `_method` de HL-110).

#### Clientes

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /clientes` | `cliente.ver` | Listado. `q` (razón social, documento, correo o teléfono), `condicion_iva`, `cuenta` (`con_cuenta` · `sin_cuenta`) |
| `GET /clientes/{id}` | `cliente.ver` | La ficha, con sus `direcciones` (la predeterminada primero) |
| `GET /clientes/impresion` | `cliente.ver` | URL firmada del listado, con los mismos filtros (D-22, §7.8) |
| `POST /clientes` | `cliente.crear` | Alta: `razon_social`, `condicion_iva`, `tipo_doc`, `nro_doc`, `email`, `telefono` |
| `PATCH /clientes/{id}` | `cliente.editar` | Edición, con los mismos campos |
| `DELETE /clientes/{id}` | `cliente.eliminar` | Elimina al cliente y sus direcciones; con cuenta de la tienda o con operaciones, **409** `regla_de_negocio` |
| `POST /clientes/{id}/acceso-tienda` | `cliente.dar_acceso_tienda` | Crea la cuenta de la tienda de un cliente del mostrador: `nombre`, `apellido` |

```json
{
  "datos": [
    {
      "id": 1,
      "texto": "Panadería Los Tilos — DNI 41556778",
      "razon_social": "Panadería Los Tilos",
      "documento": "DNI 41556778",
      "tipo_doc": "dni",
      "nro_doc": "41556778",
      "condicion_iva": "consumidor_final",
      "condicion_iva_texto": "Consumidor final",
      "tipo_comprobante": "factura_b",
      "tipo_comprobante_texto": "Factura B",
      "email": "tilos@ejemplo.com",
      "telefono": "2974123456",
      "con_cuenta": false,
      "cantidad_direcciones": 1
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1,
  "criterios": { "q": null, "condicion_iva": null, "cuenta": null }
}
```

- Las cuatro primeras claves y su orden son las de siempre: el alta rápida de la venta recibe la **misma fila** que
  el listado. `cuenta_email` viaja cuando el cliente tiene cuenta de la tienda; `direcciones` sólo en la ficha.
- El alta devuelve el cliente y el mensaje «Se creó el cliente «Panadería Los Tilos». Ya podés agregarle direcciones.»;
  la edición, «Se guardaron los datos de «Panadería Los Tilos».»; la baja, `{ "id": 1, "eliminado": true }` y «Se eliminó a
  «Panadería Los Tilos» y sus direcciones.».
- **El acceso a la tienda devuelve un secreto**: el enlace para que la persona elija su contraseña, de **un solo uso**.
  Viaja **sólo en esta respuesta** (nunca en la ficha ni en el listado), con `Cache-Control: no-store`, y no se registra.
  «Ya tiene cuenta», «no tiene correo» o «el correo es de otra cuenta» son **409 `regla_de_negocio`**. `nombre` es el
  nombre completo en la forma «Apellido, Nombre».

```json
{
  "datos": {
    "cliente_id": 1,
    "nombre": "Tilos, Panadería",
    "enlace_restablecimiento": "https://…/restablecer/5f3c…?email=tilos%40ejemplo.com"
  },
  "mensaje": "Se creó la cuenta de la tienda de «Panadería Tilos»."
}
```

#### Direcciones de un cliente

La dirección se busca **dentro** de las del cliente: la de otro cliente es **404 `no_encontrado`**. No hay `GET` de una
dirección: la lista viaja en `GET /clientes/{id}` (`direcciones`).

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `POST /clientes/{id}/direcciones` | `cliente.editar` | Alta: `calle`, `numero`, `piso_depto`, `codigo_postal`, `localidad`, `provincia` (de la lista de §5), `es_predeterminada` |
| `PATCH /clientes/{id}/direcciones/{direccion}` | `cliente.editar` | Edición, con los mismos campos |
| `DELETE /clientes/{id}/direcciones/{direccion}` | `cliente.editar` | Elimina; si era la predeterminada, la más antigua que queda pasa a serlo |

```json
{
  "datos": {
    "id": 7, "calle": "Av. Eva Perón", "numero": "1450", "piso_depto": null, "codigo_postal": "9011",
    "localidad": "Caleta Olivia", "provincia": "Santa Cruz", "es_predeterminada": true,
    "resumen": "Av. Eva Perón 1450 — Caleta Olivia, Santa Cruz (9011)"
  },
  "mensaje": "Se agregó la dirección y quedó como predeterminada."
}
```

- `cliente_id` en el cuerpo es 422: el cliente es el de la ruta. Los mensajes dicen lo que pasó de verdad: «Se guardó la
  dirección. Sigue siendo la predeterminada: para cambiarla, marcá otra.» si se quiso destildar la única predeterminada.
- La baja devuelve `{ "id": 7, "eliminada": true }`.

#### Proveedores

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /proveedores` | `proveedor.ver` | Listado. `q`, `estado` (`activos` · `inactivos`), `canal` (`email` · `portal_externo` · `manual`) |
| `GET /proveedores/{id}` | `proveedor.ver` | La ficha, con `productos_activos` |
| `GET /proveedores/impresion` | `proveedor.ver` | URL firmada del listado |
| `POST /proveedores` | `proveedor.crear` | Alta: `razon_social`, `cuit`, `email`, `telefono`, `contacto`, `canal_pedido`, `portal_url`, `plazo_entrega_dias`, `activo` |
| `PATCH /proveedores/{id}` | `proveedor.editar` | Edición; suma `desactivar_productos` |
| `DELETE /proveedores/{id}` | `proveedor.eliminar` | Elimina; con productos u órdenes de compra, **lo desactiva** (regla 2) y lo dice |

```json
{
  "datos": [
    {
      "id": 1, "razon_social": "Distribuidora Austral S.A.", "activo": true,
      "cuit": "30712345678", "cuit_formateado": "30-71234567-8", "email": "pedidos@austral.com.ar",
      "telefono": "2974000000", "contacto": "Mesa de pedidos",
      "canal_pedido": "email", "canal_pedido_texto": "Correo electrónico", "portal_url": null,
      "plazo_entrega_dias": 3, "productos": 12, "ordenes_compra": 2
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1,
  "criterios": { "q": null, "estado": null, "canal": null }
}
```

- Las tres primeras claves y su orden son las de siempre. `productos_activos` viaja sólo en la ficha.
- La baja de uno referenciado devuelve `{ "id": 1, "eliminado": false }`. Al desactivar con `desactivar_productos` = 1, el
  mensaje dice cuántos productos se desactivaron con él; sin eso, que siguen activos y a la venta.
- Errores de la sección: 401, 403, 404, 409 `regla_de_negocio`, 422 `validacion`.

### 7.10 Precios y stock (Etapa 3, tramo F, r17)

La parametrización de precios, el ajuste masivo con su historial, el kardex y el ajuste por conteo pasan a escribirse
por acá, con la misma credencial y los mismos permisos que cualquier otro cliente (regla 35). **Todo es nuevo**: ninguna
forma anterior cambia (regla 40). Valen las reglas comunes de §7.8: un permiso por acción en la ruta, 403 `sin_permiso`
sin él, listados paginados de a 20 (también el kardex, que en la web iba de a 15: HL-119), un filtro inválido es 422, el
alta es **201** y las casillas (`activo`, `incluir_manuales`) **viajan siempre** (M-31). Los importes van como cadena; los
multiplicadores y porcentajes, con tres decimales (`"2.500"`, `"15.000"`).

#### Impuestos y factores de costo

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /impuestos` | `precio.ver` | Listado, por `orden`. Los de alcance `producto` suman `productos`: cuántos tienen esa alícuota (lo cuenta la base) |
| `GET /impuestos/{id}` | `precio.ver` | Uno, con el mismo conteo |
| `POST /impuestos` | `precio.editar` | Alta: `nombre`, `alicuota`, `alcance`, `comprobante`, `orden`, `activo` |
| `PATCH /impuestos/{id}` | `precio.editar` | Edición, con los mismos campos; el `alcance` no cambia (422) |
| `DELETE /impuestos/{id}` | `precio.editar` | Elimina; si es la alícuota de algún producto, **lo desactiva** (regla 2) y lo dice |
| `GET /factores-costo` | `precio.ver` | Listado, por `orden` |
| `GET /factores-costo/{id}` | `precio.ver` | Uno |
| `POST /factores-costo` | `precio.editar` | Alta: `nombre`, `modo`, `porcentaje`, `orden`, `activo` |
| `PATCH /factores-costo/{id}` | `precio.editar` | Edición |
| `DELETE /factores-costo/{id}` | `precio.editar` | Elimina; los precios que ya lo tienen adentro no cambian hasta recalcularlos |

```json
{
  "datos": [
    {
      "id": 1, "nombre": "IVA 21 %", "alicuota": "21.00",
      "alcance": "producto", "alcance_texto": "Alícuota de IVA: cada producto elige una",
      "comprobante": "iva", "comprobante_texto": "IVA contenido",
      "orden": 10, "activo": true, "productos": 1
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 4, "criterios": {}
}
```

- Un factor: `{ "id", "nombre", "modo", "modo_texto", "porcentaje", "orden", "activo" }`.
- La baja devuelve `{ "id": 1, "eliminado": true }`, o `false` y «… es la alícuota de productos del catálogo: se desactivó en
  lugar de eliminarse.». Una alícuota repetida o un `orden` ausente son 422.

#### Política y simulación

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /precios/politica` | `precio.ver` | La política vigente |
| `PATCH /precios/politica` | `precio.editar` | `ganancia_global`, `recargo_lista`, `redondeo_multiplo`, `redondeo_modo` |
| `GET /precios/simulacion` | `precio.ver` | Qué precio daría un costo, sin escribir nada: `costo` y `alicuota_iva` obligatorios, `ganancia` opcional |

```json
{
  "datos": {
    "ganancia_global": "2.500", "margen_global": "60.00", "recargo_lista": "0.000",
    "redondeo_multiplo": "0.01", "redondeo_modo": "cercano", "redondeo_modo_texto": "Al múltiplo más cercano"
  }
}
```

`GET /precios/simulacion?costo=20000&alicuota_iva=21.00`:

```json
{
  "datos": {
    "costo": "20000.00",
    "ganancia_es_global": true,
    "alicuota_iva": "21.00",
    "calculo": {
      "costo_neto": "20000.00", "ganancia": "2.500", "margen": "60.00", "factores": [],
      "contado": {
        "neto": "50000.00", "final": "60500.00",
        "impuestos": [{ "nombre": "IVA 21 %", "alicuota": "21.00", "comprobante": "iva", "importe": "10500.00" }]
      },
      "lista": {
        "neto": "50000.00", "final": "60500.00",
        "impuestos": [{ "nombre": "IVA 21 %", "alicuota": "21.00", "comprobante": "iva", "importe": "10500.00" }]
      }
    }
  }
}
```

- `calculo` tiene la **misma forma** que el de la ficha de gestión de un producto (§7.8): las dos salen de
  `App\Support\Api\Desglose`. Sin `ganancia`, se usa la global y `ganancia_es_global` lo dice.
- `alicuota_iva` tiene que ser la de un impuesto de alcance `producto`; si no, 422.

#### Ajuste masivo y su historial

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /precios/ajustes/vista-previa` | `precio.ajustar_masivo` | Los conteos del conjunto entero y una página de «precio actual → nuevo». **No escribe** |
| `POST /precios/ajustes` | `precio.ajustar_masivo` | Aplica el ajuste y devuelve el lote (201) |
| `GET /precios/lotes` | `precio.ver` | El historial, del más nuevo al más viejo, sin filtros |
| `POST /precios/lotes/{id}/deshacer` | `precio.deshacer_ajuste` | Escribe el lote que devuelve los precios (201); sólo el último que se puede deshacer |

Los dos primeros reciben lo mismo, en la consulta o en el cuerpo: `operacion` (`precio` · `costo` · `ganancia` · `redondeo`
· `recalculo`), su parámetro (`porcentaje`, `ganancia` o `redondeo_multiplo`), `alcance` (`todos` · `recorte`), el recorte
(`categoria_id`, que incluye sus subcategorías; `marca_id`, `proveedor_id`, `ids[]`, `costo_desde`/`_hasta`,
`precio_desde`/`_hasta`) e `incluir_manuales`. Un recorte vacío, «todos» con un recorte puesto, un rango al revés, un
porcentaje de 0 o de −100, o una ganancia menor que 1 son 422.

```json
{
  "datos": [
    {
      "id": 1, "codigo": "MEM-DDR4-16", "nombre": "Memoria DDR4 16 GB",
      "precio_contado": "60500.00", "precio_lista": "60500.00",
      "nuevo_contado": "69575.00", "nuevo_lista": "69575.00", "variacion": "15.00",
      "resultado": "aplicado", "resultado_texto": "Cambia precio", "bajo_costo": false,
      "costo": "20000.00", "costo_nuevo": null
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1, "criterios": {},
  "conteos": { "total": 1, "alcanzados": 1, "sin_cambio": 0, "sin_costo": 0, "manuales_afuera": 0, "bajo_costo": 0 },
  "criterio_texto": "Categoría Memorias y sus subcategorías"
}
```

- `costo` y `costo_nuevo` son de `precio.ver_costo`: sin el permiso, **las claves no están** (reglas 21 y 36).
  `costo_nuevo` es null si la operación no toca el costo; `variacion`, si el producto hoy no tiene precio.
- `resultado`: `aplicado` · `sin_costo` · `sin_cambio` · `manual`, con su texto.

`POST /precios/ajustes` y `POST /precios/lotes/{id}/deshacer` devuelven el lote:

```json
{
  "datos": {
    "id": 2, "operacion": "deshacer", "operacion_texto": "Deshacer un ajuste anterior",
    "porcentaje": null, "ganancia": null, "redondeo_multiplo": null, "parametro_texto": null,
    "criterio_texto": "Deshace el ajuste del 21/11/2026 10:30: Categoría Memorias y sus subcategorías",
    "alcanzados": 1, "omitidos": 0, "usuario": "Vallejo, Ximena", "fecha": "2026-11-21T13:30:00Z",
    "revierte_a": { "id": 1, "fecha": "2026-11-21T13:30:00Z" }, "deshecho_el": null
  },
  "mensaje": "Se devolvieron los precios de 1 producto(s)."
}
```

- El criterio queda **congelado como texto**: sigue diciendo «Memorias» aunque la categoría se renombre.
- En el historial cada lote suma `reversible`, y el sobre suma `reversible` (el lote entero, o null): el **único** que se
  puede deshacer, esté en la página que esté. Lo decide el servidor, para que ninguna pantalla ofrezca lo que va a rechazar.
- «No hizo falta hacer nada» es **409 `regla_de_negocio`** con el aviso, no un 201: un ajuste que no alcanza a ningún
  producto, o un deshacer cuyos productos cambiaron todos de precio después (HL-122). Deshacer uno que no es el último
  también es 409, con «Sólo se puede deshacer el último ajuste…».

#### Kardex y ajuste de inventario

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /stock/movimientos` | `stock.ver` | El kardex. `producto_id`, `tipo`, `usuario_id`, `canal` (`mostrador` · `online`), `desde`, `hasta` (días completos) |
| `GET /stock/movimientos/impresion` | `stock.ver` | URL firmada del kardex, con los mismos filtros (D-22, §7.8) |
| `GET /stock/usuarios` | `stock.ver` | Quiénes registraron algún movimiento: las opciones del filtro `usuario_id` |
| `GET /productos/{id}/stock` | `stock.ver` | El stock de un producto y dónde está lo reservado |
| `POST /productos/{id}/ajustes-stock` | `stock.ajustar` | El ajuste por conteo físico: `stock_contado`, `stock_esperado`, `motivo` (201) |

```json
{
  "datos": [
    {
      "id": 1, "fecha": "2026-11-21T13:30:00Z",
      "producto": { "id": 1, "codigo": "MEM-DDR4-16", "nombre": "Memoria DDR4 16 GB" },
      "tipo": "ajuste", "tipo_texto": "Ajuste de inventario",
      "cantidad": -2, "stock_anterior": 10, "stock_resultante": 8,
      "motivo": "Dos unidades con la caja rota", "origen_texto": null, "remito_texto": null,
      "registrado_por": "Ximena Vallejo"
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1,
  "criterios": { "producto_id": null, "tipo": null, "usuario_id": null, "canal": null, "desde": null, "hasta": null }
}
```

- Del más nuevo al más viejo. `origen_texto` dice de dónde salió en palabras del negocio («Venta V-00042 · tienda
  online», «Orden de compra OC-00007», «Documento interno»), nunca una clase. Un cobro de la tienda sin cajero lo registró
  «Tienda online (cobro automático)».
- Un rango al revés es 422 en `hasta` («… no puede ser anterior a «desde»: no devolvería nada.»); en la web volvía al
  kardex limpio con un aviso (HL-120).

`GET /productos/{id}/stock`:

```json
{
  "datos": {
    "id": 1, "codigo": "MEM-DDR4-16", "nombre": "Memoria DDR4 16 GB", "texto": "MEM-DDR4-16 — Memoria DDR4 16 GB",
    "activo": true, "stock": 10, "stock_reservado": 0, "disponible": 10, "stock_minimo": 2,
    "reservas": { "pedidos": 0, "carritos": 0 }
  }
}
```

- `reservas`: cuánto de lo reservado está en pedidos de la tienda sin cobrar y cuánto en compras que se están confirmando
  (D-25). Lo reservado no tiene asiento en el kardex: sale del depósito al cobrar (regla 5).
- El ajuste pide el **conteo**, no la diferencia, y devuelve el movimiento con «El stock de «Memoria DDR4 16 GB» bajó de 10 a 8
  (-2).». `stock_esperado` es el stock que la pantalla mostraba: si al guardar es otro, alguien movió el stock mientras se
  contaba y es **409 `regla_de_negocio`**, sin pisar ese movimiento. Un conteo igual al registrado también es 409.
  `producto_id` o `tipo` en el cuerpo son 422 (el producto es el de la ruta; el tipo lo pone el sistema).
- Errores de la sección: 401, 403, 404, 409 `regla_de_negocio`, 422 `validacion`.

### 7.11 Compras (Etapa 3, tramo G, r18)

Las órdenes de compra, el armador de pedidos, la recepción con su remito y las transiciones pasan a escribirse por acá,
con la misma credencial y los mismos permisos que cualquier otro cliente (regla 35). **Todo es nuevo**: ninguna forma
anterior cambia (regla 40). Valen las reglas comunes de §7.8: un permiso por acción en la ruta (ver, crear, editar, aprobar
y recibir son cinco permisos distintos, C-2), 403 `sin_permiso` sin él, listados de a 20, un filtro inválido es 422, una
transición que la tabla no admite es **409 `regla_de_negocio`** con el mismo mensaje que daba la web.

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /compras` | `compra.ver` | El listado, del más nuevo al más viejo. `q` (número), `estado` (los del filtro, más `abiertas`), `origen` (`reposicion` · `manual`), `proveedor_id`, `desde`, `hasta` |
| `GET /compras/impresion` | `compra.ver` | URL firmada del listado, con los mismos filtros (D-22, §7.8) |
| `GET /compras/proveedores` | `compra.ver` | Los proveedores para elegir: todos, o con `activos=1` sólo los activos (HL-125) |
| `GET /compras/{id}` | `compra.ver` | La ficha: proveedor, líneas, recepciones y `acciones` |
| `GET /compras/{id}/pdf` | `compra.ver` | URL firmada del pedido formal (HL-126); sin aprobar, 409 |
| `GET /compras/sugeridos` | `compra.crear` | Qué hay que reponer, con su proveedor; el sobre suma `grupos` y `sin_proveedor` |
| `GET /compras/armador` | `compra.crear` | Los sugeridos elegidos (`productos[]`) como renglones del armador (HL-124) |
| `POST /compras` | `compra.crear` | El pedido del armador: **un borrador por proveedor** (201, las órdenes creadas) |
| `PATCH /compras/{id}` | `compra.editar` | Edición del borrador: `proveedor_id`, `observaciones`, `lineas[]`; aprobada, 409 |
| `POST /compras/{id}/aprobar` | `compra.aprobar` | Compromete el monto y congela las líneas |
| `POST /compras/{id}/enviada` | `compra.aprobar` | Anota que el pedido salió; el envío lo hace la persona |
| `POST /compras/{id}/cancelar` | `compra.aprobar` | Cancela sin mover stock |
| `POST /compras/{id}/recepciones` | `compra.recibir` | Recepción total o parcial: `cantidades{línea: n}`, `remito_numero`, `remito_fecha` (201) |
| `POST /compras/{id}/cerrar` | `compra.recibir` | Cierra con lo que llegó: el resto no va a llegar |

`GET /compras`:

```json
{
  "datos": [
    {
      "id": 7, "numero": "OC-00007", "fecha": "2026-11-21T13:30:00Z",
      "proveedor": { "id": 2, "razon_social": "Distribuidora Austral" },
      "estado": "borrador", "estado_texto": "Borrador", "generada_por_el_sistema": false,
      "total_estimado": "25000.00", "editable": true, "productos": 1
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1,
  "criterios": { "q": null, "estado": null, "origen": null, "proveedor_id": null, "desde": null, "hasta": null }
}
```

- `editable` lo decide el modelo: sólo un borrador se edita. `generada_por_el_sistema` es que la creó la reposición
  automática, sin una persona.
- Un rango al revés es 422 en `hasta`; un estado u origen que no existe, 422.

`GET /compras/{id}` (la misma forma vuelve en `PATCH`, en las transiciones y en la recepción):

```json
{
  "datos": {
    "id": 7, "numero": "OC-00007", "fecha": "2026-11-21T13:30:00Z",
    "proveedor": {
      "id": 2, "razon_social": "Distribuidora Austral", "activo": true, "cuit_formateado": "30-71234567-8",
      "contacto": "Laura Benítez", "email": "pedidos@austral.com.ar", "telefono": "0381 4221100",
      "canal_pedido": "email", "canal_pedido_texto": "Correo electrónico", "portal_url": null, "plazo_entrega_dias": 3
    },
    "estado": "recibida_parcial", "estado_texto": "Recibida parcial", "generada_por_el_sistema": false,
    "total_estimado": "25000.00", "editable": false, "productos": 1,
    "observaciones": "Entregar por la tarde",
    "creada_por": "Ximena Vallejo", "fecha_aprobacion": "2026-11-21T14:00:00Z", "aprobada_por": "Ximena Vallejo",
    "fecha_envio": "2026-11-21T14:10:00Z",
    "unidades_recibidas": 4, "unidades_pendientes": 6,
    "lineas": [
      {
        "id": 11,
        "producto": { "id": 1, "codigo": "TEC-00123", "nombre": "Teclado mecánico", "activo": true },
        "codigo_proveedor": "AUS-77",
        "cantidad_pedida": 10, "cantidad_recibida": 4, "cantidad_pendiente": 6,
        "costo_unitario": "2500.00", "subtotal": "25000.00"
      }
    ],
    "recepciones": [
      {
        "id": 31, "fecha": "2026-11-22T12:00:00Z",
        "producto": { "id": 1, "codigo": "TEC-00123", "nombre": "Teclado mecánico" },
        "tipo": "compra", "tipo_texto": "Recepción de compra",
        "cantidad": 4, "stock_anterior": 2, "stock_resultante": 6,
        "motivo": null, "origen_texto": "Orden de compra OC-00007", "remito_texto": "Remito 0001-00001234 del 21/11/2026",
        "registrado_por": "Ximena Vallejo"
      }
    ],
    "acciones": {
      "editar": false, "aprobar": false, "enviar": false, "recibir": true,
      "cerrar": true, "cancelar": false, "imprimir": true
    }
  }
}
```

- `acciones` dice qué admite **el estado** (la tabla de transiciones y lo que falta recibir), no qué admite el permiso: eso
  lo decide la ruta en cada petición y el `@can` de cada botón. Ninguna pantalla lo calcula (regla 9). `cerrar` sólo vale si
  entró algo y falta algo.
- `recepciones` son los asientos del kardex con la forma de `GET /stock/movimientos` (§7.10), del más nuevo al más viejo.
- `producto.activo` en cada línea: la edición del borrador avisa «(dado de baja)».

`GET /compras/sugeridos`:

```json
{
  "datos": [
    {
      "id": 1, "codigo": "TEC-00123", "nombre": "Teclado mecánico",
      "disponible": 1, "stock_minimo": 5, "cantidad_reposicion": 10,
      "proveedor": { "id": 2, "razon_social": "Distribuidora Austral" },
      "costo_ultimo": "2500.00"
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 1, "total": 1, "criterios": {},
  "grupos": [
    { "proveedor": { "id": 2, "razon_social": "Distribuidora Austral" }, "productos": [1], "estimado": "25000.00" }
  ],
  "sin_proveedor": 0,
  "estimado_pagina": "25000.00"
}
```

- `costo_ultimo`, `grupos[].estimado` y `estimado_pagina` son de `precio.ver_costo`: sin el permiso, **las claves no
  están** (reglas 21 y 36). Los grupos son de la página, en orden alfabético. `sin_proveedor` cuenta los que hacen falta y
  nadie provee.

`GET /compras/armador?productos[]=1&productos[]=4`:

```json
{
  "datos": [
    {
      "producto": { "id": 1, "codigo": "TEC-00123", "nombre": "Teclado mecánico" },
      "proveedor": { "id": 2, "razon_social": "Distribuidora Austral" },
      "cantidad_pedida": 10, "costo_unitario": "2500.00"
    }
  ],
  "descartados": 1,
  "aviso": "Uno de los productos que elegiste ya no hace falta pedirlo: le cargaron stock o ya tiene un pedido en curso. No se agregó.",
  "hay_pedibles": true
}
```

- Rechaza, no corrige (M-31): lo que dejó de hacer falta no entra, y `descartados` lo cuenta. El renglón propone el último
  costo del proveedor aun sin `precio.ver_costo`, como lo hacía el armador de la web (HL-127). `hay_pedibles` es false si
  ningún producto activo tiene un proveedor activo.

`POST /compras` recibe `lineas[]` con `producto_id`, `proveedor_id`, `cantidad_pedida` y `costo_unitario` (hasta 50) y
devuelve las órdenes creadas con la forma del listado, más «Se crearon 2 pedidos en borrador: OC-00008 a Distribuidora
Austral, OC-00009 a Mayorista del Litoral. Hay que aprobar cada uno para poder enviarlo.». Un producto que ese proveedor no
provee, o repetido, es 422 en su renglón (`lineas.2.producto_id`).

`POST /compras/{id}/recepciones`:

```json
{ "cantidades": { "11": 4 }, "remito_numero": "0001-00001234", "remito_fecha": "2026-11-21" }
```

- Devuelve **201** con la ficha y «Se registraron 4 unidad(es) con el remito 0001-00001234. Quedan 6 pendientes, así que la
  orden sigue parcial. …». Escribe hechos nuevos (los asientos, por `StockService::recibirCompra`), no pisa nada.
- El número y la fecha del remito van juntos o ninguno (422). Recibir más de lo pendiente, o en un estado que no recibe, es
  409 `regla_de_negocio`.

`GET /compras/{id}/pdf` devuelve `{ "url", "vence" }` como la impresión de los listados; el enlace no lleva la credencial y,
al abrirlo, se vuelve a exigir que quien lo pidió siga activo y con `compra.ver`. Un borrador es 409 («… todavía no se
aprobó. El pedido se imprime cuando alguien autoriza el gasto, no antes.»). Descargar no cambia el estado.

- Errores de la sección: 401, 403, 404, 409 `regla_de_negocio`, 422 `validacion`.

### 7.12 Ventas, panel e informes (Etapa 3, tramo H, r19)

Las ventas de la administración —presupuesto, edición, recotización, cobro, entrega, devolución, cancelación y sus
documentos—, los dos paneles y los dos informes pasan a leerse y escribirse por acá, con la misma credencial y los mismos
permisos que cualquier otro cliente (regla 35). **Todo es nuevo**: ninguna forma anterior cambia (regla 40). Los pedidos
del cliente siguen en `/pedidos` (§7.4 a §7.7), con `tienda.*`: es el mismo modelo con otro alcance y otro permiso. Valen
las reglas comunes de §7.8: un permiso por acción en la ruta (C-2), 403 `sin_permiso` sin él, un filtro o un período
inválido es 422, una transición que la tabla no admite es **409 `regla_de_negocio`** con el mismo mensaje que daba la web.

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /ventas` | `venta.ver` | El listado, del más nuevo al más viejo. `q` (número o cliente), `estado`, `cliente_id`, `vendedor_id`, `canal`, `pago` (`transferencia_en_revision`, …), `desde`, `hasta` |
| `GET /ventas/vendedores` | `venta.ver` | Las opciones del filtro: quienes cargaron ventas (`{ id, nombre }`, paginado) |
| `GET /ventas/impresion` | `venta.ver` | URL firmada del listado, con los mismos filtros (D-22, §7.8) |
| `GET /ventas/{id}` | `venta.ver` | La ficha: cliente, renglones congelados, entregas, comprobantes, pagos, `cobro`, `devolucion` y `acciones` |
| `GET /ventas/{id}/pdf` | `venta.ver` | URL firmada del presupuesto o del comprobante (HL-129); cancelada, 409 |
| `GET /ventas/{id}/entregas/{entrega}/pdf` | `venta.ver` | URL firmada del comprobante interno de una entrega (HL-130); de otra venta, 404 |
| `GET /ventas/{id}/comprobantes-transferencia/{c}` | `venta.ver_comprobante_transferencia` | URL firmada del archivo que cargó el cliente (HL-130); se abre en línea |
| `POST /ventas` | `venta.crear` | Emite el presupuesto: `cliente_id`, `descuento_porcentaje`, `observaciones`, `lineas[{producto_id, cantidad}]` (201) |
| `PATCH /ventas/{id}` | `venta.editar` | Edita el presupuesto; los renglones que ya estaban conservan su precio. Cobrada, 409 |
| `POST /ventas/{id}/recotizar` | `venta.editar` | Trae los precios de hoy a todos los renglones; `mensaje` dice qué cambió |
| `POST /ventas/{id}/cancelar` | `venta.editar` | Cancela el presupuesto o el pedido sin cobrar; el pedido libera su reserva |
| `POST /ventas/{id}/pagos` | `venta.cobrar` | Cobra: `pagos[{metodo, monto}]` por el saldo exacto; descuenta el stock (201) |
| `POST /ventas/{id}/entregas` | `venta.entregar` | Sin cuerpo entrega todo lo pendiente; con `cantidades{renglón: n}`, una parte (201) |
| `POST /ventas/{id}/devoluciones` | `venta.anular` | `cantidades{renglón: n}`, `metodo`, `motivo`; el monto lo calcula el servidor (201) |
| `GET /panel` | `panel.ver_propio` | Lo que vendí y cobré en el período (`desde`, `hasta`), contra el anterior |
| `GET /panel/general` | `panel.ver_global` | El conjunto del período, lo que requiere atención y las dos series |
| `GET /informes/vendedores` | `panel.ver_global` | El ranking del período, de a 15, con `totales` |
| `GET /informes/productos` | `panel.ver_global` | Los más vendidos del período por unidades netas, de a 15 |

`GET /ventas`:

```json
{
  "datos": [
    {
      "id": 10015, "numero": "V-10015", "fecha": "2026-11-22T23:39:00Z",
      "cliente": { "id": 5005, "razon_social": "Herrera, Rodrigo" }, "cliente_texto": "Herrera, Rodrigo",
      "vendedor": "Aguirre, Micaela", "canal": "mostrador", "canal_texto": "Mostrador",
      "estado": "presupuesto", "estado_texto": "Presupuesto", "total": "1871500.00",
      "editable": true, "productos": 4, "renglones_sin_stock": 0, "sin_stock": false
    }
  ],
  "pagina": 1, "por_pagina": 20, "ultima_pagina": 501, "total": 10016,
  "criterios": { "q": null, "estado": null, "cliente_id": null, "cliente": null, "vendedor_id": null, "vendedor": null,
                 "canal": null, "pago": null, "desde": null, "hasta": null }
}
```

- `cliente` y `vendedor` de `criterios` son los nombres del filtrado por id, para el chip de la pantalla.
- `sin_stock` es la insignia: sólo un presupuesto con algún renglón que supera el disponible de hoy.
- Sin cliente identificado, `cliente` es `null` y `cliente_texto` dice «Sin identificar».

`GET /ventas/{id}` (la misma forma vuelve en `POST`, `PATCH`, las transiciones, el cobro, la entrega y la devolución, con
`mensaje`):

```json
{
  "datos": {
    "id": 10015, "numero": "V-10015", "fecha": "2026-11-22T23:39:00Z",
    "cliente": {
      "id": 5005, "razon_social": "Herrera, Rodrigo", "texto": "Herrera, Rodrigo — DNI 90004997",
      "tipo_doc_texto": "DNI", "nro_doc": "90004997", "condicion_iva_texto": "Consumidor final",
      "telefono": "297-5004997", "email": "cliente4997@volumen.local"
    },
    "cliente_texto": "Herrera, Rodrigo", "vendedor": "Aguirre, Micaela",
    "canal": "mostrador", "canal_texto": "Mostrador", "estado": "presupuesto", "estado_texto": "Presupuesto",
    "total": "1871500.00", "editable": true, "productos": 4, "sin_stock": false,
    "es_online": false, "medio_pago": null, "modificada": null, "observaciones": null,
    "importes": {
      "subtotal": "1871500.00", "descuento": "0.00", "descuento_porcentaje": "0.00",
      "total": "1871500.00", "pagado": "0.00", "saldo": "1871500.00"
    },
    "unidades_pendientes_de_entregar": 10,
    "lineas": [
      {
        "id": 25019,
        "producto": { "id": 2033, "codigo": "VOL-02022", "rotulo": "VOL-02022 — Cableado Atomlux Patch cord MX-3022", "activo": true },
        "cotizacion_hoy": { "precio": "112000.00", "disponible": 188 },
        "descripcion": "Cableado Atomlux Patch cord MX-3022", "alicuota_iva": "21.00",
        "cantidad": 4, "cantidad_entregada": 0, "cantidad_devuelta": 0,
        "pendiente_de_entregar": 4, "pendiente_de_devolver": 4,
        "precio_unitario": "112000.00", "neto": "370247.93", "iva": "77752.07", "impuestos": [], "total": "448000.00",
        "faltante": null
      }
    ],
    "comprobantes_transferencia": [],
    "entregas": [],
    "entregada_sin_comprobante": false,
    "pagos": [],
    "medios": [
      { "metodo": "efectivo", "texto": "Efectivo" },
      { "metodo": "transferencia", "texto": "Transferencia" },
      { "metodo": "qr", "texto": "QR" }
    ],
    "cobro": { "principal": "efectivo", "problemas": [] },
    "devolucion": null,
    "acciones": {
      "editar": true, "recotizar": true, "cobrar": true, "entregar": false, "entregar_parte": false,
      "devolver": false, "cancelar": true, "imprimir": true
    }
  }
}
```

- `acciones` dice qué admite **el estado** (la tabla de transiciones y lo pendiente), no el permiso: eso lo decide la ruta
  en cada petición, y la pantalla lo refleja con un `@can` por botón (regla 12). `entregar_parte` es falso con una sola
  unidad pendiente: no hay parte que elegir.
- `cobro` viene sólo si se puede cobrar: `principal` es el medio donde se propone el saldo (transferencia si el pedido dijo
  transferencia, D-27) y `problemas` los renglones que harán fallar el cobro, en palabras: la baja del producto antes que
  el faltante. `devolucion` viene sólo si se puede devolver: `sugerido` es el medio del cobro más grande (HL-131).
- `cotizacion_hoy` (precio de contado y disponible de hoy) y `faltante` viajan sólo en un presupuesto: son el aviso de
  la edición y de la ficha, no el stock del producto. Cobrada, son `null`.
- `entregas[]` trae `{ id, numero, fecha, quien, unidades }`; `pagos[]`, `{ id, fecha, metodo, metodo_texto, monto,
  es_devolucion, registro }`, con la devolución en negativo; `comprobantes_transferencia[]`, `{ id, nombre_original,
  fecha }`.
- `mensaje` de cada operación es el texto que daba la web. El del alta y la edición suma, si hay renglones que superan
  el disponible, cuántos son: avisar no es impedir, un presupuesto no compromete stock.
- El cobro es por el saldo exacto: otra suma es 409 `regla_de_negocio` sin errores por campo, y la venta no se cobra.
  Un renglón sin stock o dado de baja también es 409. Un importe mal escrito es 422 en `pagos.{i}.monto`.
- Los tres documentos devuelven `{ "url", "vence" }` como la impresión de los listados (D-22). El enlace no lleva la
  credencial y, al abrirlo, se vuelve a exigir que quien lo pidió siga activo y con el permiso; firma vencida o tocada,
  403. El de la venta lleva su propia ruta y no la de la tienda, que exige lo cobrado (HL-129).

`GET /panel`:

```json
{
  "datos": {
    "periodo": { "desde": "2026-11-01", "hasta": "2026-11-23", "texto": "Del 01/11/2026 al 23/11/2026" },
    "vendi": { "cantidad": 1, "facturado": "360000.00", "ticket": "360000.00" },
    "cobre": { "movimientos": 0, "neto": "0.00" },
    "presupuestos_abiertos": 0,
    "anterior": {
      "desde": "2026-10-09", "hasta": "2026-10-31", "texto": "entre el 09/10 y el 31/10",
      "vendi": { "cantidad": 0, "facturado": "0.00", "ticket": "0.00" },
      "cobre": { "movimientos": 0, "neto": "0.00" }
    },
    "comparaciones": {
      "vendi_cantidad": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "vendi_facturado": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "vendi_ticket": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "cobre_neto": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "cobre_movimientos": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" }
    }
  }
}
```

`GET /panel/general`:

```json
{
  "datos": {
    "periodo": { "desde": "2026-11-01", "hasta": "2026-11-23", "texto": "Del 01/11/2026 al 23/11/2026" },
    "cobrado": "426477552.00", "facturado": "440947552.00", "cantidad": 352, "ticket": "1252691.91",
    "descuentos": "5965648.00", "margen_bruto": "165433602.16",
    "por_metodo": [
      { "metodo": "qr", "metodo_texto": "QR", "movimientos": 151, "neto": "159528430.00" },
      { "metodo": "transferencia", "metodo_texto": "Transferencia", "movimientos": 149, "neto": "156599420.00" },
      { "metodo": "efectivo", "metodo_texto": "Efectivo", "movimientos": 116, "neto": "110349702.00" }
    ],
    "por_dia": [
      { "dia": "2026-11-01", "cantidad": 17, "facturado": "22326420.00" },
      { "dia": "2026-11-02", "cantidad": 15, "facturado": "23005700.00" }
    ],
    "stock_critico": 276, "compras_abiertas": 755, "borradores_de_reposicion": 18,
    "anterior": { "desde": "2026-10-09", "hasta": "2026-10-31", "texto": "entre el 09/10 y el 31/10",
                  "cobrado": "0.00", "facturado": "0.00", "margen_bruto": "0.00" },
    "comparaciones": {
      "cobrado": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "facturado": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" },
      "margen_bruto": { "sentido": "sin_datos", "porcentaje": null, "texto": "Sin datos del período anterior" }
    }
  }
}
```

- Por omisión, del primero del mes a hoy; el anterior es del mismo largo y pegado. Una fecha final anterior a la inicial
  es 422 en `hasta`.
- La frase de cada comparación la arma el servidor (`Comparacion`): sin base no hay porcentaje (invariante 16), y la
  regla no se repite en el cliente. `sentido` es `mas`, `menos`, `igual` o `sin_datos`.
- `por_dia` y `por_metodo` vienen agregados por la base (A-26): el gráfico y las tablas los dibujan, no cuentan. Un medio
  puede quedar negativo si volvió por ahí más de lo que entró. `margen_bruto` es antes del descuento de cabecera, que se
  informa al lado en `descuentos`.
- Lo que requiere atención (`stock_critico`, `compras_abiertas`, `borradores_de_reposicion`) es de hoy y no se compara.
- El panel propio no trae ninguna clave del conjunto.

`GET /informes/vendedores` (`/informes/productos` tiene la misma forma, sin `totales`, con filas `{ producto_id, codigo,
nombre, unidades, importe }`):

```json
{
  "datos": [
    { "usuario_id": 10, "vendedor": "Luna, Sofía", "cantidad": 64, "facturado": "103666200.00", "ticket": "1619784.38" }
  ],
  "pagina": 1, "por_pagina": 15, "ultima_pagina": 1, "total": 9,
  "criterios": { "desde": "2026-11-01", "hasta": "2026-11-23", "texto": "Del 01/11/2026 al 23/11/2026", "elegido": false },
  "totales": { "cantidad": 352, "facturado": "440947552.00", "ticket": "1252691.91" }
}
```

- `totales` es la misma consulta sin agrupar: suma lo que listan todas las páginas, no sólo ésta. Es la única clave de
  más sobre la forma de un listado (§2), y sólo en este endpoint.
- `elegido` dice si la consulta traía fechas: el vacío del mes en curso y el de un período elegido dicen cosas distintas.
- Las ventas online, sin vendedor, no entran al ranking; un producto que volvió entero no es de los más vendidos.

- Errores de la sección: 401, 403, 404, 409 `regla_de_negocio`, 422 `validacion`.

### 7.13 Usuarios y accesos (Etapa 3, tramo I, r20)

El personal, los roles y las dos contraseñas —la propia y la que se fija con el enlace— pasan a leerse y escribirse por
acá. Con este tramo **ninguna ruta web escribe**: `routes/web.php` sólo entrega páginas y descargas firmadas. **Todo es
nuevo**: ninguna forma anterior cambia (regla 40). Valen las reglas comunes de §7.8: un permiso por acción (C-2), 403
`sin_permiso` sin él, un filtro inválido es 422, una regla del servicio es **409 `regla_de_negocio`** con el mismo mensaje
que daba la web. Una cuenta de la tienda pedida por `/personal/{id}` es 404: el personal es sólo el ámbito gestión.

| Método y ruta | Permiso | Qué hace |
| --- | --- | --- |
| `GET /personal` | `usuario.ver` | El listado, por apellido, de a 15. `q` (nombre, apellido, correo, legajo o DNI), `rol_id`, `estado`, `situacion` |
| `GET /personal/roles` | `usuario.ver` o `usuario.crear` | Las opciones del filtro y del alta: los roles de gestión (`{ id, texto, descripcion }`, paginado) |
| `GET /personal/{id}` | `usuario.ver` | La ficha; con `usuario.cambiar_rol` suma la descripción y los permisos del rol y `roles_asignables` |
| `POST /personal` | `usuario.crear` | Alta: `nombre`, `apellido`, `email`, `rol_id`, `password` + `password_confirmation`, `activo`, `empleado{legajo, dni, telefono, fecha_ingreso}` (201) |
| `PATCH /personal/{id}` | `usuario.editar` | Edición: lo mismo sin rol ni contraseña, con `empleado.fecha_baja`. `activo` es obligatorio (M-31: no se completa) |
| `DELETE /personal/{id}` | `usuario.eliminar` | Baja; con operaciones registradas, desactiva (`eliminado: false`); la propia cuenta, 409 |
| `PUT /personal/{id}/rol` | `usuario.cambiar_rol` | `rol_id`, entre los asignables; el rol propio, 409 |
| `POST /personal/{id}/restablecer-contrasena` | `usuario.resetear_password` | El enlace de un solo uso, sin caché (molde `acceso-tienda`, §7.9); la propia cuenta o una sin acceso, 409 |
| `GET /roles` | `rol.ver` | Los roles, los de sistema primero, de a 15, con los conteos y `se_puede_eliminar` |
| `GET /roles/{id}` | `rol.ver` | El rol con los ids de sus `permisos` |
| `GET /permisos` | `rol.ver` | El catálogo de permisos, por módulo (`{ id, clave, modulo, descripcion }`, paginado) |
| `POST /roles` | `rol.editar` | `nombre`, `ambito`, `descripcion`, `permisos[]` (ids); uno de gestión sin permisos, 422 (201) |
| `PATCH /roles/{id}` | `rol.editar` | Lo mismo; un rol de sistema conserva nombre y ámbito; el rol propio, 409 |
| `DELETE /roles/{id}` | `rol.editar` | Uno de sistema o con usuarios, 409. Devuelve `{ id, eliminado }` |
| `PUT /yo/contrasena` | ninguno (protegida) | Cambia la propia: `password_actual`, `password` + `password_confirmation`. **Rota la credencial** |
| `POST /contrasena/restablecer` | pública | Fija la contraseña con el enlace: `token`, `email`, `password` + `password_confirmation` |

`GET /personal` (`estado`: `activos` o `inactivos`; `situacion`: `en_actividad` o `dados_de_baja`):

```json
{
  "datos": [
    {
      "id": 12, "nombre": "Micaela", "apellido": "Aguirre", "nombre_completo": "Aguirre, Micaela",
      "email": "vendedor4@volumen.local", "activo": false, "acceso_texto": "Sin acceso", "es_la_propia": false,
      "rol": { "id": 3, "nombre": "Vendedor" },
      "empleado": { "legajo": "VOL-0006", "dni": "90100006", "telefono": "297-4000006",
                    "fecha_ingreso": "2025-03-22", "fecha_baja": null },
      "situacion_texto": "En actividad"
    }
  ],
  "pagina": 1, "por_pagina": 15, "ultima_pagina": 1, "total": 15,
  "criterios": { "q": null, "rol_id": null, "estado": null, "situacion": null }
}
```

`GET /personal/{id}` con `usuario.cambiar_rol` (la misma forma vuelve en `POST`, `PATCH` y `PUT …/rol`, con `mensaje`):

```json
{
  "datos": {
    "id": 2, "nombre": "Pedro Valentín", "apellido": "Colque", "nombre_completo": "Colque, Pedro Valentín",
    "email": "administrativo@sistema.local", "activo": true, "acceso_texto": "Con acceso", "es_la_propia": false,
    "rol": {
      "id": 2, "nombre": "Administrativo",
      "descripcion": "Catálogo, personas, compras y stock. Ve las ventas y resuelve las devoluciones.",
      "permisos": [ "Crear categoria", "Editar categoria", "…", "Ver venta" ]
    },
    "empleado": { "legajo": "EMP-0002", "dni": "30000000", "telefono": "297-4500000",
                  "fecha_ingreso": "2026-05-22", "fecha_baja": null },
    "situacion_texto": "En actividad",
    "roles_asignables": [
      { "id": 1, "nombre": "Administrador", "actual": false },
      { "id": 2, "nombre": "Administrativo", "actual": true },
      { "id": 4, "nombre": "Cajero", "actual": false },
      { "id": 3, "nombre": "Vendedor", "actual": false }
    ]
  }
}
```

- Sin `usuario.cambiar_rol`, `rol` trae sólo `id` y `nombre`, y `roles_asignables` **no está** (regla 36, molde HL-131).
- `empleado` es `null` en una cuenta sin ficha, y `situacion_texto` dice entonces «No corresponde»; con `fecha_baja`,
  «Baja el d/m/a».
- `es_la_propia` es ayuda de interfaz: la pantalla esconde la baja propia; el servidor da 409 igual.
- La edición que quita el acceso lo dice en el `mensaje` («… y se le quitó el acceso al sistema.»).

`POST /personal/{id}/restablecer-contrasena` (con `Cache-Control: no-store`):

```json
{
  "datos": { "id": 2, "nombre": "Colque, Pedro Valentín",
             "enlace_restablecimiento": "http://compustack.test/restablecer/3f9c…?email=administrativo%40sistema.local" },
  "mensaje": "Enlace generado para Colque, Pedro Valentín. Sirve una sola vez."
}
```

`GET /roles/{id}` (el listado trae la misma forma sin `permisos`; la ficha vuelve en `POST` y `PATCH`, con `mensaje`):

```json
{
  "datos": {
    "id": 3, "nombre": "Vendedor", "descripcion": "Emite presupuestos y ventas. No cobra ni anula.",
    "ambito": "gestion", "ambito_texto": "Gestión", "es_sistema": true,
    "cantidad_permisos": 9, "cantidad_usuarios": 8, "se_puede_eliminar": false,
    "permisos": [ 1, 9, 19, 20, 33, 34, 35, 37, 51 ]
  }
}
```

- `se_puede_eliminar` se deriva de los datos (regla 9): no es de sistema y no tiene usuarios.
- «Cualquier acción implica ver el módulo» la aplica el servicio: `permisos` con `producto.crear` guarda también `producto.ver`.

`GET /permisos`: `{ "datos": [ { "id": 2, "clave": "categoria.crear", "modulo": "categoria", "descripcion": "Crear categoria" } ], … }`,
con el sobre del paginado y `criterios` vacío.

`PUT /yo/contrasena`, **200** con la forma de la credencial (§4), sin caché:

```json
{
  "datos": {
    "credencial": "…",
    "tipo": "Bearer",
    "vence": "2026-11-24T22:00:00Z",
    "usuario": { "nombre": "Pedro Valentín", "apellido": "Colque", "email": "administrativo@sistema.local" }
  },
  "mensaje": "Tu contraseña se cambió. La próxima vez que inicies sesión usá la nueva."
}
```

- **Rota la credencial (HL-132).** Cambiar la contraseña revoca todas las credenciales de la cuenta —es lo que espera quien
  la cambia porque se la sacaron—, también la del pedido. Para no expulsar a quien hizo lo correcto, se emite una nueva,
  que el cliente guarda en lugar de la anterior. Desde la respuesta, **la credencial con que se pidió es 401**.
- Sin permiso a propósito (M-21): es de toda cuenta, también de la tienda.
- Errores: 422 en `password_actual` («Esa no es tu contraseña actual.») o en `password` (débil, no coincide, o «La
  contraseña nueva tiene que ser distinta de la actual.»); 401 si la cuenta está desactivada.

`POST /contrasena/restablecer`, pública, con el límite del ingreso (`tienda-acceso`, correo e IP):

```json
{
  "datos": { "ambito": "tienda" },
  "mensaje": "Tu contraseña quedó configurada. Ya podés entrar desde la app de la tienda con tu correo y la contraseña que elegiste."
}
```

- **No emite credencial**: la persona entra con la contraseña nueva, y una cuenta que el ingreso rechazaría por otro motivo
  no queda con sesión. `ambito` le dice a la pantalla adónde mandarla (D-26): `gestion` va al ingreso («Tu contraseña quedó
  configurada. Ya podés iniciar sesión.»), `tienda` a la app.
- El token de un solo uso lo verifica el broker de Laravel, hasheado y con vencimiento (60 minutos). Inventado, ya usado,
  vencido, de otra cuenta o reemplazado por uno más nuevo: **422 en `email`**, con el mensaje del broker. Un error en
  `password` no lo gasta.
- Revoca todas las credenciales de la cuenta (desde la r3).

## Registro de cambios

| Revisión | Tramo | Cambio |
| --- | --- | --- |
| 1 | A | Convenciones, formato de respuesta y de error, credencial, paginado, límite de intentos |
| 2 | C | `GET /categorias`, `GET /productos` y `GET /productos/{id}`: catálogo público sin precios; criterio de novedad (ventana y tope); galería nunca vacía; el árbol de categorías se pagina por raíz |
| 3 | D | `POST /registro`, `POST` y `DELETE /sesion`, `GET /perfil`; clave `precios` con `tienda.ver_precios`; `orden=precio_asc` y `precio_desc` (401 sin sesión, 403 sin permiso); cambiar o restablecer la contraseña revoca las credenciales |
| 4 | E | `GET` y `DELETE /carrito`, `POST /carrito/lineas`, `PATCH` y `DELETE /carrito/lineas/{linea}`, `POST /carrito/checkout`; se emiten `stock_insuficiente` y `carrito_cambio` (con `cambios`); importes en la solicitud, 422; dónde se guarda el carrito y cómo se asocia a la cuenta |
| 5 | F | `POST /pedidos` (201 al crear, 200 en el reintento con el mismo `carrito_id`; se emite `checkout_vencido`); `id` del carrito en `GET /carrito` y en el checkout; `direccion` en `GET /perfil`; el costo del envío pasa a `8000.00` |
| 6 | K | `PATCH /perfil` (parcial; documento con dígito verificador; lo fiscal, el correo y los importes, 422); `GET /perfil` suma `tipo_doc` + `_texto`, `nro_doc` y `condicion_iva` + `_texto` |
| 7 | K | `direccion.provincia` en `PATCH /perfil` y `POST /pedidos`: de texto de hasta 100 a **una de la lista cerrada** de 24 (§5), 422 «Elegí una provincia de la lista.»; una dirección vieja puede traer en `GET /perfil` una fuera de la lista (HL-69) |
| 8 | G | §7.5: `POST`, `GET /pedidos/{id}/pagos`, `GET /pedidos/{id}/pagos/{intento}` (consulta al proveedor si está pendiente) y `POST /simulador/intentos/{intento}/aprobar` y `/rechazar` (sólo con el simulador encendido); la forma del intento con `pedido.estado_pago`; la tabla del estado del pago y qué pasa si el pago falla (D-9) |
| 9 | H | §7.6: `GET /pedidos` (sólo online, del más nuevo al más viejo, con los dos estados y `unidades`), `GET /pedidos/{id}` (la forma de `POST /pedidos` más `estado_pago`, `ultimo_pago` y `comprobante_disponible`), `GET /pedidos/{id}/comprobante` (URL firmada de cinco minutos; sin pagar, 409; firma vencida o tocada, 403). `POST /pedidos` no cambia |
| 10 | I | Sin cambio de forma. Revisión final: cada endpoint pedido al servidor y comparado con su ejemplo (§7, «Verificación de la revisión 10»); los ejemplos, con los ids y las imágenes (`.webp`) del catálogo de demostración; este registro, en orden |
| 11 | J | `GET /tienda` (pública): los modos de entrega vigentes con su texto, sin costo (HL-81). Ninguna forma anterior cambia; las rutas pasan a 25 (23 con el simulador apagado) |
| 12 | L | `medio_pago` = `transferencia` en `POST /pedidos` (precio de contado); los `medios` del checkout pasan a tres y su `texto` dice cómo se paga («Efectivo en el local», «Transferencia bancaria», «Mercado Pago»), igual que `medio_pago_texto`; `GET /pedidos/{id}` suma `transferencia`; §7.7: `POST /pedidos/{id}/comprobante-transferencia` (multipart; 201 con el aviso), la situación `falta_comprobante` → `pendiente` y `en_revision`; permiso `tienda.cargar_comprobante`. Las rutas pasan a 26 (24 con el simulador apagado) |
| 13 | L | D-28: cada elemento de `medios` del checkout suma `modos_entrega`, las entregas con que vale (`contado` → `["retiro"]`; `transferencia` y `pasarela` → `["retiro", "envio"]`, o sólo `retiro` con el envío apagado). `POST /pedidos` con `contado` y `envio`: 422 en `medio_pago` («El pago en efectivo es sólo para retirar en el local.»). El ejemplo de `POST /pedidos` pasa a retiro, con `medio_pago_texto` «Efectivo en el local» (lo es desde la r12). Las rutas no cambian |
| 14 | B (Etapa 3) | Sesión común (HL-98): `POST /sesion` emite también a cuentas de gestión, con la misma forma y 8 horas de vigencia (D-31); la de tienda sigue en 7 días. `GET /yo` nuevo (nombre, apellido, email, `rol` con `ambito`, `permisos`). Grupo «protegida común»: `DELETE /sesion` y `GET /yo`. `401 sesion_expirada` para la credencial vencida, que hasta la r13 era `sesion_invalida`. Ninguna forma anterior cambia; las rutas pasan a 27 (25 con el simulador apagado) |
| 15 | D (Etapa 3) | §7.8, la gestión del catálogo, con la misma credencial (regla 35) y sin espacio `/admin` (regla 36): `forma=lista` en `GET /categorias` y `forma=gestion` en `GET /productos` y `GET /productos/{id}`, cada una con su permiso; altas, ediciones y bajas de marcas, categorías y productos; `recalcular`, `opciones` y `padres-posibles`; los proveedores de un producto (tres formas del listado, alta, edición, `preferido`, baja con 409 si hay un pedido vivo); `GET /proveedores` y `GET /clientes` mínimos; la impresión de los tres listados por URL firmada (`GET /<recurso>/impresion`, D-22). Con archivos, `POST` + `_method=PATCH` (HL-110). `GET /categorias` pasa al grupo «credencial si sirve» y `GET /productos` a la sesión opcional de los dos clientes (HL-108): para la app no cambia ninguna respuesta. Sin sesión, `forma=gestion` es 401 `sin_sesion`. Ninguna forma anterior cambia; las rutas pasan a 52 (50 con el simulador apagado) |
| 16 | E (Etapa 3) | §7.9, los terceros con la misma credencial (regla 35): clientes (listado con `condicion_iva` y `cuenta`, ficha con `direcciones`, alta, edición, baja, impresión por URL firmada y `acceso-tienda`, que devuelve el enlace de un solo uso, sin caché), direcciones de un cliente (alta, edición, baja; 404 si es ajena) y proveedores (listado con `canal` y conteos, ficha con `productos_activos`, alta, edición con cascada, baja que desactiva si hay productos u órdenes, impresión). Las claves nuevas van al final de `ClienteResource` y `ProveedorResource`: ninguna forma anterior cambia, y la fila del alta rápida sigue igual a la del listado. Las rutas pasan a 66 (64 con el simulador apagado) |
| 17 | F (Etapa 3) | §7.10, precios y stock con la misma credencial (regla 35): impuestos y factores de costo (listado, uno, alta, edición, baja que desactiva la alícuota en uso; `productos` cuenta los que la usan), la política (`GET` y `PATCH /precios/politica`) y la simulación (`GET /precios/simulacion`, con el `calculo` de la ficha de gestión), el ajuste masivo (`vista-previa` con `conteos` y `criterio_texto`, aplicar, el historial con `reversible` y deshacer; «nada que hacer» es 409, HL-122), el kardex (`GET /stock/movimientos` de a 20, HL-119; filtro inválido 422, HL-120; impresión por URL firmada), `GET /stock/usuarios`, `GET /productos/{id}/stock` con `reservas` y `POST /productos/{id}/ajustes-stock`. `costo` y `costo_nuevo` de la vista previa, sólo con `precio.ver_costo`. Ninguna forma anterior cambia; las rutas pasan a 88 (86 con el simulador apagado) |
| 18 | G (Etapa 3) | §7.11, las compras con la misma credencial (regla 35): el listado con sus filtros y su impresión por URL firmada, `GET /compras/proveedores` (con `activos=1` para elegir; HL-125), la ficha con `lineas`, `recepciones` (la forma del kardex) y `acciones` que decide el estado, el pedido en PDF por URL firmada propia (HL-126; sin aprobar, 409), los sugeridos con `grupos` y `sin_proveedor` (los costos, sólo con `precio.ver_costo`), `GET /compras/armador` (HL-124: los elegidos como renglones, con `descartados` y `aviso`), `POST /compras` (un borrador por proveedor, 201), `PATCH /compras/{id}`, aprobar, enviada, cancelar, cerrar y `POST /compras/{id}/recepciones` (201 con la ficha). Ninguna forma anterior cambia; las rutas pasan a 103 (101 con el simulador apagado) |
| 19 | H (Etapa 3) | §7.12, las ventas, el panel y los informes con la misma credencial (regla 35): el listado de ventas con sus filtros, `GET /ventas/vendedores` (opciones del filtro) y la impresión por URL firmada; la ficha con `lineas` (con `cotizacion_hoy` y `faltante` sólo en un presupuesto), `entregas`, `comprobantes_transferencia`, `pagos`, `medios`, `cobro`, `devolucion` y `acciones` que decide el estado; `POST /ventas` (201), `PATCH`, recotizar y cancelar; `POST /ventas/{id}/pagos`, `/entregas` y `/devoluciones` (201 con la ficha); tres documentos por URL firmada propia (la venta, HL-129; el comprobante de entrega y el de transferencia, HL-130); `GET /panel` y `GET /panel/general` (período, anterior y `comparaciones` con la frase del servidor; período inválido, 422); `GET /informes/vendedores` (con `totales`) y `GET /informes/productos`, de a 15. Ninguna forma anterior cambia: `/pedidos` sigue igual. Las rutas pasan a 126 (124 con el simulador apagado) |
| 20 | I (Etapa 3) | §7.13, usuarios y accesos con la misma credencial (regla 35): el personal (listado con sus filtros, `GET /personal/roles` para las opciones, la ficha con los permisos del rol y `roles_asignables` sólo con `usuario.cambiar_rol`, alta, edición con `activo` obligatorio, baja que desactiva si hay historial, `PUT …/rol` y el enlace de restablecimiento sin caché), los roles (listado con conteos y `se_puede_eliminar`, uno con los ids de sus `permisos`, `GET /permisos`, alta, edición y baja con 409 si es de sistema, tiene usuarios o es el propio), `PUT /yo/contrasena` (rota la credencial: devuelve la forma de `POST /sesion`, HL-132) y `POST /contrasena/restablecer` (pública, con el límite del ingreso; token inválido, 422 en `email`; `datos.ambito`, sin credencial). Ninguna forma anterior cambia. Las rutas pasan a 142 (140 con el simulador apagado) |
