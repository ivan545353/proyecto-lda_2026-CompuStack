# Pruebas de la Etapa 3

Qué se hace con los tests cuando un tramo retira rutas web y vacía pantallas (HL-99, HL-107), y los
números contra los que se compara todo lo que venga.

## 1. Números de partida

Medidos en el tramo A, sobre `develop@59714ef` (backend) y `2b26986` (app):

| Suite | Tests | Resultado | Duración |
| --- | --- | --- | --- |
| Backend (Pest, MariaDB) | **2.270** (6.441 aserciones) | todos pasan | 591 s |
| App (`flutter test`) | **1.032** | todos pasan | 1:35 |

**Al cerrar cada tramo, el backend tiene al menos 2.270 tests y la app al menos 1.032.** Un tramo puede
mover tests de archivo; no puede bajar el número.

## 2. La regla

Un test que apunta a una ruta retirada **se migra al endpoint conservando la aserción** (regla 39). No
se borra, no se marca `skip`, no se debilita.

Se migra **en el tramo del módulo**, junto con el retiro de la ruta (HL-103). El inventario de §4 es
la lista de cada tramo.

**Qué cuenta como «conservar la aserción»**: el test sigue afirmando el mismo hecho de negocio, en la
forma del contrato de la API. El molde de traducción:

| Hoy, contra la ruta web | Migrado, contra el endpoint |
| --- | --- |
| `actingAs($usuario)` | `->getJson($url, bearer($usuario))`: una credencial real (fijado en el tramo B, §6) |
| `->post(route('marcas.store'), $datos)` | `->postJson('/api/v1/marcas', $datos)` |
| `assertRedirect(...)` + `assertSessionHas('ok')` | `assertCreated()` / `assertOk()` / `assertNoContent()` + `assertJsonPath('datos.…', …)` |
| `assertSessionHasErrors('campo')` | `assertUnprocessable()` + `assertJsonPath('codigo', 'validacion')` + la clave en `errores` |
| `assertSessionHas('error', …)` de una regla de negocio | `assertStatus(409)` + `assertJsonPath('codigo', 'regla_de_negocio')` |
| `assertForbidden()` | `assertForbidden()` + `assertJsonPath('codigo', 'sin_permiso')` |
| `assertDatabaseHas` / `assertDatabaseMissing` / conteos | **igual, sin tocar** |
| `assertViewHas('marcas', …)` | `assertJsonPath('datos', …)` sobre el `GET` del listado |
| `assertSee($dato)` de un dato de negocio | `assertJsonPath(...)` sobre el endpoint que lo trae |

**Los datasets de permisos** (`pedirRuta`) recorren todas las rutas de un módulo. Se migran como un
dataset más: cambia la URL y el verbo, el permiso que se afirma queda igual. **Se agrega la otra
dirección** donde no esté: sin permiso, la clave no viaja (regla 36, molde de la regla 21).

## 3. Los tests que se parten en dos

Cuando un test hace la escritura **y** mira la pantalla (`assertSee`), se parte en dos:

- **El del endpoint** afirma el efecto: el estado, la base, el JSON.
- **El del shell** afirma que la pantalla se arma: el `GET` responde 200 para quien tiene el permiso y
  403 para quien no lo tiene, trae los botones que su `@can` habilita (regla 12) y **no trae datos de
  negocio** (regla 37). Un shell que pasa el `assertDontSee($dato)` es la prueba de HL-97.

Lo que es estructura —títulos, etiquetas, botones por permiso, el enlace a la impresión— se queda en
el test del shell. Lo que es dato —un nombre de producto, un importe, un estado— pasa al endpoint.
Ante la duda: **si cambia cuando cambia la base, es dato.**

**Las descargas** (D-22): el test pide el endpoint, afirma que devuelve una URL firmada que vence, y
con esa URL afirma el archivo, con las mismas aserciones de contenido que hoy.

**Lo que no se migra**: los tests de servicio, de modelo y de Unit no tocan rutas y no cambian. Los de
`Feature/Api` de la Etapa 2 sólo cambian si un tramo los nombra (HL-98, tramo B).

## 4. Inventario

Medido con `inventario.php` (§5). Dos medidas, porque se rompen por motivos distintos:

### 4.1 Escrituras a rutas web que se retiran (HL-99)

**285 llamadas en 24 archivos**, más los datasets de permisos. Es la medida de HL-99: el plan decía
277 en 44, con otro criterio que no quedó escrito.

| Tramo | Archivo | Llamadas | Rutas |
| --- | --- | --- | --- |
| B/C | `AutenticationTest` | 7 | `login.attempt` |
| B/C, I | `CuentaPasswordTest` | 15 | `cuenta.password.update` 12, `login.attempt` 2, `logout` 1 |
| B/C, I | `RestablecerPasswordTest` | 19 | `password.store` 11, `personal.restablecer` 5, `login.attempt` 3 |
| B/C, I | `Api/CredencialTest` | 2 | `cuenta.password.update`, `password.store` |
| I | `Api/RegistroMostradorTest` | 1 | `password.store` |
| D | `ProductoModuloTest` | 28 | `productos.store` 15, `.update` 11, `.destroy` 2 |
| D | `ProductoProveedorModuloTest` | 12 | `producto-proveedores.*` |
| D | `CategoriaModuloTest` | 11 | `categorias.*` |
| D | `MarcaModuloTest` | 11 | `marcas.*` |
| E | `ClienteModuloTest` | 24 | `clientes.*` 14, `direcciones.*` 10 |
| E | `ClienteAltaRapidaTest` | 6 | `clientes.store` (JSON, HL-100) |
| E | `ClienteAccesoTiendaTest` | 3 | `clientes.acceso_tienda` 2, `password.store` 1 |
| E | `ProveedorModuloTest` | 16 | `proveedores.*` |
| F | `AjusteMasivoModuloTest` | 23 | `precios.ajuste.aplicar` 20, `precios.lotes.deshacer` 3 |
| F | `PrecioModuloTest` | 11 | `precios.politica`, `.impuestos.*`, `.factores.*` |
| F | `StockModuloTest` | 5 | `stock.ajuste.store` |
| G | `CompraModuloTest` | 16 | `compras.recibir` 7, `.update` 4, transiciones 5 |
| G | `PedidoCompraModuloTest` | 9 | `compras.pedido.store` |
| G | `RemitoProveedorModuloTest` | 3 | `compras.recibir` |
| H | `VentaModuloTest` | 26 | `ventas.store` 10, `pagos.store` 5, `ventas.cancelar` 4, resto 7 |
| H | `EntregaModuloTest` | 5 | `ventas.entregar` |
| H | `CobroPantallaTest` | 2 | `pagos.store` |
| I | `PersonalModuloTest` | 21 | `personal.*` |
| I | `RolModuloTest` | 9 | `roles.*` |

