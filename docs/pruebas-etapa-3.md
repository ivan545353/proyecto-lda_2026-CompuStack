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
