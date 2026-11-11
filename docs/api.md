# API de la tienda — contrato

**Revisión 3** · Etapa 2, tramo D. Base: `/api/v1`.

Es el único documento que comparten el backend (este repositorio) y la app móvil (su propio
repositorio). Cada endpoint que se agrega se documenta acá, con su método y ruta, autenticación,
parámetros, respuesta con el JSON completo, errores y notas. **Un cambio de forma sube la
revisión** y se anota en el registro de cambios.

La revisión 1 fijó las convenciones, el formato de error, la credencial y el paginado. La 2 suma el
catálogo público (§7.1). La 3, la cuenta (§7.2), los precios y el orden por precio. Los demás
endpoints entran por tramo (E carrito, F pedidos, G pagos, H consulta de compras).

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
| 401 | `sesion_invalida` | Credencial vencida, revocada, desconocida, o cuenta desactivada o de gestión | Borra la credencial, «Tu sesión venció», al login |
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

`carrito_cambio` y `checkout_vencido` quedan declarados desde esta revisión; los emiten los tramos E y F.

**El 500 nunca muestra la traza**, aunque el servidor tenga `APP_DEBUG=true`: la respuesta se escribe
entera en el renderer y la excepción queda en el log. Un estado HTTP que no figura en la tabla
(413, 503…) sale también como 500 `error_interno`.

**Sin conexión no hay respuesta**: ese estado lo arma la app cuando `dio` no llega.

## 4. Autenticación

| Tipo de ruta | Qué exige | Credencial inválida |
| --- | --- | --- |
| **Pública** | Nada | No se lee |
| **Sesión opcional** (catálogo) | Nada; con credencial válida y `tienda.ver_precios`, agrega los precios | **401 `sesion_invalida`**: si no, los precios desaparecerían sin explicación |
| **Protegida** | Credencial válida, cuenta activa y de tienda, y el permiso de la ruta | 401 |

- Viaja en `Authorization: Bearer <credencial>`. Cualquier otro valor de `Authorization` cuenta
  como una credencial que no sirve (`sesion_invalida`).
- Sólo la credencial `Bearer` autentica: una sesión de navegador abierta en el panel **no** sirve
  contra `api/*`.
- La credencial vence a los 7 días (`SANCTUM_EXPIRATION`, en minutos; vacío = 10080). Al desactivar
  o dar de baja una cuenta, y al cambiar o restablecer su contraseña, se **revocan** todas sus credenciales; además cada pedido revalida que la
  cuenta siga activa y sea de tienda.
- Las cuentas de gestión no pueden usar la API: no tienen ficha de cliente.
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

- Credenciales incorrectas, cuenta de gestión e inactiva dan **el mismo 422 con el mismo mensaje**:
  decir cuál falló permitiría enumerar cuentas.
- **Límite de intentos** (`tienda-acceso`) en inicio de sesión y registro: 5 por minuto por correo e
  IP, y 30 por minuto por IP sola. El correo se compara sin distinguir mayúsculas ni espacios.

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

## 6. Textos para el cliente

Salen de un solo diccionario del backend (`App\Support\Api\TextosTienda`): estados del pedido, del
pago, niveles de disponibilidad y pasarelas. El simulador de pagos se dice siempre como lo que es:
«Simulador de pagos — no es un cobro real».

## 7. Endpoints

Tres grupos de rutas (`routes/api.php`): **pública** (no lee la credencial), **sesión opcional**
(sin credencial es visitante; con una inválida, 401 `sesion_invalida`) y **protegida**. El registro y
el inicio de sesión son públicos, con el límite `tienda-acceso`; cerrar sesión y el perfil, protegidos.

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
        { "id": 6, "nombre": "Placas de video", "hijas": [] },
        { "id": 8, "nombre": "Memorias RAM", "hijas": [] }
      ]
    },
    {
      "id": 2,
      "nombre": "Almacenamiento",
      "hijas": [ { "id": 13, "nombre": "Discos SSD", "hijas": [] } ]
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
  demostración (5 raíces) todo llega en la primera página.
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
      "imagen": "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.jpg",
      "categoria": { "id": 13, "nombre": "Discos SSD" },
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
    "imagen": "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.jpg",
    "categoria": { "id": 13, "nombre": "Discos SSD" },
    "es_novedad": true,
    "es_destacado": true,
    "disponibilidad": "ultimas_unidades",
    "disponibilidad_texto": "Últimas unidades",
    "descripcion": "Unidad de estado sólido NVMe de 1 TB en formato M.2. Arranca el sistema y abre los programas en segundos, con velocidades de lectura de hasta 4150 MB/s. Se instala directo en la placa madre, sin cables.",
    "galeria": [
      "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-1.jpg",
      "http://10.0.2.2:8000/storage/productos/demo-ssd-wd1tb-2.jpg"
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

### 7.2 Cuenta (tramo D)

Una cuenta de la tienda es un usuario con el rol **Cliente** (ámbito `tienda`, permisos `tienda.ver_precios`,
`tienda.comprar` y `tienda.ver_pedidos`) y su ficha de cliente. Las cuentas de gestión no entran por acá.

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
  el correo y el teléfono de la cuenta. Los datos fiscales los completa el local; la app no los edita.
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

- Contraseña incorrecta, correo inexistente, cuenta inactiva y cuenta de gestión dan **el mismo 422**, con
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

Protegida, sin permiso propio. Cierra la sesión **de este dispositivo**: la credencial con la que llegó el
pedido deja de servir (después da 401 `sesion_invalida`); las de otros dispositivos siguen. **204** sin
cuerpo. Errores: 401 `sin_sesion` / `sesion_invalida`.

#### `GET /api/v1/perfil`

Protegida, sin permiso propio. La cuenta de quien tiene la sesión. Sólo lectura.

```json
{
  "datos": {
    "nombre": "Lucía",
    "apellido": "Paredes",
    "email": "lucia@correo.local",
    "telefono": "297-4001122",
    "razon_social": "Lucía Paredes"
  }
}
```

- `telefono` es `null` si no lo cargó. No viajan el documento ni la condición frente al IVA.
- Errores: 401 `sin_sesion` / `sesion_invalida`.

## Registro de cambios

| Revisión | Tramo | Cambio |
| --- | --- | --- |
| 1 | A | Convenciones, formato de respuesta y de error, credencial, paginado, límite de intentos |
| 2 | C | `GET /categorias`, `GET /productos` y `GET /productos/{id}`: catálogo público sin precios; criterio de novedad (ventana y tope); galería nunca vacía; el árbol de categorías se pagina por raíz |
| 3 | D | `POST /registro`, `POST` y `DELETE /sesion`, `GET /perfil`; clave `precios` con `tienda.ver_precios`; `orden=precio_asc` y `precio_desc` (401 sin sesión, 403 sin permiso); cambiar o restablecer la contraseña revoca las credenciales |