`CuentaPasswordTest` y `RestablecerPasswordTest` cruzan tramos: las llamadas a `login.attempt` y
`logout` se migran **en el C**, que retira esas rutas (el B dejó la API y no las retira), y las de
contraseña en I. **Lo que cada tramo no migra, lo deja pasando**:
la ruta sigue viva hasta su tramo.

### 4.2 Lecturas de pantallas que se vacían (HL-107)

**279 `GET` a rutas de página en 38 archivos**, con **294 `assertSee`** y **88 `assertViewHas`**. El
plan no los contaba: no se rompen al retirar una ruta, sino al vaciar el shell. Cada `assertViewHas`
pasa al endpoint; cada `assertSee` se clasifica con la regla de §3 (dato o estructura).

Los más cargados: `VentaModuloTest` (38 `GET`, 57 `assertSee`), `CompraModuloTest` (31, 36),
`AjusteMasivoModuloTest` (7, 25), `PanelModuloTest` y `PanelAtencionTest` (34, 23), `CobroPantallaTest`
(5, 18) y `VentaTransferenciaTest` (7, 15).

También entran acá los tests de impresión (`*ImpresionTest`, `ImpresionDialogoTest`, `KardexCanalTest`):
son `GET` a descargas, y pasan a la URL firmada.

## 5. Cómo se vuelve a medir

Al empezar cada tramo de módulo:

```sh
php docs/scripts/volcar-rutas.php > rutas.txt          # rutas con controlador y middlewares
php docs/scripts/inventario-tests.php rutas.txt        # llamadas por archivo y por ruta
```

El criterio es
`->post|put|patch|delete(Json)?(` con `route('<nombre>')` o una URL literal, aunque el argumento esté
en la línea siguiente. Se excluye `/api/`. Los datasets se cuentan aparte, buscando el nombre de la
ruta como cadena.

**Al cerrar un tramo de módulo, su fila del inventario tiene que dar 0**: ninguna llamada a una ruta
web retirada.

## 6. Tramo B — Autenticación común

**El helper de la etapa**: `bearer(User): array`, en `tests/Pest.php`. Devuelve el encabezado
`Authorization` con una credencial **real**, emitida por `CredencialService::emitir()`. Se descartó
`actingAs($u, 'sanctum')`: no hay credencial, así que la vigencia, la revocación y el cierre de sesión
quedarían sin probar. D a I lo usan para llamar a la API: `$this->postJson($url, $datos, bearer($u))`.
Tiene su test en `SesionComunTest`.

**Lo que no migró el B**: las 12 llamadas a `login.attempt` y `logout` (`AutenticationTest`,
`CuentaPasswordTest`, `RestablecerPasswordTest`). Las rutas siguen vivas hasta el C; migrarlas antes
dejaría una ruta viva sin cobertura. Siguen pasando.

**Tests de la Etapa 2 que cambiaron, con su origen** (los manda el plan, HL-98 y §6):

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `Api/CuentaTest` › «incorrecta, inexistente, inactiva y de gestión dan el mismo 422» | La cuenta de gestión no inicia sesión | El caso «de gestión» sale del dataset y pasa a dos tests propios: entra con 200 y su credencial; inactiva, el mismo 422 | HL-98: entrar es de todos |
| `Api/CredencialTest` › «sesión opcional: una credencial vencida…» | `sesion_invalida` | `sesion_expirada` + `WWW-Authenticate` | §6: la vencida tiene su código |
| `Api/CuentaTest` › «una credencial vencida da 401…» | `sesion_invalida` | `sesion_expirada` | Ídem |
| `Api/TransferenciaTest` › «confirmar por transferencia congela…» | — (sin cambio de aserción) | `freezeTime()` al empezar | Fallaba por cruzar el borde de un segundo; la aserción es la misma |

**Tests nuevos**: `Api/SesionComunTest` (10: mismo endpoint, `/yo` por rol en las dos direcciones, cierre
de sesión de gestión, D-3, el helper, 429 de gestión, ni token ni contraseña en el log) y en
`Api/CredencialTest`, la vigencia de gestión y `vencio()`.

## 7. Tramo C — La administración entra con la credencial

**La ayuda del tramo**: `ingresoAdministracion($test, $email, $password)`, en `tests/Pest.php`. Hace lo
que el formulario: `POST /api/v1/sesion` y, si emitió la credencial, la lleva en la cookie hasta la
portada. Devuelve la respuesta del ingreso. Reemplaza a `post(route('login.attempt'))`.

