# Relevamiento de la Etapa 3

El requisito 2 de la consigna: qué operaciones de negocio tiene el sistema, por dónde pasan hoy y qué
hace falta para exponerlas por la API. Es también la **matriz de migración** (plan §12): cada tramo
mueve el estado de sus filas en el mismo tramo.

Medido sobre el código al cerrar la Etapa 2 (`develop@59714ef`), con `route:list` y reflexión sobre
los controladores: **134 rutas web** y **24 de API**.

## 0. Cómo se lee

**Una fila por operación**, agrupadas por módulo. Una operación junta las rutas que la hacen posible:
la página del formulario y la ruta que lo recibe son la misma operación.

Las columnas cubren las dos tablas que pide la consigna, para no escribirlas dos veces (HL-104):

| Columna | Relevamiento (consigna §2) | Matriz (plan §12) |
| --- | --- | --- |
| Operación | Funcionalidad y módulo | Operación |
| Rutas actuales | Ruta y controlador actuales | Implementación anterior |
| Entrada / Salida | Datos de entrada y salida | — |
| Permiso | Roles y permisos requeridos | Permiso requerido |
| Interfaz | Interfaz que la utiliza | Cambio en la interfaz |
| Endpoint | Adaptación necesaria | Endpoint RESTful |
| Tramo · Estado | — | Estado |

**Clase de cada ruta web**, marcada después de la ruta (plan §7, tramo A):

- **N — operación de negocio**: se va a la API y la ruta web se retira.
- **P — página**: el shell Blade se conserva, vacío de datos; lo que hoy llega renderizado lo pide por
  la API (HL-97).
- **D — descarga**: el endpoint devuelve una URL firmada temporal que el navegador abre (D-22, HL-101).
  La ruta web se retira.
- **S — sesión**: la resuelven los tramos B y C.

Todas las rutas de gestión pasan además por `auth` y `gestion`; la columna Permiso dice sólo el `can:`.
Los controladores son los de `app/Http/Controllers/`.

**Convenciones de los endpoints propuestos.**
- Modificar es `PATCH`, como el `PATCH /api/v1/perfil` de la Etapa 2.
- Una transición de estado es un `POST` a un subrecurso con el nombre de la acción (`.../aprobar`): la
  tabla de transiciones decide si se puede, no el verbo.
- Los listados paginan con `pagina` y `por_pagina` (A-25) y devuelven el sobre de `Paginado::respuesta()`.
- Los endpoints que ya existen **se extienden**: filtros y campos nuevos por permiso, nunca una forma
  cambiada (regla 40).

## 1. Resumen

| Clase | Rutas web | Destino |
| --- | --- | --- |
| N — negocio | 62 | Se retiran; su endpoint en la columna Endpoint |
| P — página | 57 | Se conservan como shell |
| D — descarga | 12 | Se retiran; endpoint con URL firmada |
| S — sesión | 3 | Tramos B y C |
| **Total** | **134** | |

Las 60 rutas web de escritura (32 POST, 15 PUT, 2 PATCH, 11 DELETE) son 58 N y 2 S (`POST login`,
`POST logout`): **ninguna queda en `web.php`** al cerrar la etapa. Las otras 4 N son las búsquedas
`GET buscar/...`, de lectura: ya devuelven JSON por sesión (HL-100) y se pliegan en los listados de la
API.

Por tramo (rutas web que retira, N y D, o que vacía, P):

| Tramo | N | D | P | S | Se retiran |
| --- | --- | --- | --- | --- | --- |
| B/C — Sesión | — | — | 2 | 3 | 2 (`POST login`, `POST logout`) |
| D — Catálogo | 18 | 3 | 11 | — | 21 |
| E — Terceros | 10 | 2 | 8 | — | 12 |
| F — Precios y stock | 10 | 1 | 10 | — | 11 |
| G — Compras | 7 | 2 | 6 | — | 9 |
| H — Ventas, pagos y panel | 7 | 4 | 11 | — | 11 |
| I — Usuarios y accesos | 10 | — | 9 | — | 10 |
| **Total** | **62** | **12** | **57** | **3** | **76** |

Las rutas `GET` de tipo P no se retiran: siguen sirviendo el shell. El `GET login` (S) también queda.

*(Los conteos los recalcula el script de §10. Si este cuadro y el script no coinciden, manda el
script.)*

---

