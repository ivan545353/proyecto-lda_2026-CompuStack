# Plan de la Etapa 3 — API RESTful e integración de la tienda móvil y la administración web

Tercera y última etapa del proyecto integrador. La Etapa 1 migró el backend a Laravel + Blade; la
Etapa 2 agregó la tienda móvil en Flutter sobre una API `/api/v1` que cubre el recorrido de compra.
**Esta etapa lleva a la API todas las operaciones de negocio y hace que las dos interfaces —la app y
la administración web— la consuman.**

**La consigna es `docs/Proyecto - Etapa 03.pdf` y manda.** Este plan la ejecuta; donde el plan y la
consigna no coincidan, gana la consigna.

**Frase de la etapa**, el criterio con el que se resuelve cualquier duda de diseño:

> Una operación de negocio tiene **un solo camino**: el servicio, detrás de un endpoint. La app y la
> administración son dos clientes del mismo endpoint, con el mismo token y los mismos permisos.

---

## 1. Estado real al empezar

Medido sobre el repositorio al cerrar la Etapa 2, no sobre lo que dicen los documentos.

### El backend

| Qué | Cuánto |
| --- | --- |
| Servicios | **23** |
| Controladores web (Blade) | **22** |
| Controladores de API (`Api/V1`) | **13** |
| Rutas web | **134** — 74 `GET`, 32 `POST`, 15 `PUT`, 2 `PATCH`, 11 `DELETE` *(el plan decía 133; HL-105)* |
| Endpoints de API | **~25**, todos de tienda |
| Vistas Blade | **69** |
| Componentes JS | **11**, de los cuales **3 ya usan `fetch`** |
| Permisos distintos usados en rutas web | **49** |
| Tests | **2.270** (117 archivos: 110 Feature, 7 Unit; 20 de ellos en `Feature/Api`) |

### La app

| Qué | Cuánto |
| --- | --- |
| Archivos Dart (sin generados) | **172** |
| Funcionalidades en `ui/` | **14** |
| Tests | **1.032** |
| Contrato que consume | `docs/api.md` **r13**, verificado ruta por ruta |

### Lo que la Etapa 2 dejó preparado (plan §10)

Ocho bases, y todas se usan en esta etapa:

- **`/api/v1` versionado** — la administración entra por el mismo prefijo.
- **API Resources** — una serialización por recurso, ya con su molde.
- **Controladores que sólo traducen** — el molde que van a copiar los 22 módulos.
- **`docs/api.md` con revisiones** — la base del OpenAPI de la promoción.
- **`CredencialService` como única clase que conoce Sanctum** — el punto por donde entra la
  autenticación unificada.
- **Permisos por acción** — el `Gate` ya gobierna los dos ámbitos.
- **Forma única de error y de paginado** — `Paginado::respuesta()`, los códigos de `api.md` §6.
- **La interfaz `Pasarela`** — no cambia en esta etapa.

### Lo que queda de la Etapa 2

No bloquea, pero conviene cerrarlo antes de la rama nueva: el pendiente **#85** (quitar el simulador
del backend, ya apagado por omisión), los **dos ensayos del guion** y los huecos de
`pruebas-etapa-2.md` §5.

---

## 2. Lectura de la consigna

Cada requisito de aprobación, con el tramo que lo cierra. **Es la tabla con la que se comprueba que
la etapa está completa.**

| # | Requisito | Tramo |
| --- | --- | --- |
| 1 | Nueva rama de desarrollo, en los dos repositorios, con la entrega de la Etapa 2 identificada | **A** |
| 2 | Relevamiento de la totalidad de las operaciones de negocio, tienda y administración | **A** |
| 3 | Arquitectura y reutilización: todo pasa por la API; nada de reglas duplicadas ni llamadas HTTP internas | **C a I** |
| 4 | Recursos y endpoints que cubran catálogo, terceros, carrito, pedidos, ventas y pagos, usuarios, roles y permisos | **D a I** |
| 5 | Métodos HTTP y códigos de estado coherentes; JSON consistente; ninguna vista Blade ni redirección desde un endpoint | **B** (contrato) y **D a I** |
| 6 | Autenticación común por tokens Bearer, con vigencia documentada, revocación, límite de intentos y sin tokens en logs | **B** |
| 7 | Autorización común por roles y permisos, resuelta en Laravel, reflejando cambios en solicitudes posteriores | **C** |
| 8 | Adaptación de la administración web: login, listados, filtros, paginado, formularios, eliminaciones, usuario y permisos, cierre de sesión | **C a I** |
| 9 | Adaptación de Flutter: dirección base, rutas, métodos, cabeceras, modelos, paginado, errores, sesión | **J** |
| — | **Promoción**: documentar la API con **OpenAPI** | **K** |

### Lo que la consigna pide en la presentación

Va en el guion del tramo K, y conviene tenerlo a la vista desde el principio porque condiciona el
diseño:

- Explicar la arquitectura final y qué se reutilizó.
- **Demostrar la API sin depender de las interfaces** — hace falta una colección de peticiones lista.
- Iniciar sesión desde Flutter **y** desde la administración.
- **Una operación permitida y otra rechazada por falta de permisos.**
- **Modificar un producto desde la administración y consultar el resultado desde Flutter.**
- Completar un recorrido de compra.
- Mostrar que las operaciones de negocio pasan por la API.

---

## 3. Hallazgos

Lo que el código dice y la consigna —o el plan anterior— no contemplaba. Numerados `HL-n` siguiendo
la serie del proyecto, que la Etapa 2 dejó en **HL-95**.

### HL-96 — La web se autentica por sesión y la API por token

`AuthController::login()` usa `Auth::attempt()` y regenera la sesión; las 133 rutas web están detrás
de ese guard. La API usa Sanctum por `CredencialService`. **Unificar no es agregar un endpoint: es
cambiar cómo se protege toda la administración.**