**Migradas las 13 llamadas** (12 `login.attempt` + 1 `logout`; el inventario del A contaba 12). Quedan
**272 en 23 archivos**.

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `AutenticationTest` › «un usuario de gestión puede iniciar sesión» | Redirección a `inicio` y autenticado | Ingreso 200, la portada lleva al panel, y autenticado | Misma aserción, de punta a punta |
| `AutenticationTest` › «rechaza credenciales incorrectas sin revelar cuál falló» | Error en `email` y sigue invitado | 422 con el mismo mensaje para la contraseña incorrecta **y** para un correo inexistente | La aserción gana un caso |
| `AutenticationTest` › «inactivo» | Error en `email` | 422 en `email` e invitado | Ídem |
| `AutenticationTest` › «ámbito tienda no entra al sistema de gestión» | El login la rechaza | La API le emite su credencial (HL-98), pero con ella la portada manda al ingreso, e invitado | Lo decide el servidor en cada pantalla |
| `AutenticationTest` › «el login limita los intentos» | 429 al sexto | 429 al sexto, en la API | Ídem |
| `AutenticationTest` › «el identificador de sesión cambia al autenticarse» | Fijación de sesión | **«Cada ingreso emite una credencial nueva, y una elegida por el cliente no sirve»** | Ya no se entra con la sesión; la defensa pasa a la credencial. **Con el OK del usuario** |
| `CuentaPasswordTest` › «la contraseña nueva sirve y la vieja no» | `logout` + dos `login.attempt` | `DELETE /api/v1/sesion` + dos ingresos (422 la vieja, entra la nueva) | Misma aserción |
| `RestablecerPasswordTest` (3) | Entra / no entra | Ídem, con `ingresoAdministracion()` | Misma aserción |

**Tests nuevos**: `CredencialWebTest`, con 11 casos:
- entrar con la cookie, sin nada en la sesión;
- sin credencial;
- vencida, con el aviso y la cookie borrada;
- revocada, inventada, inactiva y de tienda;
- 401 JSON para un componente;
- 403 sin permiso;
- permiso quitado, reflejado en el pedido siguiente;
- la API rechaza la cookie sin `Bearer`.

**Dos trampas que costaron una corrida cada una**:
- `getJson()` no manda cookies si no se le pide `withCredentials()`. Sin eso, «la API no acepta la cookie» pasaba sin haberla mandado.
- Una ruta con `auth:sanctum` deja `sanctum` como guard por omisión en el resto del test, porque `config/sanctum.php` tiene `guard => []`. El pedido web siguiente no encuentra al usuario. La ayuda hace `shouldUse('web')`.

**El JS no tiene tests**: no hay corredor en `package.json` y no se agregan librerías. Se verificó con
`npm run build`, y lo que el servidor contesta lo prueban los tests de arriba.

## 8. Tramo D — Catálogo

**Migradas las 62 escrituras del catálogo** (Producto 28, ProductoProveedor 12, Categoría 11, Marca 11),
las 4 búsquedas (`BusquedaTest`, 23 tests, y una referencia en `ClienteAltaRapidaTest` y otra en
`PedidoCompraModuloTest`) y los tests de impresión de los tres listados. El inventario (§5) baja a **210
llamadas en 19 archivos**, y ninguna es del catálogo. La suite pasa de 2.295 a **2.389**: ningún test se
perdió, y cada endpoint suma su dataset de permisos en las dos direcciones.

**Cómo se migró**:
- Una escritura `post(route('x.store'))` pasa a `postJson('/api/v1/…', bearer($u))`, y cada
  `assertSessionHasErrors('campo')` a `assertJsonValidationErrors('campo', 'errores')`. El aviso de la
  sesión (`assertSessionHas('exito')`) pasa al `mensaje` exacto, que es más estricto.
- Un `assertViewHas` pasa al JSON del endpoint que trae el dato; un `assertSee` de un dato, también. Un
  `assertSee` de estructura (un botón con su `@can`, un texto fijo de la plantilla) se queda en el shell, y
  se afirma **en las dos direcciones**: sin el permiso la plantilla no trae el botón, con el permiso sí.
- Cada shell tiene su test «no trae datos de negocio» (regla 37): un producto con nombre, código y
  categoría conocidos, y la página sin ninguno.
- Las imágenes y el logo, multipart con `post()` y `_method=PATCH` en la edición (HL-110).

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `Api/CatalogoFiltrosTest` › el 405 de categorías y de productos | `POST /categorias`, `POST /productos` y `DELETE /productos/{id}` daban 405 | El 405 se afirma sobre `PUT`, y un test nuevo: sin credencial 401, con una cuenta de tienda 403 | Esos métodos ahora existen, para la gestión. **Con el OK del usuario** |
| `ImpresionDialogoTest` › «funciona sin JS» (marcas, categorías, catálogo) | El diálogo era un `form` GET a la ruta web | Apunta a `/<recurso>/impresion`, y la URL firmada abre el archivo sin credencial | HL-111. **Con el OK del usuario** |
| `ProductoImpresionTest` › la columna de costo sin permiso | La columna no salía | 422 en `columnas.0` («pide un permiso que no tenés») | La validación rechaza, no corrige (M-31) |
| `GestorImagenesOrdenTest` › «cada imagen guardada lleva su número» | El número en la edición del producto | El componente `x-gestor-imagenes` con tres imágenes, y la ficha de la API en su orden | La edición es un shell. **Con el OK del usuario** |
| `StockModuloTest` › «el listado ofrece el acceso al stock sólo con permiso» | El enlace con el id del producto | El enlace de la plantilla (`__ID__`), en las dos direcciones | La fila es una plantilla. **Con el OK del usuario** |
| `BusquedaTest` › «sin texto o de una sola letra se rechaza» | 422 en `/buscar/productos` | Cada buscador publica `data-buscable-minimo="2"`, y la API rechaza más de 100 caracteres | HL-112: el mínimo es de la interfaz. **Con el OK del usuario** |
| `BusquedaTest` › «sin sesión 401» | 401 en `/buscar/…` | 401 `sin_sesion` en los tres listados, también en `forma=gestion` | Por eso la forma de gestión da 401 al visitante (y no 403) |
| `BusquedaTest` › `estado=todos` | Incluía las bajas | Sin `estado` | «Todos» es no mandarlo |
| `ProductoModuloTest` › destacar, quitar el destacado | La casilla en el formulario | La casilla se ofrece con `producto.editar`, y lo que la marca es `datos.destacado` | `destacado` ausente quiere decir sin cambio |
| `ProductoProveedorModuloTest` › «el comparador ordena por costo» | `assertViewHas('vinculos')` | El orden, `mas_barato`, `es_preferido` y la `diferencia` en el JSON; los textos de las marcas en la plantilla | Misma aserción, más estricta |
| `ProductoProveedorModuloTest` › «la baja bloqueada vuelve con el motivo» | Redirección con `error` | 409 `regla_de_negocio` | El contrato de la API |