## 2. Sesión y cuenta — tramos B, C e I

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Entrar a la administración | `GET login` ·S · `POST login` ·S — `Auth\AuthController@mostrarLogin`, `@login` | `email`, `password` (`LoginRequest`) | Sesión regenerada y redirección a `inicio` | `guest`, `throttle:5,1` | Admin web: el formulario se conserva y envía a la API; el token lo guarda el módulo central (D-30) | `POST /api/v1/sesion` *(existe; se extiende a cuentas de gestión, HL-98)* | C · Finalizado *(el formulario lo envía el módulo central; la credencial, en cookie: D-30)* |
| Quién soy | — *(hoy la sesión lo resuelve en cada vista)* | Token | Usuario, rol y permisos | autenticado | Admin web y Flutter: pintan la interfaz por permisos | `GET /api/v1/yo` *(nuevo, plan §6)* | B · Finalizado |
| Salir | `POST logout` ·S — `Auth\AuthController@logout` | — | Sesión invalidada, redirección a `login` | `auth` | Admin web: el botón llama a la API y borra el token | `DELETE /api/v1/sesion` *(existe)* | C · Finalizado |
| Portada | `GET /` ·P (redirección a `inicio`) · `GET inicio` ·P — `PanelController@inicio` | — | Redirección al panel que el rol puede ver | `auth`, `gestion` | Admin web: la redirección la sigue decidiendo el servidor con el Gate, ahora sobre la cuenta de la credencial (tramo C: la regla queda en un solo lugar; `/yo` sólo serviría para pintar) | — | C · Finalizado |
| Restablecer la contraseña con el enlace | `GET restablecer/{token}` ·P · `POST restablecer` ·N — `Auth\RestablecerPasswordController@mostrar`, `@restablecer` | `token`, `email`, `password` (`RestablecerPasswordRequest`) | Redirección a `login` o a `auth.cuenta-tienda-lista` si la cuenta es de tienda | `guest`, `throttle:5,1` | Admin web (y el enlace que recibe una cuenta de tienda) | `POST /api/v1/contrasena/restablecer` | I · Pendiente |
| Cambiar la contraseña propia | `GET cuenta/password` ·P · `PUT cuenta/password` ·N — `CuentaController@editarPassword`, `@actualizarPassword` | `password_actual`, `password` (`CambiarPasswordRequest`) | Redirección con aviso | `auth` | Admin web | `PUT /api/v1/yo/contrasena` | I · Pendiente |

## 3. Catálogo — tramo D

### Productos

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar catálogo | `GET productos` ·P — `ProductoController@index` · API: `GET api/v1/productos`, `GET api/v1/productos/{producto}` | `q`, `categoria_id`, `marca_id`, `proveedor_id`, `estado`, `stock`, `page` (`ProductoFiltroRequest`) | Vista `productos.index` con `productos` paginados, `categorias`, `marcas` | `producto.ver` / público en la API | Admin web: el listado, los filtros y el paginado pasan a la API. Flutter: sin cambio | `GET /api/v1/productos?forma=gestion` *(existe: otra representación del mismo recurso, con `producto.ver`; costo y stock por su permiso)* | D · Finalizado *(sin `forma`, la tienda de siempre; HL-108)* |
| Imprimir el listado de productos | `GET productos/imprimir` ·D — `ProductoController@imprimir` | Los filtros del listado | Página imprimible | `producto.ver` | Admin web: abre la URL firmada | `GET /api/v1/productos/impresion` → URL firmada | D · Finalizado |
| Crear producto | `GET productos/crear` ·P · `POST productos` ·N — `ProductoController@create`, `@store` | `codigo`, `nombre`, `descripcion`, `categoria_id`, `marca_id`, `precio_lista`, `precio_contado`, `precio_manual`, `ganancia`, `alicuota_iva`, `stock_minimo`, `cantidad_reposicion`, `activo`, `destacado` (`ProductoRequest`) | Formulario con valores por omisión; tras guardar, redirección con aviso | `producto.crear` | Admin web: el formulario envía por la API y pide categorías y marcas por la API | `POST /api/v1/productos` | D · Finalizado |
| Modificar producto | `GET productos/{producto}/editar` ·P · `PUT productos/{producto}` ·N — `ProductoController@edit`, `@update` | Ídem `ProductoRequest` | Formulario con `producto`, `calculo` de precio e `historial`; tras guardar, redirección | `producto.editar` | Admin web | `GET /api/v1/productos/{producto}?forma=gestion` *(cálculo e historial por permiso)* · `GET /api/v1/productos/opciones` · `PATCH /api/v1/productos/{producto}` *(con imágenes, `POST` + `_method=PATCH`: HL-110)* | D · Finalizado |
| Eliminar o desactivar producto | `DELETE productos/{producto}` ·N — `ProductoController@destroy` | — | Redirección; si está referenciado, se desactiva (regla 2) | `producto.eliminar` | Admin web | `DELETE /api/v1/productos/{producto}` | D · Finalizado |
| Recalcular el precio de un producto | `POST productos/{producto}/recalcular` ·N — `ProductoController@recalcular` | — | Redirección con el precio nuevo | `precio.recalcular` | Admin web | `POST /api/v1/productos/{producto}/recalcular` | D · Finalizado |

### Proveedores de un producto

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Ver los proveedores de un producto | `GET productos/{producto}/proveedores` ·P — `ProductoProveedorController@index` | — | Vista `productos.proveedores` con `producto` y `vinculos` | `producto.editar` | Admin web | `GET /api/v1/productos/{producto}/proveedores?forma=comparador` · `?forma=disponibles` | D · Finalizado |
| Vincular un proveedor | `POST productos/{producto}/proveedores` ·N — `ProductoProveedorController@store` | `proveedor_id`, `costo_ultimo`, `codigo_proveedor`, `es_preferido` (`ProductoProveedorRequest`) | Redirección | `producto.editar` | Admin web | `POST /api/v1/productos/{producto}/proveedores` | D · Finalizado |
| Modificar el vínculo | `GET productos/{producto}/proveedores/{proveedor}/editar` ·P · `PUT productos/{producto}/proveedores/{proveedor}` ·N — `@edit`, `@update` | `costo_ultimo`, `codigo_proveedor`, `es_preferido` | Formulario con `vinculo`; redirección | `producto.editar` | Admin web | `PATCH /api/v1/productos/{producto}/proveedores/{proveedor}` | D · Finalizado |
| Marcar el proveedor preferido | `PATCH productos/{producto}/proveedores/{proveedor}/preferido` ·N — `@preferido` | — | Redirección | `producto.editar` | Admin web | `POST /api/v1/productos/{producto}/proveedores/{proveedor}/preferido` | D · Finalizado |
| Desvincular un proveedor | `DELETE productos/{producto}/proveedores/{proveedor}` ·N — `@destroy` | — | Redirección | `producto.editar` | Admin web | `DELETE /api/v1/productos/{producto}/proveedores/{proveedor}` | D · Finalizado |