### HL-97 — Las páginas Blade llevan hoy los datos adentro

Cada `index` se renderiza con su colección ya resuelta. La consigna lo prohíbe: *«las páginas
entregadas inicialmente por Laravel no deberán incluir datos privados que eludan la protección de la
API»*. Son **69 vistas** a revisar; las de listado se vacían y los datos llegan por `fetch`.

### HL-98 — `VerificarCuentaTienda` rechaza las cuentas de gestión en la API

Es deliberado: la API de la Etapa 2 es la de la tienda, y hay tests que fijan ese rechazo. La
consigna pide **los mismos endpoints de sesión para los dos clientes**. El rechazo deja de estar en
el inicio de sesión y pasa a estar en lo que cada cuenta puede hacer, que es donde corresponde.

### HL-99 — 277 llamadas de escritura a rutas web en los tests

En **44 archivos**. Retirar las rutas de escritura —lo exige la consigna— las rompe todas. Es el
hallazgo más caro de la etapa y tiene su estrategia en §7, tramo A.

*Medido de nuevo en el tramo A con un criterio escrito: **285 llamadas en 24 archivos** (HL-106).*

### HL-100 — Tres componentes JS ya usan `fetch`, con sesión

`selects-buscables.js`, `lineas-pedido.js` y `alta-rapida.js` llaman con `credentials: 'same-origin'`
y `X-Requested-With`. Son los primeros clientes del módulo centralizado, y el molde de lo que viene.

### HL-101 — Las descargas no son JSON, y ya hay precedente

`imprimir`, `pdf`, `exportar` y `entregaPdf` devuelven archivos. La Etapa 2 ya resolvió el caso con
**D-22**: URL firmada temporal, que el navegador abre sin credencial. Se reutiliza; no hace falta
inventar nada.

### HL-102 — El token en memoria choca con la navegación multipágina

La consigna pide el token **en memoria** en la administración y admite *«solicitar nuevamente el
ingreso al recargar o cerrar la página»*. Pero también pide **conservar la navegación existente**, que
es multipágina: cada enlace es una recarga. En memoria estricta, la administración pediría la
contraseña en cada clic. Es la decisión de diseño más importante de la etapa: **D-30**, en §11.

### HL-103 — Retirar una ruta rompe su pantalla en el mismo momento

Las ~60 rutas de escritura sostienen los formularios Blade. Si un tramo retira rutas y deja las
pantallas para después, la administración queda inusable entre tramos. **Cada tramo de módulo es
atómico**: endpoints, pantallas, retiro de rutas y tests, juntos.

### HL-104 — El relevamiento es un entregable, no un paso previo

La consigna lo pide con seis columnas por operación (§2 del PDF) y recomienda además la tabla de
migración con estado. Son dos tablas distintas sobre las mismas ~133 rutas: una describe lo que hay,
la otra sigue lo que se migra. Conviene escribirlas una sola vez, en un documento propio.

### HL-105 — Son 134 rutas web, no 133

Las correcciones de la Etapa 2 (`59714ef`) sumaron `GET ventas/{venta}/comprobantes-transferencia/
{comprobante}`, que abre el comprobante que sube el cliente. Es una descarga y va al tramo H. La API
tiene **24 rutas**. Clasificadas en `relevamiento-etapa-3.md` §1: 62 de negocio, 57 páginas, 12
descargas y 3 de sesión. **Se retiran 76.**

### HL-106 — Las llamadas de escritura son 285 en 24 archivos

Los «277 en 44» de HL-99 salieron de un criterio que no quedó escrito. El tramo A lo fija:
`->post|put|patch|delete(Json)?(` con `route('<nombre>')` o una URL literal, aunque el argumento siga
en la línea siguiente, y sin `/api/`. El script es `docs/scripts/inventario-tests.php`. Los datasets
de permisos (`pedirRuta`) se cuentan aparte. El inventario por archivo y por tramo está en
`pruebas-etapa-3.md` §4.

### HL-107 — Vaciar un shell rompe los tests de lectura, no sólo los de escritura

HL-99 sólo contaba escrituras. Pero hay **279 `GET` a rutas de página en 38 archivos**, con **294
`assertSee` y 88 `assertViewHas`**. No se rompen al retirar una ruta: se rompen al vaciar la pantalla
(HL-97, regla 37). Cada uno se clasifica como dato (pasa al endpoint) o estructura (se queda en el
shell). La regla está en `pruebas-etapa-3.md` §3. Pesa sobre todo en H (`VentaModuloTest`: 38 `GET`,
57 `assertSee`) y en G.

### HL-108 — El catálogo todavía corta a las cuentas de gestión

*Registrado en el tramo B.* El B abrió la sesión a gestión con un grupo nuevo, `api.protegida`
(`DELETE /sesion`, `GET /yo`). Los otros dos siguen con la frontera de la tienda (D-3):
`tienda.protegida` y **`tienda.sesion_opcional`**, que es el de `GET /productos`. Una credencial de
gestión ahí da **401 `sesion_invalida`**, y el cliente de la administración borraría su sesión. El
tramo D, que comparte `productos` con la administración (regla 36), tiene que sacar ese endpoint de la
frontera de ámbito y decidir la representación por permiso. No rompe nada hoy: ningún cliente de gestión
lo llama todavía.

*Resuelto en el tramo D.* Nace el grupo `api.sesion_opcional`, que es `tienda.sesion_opcional` sin la
frontera de ámbito: `GET /productos` y su ficha pasan ahí. La gestión pide `?forma=gestion` con
`producto.ver`; sin `forma`, la respuesta es la de la tienda aunque quien pregunte tenga ese permiso. Sin
sesión, `forma=gestion` es 401 `sin_sesion` (lo que falta es ingresar). `GET /categorias`, que era pública,
pasa a «credencial si sirve»: una credencial que no sirve no es error, porque el árbol no depende de quién
mira.