**Tests nuevos**: `Api/FronteraCatalogoTest` (los grupos `api.sesion_opcional` y `api.credencial_si_sirve`),
`Api/ImpresionFirmadaTest` (la vigencia, la firma que cubre filtros y opciones, el 422 que no firma, el enlace tocado o vencido, el permiso perdido y la cuenta desactivada), y en los de
módulo: cada clave protegida en las dos direcciones, `activo`, `precio_manual` y `es_preferido` obligatorios,
las formas del listado de proveedores de un producto con su permiso, y `forma=gestion` sin tocar la tienda.

**Tres trampas**:
- `EscalaTest` sobre la API daba 9 consultas contra 8 a veces: Sanctum guarda `last_used_at` sólo si cambió
  el segundo. Se congela el reloj (`freezeTime()`).
- Un `withHeaders(bearer($a))` seguido de otro con `$b` sigue autenticando a `$a`: el guard lo memoriza. Entre
  dos usuarios, `app('auth')->forgetGuards()`.
- Al perder un permiso en medio de un test, la caché del rol lo sigue concediendo: `Cache::forget(User::claveCache($rolId))`,
  como hace `RolService`.


## 9. Tramo E — Terceros

**Migradas las 46 escrituras de los tres módulos** (Cliente, Dirección, Proveedor, el acceso a la tienda y el alta
rápida), más los tests de impresión de los dos listados. El inventario (§5) baja de 210 a **164 llamadas en 17
archivos**, y ninguna es de clientes, direcciones ni proveedores (las dos que el script marca con `?` son
`$url` de la API, y la de `ClienteAccesoTiendaTest` es el `password.store` del enlace de restablecimiento, del
tramo I). Ningún test se perdió: `ClienteModuloTest` pasa de 31 a 39 tests, `ProveedorModuloTest` de 27 a 35,
y cada archivo del tramo conserva o suma.

**Cómo se migró**: igual que en el tramo D (§8). Dos agregados:
- Una casilla que la web trataba como «ausente = no» (`activo`, `es_predeterminada`) es **obligatoria** en el
  endpoint, y el test la manda siempre (M-31, HL-118).
- Una dirección ajena se prueba en las dos capas: la página web da 404 y el endpoint da 404 `no_encontrado`.

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `ClienteAltaRapidaTest` › «el alta pedida en json devuelve 201 con la misma fila que traería una búsqueda» | El `POST clientes` web, en JSON, contra `resultadoDeBusqueda()`, con igualdad estricta | El `datos` de `POST /api/v1/clientes` contra el `datos.0` de `GET /api/v1/clientes?q=…`, con `toBe` | HL-115: un solo `ClienteResource` |
| `ClienteAltaRapidaTest` › «la venta ofrece el alta…» | `action="route('clientes.store')"` | `data-alta-rapida-api="/clientes"` | El modal envía a la API. |
| `ClienteModuloTest` › «el formulario de la primera dirección llega con el checkbox marcado» | `assertViewHas('direccion', …es_predeterminada)` | El shell trae la casilla y los ids; la ficha de la API trae `direcciones: []` sin ninguna, y una con una | La casilla la marca el JS con la ficha (HL-113). |
| `ClienteModuloTest` › una dirección ajena | 404 de la página web | 404 de la página (se conserva) y 404 `no_encontrado` de los dos endpoints | Se suma la capa de la API |
| `ClienteAccesoTiendaTest` › «la ficha ofrece el acceso sólo con el permiso, y sin correo pide cargarlo» | Con y sin correo, en la ficha renderizada | El bloque del shell con el permiso y sin él; con o sin correo, en `datos.email` de la API | La decide la pantalla con la ficha. |
| `ClienteAccesoTiendaTest` › el enlace | `with('enlace_restablecimiento')` en la sesión | `datos.enlace_restablecimiento`, `Cache-Control: no-store`, y ausente de la ficha y del listado | HL-114 |
| `ClienteModuloTest` › «un documento mal escrito se rechaza…» | `assertSessionHasInput('nro_doc', …)`: lo escrito se conserva | Sin equivalente en la API (el formulario no se recarga); el 422 por campo y que no se guarda nada se conservan, y la diferencia queda anotada en el test | HL-118 |
| `ProveedorModuloTest` › «si los pedidos se mandan por correo, el correo es obligatorio» | `assertSessionHasInput('razon_social', …)`: un error no borra los demás campos | `assertJsonMissingValidationErrors('razon_social')`: un error no marca los demás | Misma intención, dicha en el idioma del endpoint |
| `ProveedorModuloTest` › «la casilla de cascada sólo aparece si el proveedor tiene productos activos» | `assertDontSee('desactivar_productos')` sin productos | La casilla nace oculta en el shell, sólo en la edición, y `productos_activos` de la ficha decide | Misma aserción, repartida. |
| `ProveedorModuloTest` › «el listado avisa cuando el proveedor tiene órdenes de compra» | `assertSee('2 orden(es)')` en la vista | `productos` y `ordenes_compra` del listado; la pantalla arma el texto | La fila es una plantilla |
| `ClienteImpresionTest`, `ProveedorImpresionTest` | `GET /<x>/imprimir` y la descarga | El endpoint `…/impresion` y la URL firmada; 422 si la columna o el filtro no existen | D-22; la validación rechaza |
| `ImpresionDialogoTest` › `clientes` y `proveedores` | Dataset de «ruta web» | Dataset de «listado migrado por la API» | HL-116 |

