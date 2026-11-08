# API de la tienda — contrato

**Revisión 1** · Etapa 2, tramo A. Base: `/api/v1`.

Es el único documento que comparten el backend (este repositorio) y la app móvil (su propio
repositorio). Cada endpoint que se agrega se documenta acá, con su método y ruta, autenticación,
parámetros, respuesta con el JSON completo, errores y notas. **Un cambio de forma sube la
revisión** y se anota en el registro de cambios.

Esta revisión fija las convenciones, el formato de error, la credencial y el paginado. Todavía no
hay endpoints de negocio: entran por tramo (C catálogo, D cuenta y precios, E carrito, F pedidos,
G pagos, H consulta de compras).

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
  o dar de baja una cuenta se **revocan** todas sus credenciales; además cada pedido revalida que la
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

_Ninguno todavía._ Los grupos de rutas existen y están vacíos (`routes/api.php`): pública, sesión
opcional y protegida.

## Registro de cambios

| Revisión | Tramo | Cambio |
| --- | --- | --- |
| 1 | A | Convenciones, formato de respuesta y de error, credencial, paginado, límite de intentos |