### HL-109 — Las pantallas sin migrar necesitan saber quién pide, hasta el tramo I

*Registrado en el tramo C.* D-30 preveía shells sin datos privados, que no necesitan saber quién los
pide. Pero de D a I las pantallas que todavía no se migraron siguen armando sus datos en el servidor,
detrás de `auth`, y usan `@can`. Un token en `sessionStorage` no viaja en una navegación: retirar el
`POST login` las habría dejado inservibles desde el tramo C. Lo resolvió D-30 con la credencial en
una cookie, que la navegación manda sola.

### HL-110 — Los archivos no viajan en un `PATCH`

*Registrado en el tramo D.* PHP sólo arma `$_FILES` en un `POST`: el logo de una marca y las imágenes de un
producto, mandados en un `PATCH` multipart, llegan vacíos. El relevamiento proponía un `POST /marcas/{id}/logo`
aparte. Se descartó: partir la edición en dos endpoints obligaba a partir `ProductoService::actualizar`, que es
mover una regla (D-34). Se manda **`POST` con `_method=PATCH`**: Laravel lo enruta al `PATCH`, el endpoint es uno
y la llamada al servicio también. Sin archivos, el `PATCH` es un `PATCH` común.

### HL-111 — La impresión ya no funciona sin JavaScript

*Registrado en el tramo D.* El diálogo de impresión de los listados migrados era un `<form method="GET">` que
el navegador mandaba solo. Ahora pide la URL firmada a la API (D-22), y la credencial viaja como `Bearer`: sólo
la manda el JS. `ImpresionDialogoTest` («funciona sin JS») se migró al endpoint con el usuario: lo que fija es
que el diálogo apunta a `/<recurso>/impresion` y que la URL firmada abre el archivo sin credencial.

### HL-112 — Lo que el buscador validaba pasa a la interfaz

*Registrado en el tramo D.* `/buscar/productos` exigía `q` de dos caracteres o más (422). Esa búsqueda es ahora
el listado de gestión, donde `q` es opcional: sin texto, el listado trae todo, y es la pantalla del catálogo.
Decidido con el usuario: **el mínimo es de la interfaz** (`data-buscable-minimo="2"`, el selector no pregunta
antes), y la API rechaza sólo lo que no puede ser una búsqueda (más de 100 caracteres). `estado=todos` deja de
existir: «todos» es no mandar el estado. El test de la Etapa 1 se migró a esas dos aserciones.

### HL-113 — No hay `GET` de una dirección

*Registrado en el tramo E.* El relevamiento listaba alta, edición y baja de una dirección, pero no su lectura: la pantalla
«editar dirección» recibía el modelo desde el servidor. Con el shell (regla 37) la pantalla necesita traerla. No se agregó
un endpoint: la ficha del cliente (`GET /clientes/{id}`) ya trae sus `direcciones`, y la pantalla toma de ahí la que edita
y sabe si es la primera o la única predeterminada. Un endpoint más habría sido otra forma de pedir lo mismo. La página web
sigue buscando la dirección **dentro** del cliente y da 404 si es ajena.

### HL-114 — El acceso a la tienda devuelve un secreto

*Registrado en el tramo E.* Hoy el enlace para elegir la contraseña viaja en la sesión (flash) y se muestra una vez. Un
endpoint no tiene flash: `POST /clientes/{id}/acceso-tienda` lo devuelve en `datos.enlace_restablecimiento`, con `201` y
`Cache-Control: no-store`. Es de un solo uso, vence en una hora, **no aparece en la ficha ni en el listado** y no se
registra. La pantalla lo muestra una vez, como antes; si se pierde, se genera de nuevo desde Usuarios.

### HL-115 — El alta rápida se compara contra el listado de la API

*Registrado en el tramo E.* `ClienteAltaRapidaTest` fijaba, con igualdad estricta, que el alta por JSON devolvía **la misma
fila** que la búsqueda. Se migró conservando la aserción: el `datos` del `POST /clientes` contra el `datos.0` de
`GET /clientes?q=…`. Por eso hay un solo `ClienteResource` para listado, ficha y alta, con las claves nuevas al final.
El modal de la venta envía a ese mismo endpoint (`data-alta-rapida-api`): un solo camino.

### HL-116 — Las filas de `ImpresionDialogoTest` se mudan de dataset

*Registrado en el tramo E.* Las filas `clientes` y `proveedores` estaban en el dataset de «ruta web»; pasaron al de
«listado migrado por la API» (como las del tramo D), sin perder la aserción: el diálogo vive afuera del formulario de
filtros y apunta a `/<recurso>/impresion`. El de «funciona sin JavaScript» deja de valer para ellas, igual que en HL-111.

### HL-117 — Las listas de la API paginan de a 20

*Registrado en el tramo E.* La web paginaba clientes y proveedores de a 15. La API ya paginaba sus listados mínimos de a
20 (`PaginadoRequest::POR_PAGINA`): cambiarlo habría cambiado una forma existente (regla 40). Los listados del tramo
quedan en 20. Los tests que contaban 15 se migraron a 20 conservando lo que fijan (que se pagina y que el resumen cuenta
bien).

### HL-118 — Lo que el formulario conservaba, la API lo rechaza

*Registrado en el tramo E.* `activo` y `es_predeterminada` son obligatorios y booleanos en la API (M-31: rechaza, no corrige).
La web los trataba como casillas, donde ausente quería decir «no»; la pantalla los manda siempre. Tampoco existe en la
API el `assertSessionHasInput` de la web («conserva lo que el usuario escribió»): el formulario nunca se recarga, así
que lo escrito no se pierde. En el test del cliente la diferencia queda anotada en un comentario; el del proveedor («un error no borra los demás campos») pasó a «un error no marca los demás» (`assertJsonMissingValidationErrors`).