**Tests nuevos**: «los shells de clientes / de proveedores / de la dirección no traen datos de negocio» (regla 37),
«los números de la fila se enlazan sólo a los listados que quien mira puede ver», y el dataset de permisos en las
dos direcciones de cada endpoint (`endpoints de clientes`, con las tres de direcciones). `EscalaTest` suma
`clientes` y `proveedores` a «listados de la API».

**Hueco**: el JS de las pantallas (`clientes.js`, `direcciones.js`, `proveedores.js`, `alta-rapida.js`) no tiene
test automático: lo cubre el recorrido manual del usuario, como en el tramo D.

## 10. Tramo F — Precios y stock

**Migradas las escrituras de los tres módulos** (precios, ajuste masivo, stock), más las lecturas del kardex y sus
tests de impresión. El inventario (§5) baja de 164 a **128 llamadas en 16 archivos**. Las 3 que el script todavía
marca en archivos del tramo son URLs literales de los tests nuevos «las rutas web de escritura … ya no existen», que
esperan 404 o 405: es a propósito. Ningún test se perdió: `PrecioModuloTest` pasa de 16 a 24, `AjusteMasivoModuloTest`
de 36 a 43, `StockModuloTest` de 16 a 21, `MovimientoImpresionTest` de 5 a 6, y el resto conserva su número.

**Cómo se migró**: igual que en los tramos D y E (§8, §9), y con el OK del usuario para las lecturas del kardex: lo que
el `assertSee` buscaba en el HTML se busca en el JSON que pinta la pantalla (`assertJsonPath` sobre el mismo texto).

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `AjusteMasivoModuloTest` › los siete recortes | `assertSee` / `assertDontSee` del código en la vista previa | Los `codigo` de `datos` de `GET /precios/ajustes/vista-previa` (closure `codigosDe`) | La tabla es una plantilla |
| `AjusteMasivoModuloTest` › «avisa cuando hay productos afuera por precio manual» | `id="avisoManualesAfuera"` con y sin la casilla | `conteos.manuales_afuera` = 1 sin la casilla y 0 con ella | El aviso lo decide el número |
| `AjusteMasivoModuloTest` › «sin ver_costo no muestra la columna de costo» | `assertDontSee('12.345,67')` | Las claves `costo` y `costo_nuevo` **no están**; con el permiso, sí | Reglas 21 y 36, en las dos direcciones |
| `AjusteMasivoModuloTest` › las diez validaciones | `assertSessionHasErrors` | `assertJsonValidationErrors(…, 'errores')` | — |
| `AjusteMasivoModuloTest` › «un ajuste que no alcanza a nadie avisa…», «deshacer uno que no es el último…» | `with('aviso')`, `with('error')` | 409 `regla_de_negocio` con su mensaje, y ningún lote escrito | HL-122 |
| `AjusteMasivoModuloTest` › «el historial muestra el criterio congelado» | `assertSee` en la pantalla | `datos.0.criterio_texto` de `GET /precios/lotes` | — |
| `AjusteMasivoModuloTest` › «quien no puede deshacer no recibe el botón» | `assertDontSee(route('precios.lotes.deshacer', …))` | `data-deshacer` ausente sin el permiso, presente con él | La ruta ya no existe |
| `PrecioModuloTest` › impuestos, factores, política, simulador | Escrituras web y `assertSee` del cálculo | Los endpoints; `datos.calculo.contado.final` = `60500.00`; la casilla ausente es 422 | M-31 |
| `StockModuloTest` › los filtros | `assertViewHas('movimientos', total)` | `total` de `GET /stock/movimientos`; el panel, `datos.id` y `datos.stock` de `GET /productos/{id}/stock` | — |
| `StockModuloTest` › «un filtro inválido vuelve al kardex limpio con aviso» | Redirección con `error` | 422 con el error en `tipo` o en `hasta` | HL-120 |
| `StockModuloTest` › «el kardex pagina de a 15» | 15 de 20, el más nuevo primero | 20 de 25, el más nuevo primero | HL-119 |
| `StockModuloTest` › «el ajuste sin motivo…», «un stock que cambió…» | `assertSessionHasInput`, `with('error')` | 422 en `motivo`; 409 `regla_de_negocio`. Lo escrito no se pierde porque la pantalla no navega (HL-118) | — |
| `KardexCanalTest` › canal, cobro sin cajero, reservas | `assertSee` del número de venta, de «Tienda online (cobro automático)» y del párrafo `data-reservas` | `origen_texto`, `registrado_por`, `criterios.canal` y `reservas.pedidos` del JSON | Lecturas, con el OK del usuario |
| `KardexCanalTest`, `MovimientoImpresionTest` › impresión | `GET stock/imprimir` | El enlace de `GET /stock/movimientos/impresion` y su descarga firmada; 422 si el filtro no existe | D-22 |
| `MovimientoImpresionTest` › «el botón de imprimir lleva los filtros y no la página» | Los `href` de la vista | La URL firmada contiene `tipo=ajuste` y no `pagina=` | — |
| `RemitoProveedorModuloTest` › «el kardex muestra el remito» | `assertSee` en `/stock` | `datos.0.remito_texto` | — |
| `ImpresionDialogoTest` › `kardex` | Dataset de «ruta web» | Dataset de «listado migrado por la API» | HL-116 |
| `EscalaTest` › «el kardex no hace más consultas con más movimientos» | `GET /stock` | `GET /api/v1/stock/movimientos`, con `freezeTime()` | — |

**Tests nuevos**: los datasets `endpoints de precios`, `endpoints del ajuste masivo` y `endpoints de stock` con el
permiso en las dos direcciones; los shells sin datos de negocio (regla 37); las rutas retiradas; el conteo de uso de una
alícuota; la ganancia global por omisión del simulador; los conteos y el criterio de la vista previa; el rechazo sin
`incluir_manuales`; cuál es el único lote reversible; «Lo registró» ofrece sólo a quienes tienen movimientos.

**Huecos**:
- El JS de las pantallas (`precios.js`, `ajuste-masivo.js`, `stock.js`) no tiene test automático: lo cubre el recorrido
  manual del usuario, como en los tramos D y E.