### Categorías

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar categorías | `GET categorias` ·P — `CategoriaController@index` · API: `GET api/v1/categorias` | `q`, `parent_id`, `estado`, `page` (`CategoriaFiltroRequest`) | Vista con `categorias`, `padreFiltrado`, `padresConHijas` | `categoria.ver` / público en la API | Admin web. Flutter: sin cambio | `GET /api/v1/categorias?forma=lista` *(existe: otra representación, con `categoria.ver`)* · `GET /api/v1/categorias/padres-posibles` | D · Finalizado *(el árbol de la tienda, en «credencial si sirve»)* |
| Imprimir categorías | `GET categorias/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `categoria.ver` | Admin web | `GET /api/v1/categorias/impresion` → URL firmada | D · Finalizado |
| Crear categoría | `GET categorias/crear` ·P · `POST categorias` ·N — `@create`, `@store` | `nombre`, `parent_id`, `orden`, `activo`, `desactivar_contenido` (`CategoriaRequest`) | Formulario; redirección | `categoria.crear` | Admin web | `POST /api/v1/categorias` | D · Finalizado |
| Modificar categoría | `GET categorias/{categoria}/editar` ·P · `PUT categorias/{categoria}` ·N — `@edit`, `@update` | Ídem | Formulario con `categoria` y `padres`; redirección | `categoria.editar` | Admin web | `GET /api/v1/categorias/{categoria}` · `PATCH /api/v1/categorias/{categoria}` | D · Finalizado |
| Eliminar o desactivar categoría | `DELETE categorias/{categoria}` ·N — `@destroy` | — | Redirección | `categoria.eliminar` | Admin web | `DELETE /api/v1/categorias/{categoria}` | D · Finalizado |

### Marcas

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar marcas | `GET marcas` ·P — `MarcaController@index` | `q`, `estado`, `page` (`MarcaFiltroRequest`) | Vista con `marcas` paginadas | `marca.ver` | Admin web | `GET /api/v1/marcas` | D · Finalizado |
| Imprimir marcas | `GET marcas/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `marca.ver` | Admin web | `GET /api/v1/marcas/impresion` → URL firmada | D · Finalizado |
| Crear marca | `GET marcas/crear` ·P · `POST marcas` ·N — `@create`, `@store` | `nombre`, `logo` (archivo), `quitar_logo`, `activo`, `desactivar_productos` (`MarcaRequest`) | Formulario; redirección | `marca.crear` | Admin web: el logo viaja en `multipart/form-data` | `POST /api/v1/marcas` | D · Finalizado |
| Modificar marca | `GET marcas/{marca}/editar` ·P · `PUT marcas/{marca}` ·N — `@edit`, `@update` | Ídem | Formulario con `marca`; redirección | `marca.editar` | Admin web | `GET /api/v1/marcas/{marca}` · `PATCH /api/v1/marcas/{marca}` *(con logo, `POST` + `_method=PATCH` en el mismo endpoint: HL-110; no hizo falta `/logo`)* | D · Finalizado |
| Eliminar o desactivar marca | `DELETE marcas/{marca}` ·N — `@destroy` | — | Redirección | `marca.eliminar` | Admin web | `DELETE /api/v1/marcas/{marca}` | D · Finalizado |

### Búsquedas de los selectores

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Buscar productos | `GET buscar/productos` ·N — `BusquedaController@productos` | `q`, `estado`, `proveedor_id`, `con_proveedor`, `excluir` (`BusquedaRequest`) | JSON por sesión (HL-100) | `producto.ver` | Admin web: `selects-buscables.js`, `lineas-pedido.js` | `GET /api/v1/productos?forma=gestion&q=…` *(se pliega en el listado: un recurso, un camino; suma `con_proveedor` y `excluir[]`)* | D · Finalizado *(el mínimo de dos caracteres pasa a la interfaz)* |
| Buscar los proveedores de un producto | `GET buscar/productos/{producto}/proveedores` ·N — `@proveedoresDeProducto` | — | JSON | `proveedor.ver` | Admin web: armador de compras | `GET /api/v1/productos/{producto}/proveedores` | D · Finalizado |
| Buscar clientes | `GET buscar/clientes` ·N — `@clientes` | `q` | JSON | `cliente.ver` | Admin web: venta | `GET /api/v1/clientes?q=…` | D · Finalizado |
| Buscar proveedores | `GET buscar/proveedores` ·N — `@proveedores` | `q`, `estado` | JSON | `proveedor.ver` | Admin web: filtros y compras | `GET /api/v1/proveedores?q=…` | D · Finalizado |

> Las búsquedas de clientes y proveedores son del tramo D porque las usan los selectores del catálogo.
> Sus listados (tramo E) las absorben: el D dejó `GET /api/v1/clientes` (con `q`) y
> `GET /api/v1/proveedores` (con `q` y `estado`) mínimos.

## 4. Terceros — tramo E