### HL-119 — El kardex pagina de a 20

*Registrado en el tramo F.* La web paginaba el kardex de a 15 y la configuración de precios mostraba los impuestos de a
25. La API pagina todo de a 20, como en HL-117. El test que contaba 15 se migró a 20 con 25 movimientos, y conserva lo que
fija: el tamaño de la página, el total y que el más nuevo va primero. La pantalla de precios pide los impuestos y los
factores enteros, página por página (`todasLasPaginas`): son dos listas en la misma página, y un `?pagina=` compartido
movería las dos.

### HL-120 — Un filtro inválido del kardex es 422

*Registrado en el tramo F.* `MovimientoFiltroRequest` de la web volvía al kardex limpio con el aviso «Ese filtro no es
válido». En la API rechaza con 422 y el error en su campo (M-31), también para un rango de fechas al revés. La pantalla
marca el campo en el formulario de filtros (`iniciarListado` recibe el `formulario`). Lo mismo vale para la impresión: un
filtro inválido no imprime otra cosa.

### HL-121 — Tres cosas que vivían en los controladores web

*Registrado en el tramo F.* Ninguna es una regla de negocio, así que moverlas no rompe D-34:
- **Las reservas de un producto** (`reservasDe`) son una lectura, y pasaron a `StockService::reservas()` para que la
  web y la API lean lo mismo. El relevamiento las ponía en `GET /stock/reservas`; quedaron en `GET /productos/{id}/stock`,
  junto al ajuste. El panel del kardex y la referencia del conteo necesitan el stock y las reservas juntos, y así no
  dependen de `producto.ver`.
- **La ganancia global del simulador por omisión** pasó al controlador de la API: es traducir una ausencia.
  `ganancia_es_global` lo dice.
- **Las opciones de la vista previa** (categorías, marcas y proveedores) las piden los listados que ya existían, cada uno
  con su permiso. «Lo registró» del kardex no tenía de dónde salir: se sumó `GET /stock/usuarios`.

### HL-122 — «No hizo falta hacer nada» es 409

*Registrado en el tramo F.* `AjusteMasivoService::aplicar()` y `deshacer()` devuelven `null` cuando no hay nada que hacer:
un ajuste que no alcanza a ningún producto, o un deshacer cuyos productos cambiaron todos de precio después. La web
volvía con un `aviso` amarillo. En la API es **409 `regla_de_negocio`** con el mismo texto: no se escribió ningún lote,
y un 201 afirmaría algo que no pasó (regla 16).

### HL-123 — Impuestos, factores y lotes sin fila en el test de escala

*Registrado en el tramo F.* El dataset «listados de la API» de `EscalaTest` crea las filas con factories, e `Impuesto`,
`FactorCosto` y `LoteAjuste` no tienen. Crearlas sólo para este test sería sumar código de prueba que nadie más usa. El
conteo de uso del impuesto es una subconsulta (`scopeConUso`), no una consulta por fila, y el historial carga sus
relaciones de una vez. El kardex sí se mide: su test pasó al endpoint con el mismo criterio.

---

## 4. Alcance

**Entra todo lo que ya existe.** La consigna es explícita: *«el alcance comprenderá las
funcionalidades efectivamente desarrolladas en las etapas anteriores»*. No se agrega funcionalidad.

| Área | Qué entra |
| --- | --- |
| **Relevamiento** | Las ~133 rutas web y los ~25 endpoints, con módulo, ruta, controlador, entradas, salidas, permisos, interfaz y adaptación |
| **Autenticación** | Un mecanismo por tokens Bearer para los dos clientes, con vigencia documentada, revocación, límite de intentos y nada de tokens en logs |
| **Autorización** | Un solo lugar en Laravel; los permisos viajan al cliente como ayuda de interfaz, nunca como autoridad |
| **API** | Catálogo, categorías y productos; clientes y proveedores; carrito; pedidos, ventas y pagos; usuarios, roles y permisos; precios, stock, compras, informes y panel |
| **Administración web** | Login, listados, búsquedas, filtros, paginado, formularios, eliminaciones, consulta del usuario y cierre de sesión, todo por `fetch` contra la API |
| **Flutter** | Dirección base, rutas, métodos, cabeceras, modelos, paginado, errores y sesión, al contrato final |
| **Documentación** | `api.md` completo, el relevamiento, la matriz de migración y —para la promoción— **OpenAPI** |

### Lo que no cambia

- **El diseño de la administración.** La consigna lo dice dos veces: mínimos cambios, conservar
  diseño, navegación y componentes. No se rediseña ni se lleva a Flutter.
- **Las reglas de negocio.** Los 23 servicios se reutilizan tal como están. Si un tramo necesita
  cambiar un servicio, es señal de que está haciendo de más.
- **El modelo de datos**, salvo lo que exija la vigencia de los tokens.
- **La interfaz `Pasarela` y el recorrido de compra.**

---

## 5. Fuera de alcance — Etapa 4

Sigue vigente lo que declaró la Etapa 2 §5 —facturación con CAE, envíos, vales, banners, ingreso con
Google, notificaciones push— y se agrega lo que esta consigna tampoco pide:

| Qué | Por qué queda afuera |
| --- | --- |
| Renovación automática del token | *«No se exigirá implementar renovación automática ni persistencia del token en el navegador»* |
| Convertir la administración en una SPA | *«No será necesario rediseñar la administración ni trasladarla a Flutter»* |
| Funcionalidad nueva en cualquier módulo | El alcance son las funcionalidades ya desarrolladas |
| Una API pública para terceros | Los clientes son dos y conocidos |
| Versionar a `/api/v2` | §6: la extensión es aditiva y no rompe la app |

---

## 6. El contrato: lo que se conserva y lo que se extiende