- Impuestos, factores y lotes no tienen fila en «listados de la API» de `EscalaTest`: no tienen factory (HL-123).

## 11. Tramo G — Compras

**Migradas las escrituras del módulo** (armador, edición, transiciones, recepción) y su impresión, más las lecturas de
las seis pantallas. El inventario (§5) baja de 128 a **100 llamadas en 13 archivos**: las filas de `CompraModuloTest`,
`PedidoCompraModuloTest` y `RemitoProveedorModuloTest` dan 0. Las URLs retiradas se prueban con un dataset y
`pedirRuta`, así que el script no las cuenta. Ningún test se perdió: `CompraModuloTest` pasa de 51 a 57,
`PedidoCompraModuloTest` de 24 a 26, `CompraImpresionTest` de 5 a 7; el resto conserva su número, y `Api/CompraApiTest`
es nuevo (20). Las entradas que salieron de los datasets de rutas web volvieron como datasets o tests de endpoint, con
las dos mitades del permiso: las combinaciones no bajaron.

**Cómo se migró**: igual que en los tramos D a F. Lo que el `assertSee` buscaba en el HTML se busca en el JSON que pinta
la pantalla, y en el shell queda la estructura (la plantilla, el botón, el `data-*`).

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `CompraModuloTest` › «rutas de la orden» (aprobar, enviada, cerrar, cancelar, recibir, pdf) | Las dos mitades del permiso sobre la ruta web | Dataset `endpoints de la orden del modulo` sobre `/api/v1/compras/{id}…`, las dos mitades | Las rutas ya no existen |
| `CompraModuloTest` › «rutas del listado y la edición», `edicion` | `put compras.update` | `PATCH /api/v1/compras/{id}`, las dos mitades, en tests aparte | — |
| `CompraModuloTest` › filtros inválidos (estado, origen, rango) | Redirección al listado limpio con aviso | 422 con el error en su campo | HL-120 |
| `CompraModuloTest` › «el listado pagina de a 15» | 15 por página y los enlaces con el filtro | 20 por página y `criterios` con el filtro | HL-119 |
| `CompraModuloTest` › el PDF (borrador, aprobada, recibida, cancelada) | `GET compras.pdf`: redirección o descarga | `GET /compras/{id}/pdf`: 409 o el enlace firmado y su descarga, con la misma aserción de que no cambia el estado | HL-126 |
| `CompraModuloTest` › «el renglón rinde el producto como texto y lo excluye del buscador» | El `name`, el rótulo y `data-buscable-excluir` con el id | El shell, la plantilla (id oculto y rótulo) y el buscador una sola vez; el producto, `datos.lineas.0.producto.*` | El renglón lo arma el JS |
| `CompraModuloTest` › «la pantalla de recepción muestra lo pendiente» | `assertSee` del nombre y del código del proveedor | El botón y la plantilla en el shell; nombre, `codigo_proveedor` y `cantidad_pendiente` en la ficha | — |
| `CompraModuloTest` › la ficha y las transiciones | `assertSee` y `with('exito')` | `assertJsonPath` sobre la ficha y `mensaje`; la transición inválida, 409 | — |
| `PedidoCompraModuloTest` › «rutas del armador», `guardar` | `post compras.pedido.store` | `POST /api/v1/compras`, las dos mitades | — |
| `PedidoCompraModuloTest` › el armador y los sugeridos | `assertViewHas` y `assertSee` de los renglones | `GET /compras/armador` y `GET /compras/sugeridos`. Que sin `precio.ver_costo` las claves de costo no estén lo prueba `Api/CompraApiTest`, en las dos direcciones | Reglas 21 y 36 |
| `RemitoProveedorModuloTest` › recibir con y sin remito, validaciones, la ficha | `post compras.recibir`, `assertSessionHasErrors`, `assertSee` | `POST /compras/{id}/recepciones` (201, `mensaje`), 422 en su campo, `datos.recepciones.*.remito_texto` | — |
| `CompraImpresionTest` | `GET compras.imprimir` | El enlace de `GET /compras/impresion` y su descarga firmada; opción o filtro inválido, 422 | D-22 |
| `ImpresionDialogoTest` › `compras` | Dataset de «ruta web» | Dataset de «listado migrado por la API» | HL-116 |
| `PanelAtencionTest` › los destinos de compras | `assertViewHas('ordenes', total)` | `total` de `GET /api/v1/compras` con el mismo filtro | — |
| `EscalaTest` › «listados de la API» | — | Suma `compras` | — |

**Tests nuevos**: el shell de la edición sin datos de negocio (regla 37); las 9 URLs retiradas, que dan 404 o 405 con
todos los permisos del módulo y sin cambiar la orden; `Api/CompraApiTest` (el listado y sus filtros, los proveedores, la
ficha con `acciones`, el PDF firmado, el armador con `descartados`, cada escritura con su permiso, la edición de una
aprobada es 409, el estado y el total no llegan desde el cuerpo).

**Huecos**:
- El JS de las pantallas (`compras.js`, `lineas-pedido.js`, `lineas-orden.js`, `recepcion.js`) no tiene test
  automático: lo cubre el recorrido manual del usuario. Ese recorrido encontró que la edición y la recepción quedaban en
  blanco (el encabezado se buscaba dentro del contenedor y el `catch` se tragaba el error); se corrigió antes de cerrar.

## 12. Tramo H — Ventas, panel e informes

**Migradas las escrituras del módulo** (alta, edición, recotizar, cancelar, cobro, entrega, devolución) y sus cuatro
descargas, más las lecturas de las nueve pantallas de ventas, los dos paneles y los dos informes. El inventario (§5) baja
de 100 a **67 llamadas en 10 archivos**: no queda ninguna de ventas ni de pagos. Ningún archivo perdió tests salvo uno:
`VentaModuloTest` pasa de 73 a 85, `EntregaModuloTest` de 14 a 16, `PanelModuloTest` de 20 a 23,
`InformeVentasModuloTest` de 9 a 12; el resto conserva su número. `ImpresionDialogoTest` baja de 8 a 6: ver abajo.