### Clientes y direcciones

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar clientes | `GET clientes` ·P — `ClienteController@index` | `q`, `condicion_iva`, `cuenta`, `page` (`ClienteFiltroRequest`) | Vista con `clientes` paginados | `cliente.ver` | Admin web | `GET /api/v1/clientes` | E · Finalizado |
| Imprimir clientes | `GET clientes/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `cliente.ver` | Admin web | `GET /api/v1/clientes/impresion` → URL firmada | E · Finalizado |
| Crear cliente | `GET clientes/crear` ·P · `POST clientes` ·N — `@create`, `@store` | `razon_social`, `tipo_doc`, `condicion_iva`, `nro_doc`, `email`, `telefono` (`ClienteRequest`) | Formulario; redirección, o JSON para el alta rápida (`alta-rapida.js`, HL-100) | `cliente.crear` | Admin web: formulario y alta rápida desde la venta | `POST /api/v1/clientes` | E · Finalizado |
| Modificar cliente | `GET clientes/{cliente}/editar` ·P · `PUT clientes/{cliente}` ·N — `@edit`, `@update` | Ídem | Formulario con `cliente` y `direcciones`; redirección | `cliente.editar` | Admin web | `GET /api/v1/clientes/{cliente}` · `PATCH /api/v1/clientes/{cliente}` | E · Finalizado |
| Eliminar o desactivar cliente | `DELETE clientes/{cliente}` ·N — `@destroy` | — | Redirección | `cliente.eliminar` | Admin web | `DELETE /api/v1/clientes/{cliente}` | E · Finalizado |
| Dar acceso a la tienda | `POST clientes/{cliente}/acceso-tienda` ·N — `@darAccesoTienda` | `nombre`, `apellido` (`AccesoTiendaRequest`) | Redirección; la cuenta recibe el enlace para elegir contraseña | `cliente.dar_acceso_tienda` | Admin web | `POST /api/v1/clientes/{cliente}/acceso-tienda` | E · Finalizado |
| Agregar dirección | `GET clientes/{cliente}/direcciones/crear` ·P · `POST clientes/{cliente}/direcciones` ·N — `DireccionController@create`, `@store` | `calle`, `numero`, `piso_depto`, `codigo_postal`, `localidad`, `provincia`, `es_predeterminada` (`DireccionRequest`) | Formulario; redirección | `cliente.editar` | Admin web | `POST /api/v1/clientes/{cliente}/direcciones` | E · Finalizado |
| Modificar dirección | `GET clientes/{cliente}/direcciones/{direccion}/editar` ·P · `PUT clientes/{cliente}/direcciones/{direccion}` ·N — `@edit`, `@update` | Ídem | Formulario; redirección | `cliente.editar` | Admin web | `PATCH /api/v1/clientes/{cliente}/direcciones/{direccion}` | E · Finalizado |
| Eliminar dirección | `DELETE clientes/{cliente}/direcciones/{direccion}` ·N — `@destroy` | — | Redirección | `cliente.editar` | Admin web | `DELETE /api/v1/clientes/{cliente}/direcciones/{direccion}` | E · Finalizado |

### Proveedores

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar proveedores | `GET proveedores` ·P — `ProveedorController@index` | `q`, `estado`, `canal`, `page` (`ProveedorFiltroRequest`) | Vista con `proveedores` paginados | `proveedor.ver` | Admin web | `GET /api/v1/proveedores` | E · Finalizado |
| Imprimir proveedores | `GET proveedores/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `proveedor.ver` | Admin web | `GET /api/v1/proveedores/impresion` → URL firmada | E · Finalizado |
| Crear proveedor | `GET proveedores/crear` ·P · `POST proveedores` ·N — `@create`, `@store` | `razon_social`, `cuit`, `email`, `telefono`, `contacto`, `canal_pedido`, `portal_url`, `plazo_entrega_dias`, `activo`, `desactivar_productos` (`ProveedorRequest`) | Formulario; redirección | `proveedor.crear` | Admin web | `POST /api/v1/proveedores` | E · Finalizado |
| Modificar proveedor | `GET proveedores/{proveedor}/editar` ·P · `PUT proveedores/{proveedor}` ·N — `@edit`, `@update` | Ídem | Formulario con `proveedor` y `productosActivos`; redirección | `proveedor.editar` | Admin web | `GET /api/v1/proveedores/{proveedor}` · `PATCH /api/v1/proveedores/{proveedor}` | E · Finalizado |
| Eliminar o desactivar proveedor | `DELETE proveedores/{proveedor}` ·N — `@destroy` | — | Redirección | `proveedor.eliminar` | Admin web | `DELETE /api/v1/proveedores/{proveedor}` | E · Finalizado |

## 5. Precios y stock — tramo F