**El contrato de la Etapa 2 no se reescribe: se extiende.** Está en `docs/api.md` r13 y en
`.claude/rules/api.md`, y ya cumple lo que pide la consigna §5: claves en español, dinero como
cadena, fechas ISO 8601 UTC, `datos` para el recurso, el sobre de paginado, la forma de error con
`mensaje`, `codigo` y `errores`, y los códigos 200/201/204/401/403/404/409/422/500.

### Las tres decisiones de contrato

**Se extiende `/api/v1`, no se crea `/api/v2`.** La extensión es aditiva: endpoints nuevos y campos
nuevos. Ninguna forma existente cambia, así que la app sigue andando con lo que ya tiene y el tramo J
se reduce a lo que la consigna §9 enumera. Si un tramo descubre que necesita cambiar una forma
existente, **para y se discute**: es la única puerta a una v2, y abrirla cuesta caro.

**Un recurso, representación por permiso.** `GET /api/v1/productos` es el mismo endpoint para el
cliente de la tienda y para el administrativo; lo que cambia es qué campos trae, según los permisos de
quien pregunta. No se crea un espacio `/api/v1/admin/`.

Es lo que el proyecto ya hace: la regla 21 dice que sin permiso **la clave `precios` no está** —ni
siquiera en `null`— y `App\Support\Api\Precios` la implementa. Esta etapa generaliza esa idea a
`costo_promedio`, `ganancia`, `stock`, `stock_reservado`, `stock_minimo` y proveedores.

El riesgo es filtrar un campo sin querer, y se acota con el test que ya existe como molde: **afirmar
que la clave no está**, en las dos direcciones del dataset de permisos.

**Las descargas van por URL firmada.** PDF, impresión y exportación reutilizan **D-22**: el endpoint
de API devuelve una URL firmada temporal y el navegador —o el teléfono— la abre sin credencial. El
endpoint no devuelve el archivo en JSON ni obliga a pasar el token por la barra de direcciones.

### Lo que hay que agregar al contrato

| Qué | Por qué |
| --- | --- |
| `GET /api/v1/yo` | Usuario autenticado, su rol y **sus permisos**. Los dos clientes lo usan para pintar la interfaz |
| Vigencia del token, documentada | La consigna §6 lo pide explícitamente |
| `401 sesion_expirada` | Hoy hay `sin_sesion` y `sesion_invalida`; un token vencido merece su propio código, porque el cliente reacciona distinto |
| Los endpoints de cada módulo | §7, tramos D a I |

---

## 7. Los tramos

Once tramos. **Cada tramo entrega su código, sus tests y la actualización de los documentos que
toca.** Un tramo sin tests no está terminado.

### Tramo A — Rama, relevamiento y estrategia de tests

**Objetivo.** Dejar identificada la entrega de la Etapa 2, abrir la rama de la 3 en los dos
repositorios y producir el relevamiento que la consigna pide como requisito 2.

**Tareas.**

- Cerrar lo que queda de la Etapa 2 (§1), etiquetar la entrega en `compustack` y en `compustack-app`,
  y crear la rama de la Etapa 3 en los dos. **Anotar qué versión de cada repositorio va con cuál.**
- Escribir **`docs/relevamiento-etapa-3.md`** con una fila por operación y las seis columnas de la
  consigna: funcionalidad y módulo, ruta y controlador actuales, datos de entrada y salida, roles y
  permisos requeridos, interfaz que la utiliza, y adaptación necesaria para exponerla por API.
- Clasificar cada una de las 133 rutas web en: **operación de negocio** (se va a la API y la ruta se
  retira), **página** (el shell Blade se conserva, vaciado de datos), **descarga** (URL firmada,
  HL-101) o **sesión** (tramo B).
- Montar la **matriz de migración** de §12, que es la tabla que la consigna recomienda para el
  seguimiento.
- **Decidir la estrategia de tests (HL-99)** y escribirla en `pruebas-etapa-3.md`: las 277 llamadas de
  escritura a rutas web se **migran** al endpoint equivalente en el tramo del módulo, conservando la
  aserción. No se borran ni se debilitan. Donde un test mezcla la escritura con un `assertSee` de la
  vista, se parte en dos: el endpoint afirma el efecto, el shell afirma que la pantalla se arma.

**Por qué va primero.** Sin el relevamiento no se sabe cuántos endpoints son, y la consigna lo pide
como entregable propio. Y sin la estrategia de tests, el primer módulo que retire una ruta deja la
suite en rojo sin saber qué hacer.

**Criterio de terminado.** Las 133 rutas clasificadas, ninguna sin destino; la matriz armada con todo
en «Pendiente»; las dos ramas creadas y las versiones compatibles anotadas.

---

### Tramo B — Autenticación común

**Objetivo.** Un solo mecanismo de token para los dos clientes, con lo que la consigna §6 exige.

**Tareas.**

- Extender `CredencialService` —**la única clase que sabe que existe Sanctum**— para emitir
  credenciales a cuentas de gestión además de las de tienda. El rechazo de HL-98 se mueve: entrar es
  de todos, hacer es de los permisos.
- Los tres endpoints, los mismos para los dos clientes: `POST /api/v1/sesion`,
  **`GET /api/v1/yo`** y `DELETE /api/v1/sesion`.
- **Vigencia del token**, definida y documentada en `api.md`, con su porqué. Token vencido → `401
  sesion_expirada`; revocado → `401 sesion_invalida`.
- **Límite de intentos de inicio de sesión**: extender el `throttle:tienda-acceso` que ya existe, o
  uno propio para gestión, con `429 demasiados_intentos` y `reintentar_en`.
- **Ni el token ni la contraseña en los logs.** Revisar el manejador de excepciones y los
  interceptores; dejar un test que lo afirme.
- Actualizar los tests de HL-98 para que fijen la regla nueva, no la vieja.