**Cómo se migró**: igual que en D a G. El dato se afirma en el JSON (`datos.*`, `mensaje`, 409, 422) y la estructura en
el shell (plantilla, botón con su `data-accion`, `data-campo`).

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `VentaModuloTest` › alta, edición, recotizar, cancelar | `post`/`put` a la ruta web, `with('exito'/'error')` | `POST`/`PATCH /api/v1/ventas…`: la ficha, `mensaje`; la transición inválida, 409 | — |
| `VentaModuloTest` › «un error de validacion devuelve los renglones» | `withInput()` conservaba los renglones | 422 con el error en `lineas.{i}.campo`; los renglones quedan en la pantalla porque nadie la recarga | El formulario ya no se recarga |
| `VentaModuloTest` › el cobro con problemas (baja, faltante) | `assertSee` del aviso en la pantalla de cobro | `datos.cobro.problemas` de la ficha, con el mismo texto | HL-131 |
| `CobroPantallaTest` › cobro correcto, rechazo por saldo | `post pagos.store`, aviso arriba con `withInput` | `POST /ventas/{id}/pagos`: 201, o 409 con `errores` vacío (el saldo no es de un campo) | Opción (a) acordada |
| `EntregaModuloTest` › entregar todo o una parte, la ficha | `post ventas.entregar`, `assertSee` | `POST /ventas/{id}/entregas` (201); `datos.entregas.*` | — |
| `VentaImpresionTest`, `VentaTransferenciaTest` › las descargas | `GET` con sesión al PDF o al archivo | El enlace firmado de la API y su descarga, con la misma aserción sobre el contenido; de otra venta, 404 | HL-129, HL-130 |
| `ImpresionDialogoTest` › `ventas` | Dataset de «ruta web» y dos tests del diálogo sin JS | Dataset de «listado por la API» sobre `/ventas/impresion`. Los dos tests sin JS se fueron: el diálogo lo abre el JS, que es el único que tiene la credencial (HL-111) | Avisado en el paso 5 |
| `ClienteAltaRapidaTest` › el alta rápida en el formulario de venta | El `action` del formulario | El formulario por su `data-formulario-venta` | El formulario no tiene `action` |
| `AcreditarIntentoTest`, `PanelAtencionTest` | `assertSee` en la ficha o `assertViewHas` | `datos.pagos.0.metodo_texto`; `total` del listado con el mismo filtro | — |
| `PanelModuloTest`, `PanelGraficoTest` | `assertViewHas('metricas')`, `assertSee` de los números y del JSON del gráfico | `GET /panel` y `GET /panel/general`: los números, `comparaciones` y `por_dia` | — |
| `PanelModuloTest` › período inválido | Redirección al mes en curso con aviso | 422 en `hasta`; renombrado «se rechaza con el error en su campo» | HL-120 |
| `PanelModuloTest` › «lo plegado lo rinde el servidor y se abre sin javascript» | Las filas plegadas en el HTML | El `details` en el shell y las filas en la API | Regla 37 |
| `InformeVentasModuloTest` › período, paginado, total, vacío | `assertSee` y `href` de las páginas | `datos`, `por_pagina`, `criterios` (el período en la página 2, `elegido`), `totales`; los dos textos del vacío en el shell | — |

**Tests nuevos**: las 11 URLs retiradas, que dan 404 o 405 con todos los permisos del módulo y sin cambiar la venta; los
shells del listado, la ficha, el formulario, el cobro, la entrega, la devolución, los paneles y los informes sin datos de
negocio (regla 37); «cobrada, la ficha ya no trae la cotización de hoy»; los endpoints del panel y de los informes con
las dos mitades del permiso.

