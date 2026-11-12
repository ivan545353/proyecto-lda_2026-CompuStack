# API de la tienda — contrato

**Revisión 7** · Etapa 2, tramo K. Base: `/api/v1`.

Es el único documento que comparten el backend (este repositorio) y la app móvil (su propio
repositorio). Cada endpoint que se agrega se documenta acá, con su método y ruta, autenticación,
parámetros, respuesta con el JSON completo, errores y notas. **Un cambio de forma sube la
revisión** y se anota en el registro de cambios.

La revisión 1 fijó las convenciones, el formato de error, la credencial y el paginado. La 2 suma el
catálogo público (§7.1). La 3, la cuenta (§7.2), los precios y el orden por precio. La 4, el carrito y
el checkout con la reserva (§7.3). La 5, la confirmación del pedido (§7.4) y la dirección del perfil. La 6,
la cuenta editable (`PATCH /perfil`) y los datos fiscales en el perfil. La 7, la provincia de lista cerrada (§5).
Los demás endpoints entran por tramo (G pagos, H consulta de compras).

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

`stock_insuficiente` y `carrito_cambio` se emiten desde la revisión 4 (tramo E); `checkout_vencido`, desde el F.

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
          "imagen": "http://10.0.2.2:8000/storage/productos/demo-mouse-g505-1.jpg",
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
      { "medio": "contado", "texto": "Efectivo o transferencia", "total": "84000.00" },
      { "medio": "pasarela", "texto": "Mercado Pago", "total": "92400.00" }
    ],
    "entrega": [
      { "modo": "retiro", "texto": "Retiro en el local", "costo": "0.00" },
      { "modo": "envio", "texto": "Envío a domicilio", "costo": "8000.00" }
    ]
  }
}
```

- `medios`: cuánto sale por cada medio. El contado cobra el precio de contado; la pasarela, el de
  lista. Sin `tienda.ver_precios`, sin `total`. Cuál se elige lo manda la confirmación (§7.4).
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
| `medio_pago` | cuerpo | texto | sí | `contado` (efectivo o transferencia, en el local) o `pasarela` |
| `modo_entrega` | cuerpo | texto | sí | `retiro`; `envio` sólo con `tienda.envio.habilitado` |
| `direccion` | cuerpo | objeto | con `envio` | `calle` (150), `numero` (10), `piso_depto` (20, opcional), `codigo_postal` (10), `localidad` (100), `provincia` (**una de la lista cerrada**, §5). Con `retiro`, mandarla es 422 |
| importes | cuerpo | — | — | `precio`, `total`, `subtotal`, `costo_envio`, etc.: **prohibidos** (422). Los calcula el servidor |

1. Bloquea el carrito. Si ya es un pedido, devuelve **ese** pedido con **200** (D-8): el reintento
   tras un timeout recibe el mismo número, aunque cambie el medio.
2. Si no está en checkout o la reserva venció: la suelta y **409 `checkout_vencido`**.
3. Revalida como el checkout. Si algo cambió: suelta la reserva, deja las referencias nuevas y **409
   `carrito_cambio`** con `cambios`. Hay que volver a iniciar el checkout.
4. Crea el pedido con los renglones congelados **con la lista del medio** (contado → precio de contado,
   pasarela → precio de lista), suma el costo del envío (`tienda.envio.costo`, hoy `8000.00`) y pasa el
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
    "medio_pago_texto": "Efectivo o transferencia",
    "modo_entrega": "envio",
    "modo_entrega_texto": "Envío a domicilio",
    "direccion": {
      "calle": "San Martín",
      "numero": "1234",
      "piso_depto": null,
      "codigo_postal": "9000",
      "localidad": "Comodoro Rivadavia",
      "provincia": "Chubut"
    },
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
      "costo_envio": "8000.00",
      "total": "116900.00"
    }
  }
}
```

- `reserva_vence`: hasta cuándo se guarda la mercadería. De contado, 48 horas
  (`tienda.reservas.contado_horas`): **hay que pagar en el local antes de esa fecha**. Por pasarela,
  20 minutos (`tienda.reservas.pasarela_minutos`), para el pago del tramo G. Pasada la hora, la tarea
  de cada minuto cancela el pedido y libera el stock. `null` cuando ya se cobró o se canceló.
- `direccion`: la del pedido, **copiada**: editar después las direcciones del cliente no la cambia.
  `null` con retiro.
- Sin `tienda.ver_precios` no viajan `importes` ni `precio_unitario` y `total` de cada renglón.
- Nunca viajan el costo, el vendedor ni el stock.
- Que el pedido esté registrado **no dice que se pagó** (P-4): `estado` es `pendiente_pago` hasta que
  el local lo cobra o la pasarela lo acredita.
- `carrito_id` es la clave de la idempotencia: el reintento manda **el mismo**. Un carrito convertido no
  se reusa; el siguiente `GET /carrito` trae uno nuevo, con otro `id`.
- Errores: 401 `sin_sesion` / `sesion_invalida`, 403 `sin_permiso`, 404 `no_encontrado` (carrito
  ajeno o inexistente), 409 `checkout_vencido`, 409 `carrito_cambio`, 409 `regla_de_negocio` (cuenta
  sin ficha de cliente), 422 `validacion` (con envío, una provincia fuera de la lista de §5 va en
  `direccion.provincia`).

## Registro de cambios

| Revisión | Tramo | Cambio |
| --- | --- | --- |
| 1 | A | Convenciones, formato de respuesta y de error, credencial, paginado, límite de intentos |
| 2 | C | `GET /categorias`, `GET /productos` y `GET /productos/{id}`: catálogo público sin precios; criterio de novedad (ventana y tope); galería nunca vacía; el árbol de categorías se pagina por raíz |
| 3 | D | `POST /registro`, `POST` y `DELETE /sesion`, `GET /perfil`; clave `precios` con `tienda.ver_precios`; `orden=precio_asc` y `precio_desc` (401 sin sesión, 403 sin permiso); cambiar o restablecer la contraseña revoca las credenciales |
| 5 | F | `POST /pedidos` (201 al crear, 200 en el reintento con el mismo `carrito_id`; se emite `checkout_vencido`); `id` del carrito en `GET /carrito` y en el checkout; `direccion` en `GET /perfil`; el costo del envío pasa a `8000.00` |
| 6 | K | `PATCH /perfil` (parcial; documento con dígito verificador; lo fiscal, el correo y los importes, 422); `GET /perfil` suma `tipo_doc` + `_texto`, `nro_doc` y `condicion_iva` + `_texto` |
| 7 | K | `direccion.provincia` en `PATCH /perfil` y `POST /pedidos`: de texto de hasta 100 a **una de la lista cerrada** de 24 (§5), 422 «Elegí una provincia de la lista.»; una dirección vieja puede traer en `GET /perfil` una fuera de la lista (HL-69) |
| 4 | E | `GET` y `DELETE /carrito`, `POST /carrito/lineas`, `PATCH` y `DELETE /carrito/lineas/{linea}`, `POST /carrito/checkout`; se emiten `stock_insuficiente` y `carrito_cambio` (con `cambios`); importes en la solicitud, 422; dónde se guarda el carrito y cómo se asocia a la cuenta |