**Criterio de terminado.** Una cuenta de gestión y una de tienda inician sesión por el mismo
endpoint, reciben tokens distintos, y `GET /api/v1/yo` devuelve a cada una su rol y sus permisos. Un
token vencido da 401 con el código nuevo. El límite de intentos responde 429.

---

### Tramo C — Autorización común y el cliente de API de la web

**Objetivo.** Que la administración hable con la API por un solo lugar, y que el login sea la primera
pantalla que lo demuestre de punta a punta.

**Tareas.**

- **Autorización en un solo lugar.** El `Gate` con denegación por omisión y los permisos por acción
  ya existen y gobiernan los dos ámbitos; lo que falta es que las rutas de API los apliquen. Los
  permisos que viajan en `GET /api/v1/yo` son **ayuda de interfaz**: el servidor vuelve a decidir en
  cada petición, así que un cambio de permisos se refleja aunque el token siga siendo válido.
- **El módulo central de comunicación** —lo que la consigna §8 llama *«centralizar»*—: dirección de
  la API, token, cabeceras, lectura del sobre de respuesta, y el tratamiento de errores en un solo
  lado. **401 → pedir el ingreso de nuevo; 403 → avisar que no hay permiso; 422 → errores por campo;
  409 → el mensaje del servidor.** Ninguna pantalla repite esta lógica.
- **Los estados de interfaz compartidos**: carga, sin resultados, completado, error de validación,
  sin conexión, sesión vencida. Los mismos seis de la app, ahora del lado web.
- **Adaptar el formulario de inicio de sesión** a la API, y resolver **D-30** (dónde vive el token).
- Pasar los tres `fetch` de HL-100 al módulo central, sin cambiar lo que hacen.

**Por qué el login primero.** Es la rebanada más chica que atraviesa todo: formulario, token,
autorización, error 401 y estado de carga. Si funciona, los diez módulos que siguen copian el molde.

**Criterio de terminado.** Se entra a la administración con el token, no con la sesión. Una petición
sin permiso devuelve 403 y la pantalla lo dice. Quitado un permiso, la siguiente petición lo refleja
sin volver a iniciar sesión.

---

### Tramos D a I — Los módulos

**Los seis tienen la misma forma**, y por eso se describen juntos. Cada uno, para su conjunto de
módulos:

1. **Endpoints**: rutas en `/api/v1`, controladores en `Api/V1` que **sólo traducen**, Form Requests
   en `Requests/Api`, Resources en `Resources/Api`, con el permiso por acción.
2. **Las pantallas Blade**, conservando diseño y navegación: el shell se vacía de datos y el listado,
   los filtros, el paginado y el formulario pasan por el módulo central del tramo C.
3. **Retirar las rutas web de escritura** del módulo. En el mismo tramo (HL-103).
4. **Migrar los tests** del módulo al endpoint, conservando la aserción (HL-99).
5. **`docs/api.md`** con cada endpoint y su JSON de ejemplo, y la matriz de §12 al día.

| Tramo | Módulos | Rutas a migrar, aprox. |
| --- | --- | --- |
| **D — Catálogo** | Productos, categorías, marcas, producto-proveedor, búsquedas | ~32 |
| **E — Terceros** | Clientes, proveedores, direcciones | ~20 |
| **F — Precios y stock** | Precios (impuestos, factores, política, simulador), ajuste masivo, stock y ajustes | ~21 |
| **G — Compras** | Órdenes, armador, recepción, estados | ~15 |
| **H — Ventas y pagos** | Ventas, cobro, entrega, devolución, transferencias, informes, panel | ~23 |
| **I — Usuarios y accesos** | Personal, roles, cuenta, restablecimiento de contraseña | ~19 |

*Estos «aprox.» cuentan todas las rutas del módulo. Las que cada tramo **retira** (negocio y descarga)
están medidas en `relevamiento-etapa-3.md` §1: D 21, E 12, F 11, G 9, H 11, I 10.*

**Por qué agrupados así.** Por afinidad de pantallas y de permisos, no por tamaño. D son cuatro CRUD
casi idénticos: el primero cuesta y los otros tres son molde. H es el más delicado porque toca
`VentaService` y `PagoService`, que la app ya usa: **ahí es donde un error se ve en los dos clientes**.

**Por qué cada tramo hace backend y pantalla juntos.** La consigna separa los hitos 5 y 6, pero
hacerlos por separado deja la administración rota entre uno y otro (HL-103). Haciendo el módulo
completo, el sistema queda usable al final de cada tramo y la demostración se puede ensayar desde el D.

**Criterio de terminado, por tramo.** Ninguna ruta web de escritura del módulo responde; todas sus
pantallas funcionan contra la API; sus tests pasan contra los endpoints; sus filas de la matriz están
en «Finalizado».

---

### Tramo J — Flutter al contrato final

**Objetivo.** Lo que la consigna §9 enumera, y nada más.

**Tareas.** Dirección base y rutas; métodos y parámetros; cabeceras de autenticación;
modelos y lectura del JSON; información de paginado; tratamiento de códigos y errores; inicio y
cierre de sesión contra los endpoints del tramo B.

**Debería ser el tramo más chico de la etapa**, y si no lo es, algo salió mal en §6: la extensión era
aditiva. Lo que sí cambia con seguridad es la sesión, porque `GET /api/v1/yo` reemplaza a lo que la
app usaba, y el código `401 sesion_expirada` es nuevo.

**Criterio de terminado.** La suite de la app en verde, los JSON de ejemplo de los tests al día con
la revisión final de `api.md`, y el recorrido de compra completo en el teléfono.

---

### Tramo K — Pruebas, OpenAPI, documentación y demostración

**Objetivo.** Cerrar la etapa y poder defenderla.

**Tareas.**

- **OpenAPI** (requisito de promoción): autenticación, permisos, parámetros, respuestas y errores.
  Sale de `api.md`, que ya tiene todo eso escrito; lo que falta es la forma.