**Huecos**:
- El JS (`ventas.js`, `operaciones-venta.js`, `lineas-venta.js`, `panel.js`, `informes.js`) no tiene test automático. El
  recorrido manual encontró que la insignia «Sin stock suficiente» y el aviso del cobro se veían siempre: `d-flex` y
  `d-inline-block` de Bootstrap le ganaban a `hidden`. Se corrigió con una regla global en `app.scss` (pendiente #89).

## 13. Tramo I — Usuarios y accesos

**Migradas las escrituras del módulo**: el alta, la edición, la baja y el cambio de rol del personal, el enlace de
restablecimiento, el alta, la edición y la baja de roles, y las dos contraseñas. Con las lecturas de las siete pantallas
pasaron 62 llamadas, y el inventario (§5) baja de 67 a **5 llamadas en 3 archivos**, las 5 a propósito (3 del F, 2 del
dataset de `ClienteModuloTest`). **`web.php` no tiene ninguna ruta de escritura.** Ningún archivo perdió tests:
`PersonalModuloTest` pasa de 33 a 42, `RolModuloTest` de 9 a 15, `CuentaPasswordTest` de 12 a 13; el resto conserva su
número.

**Cómo se migró**: igual que en D a H. El dato se afirma en el JSON (`datos.*`, `mensaje`, 409, 422) y la estructura en
el shell.

| Test | Fijaba | Ahora | Por qué |
| --- | --- | --- | --- |
| `PersonalModuloTest` › alta, edición, baja, cambio de rol | `post`/`put`/`delete` a la ruta web, `with('exito'/'error')` | `POST`/`PATCH`/`DELETE`/`PUT /api/v1/personal…`: la ficha o `{ id, eliminado }`, `mensaje`; lo propio, 409 | — |
| `PersonalModuloTest` › un filtro inexistente | Se ignoraba | 422 con el error en su campo; renombrado | HL-120 |
| `PersonalModuloTest` › la edición sin `activo` | La casilla ausente era `false` | 422 en `activo` (test nuevo) | M-31 |
| `PersonalModuloTest` › «ni siquiera trae la columna» | El `select` del listado web | El mismo `select` en `GET /personal`, leído con `DB::listen` | — |
| `RestablecerPasswordTest` › generar el enlace | `post personal.restablecer`, el enlace en la sesión | `POST /personal/{id}/restablecer-contrasena`: `datos.enlace_restablecimiento`, `Cache-Control: no-store` | — |
| `RestablecerPasswordTest` › fijarla con el enlace | `post password.store`, redirección al ingreso; `assertSessionHasErrors('email')` | `POST /contrasena/restablecer`: `datos.ambito` y el mismo `mensaje`; 422 en `email` con el token inválido, usado, vencido, ajeno o reemplazado | — |
| `RolModuloTest` › alta, edición, baja | `post`/`put`/`delete roles.*`, `assertSessionHasErrors('permisos')`, `with('error')` | `POST`/`PATCH`/`DELETE /api/v1/roles…`: 201/200, 422 en `permisos` con el mismo texto, 409 | — |
| `CuentaPasswordTest` › las validaciones, la cuenta desactivada | `put cuenta.password.update`, `assertSessionHasErrors`, redirección | `PUT /yo/contrasena`: 422 en el mismo campo y con el mismo texto; desactivada, 401 | — |
| `CuentaPasswordTest` › «no cierra la sesión propia» | `assertAuthenticatedAs` después del cambio | La credencial devuelta entra a `/yo`; la del pedido da 401 | HL-132, acordado |
| `Api/CredencialTest` › «revoca todas sus credenciales» | Cero credenciales después del cambio | Ninguna de las anteriores sobrevive y queda sólo la devuelta; renombrado | HL-132, acordado |
| `Api/RegistroMostradorTest` › «lee que entra por la app» | `assertSee` del aviso en la respuesta del `POST` | El aviso en el shell del enlace y `datos.ambito = tienda` con «desde la app» en el `mensaje` | El aviso lo muestra el JS |

**Tests nuevos**: las 10 URLs retiradas (5 del personal, 3 de roles, 2 de contraseñas), que dan 404 o 405 sin cambiar
nada; los shells del personal, de roles y de la cuenta sin datos de negocio (regla 37); los endpoints de personal y de
roles con las dos mitades del permiso; las opciones de rol con `usuario.ver` o `usuario.crear`; la ficha con y sin
`usuario.cambiar_rol`; `se_puede_eliminar` y los conteos de roles; el 401 sin credencial en `PUT /yo/contrasena`.

**Huecos**:
- El JS (`personal.js`, `roles.js`, `sesion.js`) no tiene test automático: lo cubre el recorrido manual. Lo que más
  conviene mirar: cambiar la contraseña propia y seguir navegando sin que pida el ingreso (la cookie con la credencial
  rotada), el enlace de gestión (al ingreso con el aviso) y el de la tienda (el bloque de la app).

## 14. Tramo K — Pruebas, OpenAPI, documentación y demostración

**El tramo no migra ni toca ningún test: sólo suma.** No retiró rutas ni vació pantallas, así que el inventario (§5)
sigue en **5 llamadas en 3 archivos**, las 5 a propósito.

**Tests nuevos** (14):

| Test | Qué fija | Por qué |
| --- | --- | --- |
| `Api/OpenApiTest` (9) | `docs/openapi.yaml` contra el enrutador: cada operación es una ruta (las del simulador pueden faltar con `TIENDA_SIMULADOR` apagado) y cada ruta es una operación; la versión es la revisión de `api.md`; los `$ref` resuelven; la seguridad y el 401 son los del middleware; `x-permiso` es el `can:` y la ruta con permiso declara el 403; los parámetros del path están declarados; cada parámetro trae sólo sus claves; los `operationId` son únicos y cada operación tiene una respuesta 2xx | Regla 16: el documento no afirma lo que el servidor no hace. No toca la base |
| `Api/GuionEtapa3Test` (3) | Las tres secuencias de la colección de Postman, con sus mismas afirmaciones: la venta de mostrador (sin `precio.ver_costo` ni `stock.ver` no viajan `costo_promedio` ni `stock`; el vendedor emite y no cobra, 403; la cajera cobra y entrega; el PDF firmado abre sin credencial y con la firma tocada da 403; el kardex trae la salida), la compra completa (carrito, checkout, pedido, intento, aprobación, el stock que baja al cobrar y la venta vista desde la administración) y el 403 de la misma credencial cuando el rol Cliente pierde `tienda.comprar`, con la vuelta al 200 al devolverlo | Los siete puntos se hacen sin tocar código |
| `AjusteMasivoModuloTest` › criterios como objeto | La vista previa y el historial traen `"criterios":{}` en el JSON crudo | HL-137 |
| `PedidoCompraModuloTest` › criterios como objeto | Los sugeridos traen `"criterios":{}` en el JSON crudo | HL-137 |

**Por qué el JSON crudo**: `json()` decodifica como arreglo asociativo, y ahí `{}` y `[]` son lo mismo. La diferencia
sólo se ve en el texto.

**La trampa del YAML** que la segunda aserción de los parámetros vino a atajar: una coma sin comillas dentro de
`{ … }` no hace fallar la lectura. Corta el valor y deja una clave suelta, y en el paso 4 eso le había sacado el `schema`
al filtro `q` de `/clientes` sin que ningún test lo notara.

**Huecos**:
- El JS del tramo I sigue sin test automático. El recorrido manual está en `demostracion-etapa-3.md`, y se hace antes
  de los ensayos.
- `GuionEtapa3Test` reemplaza el checkout de Mercado Pago por el simulador, que resuelve por el mismo camino (D-13). El
  checkout de verdad se prueba en los ensayos, con las cuentas de prueba (D-23).
- El OpenAPI no se valida contra el esquema oficial de OpenAPI 3.1: haría falta una librería. El test comprueba lo que
  el documento afirma sobre el servidor, no su gramática completa.

**La suite al cerrar**: backend **2.585** tests (8.122 aserciones), todos pasan, en 718 s: los 2.571 del tramo I más
los 14 nuevos. La de la app no cambió en este tramo (1.049); la corre el usuario.
