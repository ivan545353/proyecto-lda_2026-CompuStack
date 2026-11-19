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