- **La colección de peticiones** para *«demostrar la API sin depender de las interfaces»*. Es un
  requisito de la presentación y conviene armarla a medida que los tramos avanzan, no al final.
- La matriz de §12 completa, el relevamiento al día, `trazabilidad.md` y `modelo-datos.md` cerrados.
- **El guion de la defensa**, con los siete puntos que la consigna enumera, ensayado dos veces.
- La suite completa de los dos repositorios, en verde.

**Criterio de terminado.** Los siete puntos de la presentación se hacen sin tocar código, y la
operación rechazada por permisos se muestra desde las dos interfaces.

---

## 8. Orden y dependencias

```
A ─▶ B ─▶ C ─▶ ┌─ D ─ E ─ F ─ G ─ H ─ I ─┐ ─▶ J ─▶ K
   relevamiento │      los seis módulos   │   app   cierre
   token  autorización + cliente web
```

- **A va primero y no se discute.** Sin el relevamiento no se sabe el tamaño; sin la estrategia de
  tests, el primer retiro de rutas deja la suite en rojo.
- **B antes que C.** No se puede autorizar a quien todavía no se sabe autenticar.
- **C antes que D.** El módulo central y los seis estados se escriben una vez; si un módulo arranca
  antes, los improvisa y después hay que desarmarlos.
- **D a I pueden ir en cualquier orden entre sí**, porque no dependen unos de otros. El orden
  propuesto va de lo repetitivo a lo delicado: **D primero porque es el molde** y **H más adelante
  porque toca lo que la app ya usa**.
- **J después de los módulos**, con el contrato ya quieto. Hacerlo antes obliga a repetirlo.
- **K es transversal**: el OpenAPI, la colección y la matriz se llenan desde A. En el tramo sólo se
  cierran y se ensaya.

---

## 9. Qué se recorta si el tiempo no alcanza

**Los once tramos son requisitos de aprobación**, salvo el OpenAPI, que es promoción. Así que el
recorte no es de tramos sino de profundidad, y en este orden:

1. **El OpenAPI** (promoción, no aprobación). Se declara y queda en Etapa 4.
2. **El refinamiento de las pantallas**: animaciones y transiciones del lado web. Los seis estados
   **no** se recortan: la consigna §8 los pide.
3. **Los informes y el panel** del tramo H, que son lectura y se pueden dejar renderizados en el
   servidor si no llevan datos protegidos más allá de lo que el permiso ya controla. **Hay que
   declararlo**, porque roza el requisito 3.

**No se recorta, bajo ningún concepto**, porque la consigna los nombra uno por uno: el retiro de las
rutas de escritura, la autenticación común por tokens, la autorización central, los códigos de estado
coherentes, y que ningún endpoint devuelva Blade o redirija al login.

---

## 10. Riesgos

| Riesgo | Por qué | Cómo se acota |
| --- | --- | --- |
| **La etapa se calcula por los endpoints y no por las pantallas** | 22 módulos, 69 vistas y 11 componentes JS: la mitad del trabajo es el lado web | Cada tramo de módulo incluye sus pantallas; el criterio de terminado las nombra |
| **La administración queda rota entre tramos** | Retirar una ruta rompe su formulario (HL-103) | Tramos atómicos: endpoints, pantallas, retiro y tests, juntos |
| **La suite se cae y no se levanta** | 277 llamadas de escritura a rutas web en 44 archivos (HL-99) | La estrategia se decide en A, y cada módulo migra **sus** tests en **su** tramo. Nunca se borra un test para que pase |
| **Se filtra un campo protegido** | Un recurso, dos audiencias (§6) | `Support/Api/Precios` como molde, y el test que afirma que **la clave no está**, en las dos direcciones del dataset |
| **Aparece lógica de negocio en un controlador de API** | 22 módulos copiando un molde, con prisa | Los controladores sólo traducen; es criterio de terminado y está en `.claude/rules/api.md` |
| **Alguien resuelve la reutilización llamando a la propia API** | Es tentador desde un comando o un job | **La consigna lo prohíbe explícitamente.** Se reutiliza el servicio, nunca por HTTP |
| **La app se rompe sin que nadie lo note** | El backend cambia en otro repositorio | §6: la extensión es aditiva. Cambiar una forma existente obliga a parar y discutir |
| **El token en la administración** | HL-102: en memoria contra navegación multipágina | **D-30** lo decide en el tramo C, con su porqué escrito |
| **Una página deja datos privados en el HTML** | 69 vistas, y hoy todas los llevan (HL-97) | Revisión por módulo y un test que mire el HTML entregado |
| **El relevamiento se hace a ojo** | Son 133 rutas y aburre | Se arma con `route:list` y se cruza con los controladores: ninguna fila sin clasificar |

---

## 11. Decisiones a confirmar

Cinco. La primera cambia el diseño de toda la administración.

### D-30 — Dónde vive el token en la administración web

El problema está en HL-102. Tres salidas:

| Salida | A favor | En contra |
| --- | --- | --- |
| **En memoria estricta** | Es lo que la consigna dice literalmente; nada queda al alcance de un XSS | Cada navegación pide la contraseña: la administración multipágina se vuelve inusable |
| **En `sessionStorage`** | Sobrevive a la navegación y muere al cerrar la pestaña; no es persistencia entre sesiones | Un XSS podría leerlo |
| **Sesión sólo para servir los shells, token para la API** | Conserva la navegación tal cual; los dos clientes usan Bearer contra la API, que es lo que pide §6 | Convive un mecanismo viejo con uno nuevo, y hay que explicar por qué |

**Mi recomendación: `sessionStorage`**, con los shells de Blade sin dato privado alguno. Cumple la
consigna en lo que importa —el mismo mecanismo de token contra la API, nada que sobreviva al cierre
de la pestaña— y conserva la navegación existente, que la §8 pide expresamente. Se documenta la
decisión y se nombra la alternativa estricta y por qué se descartó.