### Precios

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar la configuración de precios | `GET precios` ·P — `PrecioController@index` | — | Vista con `politica`, `impuestos`, `factores`, `uso` | `precio.ver` | Admin web | `GET /api/v1/precios/politica` · `GET /api/v1/impuestos` · `GET /api/v1/factores-costo` | F · Finalizado |
| Modificar la política de precios | `PUT precios/politica` ·N — `@actualizarPolitica` | `ganancia_global`, `recargo_lista`, `redondeo_multiplo`, `redondeo_modo` (`PoliticaPreciosRequest`) | Redirección | `precio.editar` | Admin web | `PATCH /api/v1/precios/politica` | F · Finalizado |
| Simular un precio | `GET precios/simulador` ·P — `@simulador` | `costo`, `ganancia`, `alicuota_iva` (`SimuladorRequest`) | Vista con `politica`, `alicuotas` y el `calculo` | `precio.ver` | Admin web | `GET /api/v1/precios/simulacion` | F · Finalizado |
| Crear impuesto | `GET precios/impuestos/crear` ·P · `POST precios/impuestos` ·N — `@crearImpuesto`, `@guardarImpuesto` | `nombre`, `alicuota`, `alcance`, `comprobante`, `orden`, `activo` (`ImpuestoRequest`) | Formulario; redirección | `precio.editar` | Admin web | `POST /api/v1/impuestos` | F · Finalizado |
| Modificar impuesto | `GET precios/impuestos/{impuesto}/editar` ·P · `PUT precios/impuestos/{impuesto}` ·N — `@editarImpuesto`, `@actualizarImpuesto` | Ídem | Formulario con `impuesto` y `enUso`; redirección | `precio.editar` | Admin web | `GET /api/v1/impuestos/{impuesto}` · `PATCH /api/v1/impuestos/{impuesto}` | F · Finalizado |
| Eliminar impuesto | `DELETE precios/impuestos/{impuesto}` ·N — `@eliminarImpuesto` | — | Redirección | `precio.editar` | Admin web | `DELETE /api/v1/impuestos/{impuesto}` | F · Finalizado |
| Crear factor de costo | `GET precios/factores/crear` ·P · `POST precios/factores` ·N — `@crearFactor`, `@guardarFactor` | `nombre`, `modo`, `porcentaje`, `orden`, `activo` (`FactorCostoRequest`) | Formulario; redirección | `precio.editar` | Admin web | `POST /api/v1/factores-costo` | F · Finalizado |
| Modificar factor de costo | `GET precios/factores/{factor}/editar` ·P · `PUT precios/factores/{factor}` ·N — `@editarFactor`, `@actualizarFactor` | Ídem | Formulario; redirección | `precio.editar` | Admin web | `GET /api/v1/factores-costo/{factor}` · `PATCH /api/v1/factores-costo/{factor}` | F · Finalizado |
| Eliminar factor de costo | `DELETE precios/factores/{factor}` ·N — `@eliminarFactor` | — | Redirección | `precio.editar` | Admin web | `DELETE /api/v1/factores-costo/{factor}` | F · Finalizado |
| Ajuste masivo de precios | `GET precios/ajuste` ·P · `POST precios/ajuste` ·N — `AjusteMasivoController@index`, `@aplicar` | `ids` y los parámetros del ajuste (`AjusteMasivoRequest`) | Vista previa con `datos`, `conteos`, `filas`; tras aplicar, redirección al lote | `precio.ajustar_masivo` | Admin web | `GET /api/v1/precios/ajustes/vista-previa` · `POST /api/v1/precios/ajustes` | F · Finalizado |
| Consultar los lotes de ajuste | `GET precios/lotes` ·P — `@lotes` | — | Vista con `lotes` | `precio.ver` | Admin web | `GET /api/v1/precios/lotes` | F · Finalizado |
| Deshacer un lote | `POST precios/lotes/{lote}/deshacer` ·N — `@deshacer` | — | Redirección; se escribe el lote inverso (M-16) | `precio.deshacer_ajuste` | Admin web | `POST /api/v1/precios/lotes/{lote}/deshacer` | F · Finalizado |