**Decidida en el tramo C: la credencial en una cookie** (HL-109). Es una sola credencial, la de Sanctum
que emite `POST /api/v1/sesion`. El módulo central (`resources/js/api/cliente.js`) la escribe en la cookie
`credencial` y la manda como `Bearer` a la API. La navegación la manda sola, y `AutenticarConCredencial`
la lee para servir las pantallas Blade, `@can` incluido. No se guarda nada en la sesión, que queda sólo
para el CSRF y los avisos. La API no lee la cookie: sólo el `Bearer` (`CredencialWebTest`).
- **Contra `sessionStorage`**: no viaja en la navegación, y las pantallas sin migrar quedarían rotas hasta el I.
- **Contra «sesión para los shells»**: serían dos credenciales con vigencias y revocaciones distintas.
  Con una sola, revocarla, que venza (8 h, D-31) o desactivar la cuenta corta la API y las pantallas
  en el mismo pedido. Y un permiso quitado se nota en el pedido siguiente.
- **Lo que cuesta**: un XSS podría leerla, igual que con `sessionStorage` (el JS tiene que leerla para
  el `Bearer`). Muere al cerrar el navegador, no la pestaña. Lleva `SameSite=Strict` y `Secure` en https.
  Las rutas web que todavía escriben siguen pidiendo el token CSRF.

### D-31 — Vigencia del token

Hay que definirla y documentarla. **Propongo distinguir por cliente**: la administración, lo que dura
un turno de trabajo; la app, más largo, porque un teléfono no se comparte y volver a escribir la
contraseña en un celular es caro. Hace falta un número para cada uno y su porqué.

**Decidida en el tramo B.** Por el ámbito de la cuenta y no por el cliente que dice ser, porque el
ámbito es un dato (regla 9): **gestión, 8 horas** (`SANCTUM_EXPIRATION_GESTION`, 480); **tienda, 7
días** (`SANCTUM_EXPIRATION`, sin cambio). Vencida, `401 sesion_expirada`. Documentada en `api.md` §4, r14.

### D-32 — El relevamiento, ¿un documento o dos?

La consigna pide el relevamiento (§2) y recomienda la matriz de migración. **Propongo un documento,
`relevamiento-etapa-3.md`, con las dos tablas**: la descriptiva se escribe una vez en el tramo A y no
se toca más; la de migración cambia de estado en cada tramo.

### D-33 — Qué pasa con los informes y el panel

Son lectura pura y bastante consulta agregada. ¿Pasan por la API como todo lo demás, o se
consideran páginas y se dejan renderizadas? **Propongo pasarlos**, por coherencia con el requisito 3,
y dejarlos como primer candidato de recorte (§9) si el tiempo aprieta.

### D-34 — ¿Se tocan los servicios?

El alcance dice que no. Pero algunos métodos reciben hoy un `Request` o dependen de la sesión para
saber quién opera. **Propongo la regla**: si un servicio necesita cambiar, el cambio se limita a
recibir el dato en lugar de buscarlo, nunca a mover una regla. Y si un tramo propone más, para.

---

## 12. Matriz de migración

La tabla que la consigna recomienda, con una fila por operación. Se arma en el tramo A con todo en
«Pendiente» y cada tramo mueve sus filas.

**Armada en el tramo A en `relevamiento-etapa-3.md`**, junto con el relevamiento (HL-104): las columnas
de esta tabla están ahí, módulo por módulo. Las filas de abajo quedan como el molde original.

| Operación | Implementación anterior | Endpoint RESTful | Permiso requerido | Cambio en la interfaz | Estado |
| --- | --- | --- | --- | --- | --- |
| Consultar catálogo | `GET /productos` · `ProductoController@index` | `GET /api/v1/productos?forma=gestion` | `producto.ver` / público | Administración web y Flutter | Finalizado (tramo D) |
| Modificar producto | `PUT /productos/{id}` · `ProductoController@update` | `PATCH /api/v1/productos/{id}` | `producto.editar` | Administración web | Finalizado (tramo D) |
| Registrar pedido | `POST /api/v1/pedidos` | *(ya en la API)* | Cliente | Flutter | Finalizado |
| Administrar roles | `PUT /roles/{id}` · `RolController@update` | `PATCH /api/v1/roles/{id}` | `rol.editar` | Administración web | Pendiente |
| … | | | | | |

Las filas que ya están en la API desde la Etapa 2 entran como **Finalizado**, con su endpoint: son
parte del relevamiento y conviene que se vean, porque muestran que una parte del trabajo ya estaba
hecha.

---

## 13. Antes de empezar

Cinco verificaciones que, si fallan, cambian el plan. Se hacen en el tramo A, antes del relevamiento:

1. **Correr las dos suites completas** y anotar los números de partida. Todo lo que venga después se
   compara contra ellos.
2. **Comprobar que `GET /api/v1/yo` no existe ya** con otro nombre en `PerfilController`, para
   extender en lugar de duplicar.
3. **Mirar cómo `Gate::before` trata a una cuenta de gestión que llega por la API**, que hoy no pasa
   nunca por ahí.
4. **Confirmar que el `throttle` de acceso se puede reutilizar** para las cuentas de gestión.
5. **Elegir una pantalla de administración y recorrerla entera** —listado, filtro, paginado,
   formulario, eliminación— anotando cada dato que hoy llega renderizado. Es la medida real de HL-97,
   y es lo que dice si el tramo D dura tres días o diez.

---

## 14. Lo que esta etapa tiene que lograr, en una frase

Que **modificar un producto desde la administración y verlo desde el teléfono** pase por el mismo
endpoint, con el mismo token y los mismos permisos. Si eso se cumple, la administración y la tienda
dejaron de ser dos sistemas que comparten una base de datos y pasaron a ser dos clientes del mismo
sistema.