### Stock

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar movimientos de stock | `GET stock` ·P — `StockController@index` | `producto_id`, `tipo`, `usuario_id`, `canal`, `desde`, `hasta`, `page` (`MovimientoFiltroRequest`) | Vista con `movimientos`, `producto`, `reservas`, `usuarios` | `stock.ver` | Admin web | `GET /api/v1/stock/movimientos` · `GET /api/v1/stock/usuarios` · `GET /api/v1/productos/{producto}/stock` | F · Finalizado *(las reservas son del producto, junto a su ajuste; «Lo registró» pide sus opciones)* |
| Imprimir movimientos | `GET stock/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `stock.ver` | Admin web | `GET /api/v1/stock/movimientos/impresion` → URL firmada | F · Finalizado |
| Ajustar el stock por conteo | `GET stock/{producto}/ajuste` ·P · `POST stock/{producto}/ajuste` ·N — `@crearAjuste`, `@guardarAjuste` | `stock_contado`, `motivo`, `stock_esperado` (`AjusteStockRequest`) | Formulario con `producto`; redirección. Pasa por `StockService` (regla 4) | `stock.ajustar` | Admin web | `POST /api/v1/productos/{producto}/ajustes-stock` | F · Finalizado |

## 6. Compras — tramo G

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar órdenes de compra | `GET compras` ·P — `CompraController@index` | `q`, `estado`, `origen`, `proveedor_id`, `desde`, `hasta`, `page` (`OrdenCompraFiltroRequest`) | Vista con `ordenes` y `proveedores` | `compra.ver` | Admin web | `GET /api/v1/compras` | G · Finalizado *(los proveedores del filtro, por `GET /api/v1/compras/proveedores`: HL-125)* |
| Imprimir órdenes | `GET compras/imprimir` ·D — `@imprimir` | Los filtros | Página imprimible | `compra.ver` | Admin web | `GET /api/v1/compras/impresion` → URL firmada | G · Finalizado |
| Armar un pedido de compra | `GET compras/pedido` ·P · `POST compras/pedido` ·N — `@armar`, `@guardarPedido` | `productos` (precarga) · `lineas` (`PedidoCompraRequest`) | Armador con `lineas`, `productos`, `proveedores`; tras guardar, una orden por proveedor | `compra.crear` | Admin web: `lineas-pedido.js` | `POST /api/v1/compras` | G · Finalizado *(la precarga de los sugeridos, por `GET /api/v1/compras/armador`: HL-124)* |
| Consultar los sugeridos | `GET compras/sugeridos` ·P — `@sugeridos` | — | Vista con `sugeridos`, `grupos`, `estimado` | `compra.crear` | Admin web | `GET /api/v1/compras/sugeridos` | G · Finalizado |
| Ver una orden | `GET compras/{orden}` ·P — `@show` | — | Vista con `orden` | `compra.ver` | Admin web | `GET /api/v1/compras/{orden}` | G · Finalizado |
| Modificar una orden | `GET compras/{orden}/editar` ·P · `PUT compras/{orden}` ·N — `@edit`, `@update` | `proveedor_id`, `observaciones`, `lineas` (`OrdenCompraRequest`) | Formulario; redirección | `compra.editar` | Admin web | `PATCH /api/v1/compras/{orden}` | G · Finalizado *(la pantalla lee la ficha y los proveedores activos; la guardia de estado queda en el shell)* |
| Aprobar | `POST compras/{orden}/aprobar` ·N — `@aprobar` | — | Redirección | `compra.aprobar` | Admin web | `POST /api/v1/compras/{orden}/aprobar` | G · Finalizado |
| Marcar enviada | `POST compras/{orden}/enviada` ·N — `@marcarEnviada` | — | Redirección | `compra.aprobar` | Admin web | `POST /api/v1/compras/{orden}/enviada` | G · Finalizado |
| Cancelar | `POST compras/{orden}/cancelar` ·N — `@cancelar` | — | Redirección | `compra.aprobar` | Admin web | `POST /api/v1/compras/{orden}/cancelar` | G · Finalizado |
| Cerrar incompleta | `POST compras/{orden}/cerrar` ·N — `@cerrarIncompleta` | — | Redirección | `compra.recibir` | Admin web | `POST /api/v1/compras/{orden}/cerrar` | G · Finalizado |
| Recibir mercadería | `GET compras/{orden}/recepcion` ·P · `POST compras/{orden}/recepcion` ·N — `@recepcion`, `@recibir` | `cantidades`, `remito_numero`, `remito_fecha` (`RecepcionCompraRequest`) | Formulario con `orden`; redirección. Entra stock por `StockService` | `compra.recibir` | Admin web | `POST /api/v1/compras/{orden}/recepciones` | G · Finalizado *(201 con la ficha; la guardia de estado queda en el shell)* |
| Descargar la orden en PDF | `GET compras/{orden}/pdf` ·D — `@pdf` | — | PDF (dompdf) | `compra.ver` | Admin web | `GET /api/v1/compras/{orden}/pdf` → URL firmada | G · Finalizado *(ruta firmada propia, `compras/{orden}/pedido.pdf`: HL-126; sin aprobar, 409)* |

## 7. Ventas, pagos y panel — tramo H

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar ventas | `GET ventas` ·P — `VentaController@index` | `q`, `estado`, `cliente_id`, `vendedor_id`, `canal`, `pago`, `desde`, `hasta`, `page` (`VentaFiltroRequest`) | Vista con `ventas` y `vendedores` | `venta.ver` | Admin web | `GET /api/v1/ventas` | H · Finalizado *(los vendedores del filtro, por `GET /api/v1/ventas/vendedores`)* |
| Exportar ventas | `GET ventas/exportar` ·D — `@exportar` | Los filtros | Archivo | `venta.ver` | Admin web | `GET /api/v1/ventas/exportacion` → URL firmada | H · Finalizado *(corrección: el endpoint es `GET /api/v1/ventas/impresion`, como D a G, y no `ventas/exportacion`)* |
| Registrar venta | `GET ventas/crear` ·P · `POST ventas` ·N — `@create`, `@store` | `cliente_id`, `descuento_porcentaje`, `observaciones`, `lineas[producto_id, cantidad]` (`VentaRequest`) | Formulario con `productos`, `congelados`, `tope` de descuento; redirección | `venta.crear` | Admin web: `lineas-pedido.js`, `alta-rapida.js` | `POST /api/v1/ventas` | H · Finalizado *(corrección: la interfaz usa `lineas-venta.js`, no `lineas-pedido.js`)* |
| Ver una venta | `GET ventas/{venta}` ·P — `@show` | — | Vista con `venta`, `entregas`, `comprobantesTransferencia` | `venta.ver` | Admin web | `GET /api/v1/ventas/{venta}` | H · Finalizado *(la ficha trae `acciones`, `cobro` y `devolucion`: HL-131)* |
| Modificar una venta | `GET ventas/{venta}/editar` ·P · `PUT ventas/{venta}` ·N — `@edit`, `@update` | Ídem `VentaRequest` | Formulario; redirección | `venta.editar` | Admin web | `PATCH /api/v1/ventas/{venta}` | H · Finalizado |
| Recotizar | `POST ventas/{venta}/recotizar` ·N — `@recotizar` | — | Redirección con los precios del día | `venta.editar` | Admin web | `POST /api/v1/ventas/{venta}/recotizar` | H · Finalizado |
| Cancelar una venta | `POST ventas/{venta}/cancelar` ·N — `@cancelar` | — | Redirección; libera la reserva | `venta.editar` | Admin web | `POST /api/v1/ventas/{venta}/cancelar` | H · Finalizado |
| Cobrar | `GET ventas/{venta}/cobro` ·P · `POST ventas/{venta}/cobro` ·N — `PagoController@create`, `@store` | `pagos[]` (`PagoRequest`) | Formulario con `saldo`, `medios`, `problemas`; redirección. El stock sale acá (regla 5) | `venta.cobrar` | Admin web | `POST /api/v1/ventas/{venta}/pagos` | H · Finalizado *(la pantalla lee la ficha: el saldo, el medio principal y los problemas, HL-128 y HL-131)* |
| Entregar | `GET ventas/{venta}/entrega` ·P · `POST ventas/{venta}/entregar` ·N — `@entrega`, `@entregar` | `cantidades` (`EntregaRequest`) | Formulario con `venta`; redirección | `venta.entregar` | Admin web | `POST /api/v1/ventas/{venta}/entregas` | H · Finalizado *(sin cuerpo, entrega todo lo pendiente; la entrega parcial lee la ficha)* |
| Descargar el remito de una entrega | `GET ventas/{venta}/entregas/{entrega}/pdf` ·D — `@entregaPdf` | — | PDF | `venta.ver` | Admin web | `GET /api/v1/ventas/{venta}/entregas/{entrega}/pdf` → URL firmada | H · Finalizado *(ruta firmada propia: HL-130)* |
| Registrar una devolución | `GET ventas/{venta}/devolucion` ·P · `POST ventas/{venta}/devolucion` ·N — `@devolucion`, `@devolver` | `cantidades`, `metodo`, `motivo`, `monto` (`DevolucionRequest`) | Formulario con `medios` y `sugerido`; redirección | `venta.anular` | Admin web | `POST /api/v1/ventas/{venta}/devoluciones` | H · Finalizado *(el `monto` sigue prohibido: lo calcula el servidor; el medio sugerido, en la ficha)* |
| Descargar el comprobante de la venta | `GET ventas/{venta}/pdf` ·D — `@pdf` | — | PDF | `venta.ver` | Admin web | `GET /api/v1/ventas/{venta}/pdf` → URL firmada | H · Finalizado *(ruta firmada propia, imprime también el presupuesto: HL-129)* |
| Ver un comprobante de transferencia | `GET ventas/{venta}/comprobantes-transferencia/{comprobante}` ·D — `ComprobanteTransferenciaController@show` | — | El archivo que subió el cliente | `venta.ver_comprobante_transferencia` | Admin web | `GET /api/v1/ventas/{venta}/comprobantes-transferencia/{comprobante}` → URL firmada | H · Finalizado *(ruta firmada propia: HL-130)* |
| Informe por vendedor | `GET ventas/vendedores` ·P — `InformeVentasController@vendedores` | `desde`, `hasta` (`PanelFiltroRequest`) | Vista con `filas` y `total` | `panel.ver_global` | Admin web | `GET /api/v1/informes/vendedores` | H · Finalizado *(con `totales`; período inválido, 422)* |
| Informe por producto | `GET ventas/productos` ·P — `@productos` | `desde`, `hasta` | Vista con `filas` | `panel.ver_global` | Admin web | `GET /api/v1/informes/productos` | H · Finalizado |
| Panel propio | `GET panel` ·P — `PanelController@propio` | `desde`, `hasta` | Vista con `metricas` (Chart.js) | `panel.ver_propio` | Admin web | `GET /api/v1/panel` | H · Finalizado |
| Panel general | `GET panel/general` ·P — `@general` | `desde`, `hasta` | Vista con `metricas` | `panel.ver_global` | Admin web | `GET /api/v1/panel/general` | H · Finalizado *(la frase de cada comparación, del servidor; período inválido, 422)* |

## 8. Usuarios y accesos — tramo I

| Operación | Rutas actuales | Entrada | Salida | Permiso | Interfaz | Endpoint | Tramo · Estado |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Consultar el personal | `GET personal` ·P — `PersonalController@index` | `q`, `rol_id`, `estado`, `situacion`, `page` (`PersonalFiltroRequest`) | Vista con `personal`, `usuarios`, `roles` | `usuario.ver` | Admin web | `GET /api/v1/personal` | I · Pendiente |
| Dar de alta a un empleado | `GET personal/crear` ·P · `POST personal` ·N — `@create`, `@store` | `nombre`, `apellido`, `email`, `activo`, `rol_id`, `password` (`PersonalRequest`) | Formulario; redirección | `usuario.crear` | Admin web | `POST /api/v1/personal` | I · Pendiente |
| Modificar un empleado | `GET personal/{usuario}/editar` ·P · `PUT personal/{usuario}` ·N — `@edit`, `@update` | Ídem | Formulario con `usuario`; redirección | `usuario.editar` | Admin web | `GET /api/v1/personal/{usuario}` · `PATCH /api/v1/personal/{usuario}` | I · Pendiente |
| Dar de baja a un empleado | `DELETE personal/{usuario}` ·N — `@destroy` | — | Redirección; se desactiva (regla 2) | `usuario.eliminar` | Admin web | `DELETE /api/v1/personal/{usuario}` | I · Pendiente |
| Cambiar el rol | `GET personal/{usuario}/rol` ·P · `PATCH personal/{usuario}/rol` ·N — `@editarRol`, `@cambiarRol` | `rol_id` (`CambiarRolRequest`) | Formulario con `roles`; redirección | `usuario.cambiar_rol` | Admin web | `PUT /api/v1/personal/{usuario}/rol` | I · Pendiente |
| Generar el enlace de restablecimiento | `POST personal/{usuario}/restablecer-contrasena` ·N — `@generarEnlaceDeRestablecimiento` | — | Redirección con el enlace | `usuario.resetear_password` | Admin web | `POST /api/v1/personal/{usuario}/restablecer-contrasena` | I · Pendiente |
| Administrar roles | `GET roles` ·P · `GET roles/crear` ·P · `POST roles` ·N · `GET roles/{rol}/editar` ·P · `PUT roles/{rol}` ·N · `DELETE roles/{rol}` ·N — `RolController` | `nombre`, `descripcion`, `ambito`, `permisos[]` (`RolRequest`) | Vistas con `roles`, `rol`, `permisos`, `permisosActuales` | `rol.ver` (consultar) / `rol.editar` | Admin web | `GET /api/v1/roles` · `GET /api/v1/permisos` · `POST /api/v1/roles` · `GET`/`PATCH`/`DELETE /api/v1/roles/{rol}` | I · Pendiente |

## 9. Tienda — ya en la API desde la Etapa 2

Entran como **Finalizado**: muestran que una parte del trabajo ya estaba hecha. Controladores en
`Api/V1/`; todas las rutas protegidas pasan por `tienda.protegida`.

| Operación | Endpoint | Entrada | Salida | Permiso | Interfaz | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| Datos de la tienda | `GET api/v1/tienda` — `TiendaController@show` | — | `TiendaResource` | público | Flutter | Finalizado |
| Catálogo de la tienda | `GET api/v1/productos`, `GET api/v1/productos/{producto}`, `GET api/v1/categorias` | `CatalogoRequest`, `PaginadoRequest` | Listado paginado y ficha, `precios` según permiso | público, `tienda.sesion_opcional` | Flutter *(la administración se suma en el tramo D)* | Finalizado |
| Registrarse | `POST api/v1/registro` — `RegistroController@store` | `nombre`, `apellido`, `email`, `password`, `telefono` | Token | `throttle:tienda-acceso` | Flutter | Finalizado |
| Iniciar y cerrar sesión | `POST api/v1/sesion`, `DELETE api/v1/sesion` — `SesionController` | `email`, `password` | Token / 204 | `throttle:tienda-acceso` / `tienda.protegida` | Flutter *(extendido en el tramo B: también cuentas de gestión)* | Finalizado |
| Perfil | `GET api/v1/perfil`, `PATCH api/v1/perfil` — `PerfilController` | `nombre`, `apellido`, `telefono`, `tipo_doc`, `nro_doc`, `direccion` | `PerfilResource` | `tienda.protegida` | Flutter | Finalizado |
| Carrito | `GET`/`DELETE api/v1/carrito`, `POST api/v1/carrito/lineas`, `PATCH`/`DELETE api/v1/carrito/lineas/{linea}` | `producto_id`, `cantidad` | `CarritoResource` | `tienda.comprar` | Flutter | Finalizado |
| Revisar el pedido | `POST api/v1/carrito/checkout` — `CheckoutController@store` | — | `ResumenCheckoutResource` | `tienda.comprar` | Flutter | Finalizado |
| Registrar pedido | `POST api/v1/pedidos` — `PedidoController@store` | `carrito_id`, `medio_pago`, `modo_entrega`, `direccion` | `PedidoResource` | `tienda.comprar` | Flutter | Finalizado |
| Mis pedidos | `GET api/v1/pedidos`, `GET api/v1/pedidos/{pedido}`, `GET api/v1/pedidos/{pedido}/comprobante` | `PaginadoRequest` | Listado, detalle y URL firmada del comprobante | `tienda.ver_pedidos` | Flutter | Finalizado |
| Descargar el comprobante | `GET api/v1/pedidos/{pedido}/comprobante.pdf` — `@descargarComprobante` | Firma | PDF | `signed` (D-22) | Flutter / navegador | Finalizado |
| Pagar un pedido | `POST api/v1/pedidos/{pedido}/pagos`, `GET api/v1/pedidos/{pedido}/pagos`, `GET api/v1/pedidos/{pedido}/pagos/{intento}` — `PagoPedidoController` | `pasarela` | `IntentoPagoResource` | `tienda.comprar` / `tienda.ver_pedidos` | Flutter | Finalizado |
| Subir el comprobante de transferencia | `POST api/v1/pedidos/{pedido}/comprobante-transferencia` — `ComprobanteTransferenciaController@store` | `archivo` | `PedidoDetalleResource` | `tienda.cargar_comprobante` | Flutter | Finalizado |

## 10. Control de cobertura

Ninguna ruta web sin destino. El script cruza `route:list` con este documento: cada ruta web tiene que
aparecer en una fila como `` `MÉTODO uri` `` seguida de su clase.

```sh
php artisan route:list --except-vendor --json > rutas.json
php -r '$r=json_decode(file_get_contents("rutas.json"),true);$d=file_get_contents("docs/relevamiento-etapa-3.md");$c=[];$f=[];
foreach($r as $x){if(str_starts_with($x["uri"],"api/")||$x["uri"]==="up")continue;$m=explode("|",$x["method"])[0];$u=$x["uri"];
if(preg_match("#`".preg_quote("$m $u","#")."` ·([NPDS])#",$d,$k))$c[$k[1]]=($c[$k[1]]??0)+1;else $f[]="$m $u";}
ksort($c);print_r($c);echo "sin destino: ",count($f),"\n",implode("\n",$f),"\n";'
```
