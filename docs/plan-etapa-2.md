# Plan de la Etapa 2 — Tienda móvil con Flutter e integración con Laravel

Etapa en curso. La autoridad es la consigna (`docs/Proyecto - Etapa 02.pdf`). El código
dice qué existe; este plan, qué se construye y por qué. Lo que la consigna no pide va a la
Etapa 4 (§5), con su motivo.

**De dónde sale.** De la consigna de la Etapa 2, del documento «Etapa 2 — Requerimientos de
la aplicación móvil» (versión 2, al cierre de la Etapa 1) y de una verificación del código
en el commit `ce0b491`. Donde los requerimientos y el código no coinciden, manda el código, y
la diferencia está en la §3.

**Cómo se lee.**

| Sección | Qué responde |
| --- | --- |
| §1 | Qué hay de verdad en el código, y qué se decidió antes de empezar |
| §2 | Qué pide la consigna, numerado, y qué tramo lo cierra. **Es la tabla con la que se aprueba la etapa** |
| §3 | Qué suponían los documentos y no es cierto |
| §4 y §5 | Qué entra y qué no |
| §6 | El contrato de la API, que es el primer hito |
| §7 | Los nueve tramos, con el detalle para arrancar cualquiera sin volver a analizar el proyecto |
| §8 a §11 | Orden, recortes, Etapa 3 y riesgos |
| §12 | Las preguntas: las resueltas y las que quedan |
| §13 | La matriz de seguimiento |
| §14 | **Lo que queda por completar** |

---

## 1. Estado real al empezar

Verificado leyendo el código en el commit `ce0b491` («ETAPA 01 Finalizada»). Es lectura,
no ejecución: la suite no se corrió para escribir esto.

### 1.1 Lo que se confirma

| Pieza | Qué dice el código |
| --- | --- |
| Dos precios por producto | `precio_lista` («tarjeta / cuotas») y `precio_contado` («efectivo / transferencia / QR») en la migración de `productos` |
| Ventas | `canal` (`mostrador`, `online`), `modo_entrega` (`retiro`, `envio`), `costo_envio`, `usuario_id` nullable. Fuera de `$fillable` salvo `usuario_id`: se escriben por asignación directa |
| Once estados | `MaquinaEstadosVenta`: `pendiente_pago → pagada | cancelada`. No hay `presupuesto → pendiente_pago`, y `MaquinaEstadosVentaTest` lo fija como prohibido |
| Alta de venta | `VentaService::crear(array, User)`: nace en `presupuesto` y `escribirLineas()` cotiza siempre con `precio_contado` |
| Cobro | `PagoService::cobrar(Venta, array, User)`: bloquea antes de leer, exige el saldo exacto, es idempotente por `mp_payment_id` (`esReintentoYaAcreditado()`, búsqueda global) y termina en `VentaService::marcarPagada(Venta, User)` |
| Stock | `StockService` tiene cinco métodos y ni `reservar` ni `liberar`. `descontar()` ya acepta `?User`; el que exige usuario es `marcarPagada()` |
| Disponible | `Producto::DISPONIBLE` y el accesor `stock_disponible`. `stock_reservado` en cero en todas partes |
| Cuentas | Rol **Cliente** sembrado, de ámbito `tienda`, sin permisos. `UsuarioService::crear()` arma `user` + `cliente` en una transacción. El seeder de demostración ya trae una cuenta de tienda (`cliente@sistema.local`) con dirección |
| Frontera de gestión | Doble: `AuthController` rechaza el ámbito tienda, y el grupo de gestión lleva `['auth', 'gestion']`, más el `Gate::before` que concede o devuelve `null` |
| Catálogo | `destacado` sin formulario, `created_at`, `Categoria::idsDescendientes()`, `scopeBuscar()` (nombre **o código**), `deCategoria()`, `conEstado()` |
| Pagos | ENUM `efectivo, transferencia, qr, mercadopago`; `Pago::METODOS_EN_USO` ofrece tres |
| Capa de API | No existe: ni Sanctum en `composer.json` ni `routes/api.php`. `bootstrap/app.php` traduce sólo `ReglaDeNegocioException`, con `back()` |
| Programador | `routes/console.php`: sólo la reposición de las 07:00 |
| Imágenes | `APP_URL=http://localhost:8000`; el disco `public` arma `env('APP_URL').'/storage'`; las vistas usan `Storage::url()` |
| Informes | `InformeVentasService::ventasConVendedor()` hace `join` con `users`: las ventas sin vendedor quedan afuera, y el comentario ya lo dice |
| Listado de ventas | No muestra ni filtra el canal |
| Volumen | `CatalogoVolumenSeeder`: los 20.012 productos con el mismo `created_at` (`$this->ahora`), `imagenes` nulo y `destacado` falso. `DatabaseSeeder` no lo llama |
| Documentos | `pendientes-usabilidad.md` termina en el #80; `trazabilidad.md` §15 está vacía |

### 1.2 Lo que hay que corregir del estado verificado

| Se suponía | El código dice | Qué cambia |
| --- | --- | --- |
| Unas 1.190 definiciones de test, unas 25 funciones globales | **1.239** `test()`/`it()` en 84 archivos; **28** funciones globales fuera de `tests/Pest.php` (más 27 adentro) y 12 constantes | Nada en la regla: todo helper nuevo de la etapa lleva un prefijo propio |
| Unos quince comentarios con la «Etapa 2» vieja | **23 menciones en 18 archivos**. Algunas hablan de la tienda, que sí es esta etapa | Se corrigen a «Etapa 4» sólo las de facturación, envíos y vales, cuando un tramo toca el archivo |
| `tipo_doc` y `condicion_iva` son NOT NULL | `tipo_doc` es NOT NULL **sin** valor por omisión; `condicion_iva` tiene `consumidor_final` por omisión; **`nro_doc` es nullable**, y MariaDB admite varios NULL bajo UNIQUE(`tipo_doc`, `nro_doc`) | El registro mínimo no pide documento: `tipo_doc = 'dni'` explícito y `nro_doc` nulo. La contracara: la vinculación con un cliente de mostrador sólo se podría hacer por correo, y `clientes.email` no es único |
| `recotizar()` ya revalida los precios | Revalida, pero **sólo un presupuesto** (`exigirPresupuesto()`) y **siempre a contado** | No sirve tal cual: la revalidación de la app ocurre sobre el carrito, antes de que exista la venta, y con la lista del medio de pago. Se reusa el criterio (inexistente, inactivo, precio cambiado) y la forma de `cambios[]`, no el método |
| «El rol Cliente recibe 403 en gestión» | La frontera **no responde 403**: `VerificarAmbitoGestion` cierra la sesión y redirige al login (302). Un token Bearer no autentica en el grupo `web`, que usa el guard de sesión: también redirige | El test afirma **«nunca 200, siempre al login»**, recorriendo todas las rutas que llevan `gestion`, con sesión de Cliente y con token |
| El kardex no prevé las reservas | `movimientos_stock.tipo` ya tiene `reserva_liberada` (ENUM, `MovimientoStock::TIPOS`, `EsquemaDatosTest`) y no tiene `reserva`. `StockService::registrar()` es privado por la regla «no hay forma de registrar un movimiento sin mover el stock» | Decidido en D-5: la reserva no deja asiento |
| `StockService` respeta la regla 6 | `descontar()` escribe `$p->stock - $p->stock_reservado` a mano | Se corrige cuando el tramo E reescribe `descontar()` |
| El seeder de demostración necesita fechas | Necesita más: 11 productos, descripción de relleno («Producto de demostración: …»), **ninguna imagen**, la misma fecha de alta y ningún destacado. Dos con stock cero (`HX-STINGER`, `COR-4000D`) | El tramo C agrega el seeder de la tienda. Los dos sin stock sirven para mostrar «sin disponibilidad» |
| — | `direcciones` y `DireccionService` existen desde la Etapa 1 | El envío mínimo **propone** la dirección predeterminada y la copia al pedido |

```php
// app/Services/StockService.php — descontar()
$p = Producto::lockForUpdate()->findOrFail($producto->id);
$disponible = $p->stock - $p->stock_reservado;
```

### 1.3 Lo que el estado verificado no decía

**A. La baja de una cuenta no corta la API.** `VerificarUsuarioActivo` se agrega sólo al
grupo `web` y trabaja con la sesión:

```php
// bootstrap/app.php
$middleware->web(append: [
    \App\Http\Middleware\VerificarUsuarioActivo::class,
]);
```

Con un token, un cliente desactivado seguiría operando: es A-5 reabierto por la puerta
nueva. El tramo A agrega su equivalente para `api/*`, y `UsuarioService` revoca los tokens al
desactivar.

**B. Una cuenta de gestión no tiene ficha de cliente.** La invariante de `UsuarioService`
garantiza fila en `clientes` sólo para el ámbito tienda. Una cuenta de gestión no puede tener
carrito ni pedidos: la API la rechaza (D-3).

**C. Una reserva propia se cuenta como faltante.** La pantalla de cobro avisa con
`Venta::faltantesDeStock()` (`cantidad > stock_disponible`), y `descontar()` valida contra el
disponible. Con el pedido online ya reservado, los dos cuentan sus propias unidades como no
disponibles: aviso falso y `StockInsuficienteException` si el producto está justo.
`descontar()` tiene que **consumir** la reserva del pedido, y el aviso tiene que descontarla.

**D. La web ya cancela un pedido online, y no libera nada.** La ficha muestra «Cancelar el
presupuesto» siempre que `cancelada` sea destino, así que también en `pendiente_pago`.
`VentaService::cancelar()` no sabe de reservas, y el texto dice «presupuesto».

**E. Plata aprobada afuera contra un rollback adentro.** `cobrar()` → `marcarPagada()` →
`descontar()` es una sola transacción. Si el descuento falla, se revierte la fila de `pagos`.
Con Mercado Pago, la plata **ya entró** en el proveedor: el sistema la tendría sin
registrarla, que es la regla 16 al revés. Dos condiciones de diseño para E y G:
- una reserva con un intento de pasarela en curso no vence;
- consumir la reserva no puede fallar por falta de disponible.

Si aun así falla, el intento queda «aprobado sin acreditar» y se reintenta: nunca se pierde.

**F. Las decisiones 8 y 16 del prompt se contradecían para el contado.** Resuelto en D-1.

**G. Dos tests de la Etapa 1 fijan lo que esta etapa cambia.**
- **`VentaFiltrosTest`** fija `Venta::ESTADOS_EN_USO` sin `pendiente_pago`. Cambia con
  permiso (D-2).
- **`PagoServiceTest`** fija `METODOS_EN_USO` en tres. **No cambia**: `METODOS_EN_USO` sigue
  significando «lo que ofrece el cajero», y la pasarela escribe su método por su propio
  camino. Cambia el motivo escrito en `Pago`, no el test.
- `EsquemaDatosTest` fija los once estados de `ventas`, no los métodos de `pagos`.

**H. No hay `morphMap`.** `origen_type` guarda el nombre completo de la clase. Con D-5 ningún
carrito llega al kardex, así que no hace falta tocarlo.

**I. La concurrencia se prueba inyectando.** Cada test corre en su transacción, así que una
segunda conexión no ve los datos. `PagoServiceTest` («dos cobros concurrentes no exceden el
saldo») mete la escritura competidora con `DB::listen` en el instante del `FOR UPDATE`. Las
pruebas de la reserva usan la misma técnica.

**J. No hay proyecto Flutter.** La app arranca de cero, en su propio repositorio (D-4).

### 1.4 Qué bloquea qué

| Bloqueo | Tramos | Estado |
| --- | --- | --- |
| Sanctum o JWT | A, D | **Resuelto**: Sanctum (D-7) |
| Dónde vive el proyecto Flutter | B | **Resuelto**: repositorio propio (D-4) |
| La reserva del pedido de contado | E, F, G | **Resuelto** (D-1) |
| Asiento de kardex para las reservas | E | **Resuelto**: no deja asiento (D-5) |
| Permiso para cambiar `VentaFiltrosTest` | F | **Dado** (D-2) |
| Nombre, identificador e ícono de la app | B | **Resuelto** (D-18). Falta el archivo del logo (§14.1) |
| `url_launcher` para abrir Mercado Pago | G | **Resuelto**: se usa (D-19) |
| Si Mercado Pago es recortable | G | **Resuelto**: es obligatorio (D-20) |
| URL de imágenes y catálogo sin fotos | C | Se resuelve en A (URL) y en C (seeder) |

### 1.5 Decisiones tomadas

Se tomaron antes de escribir los tramos. No se reabren durante la etapa: si una resulta
equivocada al implementarla, se plantea y se decide de nuevo antes de escribir código.

| # | Decisión | Por qué | Qué cambia |
| --- | --- | --- | --- |
| D-1 | **El pedido de contado reserva con plazo propio** en `config/` (48 horas al empezar). Al vencer, **el pedido se cancela solo** y libera la reserva | Lo que la app confirma, el local lo tiene. Veinte minutos no alcanzan para llegar al mostrador, y sin reserva el cobro podría fallar después de que la app dijo «pedido registrado» | La tarea programada cancela los pedidos de contado vencidos. El plazo está en `config/tienda.php` y en `docs/api.md` |
| D-2 | **`VentaFiltrosTest` cambia**: `pendiente_pago` entra en `Venta::ESTADOS_EN_USO` | La regla que fijaba («ninguna pantalla de la Etapa 1 lo dispara») deja de ser cierta, y el cajero tiene que poder filtrarlo | El test afirma que `pendiente_pago` está en uso y que `en_preparacion`, `despachada` y `lista_retiro` siguen afuera. Cambia en el tramo F, con el motivo en el commit |
| D-3 | **La API rechaza las cuentas de gestión** | No tienen ficha de cliente. Es el espejo del login web | El inicio de sesión responde el mismo error que con credenciales incorrectas |
| D-4 | **La app vive en su propio repositorio** | Otro lenguaje, otra cadena de compilación, otro ciclo de versiones | Ver «Dos repositorios», abajo |
| D-5 | **La reserva no deja asiento en el kardex** | El kardex responde qué entró y salió del depósito, y una reserva no mueve mercadería | El rastro queda en el carrito y en el pedido. `reserva_liberada` queda declarado y sin usar, como `en_preparacion` |
| D-6 | **El rol Cliente recibe permisos de tienda**, uno por acción: `tienda.ver_precios`, `tienda.comprar` (carrito, confirmación y pago) y `tienda.ver_pedidos` | La consigna pide reutilizar «las cuentas y los permisos». El precio queda protegido como el costo con `precio.ver_costo` | Sin token, el precio no viaja y el orden por precio da 401; con token sin el permiso, el precio no viaja y el orden da 403. El Administrador los recibe por su `'*'` sin efecto (D-3). Pendiente #81: la pantalla de roles ofrece el módulo `tienda` a roles de gestión |
| D-7 | **Sanctum** emite la credencial | Viene con `php artisan install:api`; revocar al cerrar sesión es nativo (una fila por credencial); el vencimiento es configuración. JWT necesita un paquete de terceros y una lista negra para revocar | `config/sanctum.php` con `expiration` (7 días al empezar, acortable desde `.env` para mostrar la sesión vencida). Sólo `CredencialService` sabe que es Sanctum |
| D-8 | **La idempotencia de la confirmación es el carrito**: la confirmación nombra `carrito_id`, y `ventas.carrito_id` es UNIQUE | La base lo garantiza, sin tabla ni cabecera extra. Es el patrón de `mp_payment_id`. Además deja escrito de qué carrito salió cada venta | Un segundo `POST /pedidos` con el mismo carrito devuelve 200 con el mismo pedido |
| D-9 | **Pasarela: el rechazo conserva la reserva; el vencimiento cancela.** La reserva dura 20 minutos, nunca vence con un intento pendiente, y al vencer sin pago aprobado el pedido se cancela solo | Es la regla del contado con otro plazo: un solo camino de vencimiento, menos casos | La tarea programada cancela también estos pedidos. El intento pendiente tiene su propio vencimiento, y la preferencia de Mercado Pago vence con él |
| D-10 | **El medio de pago queda fijo en el pedido** | La consigna pide reintentar, no cambiar de medio. Cambiarlo pide recotizar un pedido pendiente | Reintentar es otro intento por la misma pasarela. Cambiar de medio va a la Etapa 4 |
| D-11 | **El carrito guarda un precio de referencia, nunca un precio de venta**: `carrito_lineas.contado_referencia` y `lista_referencia` | Para «informar si cambió el precio» hace falta contra qué comparar. La venta se cotiza siempre al precio vigente: la referencia sólo detecta el cambio | Al iniciar el checkout y al confirmar, si el precio vigente difiere de la referencia: 409 `carrito_cambio` con los cambios, y la referencia se actualiza. El segundo intento, ya revisado, pasa. El porqué va en `modelo-datos.md` §4, junto a «el carrito no guarda precios» |
| D-18 | **La app se llama CompuStack.** Identificador de Android `ar.compustack.tienda`; paquete Dart `compustack`. Ícono: el logo de CompuStack sobre fondo **blanco crema** (`#FAF6EC` al empezar, ajustable) | El identificador (`applicationId`) es el nombre único y permanente de la app en Android: dos apps con el mismo no conviven en un teléfono, y una vez publicada no se cambia. Se escribe como un dominio al revés. `ar.compustack` no exige tener ese dominio mientras la app no se publique, y `tienda` deja lugar para otra app del mismo sistema | El tramo B fija nombre, identificador e ícono. El ícono adaptativo se genera con el asistente *Image Asset* de Android Studio, **sin librería**: el logo como primer plano y el crema como fondo |
| D-19 | **`url_launcher` entra a la lista de librerías** | Mercado Pago se paga en su propia página (Checkout Pro), que la app tiene que abrir. Es el paquete oficial del equipo de Flutter para eso | Se usa detrás de un servicio propio (`NavegadorExterno`) para que los tests lo reemplacen por un falso y ningún widget lo importe (regla 24). Abre en una pestaña personalizada de Chrome |
| D-20 | **Mercado Pago es obligatorio**, no recortable | La promoción suma a la calificación final, y se decide tratarla como requisito | Sale de los recortes de la §9 y entra en «no se recorta nunca». El simulador seguía como respaldo; **D-23 lo saca de la app**: las pruebas son las cuentas de prueba de Mercado Pago. Las cuentas de prueba se preparan antes del tramo E (§14.2) |
| D-21 | **El catálogo se pagina con números, no con scroll infinito** (decidida en el tramo D) | Elegir la página da control y deja saber dónde se está («Página 5 de 981»); con 20.000 productos el scroll infinito no tiene final a la vista | El pie lleva «anterior», números y «siguiente» en Catálogo y Búsqueda; otra página reemplaza a la que se ve. Reemplaza lo que el tramo C pedía («la página siguiente al acercarse al final») |
| D-22 | **La cuenta se edita desde la app; lo fiscal que decide el comprobante, no** (decidida al cerrar el tramo F, resuelve Q-2) | Comprar pide los datos de quien compra: cargarlos una vez y que se autocompleten ahorra pasos (regla 30). La condición frente al IVA decide Factura A o B, y declararla mal es un problema fiscal del local; el domicilio de facturación sólo lo pide la facturación con ARCA, que está fuera de alcance (§5) | Tramo K: `PATCH /perfil` con nombre, apellido, teléfono, documento (`tipo_doc` + `nro_doc`, opcional) y la dirección predeterminada. La condición frente al IVA se ve y no se edita. No hay domicilio de facturación. El PDF que baja la app se llama **comprobante**, nunca factura: no es fiscal |
| D-23 | **El simulador de pagos sale de la app; las pruebas son las cuentas de prueba de Mercado Pago** (decidida al cerrar la app del tramo G; revisa D-20) | Con «simulador» se quería decir la simulación que ya da Mercado Pago con sus cuentas de prueba (vendedor y comprador de prueba, tarjetas que aprueban o rechazan según el titular), no una segunda pasarela propia. Una opción que el servidor puede tener apagada dejaba en la pantalla de pago una salida sin destino (regla 34) | La app ofrece una sola pasarela: «Pagar con Mercado Pago», sin elección. El rechazo a voluntad se muestra con el titular `OTHE` y la aprobación con `APRO`. El backend todavía tiene `PasarelaSimulador`, `/simulador/…`, `TIENDA_SIMULADOR` y el valor `simulador` de `pagos.metodo`: se quitan en un tramo del backend, y hasta entonces `TIENDA_SIMULADOR=false`. `api.md`, `modelo-datos` y `trazabilidad` describen el backend y se corrigen con él |

**Decididas por omisión, con la recomendación.** No bloqueaban y se pueden revisar antes del
tramo que las usa:

| # | Decisión | Por qué | Tramo |
| --- | --- | --- | --- |
| D-12 | **La descripción breve se deriva de la completa**: las primeras palabras, hasta el largo de `config/tienda.php` | Una columna nueva pediría un campo en el formulario del producto: una tercera ampliación de la web | C |
| D-13 | **`simulador` al final del ENUM de `pagos.metodo`**, con su texto «Simulador — no es cobro real» | Registrar un pago simulado como `mercadopago` afirmaría algo falso. Es lo barato: lo que hizo la migración de `entregada_parcial` | G |
| D-14 | **«Por precio» ordena por `precio_contado`**, desempatando por `id` | Es el primero que se muestra, y `precio_lista` sale de él con un recargo: el orden casi no cambia | D |
| D-15 | **No se generan miniaturas.** La app pide la imagen original y la decodifica al tamaño en que la muestra | Generarlas sin librería pide GD a mano y un tamaño por imagen guardada. El peso queda acotado por el paginado | C |
| D-16 | **La reserva ocurre al entrar al checkout**, no al agregar al carrito | Un carrito puede quedar abierto días: reservar al agregar bloquearía mercadería que nadie va a pagar. Es la decisión 9 de la Fase 7.5 aplicada a la tienda | E |
| D-17 | **La vinculación con un cliente de mostrador va a la Etapa 4** | No se exige, y sin documento en el registro sólo se podría hacer por correo, que no es único | — |

**Dos repositorios (D-4).**
- **El contrato vive en el backend** (`docs/api.md`), que es quien lo cumple. El README de la
  app nombra la revisión del contrato que consume.
- **Los JSON de ejemplo** de los tests de serialización de la app se copian del contrato.
- **La trazabilidad** (§15 de `trazabilidad.md`) y **la matriz de seguimiento** (§13 de este
  plan) viven en el backend.
- **La arquitectura de la app** se documenta en su repositorio (`ARQUITECTURA.md`), y este
  plan la referencia.
- **`pubspec.lock` se versiona** en el repositorio de la app.
- **La entrega son dos repositorios**, y la presentación los nombra.
- **Un tramo con lado de app se cierra con los dos lados**: no alcanza con uno.

---

## 2. Lectura de la consigna

Cada exigencia de la consigna, numerada, con el tramo que la cierra. **Es la tabla con la
que se comprueba que la etapa está completa**: una fila sin cumplir es una etapa sin aprobar.
Las letras son las de la §7: A contrato, B cimientos de la app, C catálogo, D cuenta y
precios, E carrito y reserva, F confirmación, G pagos, H consulta, I pruebas y demostración.

### 2.1 Requisitos de aprobación

**1 — Catálogo de productos**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 1.1 | Explorar el catálogo sin iniciar sesión | `GET /api/v1/productos` y `/categorias`, públicos | C |
| 1.2 | Listado con nombre, imagen y descripción breve | Recurso de listado; URL absoluta de imagen con imagen por omisión; descripción breve derivada (D-12) | A, C |
| 1.3 | Navegación y filtrado por categorías | Árbol de categorías activas; el filtro incluye las subcategorías (`idsDescendientes()`) | C |
| 1.4 | Identificar y consultar las novedades | Marca `es_novedad` y la sección `novedades` | C |
| 1.5 | **Definir y documentar el criterio de novedad** | Fecha de alta dentro de una ventana, con tope, en `config/tienda.php`. Escrito en `docs/api.md` y en `modelo-datos.md` | C |
| 1.6 | Identificar y consultar los destacados | Marca `es_destacado` y la sección `destacados` | C |
| 1.7 | **Destacados administrados en el backend** | La casilla «destacado» en el formulario del producto | C |
| 1.8 | Búsqueda por nombre | `Producto::scopeBuscar()`: nombre **o código**. Más amplio sin contradecir, y documentado | C |
| 1.9 | Orden por nombre y por fecha de incorporación | `orden=nombre` y `orden=recientes`, desempatando por `id` | C |
| 1.10 | Paginado de resultados | La envoltura única del contrato | A, C |
| 1.11 | Orden por precio, ascendente y descendente, **sólo con sesión** | `orden=precio_asc` y `precio_desc`, por `precio_contado` (D-14). Sin sesión, 401; sin permiso, 403: ordenar sin mostrar el precio filtraría cuál es más caro | D |
| 1.12 | **Reiniciar el paginado** al cambiar filtros u orden, con coherencia | El modelo de vista descarta las páginas y cancela el pedido en vuelo; la respuesta trae los `criterios` aplicados y la app descarta la que no coincide | C |
| 1.13 | **Los endpoints públicos no devuelven precios ni importes protegidos** | Sin `tienda.ver_precios`, la clave `precios` **no está**. Costo, ganancia y margen no se publican nunca | C, D |
| 1.14 | Una indicación para iniciar sesión en lugar del precio | En la tarjeta y en la ficha; al volver del login sigue en el mismo producto | C, D |

**2 — Detalle del producto**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 2.1 | Nombre, imagen o galería, descripción completa y categoría | `GET /api/v1/productos/{id}` | C |
| 2.2 | Condición de novedad o destacado | Las mismas marcas que el listado | C |
| 2.3 | Disponibilidad, **sin restricción** | `disponible`, `ultimas_unidades`, `sin_stock`, con umbral en `config/`. Nunca el número. La ve el visitante | C |
| 2.4 | Precio, sólo con sesión | Los dos, con su rótulo | D |
| 2.5 | Selector de cantidad y «agregar», sólo con sesión | En la barra fija de la ficha | E |
| 2.6 | Inexistentes, inactivos o sin disponibilidad, sin acciones imposibles | Inexistente o inactivo: 404 y su pantalla. Sin disponibilidad: se ve, sin «agregar», y **el servidor rechaza agregarlo igual** (409) | C, E |

**3 — Registro y autenticación**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 3.1 | Registrar un cliente con los datos mínimos | `POST /api/v1/registro`, por `UsuarioService::crear()` | D |
| 3.2 | Iniciar sesión | `POST /api/v1/sesion`; rechaza las cuentas de gestión y las inactivas | D |
| 3.3 | Consultar los datos básicos | `GET /api/v1/perfil` | D |
| 3.4 | Cerrar sesión | `DELETE /api/v1/sesion` **revoca** la credencial | D |
| 3.5 | Reutilizar las cuentas y los permisos | Las mismas tablas; permisos de tienda (D-6); sólo cambia cómo se emite la credencial (D-7) | A, D |
| 3.6 | Gestionar sesiones vencidas o inválidas | 401 `sesion_invalida`; la app borra la credencial y manda al login, conservando a dónde iba | A, B, D |
| 3.7 | Credenciales en un mecanismo apropiado | `flutter_secure_storage` (Keystore). La contraseña nunca se guarda | B, D |
| 3.8 | Precios, carrito y compras exigen sesión | Rutas protegidas en el servidor y guardas en `go_router` | D |

**4 — Carrito de compras**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 4.1 | Agregar productos | Agregar dos veces suma cantidad: UNIQUE(carrito, producto) | E |
| 4.2 | Consultar el contenido | Con avisos de disponibilidad y de precio | E |
| 4.3 | Modificar cantidades | — | E |
| 4.4 | Eliminar productos | — | E |
| 4.5 | **Vaciar el carrito** | Acción propia | E |
| 4.6 | Precios unitarios, subtotales y total | Calculados por el servidor, a contado y a lista (HL-5) | E |
| 4.7 | Conservarlo al cerrar y abrir | Vive en el servidor, asociado a la cuenta | E |
| 4.8 | **Documentar dónde se guarda y cómo se asocia**, sin acceso de otro usuario del dispositivo | En el teléfono sólo queda la credencial; cerrar sesión la revoca y borra el estado en memoria. En `docs/api.md` y `modelo-datos.md` §4 | E |
| 4.9 | **Revalidar antes de confirmar**: existencia, disponibilidad, cantidades y precios | En el servidor, al iniciar el checkout y al confirmar (D-11) | E, F |
| 4.10 | **El servidor calcula los importes** | Un campo de importe en la solicitud se rechaza (`prohibited`) | E, F |
| 4.11 | Informar los cambios y permitir revisar | 409 `carrito_cambio` con `cambios`; la app vuelve al carrito con los cambios a la vista | E, F |

**5 — Confirmación del pedido**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 5.1 | Revisar productos y cantidades | Primer paso del checkout | F |
| 5.2 | Verificar los datos del cliente | Los del perfil, de sólo lectura | F |
| 5.3 | Indicar entrega o retiro | Retiro, o envío con la dirección **congelada en el pedido** y costo fijo de `config/` | F |
| 5.4 | Consultar el importe definitivo | Con la lista del medio elegido, calculado por el servidor | F |
| 5.5 | Elegir el medio de pago | Contado (efectivo o transferencia) o pasarela | F |
| 5.6 | Confirmar | `POST /api/v1/pedidos` | F |
| 5.7 | Registrar el pedido vinculado al cliente y a ventas y pagos | Una fila de `ventas` (`online`, `cliente_id`, sin vendedor, `pendiente_pago`) con renglones congelados, por `VentaService::crearOnline()` | F |
| 5.8 | Identificador y estado consultable | `V-00042` y el estado en lenguaje del negocio | F, H |
| 5.9 | **Estado del pedido distinto del estado del pago** | Pedido: `ventas.estado`. Pago: derivado de pagos e intentos (§7, tramo G) | F, G, H |
| 5.10 | **Cuándo se reserva y se descuenta, y qué pasa ante cancelación o pago fallido** | Reserva al iniciar el checkout (D-16); descuento al cobrar, consumiendo la reserva; vencimientos de D-1 y D-9 | E, F, G |
| 5.11 | **Sin registros parciales** | Una transacción por confirmación | F |
| 5.12 | **Repetir el toque o reintentar no duplica** | `ventas.carrito_id` UNIQUE (D-8) y el botón bloqueado | B, F |

**6 — Pagos**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 6.1 | Flujo de pago completo asociado al pedido | Contra la interfaz `Pasarela` | G |
| 6.2 | Simulador **identificado claramente** (aprobación) | Las cuentas de prueba de Mercado Pago (D-23): vendedor y comprador de prueba; la tarjeta de prueba aprueba o rechaza según el titular | G |
| 6.3 | Pasarela en entorno de pruebas (**promoción**) | Mercado Pago en modo de prueba, detrás de la misma interfaz. **Obligatorio en este plan** (D-20) | G |
| 6.4 | Pendientes, aprobados y rechazados | Estados del intento (más `vencido`) | G |
| 6.5 | Consultar el resultado | `GET /api/v1/pedidos/{id}/pagos/{intento}` | G |
| 6.6 | Reintentar sin duplicar el pedido | Otro intento sobre el mismo pedido | G |
| 6.7 | **Intentos registrados y asociados** | Tabla `intentos_pago`; `pagos` recibe sólo el aprobado | G |
| 6.8 | **El backend determina y persiste el estado** | Lo escribe el servidor después de verificar | G |
| 6.9 | **Verificar con el mecanismo del proveedor**; el regreso del usuario no confirma | Consulta a Mercado Pago por `external_reference` | G |
| 6.10 | **Ningún dato sensible de tarjetas** | La tarjeta se carga en Mercado Pago | G |
| 6.11 | **Claves privadas en el backend** | `.env`, leídas sólo desde `config/services.php` | G |

**7 — Consulta de compras**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 7.1 | Listado de sus pedidos | Paginado, del más nuevo al más viejo | H |
| 7.2 | Fecha, identificador, importe y estado | — | H |
| 7.3 | Detalle de los productos | Renglones congelados | H |
| 7.4 | Estado del pago | Aparte del estado del pedido | H |
| 7.5 | **Sólo lo propio**, verificado en Laravel aunque se pida un identificador ajeno | 404 que no revela si existe; vale para pedidos, pagos e intentos | G, H |

**8 — Integración asincrónica con Laravel**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 8.1 | Operar sobre datos del backend; los locales no reemplazan la integración | Los falsos existen sólo en los tests de la app | B a H |
| 8.2 | HTTP asincrónico | `dio` | B |
| 8.3 | JSON | Toda respuesta de `api/*`, también los errores | A |
| 8.4 | **Públicos y protegidos claramente diferenciados** | Grupos de rutas y la columna «Autenticación» del contrato | A |
| 8.5 | Validación y autorización en el servidor | Form Requests, permisos y servicios | A a H |
| 8.6 | Errores consistentes | Forma única y su renderer | A |
| 8.7 | Paginado suficiente | Envoltura única | A |
| 8.8 | **Dirección configurable** | `--dart-define` y la pantalla de ajustes; URL de imágenes desde el host del pedido | A, B |
| 8.9 | Sin base directa ni HTML o Blade | Sólo `api/v1` | B |
| 8.10 | **Contrato documentado** | `docs/api.md`, endpoint por endpoint | A a H, cerrado en I |

**9 — Interfaz y organización**

| # | La consigna pide | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| 9.1 | Navegación coherente | Barra inferior de cuatro destinos y `go_router` | B |
| 9.2 | Carga en curso | Esqueleto sobre el layout real | B, cada pantalla |
| 9.3 | Consulta sin resultados | Distinto por filtro y por falta de datos | B, cada pantalla |
| 9.4 | Operación completada | Confirmación con movimiento | B, cada pantalla |
| 9.5 | Error de validación | Errores del 422 junto a su campo | B, cada pantalla |
| 9.6 | Sin conexión o servidor caído | Dos mensajes y dos acciones | B, cada pantalla |
| 9.7 | **Sesión vencida** | El 401 lleva al login conservando a dónde iba | B, D |
| 9.8 | Evitar envíos repetidos | El botón propio se bloquea solo | B |
| 9.9 | Recuperación cuando se pueda | Reintentar, revisar, volver a iniciar sesión | B a H |
| 9.10 | Separar pantallas, modelos, servicios y estado | MVVM con repositorios | B |
| 9.11 | **Justificar la solución** | `ARQUITECTURA.md` de la app | B, I |

### 2.2 Objetivos con exigencia propia

| # | El objetivo dice | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| O-1 | **Distintos tamaños de pantalla** | La grilla cambia de columnas por ancho; la ficha se acomoda; se prueba en teléfono y tableta | B, C |
| O-2 | Seguridad **en Flutter y en Laravel** | Laravel: precio omitido, propiedad, idempotencia, cuenta activa. Flutter: almacenamiento seguro, registros enmascarados, tráfico en claro sólo en depuración | A a H |
| O-3 | Pruebas y documentación | Pest, tests de la app y el informe | Todos, I |

### 2.3 Presentación y justificación

| # | Hay que mostrar | De dónde sale | Tramo |
| --- | --- | --- | --- |
| P-1 | Evolución respecto de la Etapa 1 | `trazabilidad.md` §15 | I |
| P-2 | Arquitectura y separación Flutter/Laravel | `ARQUITECTURA.md` y este plan | I |
| P-3 | Recorrido de visitante y de cliente | — | C, D |
| P-4 | Una compra del catálogo a la consulta del pedido y su pago | — | H |
| P-5 | El intercambio asincrónico | El registro del interceptor, con la credencial enmascarada | B, I |
| P-6 | Cómo se protegen precios y operaciones | Los tests de precio omitido y de pedido ajeno | D, H |
| P-7 | **Un error de conexión y un pago rechazado** | El servidor apagado; Mercado Pago de prueba rechazando (titular `OTHE`, D-23) | B, G |
| P-8 | Decisiones sobre estado, carrito y stock | D-1, D-5, D-8, D-9, D-11, D-16 | I |
| P-9 | **Un riesgo de seguridad detectado y su solución** | Se elige durante el desarrollo. Candidatos: la cuenta desactivada que seguía operando por la API (§1.3 A) y el orden por precio que filtraba el precio (1.11) | I |
| P-10 | Pruebas, dificultades y pendiente | Informe de pruebas y `pendientes-usabilidad.md` | I |

### 2.4 Seguimiento e hitos

| # | La consigna dice | Cómo se cumple | Tramo |
| --- | --- | --- | --- |
| S-1 | **Recomendación de seguimiento**: una tabla que relacione los componentes originales con los nuevos componentes Laravel | **Es `trazabilidad.md` §15**, tramo por tramo. La matriz de la §13 es una herramienta propia, **no** la tabla de la consigna | A a I |
| Hito 1 | Revisar el backend; alcance y contrato | §1 a §6 y el tramo A | A |
| Hito 2 | Estructura Flutter, catálogo y detalle | — | B, C |
| Hito 3 | Autenticación y precios | — | D |
| Hito 4 | Carrito y confirmación | — | E, F |
| Hito 5 | Pagos y consulta | — | G, H |
| Hito 6 | Pruebas, documentación y demostración | — | I |

### 2.5 La promoción

«Promoción» es una condición de la cátedra: lo marcado así suma a la calificación final. El
«(promoción)» del requisito 6 aparece delante de la pasarela y delante de «en ambos casos se
deberán contemplar, como mínimo, pagos pendientes, aprobados y rechazados». **La ambigüedad ya
no cambia nada**: el plan hace las dos modalidades (D-20), y las dos con los tres estados.

---

## 3. Hallazgos

Dónde los requerimientos o el prompt de la etapa suponen algo que el código no cumple. Los de
la §1.3 se nombran por su letra.

### 3.1 Contra los requerimientos

| # | Suponen | El código dice | Qué cambia | Tramo |
| --- | --- | --- | --- | --- |
| HL-1 | «Cobro y entrega en la web sirven igual para un pedido de la app» | **La mitad.** La entrega sirve: parte de `pagada`. El comprobante también: la ficha y el PDF omiten «Atendió» sin vendedor, y el listado muestra «—». **El cobro y la cancelación no**: aviso de faltantes falso (§1.3 C), «Cancelar el presupuesto» sin liberar y con el nombre equivocado (§1.3 D), el filtro de estado sin «Pendiente de pago» y el listado sin canal | Se corrige lo existente: el aviso descuenta la reserva propia, `cancelar()` la libera, el texto depende del canal | F, G |
| HL-2 | La idempotencia por `mp_payment_id` alcanza | **Sólo para Mercado Pago**. Un identificador inventado del simulador en una columna `mp_*` afirmaría algo falso | `pagos.intento_pago_id` nullable, FK y **UNIQUE**: un intento aprobado, como mucho un pago. `mp_payment_id` sigue con el identificador real | G |
| HL-3 | «Admitir el cobro sin usuario» | Volver nullable el `User` de `cobrar()` dejaría al mostrador cobrar con cajero nulo | **`PagoService::acreditarIntento()`**, aparte, compartiendo los pasos privados. `cobrar()` sigue exigiendo cajero; `marcarPagada()` acepta `?User` | G |
| HL-4 | La reserva vive en el carrito | Con D-1 y D-9 la reserva **sobrevive a la confirmación**, y ni `carritos` apunta a la venta ni `ventas` tiene columnas de reserva | **Dos dueños sucesivos**: el carrito en checkout y, después, el pedido. Pasa en la transacción de la confirmación; `ventas.reserva_vence`. Invariante con test: **`stock_reservado` = carritos en checkout vigentes + pedidos pendientes con reserva**. `liberar()` nunca baja de cero ni falla | E, F |
| HL-5 | Un total calculado por el servidor | Antes de elegir el medio hay **dos**: contado y lista | El carrito devuelve los dos por renglón y en total; la confirmación, uno | E |
| HL-6 | El contrato nombra 422, 401, 403, 404 y 409 | Laravel devuelve sus errores con claves en inglés, y también 429, 405 y 500 | El renderer traduce **todos**; el 500 sin traza; límite de intentos en sesión y registro; el 429 en el contrato | A |
| HL-7 | «Sesión vencida» es un estado | Los tokens de Sanctum **no vencen** por omisión | `sanctum.expiration` en configuración (D-7) | A, D |
| HL-8 | «Reutilizar los permisos» | El rol Cliente tiene cero permisos | Resuelto en D-6 | A, D |
| HL-9 | El panel cuenta las ventas online sin cambios | `cobradoPorMetodo()` agrupa por `metodo`: un valor nuevo aparece, **con su nombre crudo** si falta en `Pago::METODOS`, y la plata del simulador no es real | `Pago::METODOS` con «Simulador — no es cobro real» | G |

### 3.2 Contra el prompt de la etapa

| # | Supone | El código o el entorno dice | Qué cambia | Tramo |
| --- | --- | --- | --- | --- |
| HL-10 | La tarea de reservas «corre cada minuto» | El programador **no corre solo**: hace falta `schedule:run` en cron o `php artisan schedule:work`. En XAMPP para Windows no hay cron, así que la reposición de las 07:00 tampoco corrió nunca en desarrollo | La demostración levanta `schedule:work` (README). Además, la confirmación y el cobro **miran el vencimiento de su propia reserva**: el resultado no depende de que la tarea haya pasado | E, I |
| HL-11 | Tráfico en claro «acotado a la IP» y «quitado para la entrega», sin IP en el plan | Con la política en `src/main`, la IP queda en el repositorio y quitarla depende de acordarse | La política va **sólo en `src/debug`**: el release no admite tráfico en claro **por construcción**. `10.0.2.2` se escribe; la IP de la red sale de `local.properties`, que no se versiona. La demostración usa la compilación de depuración | B |
| HL-12 | Emulador por `10.0.2.2`, teléfono por la IP | `php artisan serve` (README) **escucha sólo en `localhost`**: alcanza al emulador, no a un teléfono | Para el teléfono: `--host=0.0.0.0` y el puerto abierto en el firewall. Al README | B |
| HL-13 | URL de imagen «del host del pedido o por entorno» | `Storage::url()` usa `APP_URL`; **`url()` usa el host del pedido**, y nada fuerza la raíz | Los recursos arman `url('storage/'.$ruta)`: sirve a `10.0.2.2` y a la IP sin configuración. La web sigue con `Storage::url()` | A |
| HL-14 | La venta online entra por un alta nueva, con la lista del medio | `escribirLineas()` es privado y cotiza a contado | Recibe la lista (`contado` por omisión). El mostrador y sus tests no cambian | F |
| HL-15 | El test de la frontera da 403 | Redirige al login | «Nunca 200, siempre al login» | D |
| HL-16 | Las fechas son de Buenos Aires | `config/app.php` fija `'timezone' => 'UTC'` a mano; el `APP_TIMEZONE` del `.env.example` no se lee. La web muestra UTC: una venta de las 22:00 figura al día siguiente, también en `facturadoPorDia()` | La API manda ISO 8601 con `Z`, y la app convierte. La web no se toca: pendiente #82 | A |

### 3.3 Detectados al construir

| # | Se suponía | El código dice | Qué cambia | Tramo |
| --- | --- | --- | --- | --- |
| HL-17 | El renderer de `api/*` es un `render()` más | `StockInsuficienteException` y `TransicionInvalidaException` **extienden** `ReglaDeNegocioException` | El renderer de `api/*` va antes del web y mira primero las hijas | A |
| HL-18 | `expectsJson()` decide si responder JSON | Una ruta inexistente de `api/*` **no corre middlewares de grupo** | Decide `$request->is('api/*')` | A |
| HL-19 | `sin_sesion` y `sesion_invalida` salen de excepciones distintas | `auth:sanctum` lanza la **misma** `AuthenticationException` sin credencial y con una vencida o revocada | Se distingue por el encabezado `Authorization` (cualquier valor cuenta como intento, no sólo `Bearer`). `RespuestaDeError::desde()` recibe también el `Request` | A |
| HL-20 | «5 por minuto por correo e IP, como el login web» | El login web limita con `throttle:5,1` **por IP**, no por correo e IP | `tienda-acceso`: 5 por correo e IP y 30 por IP sola. La web no se toca | A, D |
| HL-21 | El login de la API es «como el login web» (§6.4) | El web da mensajes distintos para cuenta deshabilitada y sin acceso | La API da el mismo 422 con el mismo mensaje en los tres casos | D |
| HL-22 | `UsuarioService` sólo desactiva | `eliminar()` **borra la fila** si no hay historial, y la rama que desactiva **no pasa por `actualizar()`**; `personal_access_tokens` no tiene FK | Las dos ramas de `eliminar()` y `actualizar()` revocan, en la misma transacción | A |
| HL-23 | Revocar es una operación más | Borrar la fila de una credencial parece chocar con la regla 1 | No choca: una credencial es un permiso de acceso, no un hecho. El porqué va en `modelo-datos.md` | A |
| HL-24 | Los `render()` ven la excepción tal como se lanzó | Laravel la **prepara antes**: `ModelNotFoundException` llega como `NotFoundHttpException`, `AuthorizationException` como `AccessDeniedHttpException`, y `abort(403)` como una `HttpException` genérica | El renderer acepta las tres formas y mira también el estado HTTP | A |
| HL-25 | `sanctum.expiration` fija la fecha de vencimiento | Sanctum 4 **no la escribe**: `expires_at` queda en null y sólo se consulta al validar | `CredencialService::emitir()` escribe la fecha; de ahí sale el `vence` | A |
| HL-26 | `install:api` deja la API lista | El `guard` de Sanctum viene en `['web']`: una sesión abierta en el panel **autenticaría** contra `api/*` | `'guard' => []`: sólo la credencial `Bearer` | A |
| HL-27 | `SANCTUM_EXPIRATION=` vacío en `.env.example` da el valor por omisión | `env()` devuelve `''`, y Sanctum lo lee como «no vence nunca» | `(int) env('SANCTUM_EXPIRATION') ?: 10080` | A |
| HL-28 | `FThemes.zinc.light` y `.dark` existen en `forui` | La 0.27.7 **quitó `FThemes` y todos los esquemas menos Neutral**; además su `ThemeData` es el de `material_ui`, no el de Flutter | Zinc se genera con `dart run forui theme create -p cabbbc` en `lib/config/tema/` (`forui_cli` fijado, sólo desarrollo); `MaterialApp` recibe un `ThemeData` mínimo (brillo y fondo) | B |
| HL-29 | El release sólo necesita que `src/debug` no tenga política de red | `flutter create` pone `INTERNET` **sólo en `debug` y `profile`**: el release no habría llegado a la red | `INTERNET` en `src/main`. Además AGP 9 rechaza directorios generados en `sourceSets`: el XML de red de `debug` lo genera Gradle con la API de variantes (`10.0.2.2` más `compustack.ipLan` de `local.properties`) | B |
| HL-30 | La compilación en Windows anda tal cual | Con la caché de pub en `C:` y el proyecto en `D:`, la compilación incremental de Kotlin falla («Could not close incremental caches») | `kotlin.incremental=false` en `android/gradle.properties` | B |
| HL-31 | Un 401 siempre borra la credencial | Un 401 **tardío** de un pedido viejo borraría la de una sesión recién abierta | `AuthInterceptor` sólo la borra si sigue siendo la que viajó en ese pedido | B |
| HL-32 | La prueba de conexión de Ajustes es un pedido más | Iría con la credencial a una dirección que todavía no es de confianza | Los pedidos marcados `sinCredencial` no la llevan, y un 401 de la prueba no cierra la sesión | B |
| HL-33 | `ref.read(proveedor.future)` alcanza para esperar un `StreamProvider` | **Riverpod 3 pausa un provider sin oyentes**: no completa nunca | `iniciarContenedor` deja un oyente permanente de la dirección | B |
| HL-34 | Leer el `Dio` perezosamente evita el círculo `Dio → dirección → ajustes → prueba → Dio` | Riverpod lo detecta **en debug** aunque la lectura sea perezosa: `CircularDependencyError` al tocar «Probar conexión» | La prueba va en `ConexionRepository`, aparte de `AjustesRepository`; `proveedores_test.dart` prueba el cableado real | B |
| HL-35 | El ícono se genera con *Image Asset* de Android Studio | No hay forma de abrirlo desde la sesión | `tool/generar_icono.py` (Pillow) hace lo mismo: adaptativo, redondo, heredado y monocromático | B |
| HL-36 | La casilla «destacado» es una ampliación de la web que sólo agrega un control | `destacado` está en la tabla y en `$fillable`, pero **`ProductoService::atributos()` y `ProductoRequest` no lo conocen**: la web nunca pudo escribirlo | Se agrega a las dos, enumerado (regla 19). El servicio lo escribe sólo si la clave viene, para que un llamador que no lo conoce no apague un destacado | C |
| HL-37 | La casilla va bajo `@can('producto.editar')` y con eso alcanza | El formulario es **uno solo** para el alta (`producto.crear`) y la edición (`producto.editar`): quien sólo crea no ve la casilla, pero podría mandar `destacado=1` armando el pedido | `ProductoRequest` descarta `destacado` si el usuario no tiene `producto.editar`: la vista oculta y el servidor hace cumplir | C |
| HL-38 | El seeder de demostración ya da un catálogo con qué probar novedades | `CatalogoDemoSeeder` deja el texto de relleno «Producto de demostración: …» y **todo `created_at` en el mismo instante**; `CatalogoVolumenSeeder` hace lo mismo con 20.000 filas, sin fotos | `TiendaDemoSeeder` repara los 11 de la demo. Novedades = ventana **y** tope, porque la ventana sola da «todos» con el seeder de volumen | C |
| HL-39 | «Árbol de categorías» se devuelve entero | La regla 15 y `api.md` §2 dicen que **todo listado pagina, sin excepción** | Se pagina por raíz y cada raíz trae su rama completa; `total` cuenta raíces. Con 5 raíces todo llega en la primera página | C |
| HL-40 | Un `JsonResource` devuelve `{ "datos": … }` | Laravel lo envuelve en **`data`** por omisión | `Recurso::respuesta()` arma la envoltura del contrato, gemela de `Paginado`, sin tocar la configuración global | C |
| HL-41 | `whereNumber('producto')` alcanza para tipar el id de la ruta con `int` | Un número más largo que el mayor entero de PHP **pasa la ruta pero no cabe en un `int`**: `TypeError`, 500. Lo encontró un test | El controlador y el modelo reciben el id como texto; la base responde que no hay fila y es 404 | C |
| HL-42 | Un provider con `AsyncValue` que falla muestra su error | **Riverpod 3 reintenta solo, con espera creciente**: la pantalla de error tarda en aparecer y un test con una falla se cuelga | `retry: sinReintento` en el contenedor y en cada provider; la acción «Reintentar» es del usuario | C |
| HL-43 | Si una acción asigna `state` a mano, el `build` pendiente ya no importa | **Riverpod aplica igual el resultado del `build`** y pisaba la lista nueva con un marcador vacío | `CatalogoViewModel._resultadoDelSucesor`: el `build` espera al sucesor y devuelve su resultado | C |
| HL-44 | Poner `AsyncLoading` en `state` borra la lista mientras llega la nueva | **Conserva el valor anterior** (`copyWithPrevious` es interno): los filtros siguen a la vista | Se aprovecha; se prueba | C |
| HL-45 | Una ruta apilada con `push` cambia `currentConfiguration.uri` | No lo cambia: en el test se miraba la ubicación equivocada | Los tests miran la pantalla o `currentConfiguration.last.matchedLocation` | C |
| HL-46 | Un teléfono real alcanza al servidor con `10.0.2.2` o `127.0.0.1` | `10.0.2.2` sólo existe en el emulador (en un teléfono: `connectionTimeout`) y `127.0.0.1` es el propio teléfono | `adb reverse tcp:8000 tcp:8000` por USB, o la IP de la PC por Wi-Fi; documentado en el `README` de la app | C |
| HL-47 | La barra inferior de `forui` respeta los botones del sistema | Con Android 17 (borde a borde) **trae `safeAreaBottom: false`** y reserva sólo 2/3: los botones la tapaban | `safeAreaBottom: true`; `Pantalla` y `PantallaProvisoria` reservan el borde fuera de la cáscara | C |
| HL-48 | El `FScaffold` de cada pantalla maneja bien el teclado | **El teclado se descuenta dos veces** (el de la cáscara y el de la pantalla de adentro): quedaba un hueco que tapaba la Búsqueda | La cáscara entrega a su cuerpo un `MediaQuery` con el borde de abajo en cero (`_SinBordeInferior`) | C |
| HL-49 | `FSheet` pinta el contenido de la hoja | **Sólo tiñe lo de atrás**: el menú y los filtros se veían transparentes. Con `useSafeArea` el fondo además quedaba con franjas | `FondoHoja` pone fondo, borde y esquinas, y resuelve el área segura por dentro | C |
| HL-50 | «Atrás» del dispositivo siempre vuelve | **En Carrito y Perfil cerraba la app**, y el login al que manda la guarda (que *reemplaza* la ruta) quedaba solo en la pila | `PopScope` en la cáscara (vuelve a Inicio) y en el login (a Inicio si no hay nada atrás); 8 tests con el «atrás» real | C |
| HL-51 | Una grilla de tarjetas se ve pareja sola, y un `Wrap` de insignias sobre la foto alcanza | `SliverMasonryGrid` respeta la altura de cada una (el nombre ocupa 1 o 2 renglones), y el `Wrap` sin ancho **se salía de la tarjeta** (a 276 px en una de 130) | El nombre reserva dos renglones con altura fija; la disponibilidad va en uno; las marcas se apilan acotadas y con color propio | C |
| HL-52 | Dar `tienda.*` al Cliente es sumar permisos | `RolPermisoSeederTest` (Etapa 1) fijaba que el Cliente tiene **cero** permisos | Se afina con permiso: sigue fijando «ningún permiso de gestión» y ahora además las tres claves exactas de tienda | D |
| HL-53 | Nombre y apellido, hasta 100 cada uno, forman la razón social | `clientes.razon_social` mide **150**: «nombre apellido» puede llegar a 201 | `RegistroRequest` lo **rechaza** en `apellido` (M-31), no lo recorta | D |
| HL-54 | Una cuenta de la tienda con sesión web termina en el login en cualquier ruta de gestión (HL-15) | `SubstituteBindings` corría **antes** que `gestion`: las 52 rutas con parámetro daban 404 con un id inexistente, y el 404 contra el login dejaba saber qué ids existen | `VerificarAmbitoGestion` entra en la prioridad antes de `SubstituteBindings` (`bootstrap/app.php`); `FronteraGestionTest` recorre todas las rutas de gestión | D |
| HL-55 | La app habla en español porque sus textos lo están | Sin idioma fijo, forui sigue al teléfono: en uno en inglés anuncia «Page 5». Fijarlo rompió el arranque: **forui 0.27 trae los textos de Material de `material_ui`**, que no son los que pide el `MaterialApp` de `flutter/material` | `idiomaApp` (`es_AR`) y los delegados de `flutter_localizations` (del SDK) además de los de forui | D |
| HL-56 | Al abrir sesión, la guarda lleva a `desde` | Sólo si la guarda puso el login. **Un login apilado con `push`** (desde Perfil, una ficha o «Tu sesión venció») quedaba abierto: go_router evalúa la redirección sobre la ruta de abajo | El formulario avisa `completado` y la vista se va sola: a lo de abajo si hay algo, si no a `desde` | D |
| HL-57 | `Carrito` tiene la relación `venta` desde el E | La columna que la sostiene, `ventas.carrito_id` (D-8), nace en el F; hoy nadie apunta a nadie (HL-4) | La relación entra en el F, con su columna | E, F |
| HL-58 | `tienda.envio.habilitado` existe (lo nombran el F y el recorte 2) | `config/tienda.php` sólo tenía `envio.costo` | Se agrega en el E, en `true`: el resumen del checkout ya ofrece los modos de entrega. Con `false`, sólo retiro | E |
| HL-59 | «+» se puede frenar en el stock antes de pedir (Nielsen: prevenir el error) | El número no viaja (2.3) y ninguna ruta valida sin agregar | En el carrito, el 409 de «+» fija el tope exacto («Es todo el stock disponible.»); en la ficha, el 409 de «Agregar» deja probar una unidad menos. Ninguno es un error grande. Frenarlo antes pide un `maximo` en el contrato (pregunta abierta) | E |
| HL-60 | Carrito se abre con `push` desde la barra inferior | Fuera de la cáscara, **sin sesión** el login quedaba apilado y al entrar volvía a lo de abajo, no al carrito (HL-56) | Con sesión, `push`; sin sesión, `go`: la guarda pone el login solo y al entrar lleva a `desde` | E |
| HL-61 | `Venta::scopeDelCliente(Cliente)` entra en el F | Ya existe `scopeDeCliente(int\|string\|null)`, el del filtro del listado | Se reusa con `$cliente->id`: un scope casi homónimo serían dos maneras de decir lo mismo | F |
| HL-62 | `marcarPagada(Venta, ?User)` | Exigía `User`: en la Etapa 1 siempre cobra una persona | Se ensancha en el F; lo aprovecha la acreditación de la pasarela del G, que no tiene cajero | F |
| HL-63 | `cancelarVencidos()` excluye los pedidos con un intento de pago pendiente | Los intentos de pago nacen en el G: en el F no hay qué mirar | El F cancela todo pedido vencido; el G agrega la exclusión, con su test | F, G |
| HL-64 | La confirmación nombra `carrito_id` (D-8) | Ni `GET /carrito` ni el checkout exponían el `id`: la app no tenía de dónde sacarlo | `CarritoResource` suma `id` (null si el carrito no se guardó); `api.md` revisión 5 | F |
| HL-65 | «Juntos o ninguno»: sin documento, `tipo_doc` y `nro_doc` vacíos | `clientes.tipo_doc` es enum **no nulo** y el registro guarda `dni` sin número | Sin documento = `nro_doc` nulo, `tipo_doc` queda como estaba; el perfil manda los tres en `null` si no hay número | K |
| HL-66 | DNI de 7 u 8 dígitos; CUIT y CUIL con dígito verificador | `ReglasFiscales` (la ficha del cajero) acepta DNI de 6 a 9 y no revisa el verificador, y los tests de la Etapa 1 usan CUIT inventados | La regla estricta vive aparte (`DocumentoTienda`), sólo en la API; la web no cambia. Por ser el `PATCH` parcial, un dato que cargó el cajero no traba al cliente que no lo toca | K |
| HL-67 | El documento es opcional | `UNIQUE(tipo_doc, nro_doc)`, y Responsable inscripto y Monotributo exigen documento (`ReglasFiscales::EXIGEN_DOCUMENTO`) | 422 en `nro_doc` si es de otro cliente (también si choca al guardar) o si esa condición lo quiere quitar | K |
| HL-68 | «La dirección predeterminada (la crea, la cambia o la quita)» | El cliente puede tener varias, cargadas en la web, y `DireccionService` no deja ninguna sin predeterminada | `direccion: null` borra la predeterminada con `DireccionService::eliminar` y asciende la más antigua; el perfil propone esa. Ningún pedido apunta a `direcciones` (copia `entrega_*`) | K |
| HL-69 | La dirección de la API se valida como la de la web | La web valida `provincia` con `Rule::in(Direccion::PROVINCIAS)` y arma el selector con esa lista; `PATCH /perfil` y `POST /pedidos` sólo pedían `string\|max:100`: «Chubutt» quedaba en el pedido y en la predeterminada, y el selector del cajero la mostraba vacía | Los dos requests suman `Rule::in(Direccion::PROVINCIAS)`, 422 en `direccion.provincia` con el texto de la web. Las 24 van escritas en el contrato (`api.md` revisión 7, §5), sin endpoint: son fijas. Lo ya guardado no se corrige (M-16): la app hace elegir de nuevo una provincia fuera de la lista | K |
| HL-70 | «Los pedidos se buscan con `delCliente()`» | No existía: está `Venta::deCliente($id)`, un **filtro del listado** del cajero que con `null` no filtra. Usarlo para la pertenencia dejaría ver todo | `Venta::delCliente(Cliente)`: siempre filtra, por cliente y canal online. `IntentoPagoService::pedidoDe()` e `intentoDe()` lo usan; ajeno, 404 | G |
| HL-71 | La preferencia vence con `expiration_date_to` | La documentación de Mercado Pago (verificada en el tramo, §14.3) pide además `expires: true`, y la fecha en ISO 8601 con zona | La preferencia lleva `expires`, `expiration_date_from` y `expiration_date_to`; probado contra el sandbox con la credencial de prueba | G |
| HL-72 | `consultar()` traduce **el** estado del pago | Una `external_reference` puede tener **varios** pagos: el comprador reintenta dentro de Checkout Pro tras un rechazo. Y un `approved` no dice por cuánto | Manda el `approved`; si no, uno en curso (`pending`, `in_process`, `authorized`) es pendiente; rechazado sólo si todos lo fueron; `refunded` o un estado desconocido, pendiente; otra moneda no cuenta. Un aprobado por otro importe queda `aprobado` con `importe_distinto`, no se acredita solo y el pedido dice «en revisión» | G |
| HL-73 | `vence_at` es un `TIMESTAMP NOT NULL` | En MariaDB con `explicit_defaults_for_timestamp` apagado, el primer `TIMESTAMP NOT NULL` sin `DEFAULT` recibe `ON UPDATE CURRENT_TIMESTAMP`: guardar la `url_pago` le pisaba el vencimiento con la hora de la sesión, y el intento de Mercado Pago nacía vencido. Los tests del simulador no lo veían (no hay `UPDATE`) | Una migración (`fix_timestamps_sin_on_update`) les da `DEFAULT` explícito (`useCurrent()->change()`) a `vence_at` y a **`pagos.fecha`** (Etapa 1), que tenía la misma trampa: hoy ningún camino actualiza un pago, pero el primero le cambiaría la fecha del cobro. Los 10.146 pagos de la base conservaron su fecha. Un test de comportamiento por columna | G |
| HL-74 | «La app abre `url_pago` y el usuario paga con la cuenta compradora de prueba» | La pestaña personalizada usa las cookies del Chrome del teléfono: si ahí está abierta una cuenta **real** de Mercado Pago, paga con ella, y Mercado Pago rechaza mezclar una parte de prueba con una real («Una de las partes con las que intentás hacer el pago es de prueba»). La app de Mercado Pago, si está instalada, también puede quedarse con el enlace | Nada en el código: antes de probar (y en la defensa) se cierra la cuenta real en Chrome, o se usa el emulador, y se entra con el comprador de prueba. Anotado en el `README` de la app | G |
| HL-75 | «El detalle trae los intentos; el listado, `fecha` y `total`» | Los intentos completos en el detalle serían un listado sin paginar (regla 15), y `total` a la vista rompe la regla 21 | El detalle lleva sólo `ultimo_pago` (el que decide `estado_pago`); los intentos, por `GET /pedidos/{id}/pagos`, que pagina. El total va en `importes`, que sin `tienda.ver_precios` no viaja. La fecha se llama `creado`, como en `POST /pedidos`, y la cantidad de productos es `unidades` (suma de cantidades) | H |
| HL-76 | «Mis pedidos: lista paginada» y el detalle con «renglones, los dos estados como insignias» | Probada en el teléfono, la primera versión (renglones separados por una línea, todo en `FilaDato`) se leía como un bloque de texto: había que leer todo para saber qué se miraba. Y un pedido pagado mostraba dos insignias «Pagado» (`estado_texto` y `estado_pago_texto` dicen lo mismo) | Cada pedido en su tarjeta (número y total arriba, fecha y unidades atenuadas, estados al pie); el detalle con una cabecera (total grande, estados, una sola indicación) y tarjetas por sección con «etiqueta … valor»; dos insignias con el mismo texto se muestran como una. El listado usa el `Paginador` del catálogo, no un scroll que suma páginas: un cliente puede tener muchos pedidos | H |
| HL-77 | «`reserva_vence` es el “Pagalo antes del…” mientras está pendiente» | Sin la tarea programada corriendo (`tienda:vencer-reservas`), un pedido vencido sigue «Esperando el pago» y la app ofrecía «Pagar» | No cambia el contrato: la tarea es la que cancela. La app, si el plazo pasó y el pago sigue `pendiente` o `rechazado`, dice «Venció el plazo para pagarlo.» y no ofrece pagar (`Pedido.vencido`) | H |
| HL-78 | §13: «Sesión vencida … D pendiente» | El D la probó de punta a punta (`sesion_vencida_test.dart`; `trazabilidad.md` §15, tramo D): la fila no se actualizó al cerrar el D | La fila pasa a «Hecho» | I |
| HL-79 | «`README.md` del backend: `schedule:work`, `serve --host=0.0.0.0`, `storage:link`, las variables nuevas» | El README seguía en la Etapa 1: decía que el login rechazaba la cuenta de cliente «porque no hay tienda», ponía la API en una «Etapa 3», y no nombraba `tienda:vencer-reservas` ni las claves de la tienda | README al día (Estado, Alcance, Configuración, Ejecución, Tarea programada, Estructura, Documentación) | I |
| HL-80 | «Hasta entonces `TIENDA_SIMULADOR=false`» (D-23) | `config/tienda.php` toma `env('TIENDA_SIMULADOR', true)`: sin la clave en `.env`, el simulador se enciende. El `.env` de desarrollo lo tenía encendido (la verificación de `api.md` encontró la ruta `/simulador/…` publicada), y `.env.example` decía «true en la demostración» | El valor por omisión pasa a `false`: sin la clave, apagado. `phpunit.xml` fija `TIENDA_SIMULADOR=true`, porque las rutas del simulador se registran al arrancar y los tests del G las usan (sin eso, 6 de `Api/PagoTest` daban 404). `.env.example` corregido | I |

---

## 4. Alcance

Sale de la consigna (§2) y de las decisiones (§1.5). Lo que no está acá no se construye.

### 4.1 Backend (este repositorio)

| Área | Qué entra |
| --- | --- |
| **Capa de API** | `routes/api.php` con `/api/v1`; Sanctum (D-7); el renderer de errores de `api/*`; la forma única de respuesta, error y paginado; el control de cuenta activa y de ámbito; el límite de intentos de sesión y registro |
| **Catálogo** | Listado y ficha con sesión opcional; árbol de categorías; búsqueda; orden; novedades y destacados; disponibilidad por niveles; URL absolutas con imagen por omisión |
| **Cuenta** | Registro por `UsuarioService`, sesión con revocación, perfil, rechazo de gestión, revocación al desactivar |
| **Permisos** | `tienda.ver_precios`, `tienda.comprar`, `tienda.ver_pedidos` al rol Cliente |
| **Carrito** | `carritos`, `carrito_lineas` (sin `session_token`, con precio de referencia), `CarritoService`, los dos importes, la revalidación |
| **Stock** | `reservar()`, `liberar()` y `descontar()` que consume la reserva; los dos dueños de la reserva; la tarea de cada minuto |
| **Pedido** | `VentaService::crearOnline()`, idempotente por carrito, con la lista del medio y la entrega mínima congelada |
| **Pagos** | Interfaz `Pasarela` con simulador y Mercado Pago; `intentos_pago`; `pagos.intento_pago_id`; `acreditarIntento()`; verificación por consulta |
| **Consulta** | Pedidos propios con estado del pedido y del pago |
| **Configuración** | `config/tienda.php` (novedad, disponibilidad, plazos, envío, paginado, simulador); Mercado Pago en `config/services.php` |
| **Web: ampliaciones declaradas** | Casilla «destacado» en el producto; canal visible y filtrable en ventas |
| **Web: correcciones de lo que existe** | Aviso de faltantes del cobro, cancelación que libera y nombra según el canal, «Pendiente de pago» en el filtro (D-2). No amplían: hacen funcionar lo existente con el pedido online |
| **Datos** | `TiendaDemoSeeder`: imágenes, descripciones reales, fechas repartidas, destacados |

### 4.2 App (repositorio propio)

| Área | Qué entra |
| --- | --- |
| **Cimientos** | Proyecto Flutter para Android, **CompuStack** (`ar.compustack.tienda`), con su ícono (D-18); MVVM con repositorios; `forui` Zinc claro y oscuro; `dio` con interceptores; dirección del servidor configurable; `go_router` con guardas; tráfico en claro sólo en depuración; los seis estados y el botón que se bloquea |
| **Pantallas** | Inicio, catálogo, búsqueda, ficha, inicio de sesión, registro, perfil, carrito, confirmación en pasos, pago, resultado, mis pedidos, detalle, ajustes |
| **Pruebas** | Serialización contra el contrato, repositorios y modelos de vista con falsos, widgets en los seis estados, guardas |
| **Documentos** | `README.md` y `ARQUITECTURA.md` |

### 4.3 Documentos

| Documento | Repositorio | Qué recibe |
| --- | --- | --- |
| `docs/plan-etapa-2.md` | Backend | Este plan y su cierre |
| `docs/api.md` | Backend | **El contrato**, endpoint por endpoint. Entregable |
| `docs/trazabilidad.md` §15 | Backend | La tabla de la recomendación de seguimiento |
| §13 de este plan | Backend | La matriz de seguimiento |
| `docs/modelo-datos.md` | Backend | Cada tabla y columna nueva con su porqué; §4, §5 y §10 |
| `docs/pendientes-usabilidad.md` | Backend | Desde el #81 |
| `docs/pruebas-etapa-2.md` | Backend | El informe de pruebas, de los dos repositorios |
| `docs/seguridad-etapa-2.md` | Backend | El riesgo detectado y su solución (P-9) |
| `docs/demostracion-etapa-2.md` | Backend | El guion de la defensa |
| `README.md`, `ARQUITECTURA.md` | App | Compilación, servidor, revisión del contrato; capas y reglas 22 a 28 |

---

## 5. Fuera de alcance — Etapa 4

La consigna no lo pide. No es recorte por tiempo: es alcance.

| Qué | Por qué sale |
| --- | --- |
| **Facturación con ARCA**: CAE, `comprobantes`, `comprobante_lineas`, `docs/arca.md` | Certificados y homologación, y la consigna no la pide. **Las tablas no se crean, ni vacías** |
| **Ingreso con Google** | La consigna pide reutilizar las cuentas del backend |
| **Módulo de envíos**: `envios`, costo calculado, seguimiento | Entra el mínimo de entrega |
| **`en_preparacion`, `despachada`, `lista_retiro`** | Son del módulo de envíos. El pedido online se entrega con la entrega del tramo H de la Fase 7.5 |
| **Banners** y **vales** | No figuran |
| **Carrito anónimo** y su fusión | La consigna exige sesión para el carrito |
| **Varias direcciones** en la app | Se propone la predeterminada y se copia al pedido |
| **Editar el perfil** y los datos fiscales | La consigna pide consultar |
| **Recuperar o cambiar la contraseña** desde la app, **verificar el correo** | La consigna pide registro, inicio, consulta y cierre |
| **Filtro por marca** y **rango de precio** | La consigna pide categoría, búsqueda y orden |
| **«Más vendidos»** | No es información administrada en el backend |
| **Cancelar un pedido desde la app** | La consigna pide definir la cancelación, no que el cliente la dispare: la producen el vencimiento y el mostrador |
| **Cambiar de medio de pago** con el pedido confirmado | D-10 |
| **Devoluciones** desde la app | Siguen en la web |
| **Descargar el comprobante** | No figura |
| **Repetir una compra**, **push**, **caché sin red** | No figuran; sí se avisa la falta de conexión |
| **Pantalla de gestión de pedidos online** | El canal en el listado alcanza |
| **Vincular cuentas con clientes de mostrador** | D-17 |
| **Pagos parciales** | Fuera desde la Etapa 1; las cuotas las maneja Mercado Pago |
| **Webhook de Mercado Pago** y **túnel** | Sin dirección pública no llega; el túnel es frágil en la defensa |
| **Miniaturas generadas** | D-15 |

### Lo que va a la Etapa 3 si no se fusiona

| Qué | Por qué |
| --- | --- |
| Que la web consuma la misma API | Es el trabajo grande de esa etapa |
| JWT, si hiciera falta | Toca `CredencialService` en Laravel y `AuthService` en la app. Nada más |
| La documentación formal (OpenAPI) | Se escribe sobre `docs/api.md` |

### Fuera de cualquier etapa, y se declara

| Qué | Por qué |
| --- | --- |
| **iOS** | No hay equipo para compilarlo |
| **Publicar en las tiendas** | La app se demuestra en emulador o en un teléfono de la misma red |

---

## 6. El contrato de comunicación

Es el **primer hito** de la consigna y, con dos repositorios, lo único que los une. Se fija
antes del primer endpoint, y `docs/api.md` crece endpoint por endpoint desde el tramo A.

### 6.1 Reglas generales

| Regla | Qué fija | Por qué |
| --- | --- | --- |
| **Versión en la ruta** | `/api/v1/...` | Cuesta nada hoy y es irreversible con una app instalada |
| **Siempre JSON** | El middleware `ForzarJson` del grupo `api` fija `Accept: application/json`. Ninguna respuesta de `api/*` es HTML ni redirección | Sin eso, un 401 de Laravel es una redirección al login web |
| **Claves en español y `snake_case`** | `precio_contado`, `ultima_pagina` | El vocabulario del sistema |
| **Dinero como cadena** | `"60500.00"`: dos decimales, punto, sin miles | `decimal(12,2)` (A-17). Un número JSON es `double` en Dart. La app sólo formatea: `$ 60.500,00` |
| **Cantidades enteras** | `"cantidad": 2` | — |
| **Fechas ISO 8601 UTC** | `"2026-11-08T07:00:00Z"` | La base guarda UTC (HL-16); la app convierte |
| **Identificadores enteros**, con su forma de pantalla | `"id": 42, "numero": "V-00042"` | La app no arma números |
| **Estados con su texto** | `"estado": "pendiente_pago", "estado_texto": "Esperando el pago"` | El texto para el cliente sale de un solo diccionario del backend (`App\Support\Api\TextosTienda`). La app no traduce ENUM |
| **Un campo protegido no viaja** | Sin `tienda.ver_precios`, la clave `precios` **no está** | Regla 21: `null` dice «no tiene valor»; ausente, «no es para vos» |
| **Lo que no se publica nunca** | `costo_promedio`, `ganancia`, `precio_manual`, `stock`, `stock_reservado`, `stock_minimo`, proveedores | Son de gestión. La disponibilidad viaja por niveles |

### 6.2 Respuesta correcta

**Un recurso:**

```json
{ "datos": { "id": 42, "numero": "V-00042", "estado": "pendiente_pago", "estado_texto": "Esperando el pago" } }
```

**Una operación** puede sumar `mensaje`, en lenguaje del negocio:

```json
{ "datos": { "...": "..." }, "mensaje": "Pedido V-00042 registrado. Lo pagás en el local." }
```

**Un listado** pagina siempre:

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

- `criterios` devuelve lo que el servidor aplicó, normalizado. La app descarta la respuesta
  que no coincide con los criterios de la pantalla (1.12).
- Una página después de la última devuelve `datos` vacío con los metadatos, no 404.
- El orden siempre desempata por `id`.
- `por_pagina` va de 1 al tope de `config/tienda.php`; más que el tope es 422 (regla 20).

| Código | Cuándo |
| --- | --- |
| 200 | Lectura, o una operación que no creó nada (incluido el reintento idempotente) |
| 201 | Registro, pedido o intento de pago creados |
| 204 | Cerrar sesión |

### 6.3 Respuesta de error

```json
{ "mensaje": "No hay stock disponible de «Disco SSD WD Blue SN580 1TB»: hay 1 y se piden 2.", "codigo": "stock_insuficiente", "errores": {} }
```

- `mensaje` se muestra tal cual.
- `codigo` le dice a la app qué hacer **sin leer el texto**. Es la única ampliación respecto
  de los requerimientos: varios errores comparten el 409 y piden reacciones distintas.
- `errores` trae los campos del 422; en los demás, objeto vacío.

| HTTP | `codigo` | De dónde sale | Qué hace la app |
| --- | --- | --- | --- |
| 401 | `sin_sesion` | Falta la credencial en una ruta protegida | Al login, conservando a dónde iba |
| 401 | `sesion_invalida` | Credencial vencida, revocada, o cuenta desactivada | Borra la credencial, «Tu sesión venció», al login |
| 403 | `sin_permiso` | Falta un permiso de tienda | Explica qué no se puede |
| 404 | `no_encontrado` | Inexistente, inactivo **o ajeno** | Pantalla de «no existe» |
| 405 | `metodo_no_permitido` | — | Error de programación |
| 409 | `stock_insuficiente` | `StockInsuficienteException` | Vuelve al carrito |
| 409 | `carrito_cambio` | La revalidación encontró cambios; trae `cambios` | Muestra los cambios y deja revisar |
| 409 | `checkout_vencido` | La reserva del checkout venció antes de confirmar | Vuelve al carrito para iniciar el checkout de nuevo |
| 409 | `transicion_invalida` | `TransicionInvalidaException` | Recarga el pedido |
| 409 | `regla_de_negocio` | `ReglaDeNegocioException` | Muestra el mensaje |
| 422 | `validacion` | `ValidationException` | Cada error junto a su campo |
| 429 | `demasiados_intentos` | Límite de sesión o registro; trae `reintentar_en` en segundos | Bloquea el botón con la cuenta regresiva |
| 500 | `error_interno` | Cualquier otra | «Algo falló del lado del servidor», con reintento |

**El 500 nunca muestra la traza**, aunque `APP_DEBUG=true`: el renderer de `api/*` escribe su
respuesta y la excepción queda en el log. **Sin conexión no hay respuesta**: ese estado lo
arma la app cuando `dio` no llega.

### 6.4 Autenticación

| Tipo de ruta | Qué exige | Credencial inválida |
| --- | --- | --- |
| **Pública** | Nada | No se lee |
| **Sesión opcional** (catálogo) | Nada; con credencial válida y `tienda.ver_precios`, agrega los precios | **401 `sesion_invalida`**: si no, los precios desaparecerían sin explicación |
| **Protegida** | Credencial válida, cuenta activa y de tienda, y el permiso de la ruta | 401 |

- Viaja en `Authorization: Bearer <credencial>`.
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

- Credenciales incorrectas, cuenta de gestión e inactiva dan **el mismo 422 con el mismo
  mensaje**, como el login web: decir cuál falló permite enumerar cuentas.

### 6.5 Las formas que se repiten

**Disponibilidad** (también para el visitante):

```json
"disponibilidad": "ultimas_unidades", "disponibilidad_texto": "Últimas unidades"
```

`disponible`, `ultimas_unidades`, `sin_stock`; umbral en `config/tienda.php`; calculada con
`Producto::stock_disponible` (regla 6).

**Precios** (sólo con `tienda.ver_precios`):

```json
"precios": {
  "contado": { "importe": "54450.00", "texto": "Efectivo o transferencia" },
  "lista":   { "importe": "60500.00", "texto": "Mercado Pago" }
}
```

Un objeto, para que omitirlo sea quitar **una** clave y el test revise una.

**Imagen**: URL absoluta con el host del pedido. Sin foto, la URL de la imagen por omisión,
nunca `null`.

**Cambios de la revalidación** (409 `carrito_cambio`):

```json
"cambios": [
  { "producto_id": 7, "descripcion": "Mouse Logitech G505", "tipo": "precio", "lista": "contado", "anterior": "40000.00", "actual": "42000.00" },
  { "producto_id": 9, "descripcion": "Fuente EVGA 600W", "tipo": "disponibilidad", "pedida": 3, "disponibilidad": "ultimas_unidades" },
  { "producto_id": 12, "descripcion": "Gabinete Corsair 4000D", "tipo": "inactivo" }
]
```

### 6.6 Idempotencia

| Operación | Mecanismo | Qué ve el reintento |
| --- | --- | --- |
| Confirmar el pedido | `carrito_id` en la solicitud; `ventas.carrito_id` UNIQUE (D-8), leído con el carrito bloqueado | 200 con el mismo pedido |
| Iniciar un intento | Como mucho un intento **pendiente** por pedido, verificado con la venta bloqueada | 200 con el intento existente |
| Acreditar un intento | `pagos.intento_pago_id` UNIQUE, dentro del bloqueo de la venta | Nada nuevo |
| Cerrar sesión | Revocar una revocada no falla | 204 |

La app además bloquea el botón (9.8). El servidor no confía en eso.

### 6.7 Cómo se documenta cada endpoint en `docs/api.md`

| Parte | Qué lleva |
| --- | --- |
| **Método y ruta** | `POST /api/v1/pedidos` |
| **Autenticación** | Pública, sesión opcional o protegida, con el permiso |
| **Parámetros** | Tabla: nombre, dónde va (ruta, consulta, cuerpo), tipo, obligatorio, regla |
| **Respuesta** | Código y un **JSON de ejemplo completo**, el que copian los tests de la app |
| **Errores** | Tabla: HTTP, `codigo`, cuándo |
| **Notas** | Idempotencia, qué se omite sin sesión, por qué |

El documento lleva **revisión numerada** y registro de cambios. Un cambio de forma sube la
revisión; uno incompatible pediría `/api/v2`, y no está previsto.

### 6.8 Inventario de endpoints

| Método y ruta | Qué hace | Acceso | Tramo |
| --- | --- | --- | --- |
| `GET /api/v1/categorias` | Árbol de categorías activas | Pública | C |
| `GET /api/v1/productos` | Catálogo: `categoria_id`, `q`, `orden`, `seccion`, `pagina`, `por_pagina`. `orden=precio_*` exige `tienda.ver_precios` | Sesión opcional | C, D |
| `GET /api/v1/productos/{id}` | Ficha; inexistente o inactivo, 404 | Sesión opcional | C, D |
| `POST /api/v1/registro` | Crea `user` y `cliente`; devuelve la credencial | Pública, con límite | D |
| `POST /api/v1/sesion` | Inicia sesión | Pública, con límite | D |
| `DELETE /api/v1/sesion` | Cierra y **revoca** | Protegida | D |
| `GET /api/v1/perfil` | Datos básicos (desde K, con el documento y la condición frente al IVA) | Protegida | D, K |
| `PATCH /api/v1/perfil` | Edita la cuenta propia: nombre, apellido, teléfono, documento y dirección predeterminada | Protegida | K |
| `GET /api/v1/carrito` | Renglones con los dos importes, totales y avisos | `tienda.comprar` | E |
| `POST /api/v1/carrito/lineas` | Agrega (`producto_id`, `cantidad`); si ya está, suma | `tienda.comprar` | E |
| `PATCH /api/v1/carrito/lineas/{linea}` | Cambia la cantidad | `tienda.comprar` | E |
| `DELETE /api/v1/carrito/lineas/{linea}` | Quita | `tienda.comprar` | E |
| `DELETE /api/v1/carrito` | **Vacía** | `tienda.comprar` | E |
| `POST /api/v1/carrito/checkout` | Revalida y **reserva**; devuelve el resumen por medio, la entrega, los medios y el vencimiento. Cualquier escritura posterior al carrito suelta el checkout | `tienda.comprar` | E |
| `POST /api/v1/pedidos` | **Confirma**; idempotente por `carrito_id` | `tienda.comprar` | F |
| `GET /api/v1/pedidos` | Mis pedidos | `tienda.ver_pedidos` | H |
| `GET /api/v1/pedidos/{id}` | Detalle con estado del pedido y **del pago**; ajeno, 404 | `tienda.ver_pedidos` | H |
| `GET /api/v1/pedidos/{id}/comprobante` | Una URL firmada y de vida corta para bajar el comprobante (no fiscal) de un pedido pagado; ajeno, 404 | `tienda.ver_pedidos` | H |
| `POST /api/v1/pedidos/{id}/pagos` | Inicia un intento por la pasarela del pedido (`pasarela`: `simulador` o `mercadopago`) | `tienda.comprar` | G |
| `GET /api/v1/pedidos/{id}/pagos` | Intentos y estado del pago | `tienda.ver_pedidos` | G |
| `GET /api/v1/pedidos/{id}/pagos/{intento}` | Resultado; si está pendiente con Mercado Pago, **consulta al proveedor** antes de responder | `tienda.ver_pedidos` | G |
| `POST /api/v1/simulador/intentos/{intento}/aprobar` | **Simulador.** Aprueba | `tienda.comprar`, si `tienda.pagos.simulador` está activo | G |
| `POST /api/v1/simulador/intentos/{intento}/rechazar` | **Simulador.** Rechaza | Ídem | G |

El simulador se identifica **en la ruta, en la respuesta** (`"pasarela": "simulador"`,
`"pasarela_texto": "Simulador de pagos — no es un cobro real"`) **y en la pantalla**. Un intento
ajeno es 404.

---

## 7. Los tramos

Cada tramo entrega su código, **sus tests** y los documentos que toca. Un tramo sin tests no
está terminado, y ninguno se cierra sin `php artisan test` completo en verde (y, si tiene lado
de app, `flutter analyze` sin advertencias y `flutter test` en verde). **De cada
funcionalidad se hace primero el backend**, con sus tests en Pest; después la app.

Convenciones que valen para todos los tramos, y no se repiten:
- Los controladores de API viven en `app/Http/Controllers/Api/V1/`, **sólo traducen**:
  validan con su Form Request, llaman al servicio, devuelven un Resource.
- Los Form Requests de API viven en `app/Http/Requests/Api/`; los Resources, en
  `app/Http/Resources/Api/`.
- Los campos se enumeran uno por uno al escribir (regla 19). La validación rechaza, no
  corrige (regla 20).
- Los helpers globales nuevos de los tests llevan el prefijo `tienda` (`tiendaCliente()`,
  `tiendaProducto()`), para no chocar con los 28 existentes (§1.2).
- Cada endpoint nuevo entra en `docs/api.md` **en el mismo tramo**, con su JSON de ejemplo.
- Cada componente nuevo entra en `trazabilidad.md` §15, en la tabla del tramo.
- La app sigue `ARQUITECTURA.md`: una vista, un modelo de vista; ningún widget importa
  `data/services/`; los repositorios no se conocen; los modelos son `freezed`.

### Tramo A — Contrato y capa de API

**Objetivo.** Que exista `api/v1` con su contrato, sus errores y su credencial antes del
primer endpoint de negocio.

**Por qué va primero.** Lo pide la consigna como primer hito, y con dos repositorios el
contrato es lo único compartido: fijarlo después es reescribir los dos lados.

**Tareas.**
- `php artisan install:api`: instala Sanctum, crea `routes/api.php`, la migración de
  `personal_access_tokens` y agrega `api:` a `withRouting()`. `User` usa `HasApiTokens`.
- `config/sanctum.php`: `expiration` desde `env('SANCTUM_EXPIRATION', 10080)` (7 días), y
  `SANCTUM_EXPIRATION=` en `.env.example`, sin valor secreto.
- `App\Services\CredencialService`: `emitir(User): array` (la forma de §6.4) y
  `revocarActual(User)`, `revocarTodas(User)`. **Es la única clase que sabe que es Sanctum**
  (decisión 2 del prompt).
- Middlewares en `app/Http/Middleware/Api/`:
  - `ForzarJson`: fija `Accept: application/json` en todo `api/*`.
  - `SesionOpcional`: si viene `Authorization`, autentica con el guard `sanctum`; si la
    credencial no sirve, 401 `sesion_invalida`. Si no viene, sigue como visitante.
  - `VerificarCuentaTienda`: con usuario autenticado, exige `activo` y ámbito tienda; si no,
    401 `sesion_invalida`. Es la mitad API de `VerificarUsuarioActivo` (§1.3 A).
- `UsuarioService::actualizar()` y `eliminar()`: cuando la cuenta queda inactiva,
  `CredencialService::revocarTodas()`. Un cambio de comportamiento en un servicio de la
  Etapa 1: sus tests siguen igual, y se agrega uno.
- Renderer de errores: `App\Http\Api\RespuestaDeError::desde(Throwable): JsonResponse`, con la
  tabla de §6.3. En `bootstrap/app.php`, un `render()` que **sólo actúa si
  `$request->is('api/*')`**, registrado antes del de `ReglaDeNegocioException`, que pasa a
  devolver `null` para `api/*` y deja seguir. La web no cambia.
- Límite de intentos: `RateLimiter::for('tienda-acceso', …)` en `AppServiceProvider`, 5 por
  minuto por correo e IP, el mismo criterio que el login web.
- Respuesta y paginado: `App\Http\Api\Paginado::respuesta(LengthAwarePaginator, string
  $resource, array $criterios)` arma la envoltura de §6.2.
- Dinero: `Importe::json(float|string): string` en `App\Support\Importe` (ya existe la clase,
  con `pesos()`): `number_format($v, 2, '.', '')`.
- Imágenes: `App\Support\Api\Imagenes::url(?string $ruta): string`, con `url('storage/'.$ruta)`
  (HL-13), y la imagen por omisión en `public/img/producto-sin-imagen.svg`.
- Textos para el cliente: `App\Support\Api\TextosTienda` (estados del pedido, del pago, de la
  disponibilidad, pasarelas). Un solo diccionario.
- `config/tienda.php` con las claves de todos los tramos, aunque se llenen después:
  `novedades.dias`, `novedades.tope`, `disponibilidad.ultimas_unidades`, `paginado.por_pagina`,
  `paginado.tope`, `reservas.checkout_minutos` (20), `reservas.contado_horas` (48),
  `reservas.pasarela_minutos` (20), `pagos.simulador` (`env('TIENDA_SIMULADOR', true)`),
  `pagos.intento_minutos`, `envio.costo`, `carrito.cantidad_maxima`,
  `descripcion_breve.palabras`.
- `routes/api.php`: el prefijo `v1`, los tres grupos (pública, sesión opcional, protegida)
  vacíos, y una ruta de prueba sólo en los tests.
- `docs/api.md`: revisión 1, con las convenciones de §6 y el registro de cambios.
- `docs/modelo-datos.md`: `personal_access_tokens` con su porqué (una fila por credencial es
  lo que hace posible revocar), y §10 actualizada.
- `trazabilidad.md` §15: la tabla del tramo A. La matriz de §13 queda con todas las filas en
  «pendiente».

**Tests** (`tests/Feature/Api/ContratoTest.php`, `CredencialTest.php`):
- una ruta inexistente de `api/*` devuelve 404 con la forma de error, en JSON, sin enviar
  `Accept`;
- un 422 trae `mensaje`, `codigo: validacion` y `errores` en español;
- `ReglaDeNegocioException`, `StockInsuficienteException` y `TransicionInvalidaException`
  dan 409 con su `codigo`;
- una excepción cualquiera da 500 **sin traza**, con `APP_DEBUG=true`;
- el límite da 429 con `reintentar_en`;
- `SesionOpcional`: sin credencial sigue; con credencial inválida, 401 `sesion_invalida`;
- `VerificarCuentaTienda`: cuenta desactivada, 401; desactivar una cuenta revoca sus
  credenciales;
- la web no cambió: `ReglaDeNegocioException` en una ruta web sigue redirigiendo con
  `error` (ya lo cubren los tests de módulo; se corre la suite completa);
- `Importe::json()` con enteros, decimales y redondeo.

**Entregable.** `api/v1` vacía pero completa en su forma; `docs/api.md` revisión 1.

**Criterio de terminado.** Cualquier error de `api/*` sale con la forma de §6.3, y la suite de
la Etapa 1 sigue en verde sin cambios.

**Cierra.** 3.5 (en parte), 3.6 (servidor), 8.3, 8.4, 8.6, 8.7, 8.10 (inicio).

**Documentos.** `docs/api.md`, `modelo-datos.md` (§10 y la tabla de credenciales),
`trazabilidad.md` §15.

### Tramo B — Cimientos de la app

**Objetivo.** El proyecto Flutter con su arquitectura, su tema, su cliente HTTP y los seis
estados, antes de la primera pantalla.

**Por qué antes de C.** Sin cliente HTTP, tema y navegación, cada pantalla los improvisa.

**Tareas** (en el repositorio de la app).
- `flutter create --org ar.compustack --project-name compustack --platforms android`. En
  `android/app/build.gradle.kts`, `applicationId` y `namespace` pasan a `ar.compustack.tienda`;
  en el manifiesto, `android:label="CompuStack"` (D-18).
- Ícono adaptativo con *Image Asset* de Android Studio: el logo de CompuStack como primer
  plano, el blanco crema como fondo en `res/values/ic_launcher_background.xml`. Se generan
  las variantes redonda y heredada. Sin `flutter_launcher_icons`, que sería una librería más.
- `pubspec.yaml` con las librerías de la lista **más `url_launcher`** (D-19), **versiones
  fijas** (`forui` exacta), y `pubspec.lock` versionado. Antes de fijar, se verifica que cada
  paquete tenga publicación reciente y sea compatible con el Flutter estable.
- `data/services/navegador_externo.dart`: `NavegadorExterno.abrir(Uri)`, la única clase que
  importa `url_launcher`.
- Carpetas: `config/`, `core/`, `data/services/`, `data/repositories/`, `data/models/`,
  `ui/<funcionalidad>/`, `ui/comun/`, `routing/`.
- `config/entorno.dart`: `String.fromEnvironment('API_URL')`. La pantalla de ajustes guarda
  otra dirección en `flutter_secure_storage` (sin librería nueva), que prevalece sobre la de
  compilación. **La dirección nunca está escrita en el código.**
- Tema: `FThemes.zinc.light` y `.dark`, elegido con `MediaQuery.platformBrightnessOf`;
  `MaterialApp` con `FTheme` encima. forui se usa sólo dentro de `ui/comun/`.
- `core/http/`: un `Dio` por app, con tres interceptores:
  - `AuthInterceptor`: pega `Authorization` leyendo `TokenStorage`; ante 401, borra la
    credencial y avisa al `SessionRepository`;
  - `RegistroInterceptor`: registra pedido y respuesta, con `Authorization` enmascarado
    (NF-14);
  - `ErrorInterceptor`: traduce todo a `Falla`.
- `core/errores/falla.dart`: una clase sellada con `SinConexion`, `ServidorCaido`,
  `SesionInvalida`, `SinPermiso`, `NoEncontrado`, `Validacion(errores)`,
  `Conflicto(codigo, cambios)`, `DemasiadosIntentos(segundos)`, `ErrorInterno`. Sin conexión es
  `DioExceptionType.connectionError` o un timeout de conexión; servidor caído es 5xx o una
  respuesta que no tiene la forma del contrato.
- `core/formato/pesos.dart`: formatea `"60500.00"` como `$ 60.500,00` **desde la cadena**, sin
  pasar por `double` y sin `intl` (que no está en la lista).
- `routing/`: `go_router` con un `StatefulShellRoute` de cuatro destinos (Inicio, Categorías,
  Carrito, Cuenta) y una guarda que manda al login con `?desde=` cuando la ruta pide sesión.
- Android: `src/debug/res/xml/network_security_config.xml` con `10.0.2.2` y la IP leída de
  `local.properties` por Gradle; `src/main` sin tráfico en claro (HL-11). `local.properties`
  fuera del repositorio.
- `ui/comun/`: `EstadoCargando` (skeletonizer sobre el layout real), `EstadoVacio(motivo:
  filtro | sinDatos)`, `EstadoCompletado`, `ErroresDeCampo`, `EstadoSinConexion` y
  `EstadoServidorCaido` (dos mensajes, dos acciones), `EstadoSesionVencida`, y
  `VistaAsincronica<T>` que elige uno. `BotonAccion`: se bloquea solo mientras su comando
  corre (9.8). `Precio`, `InsigniaDisponibilidad` (texto, no sólo color), `TarjetaProducto`
  (esqueleto en este tramo).
- Pantalla de ajustes: muestra la dirección vigente, deja cambiarla y la prueba contra
  `GET /api/v1/categorias`.
- `README.md` (cómo compilar con `--dart-define=API_URL=…`, cómo usar emulador y teléfono,
  qué revisión del contrato consume) y `ARQUITECTURA.md` (capas, reglas 22 a 28, por qué MVVM
  con repositorios, por qué cada librería).
- `analysis_options.yaml` con `flutter_lints`.

**Tests.**
- `pesos_test.dart`: enteros, decimales, miles, cero, negativos.
- `error_interceptor_test.dart`, con un adaptador falso de `dio`: cada código de §6.3 da su
  `Falla`; sin red da `SinConexion`; HTML da `ServidorCaido`.
- `auth_interceptor_test.dart`: pega la cabecera; ante 401 borra la credencial; el registro
  no muestra la credencial.
- Widget tests de los seis estados y de `BotonAccion` (dos toques, una ejecución).
- Guarda: una ruta con sesión requerida redirige al login con `desde`.
- Ajustes: guardar una dirección la vuelve la vigente.

**Entregable.** CompuStack abre con su ícono, navega entre cuatro destinos vacíos y apunta al
servidor configurado.

**Criterio de terminado.** `flutter analyze` sin advertencias, `flutter test` en verde, y la
compilación de release **no** admite tráfico en claro.

**Cierra.** 3.7 (mecanismo), 8.2, 8.8 (app), 8.9, 9.1 a 9.10 (componentes), O-1 (base).

**Documentos.** `README.md` y `ARQUITECTURA.md` de la app; `trazabilidad.md` §15 (la app
como componente nuevo).

### Tramo C — Catálogo y detalle

**Objetivo.** El catálogo público, sin precios, con novedades, destacados y la ficha.

**Tareas del backend.**
- `Categoria::idsVisiblesEnTienda(): array`: las categorías activas **cuyos ancestros
  también lo están**. `CategoriaService` puede desactivar un padre sin tocar a sus hijas, y
  una hija activa bajo un padre inactivo no se muestra. Se calcula una vez por pedido: la
  tabla es chica.
- Scopes nuevos en `Producto`:
  - `scopeEnTienda()`: `activo` y categoría visible;
  - `scopeNovedades()`: `created_at` dentro de `novedades.dias`, los `novedades.tope` más
    recientes. MariaDB no admite `LIMIT` dentro de un `IN`, así que los ids del tope se leen
    primero (son pocos) y se filtra por ellos;
  - `scopeDestacados()`;
  - `scopeOrdenTienda(string $orden)`: `nombre`, `recientes`, y desde el tramo D
    `precio_asc` y `precio_desc`; siempre desempata por `id`.
- `Producto::esNovedad(): bool` con el mismo criterio, para la marca del recurso.
- `Producto::descripcionBreve(): string`: las primeras `descripcion_breve.palabras` palabras
  de `descripcion` (D-12).
- `Producto::disponibilidad(): string` con `stock_disponible` y el umbral de `config/`.
- Endpoints: `Api\V1\CategoriaController@index`, `Api\V1\ProductoController@index` y `@show`.
  `CatalogoRequest`: `q` (texto, máximo 100), `categoria_id` (entero), `orden` (lista
  cerrada), `seccion` (`novedades`, `destacados`), `pagina`, `por_pagina`.
- Resources: `CategoriaResource` (árbol), `ProductoListadoResource` (id, código, nombre,
  descripción breve, imagen principal, categoría, marcas `es_novedad` y `es_destacado`,
  disponibilidad) y `ProductoFichaResource` (además: descripción completa, galería
  ordenada, marca). **Ninguno tiene precios todavía.**
- **Web, ampliación 1**: la casilla «destacado» en `productos/form.blade.php`, bajo
  `@can('producto.editar')`; `ProductoRequest` la normaliza con `boolean()` como `activo`;
  `ProductoService::crear()` y `actualizar()` la escriben enumerada.
- `TiendaDemoSeeder`, llamado por `DatabaseSeeder` después del catálogo: descripciones
  reales, `created_at` repartidas en los últimos 90 días (unas pocas dentro de la ventana de
  novedad), tres o cuatro destacados, y las imágenes copiadas de
  `database/seeders/imagenes/` al disco `public`. **Las fotos son propias o de uso libre**,
  versionadas en el repositorio: no se descargan de la web.
- `docs/api.md`: los tres endpoints; el criterio de novedad escrito con sus dos números y su
  porqué (la ventana sola da todos o ninguno con el seeder de volumen).
- `modelo-datos.md`: la novedad y la descripción breve derivadas, sin columna.

**Tests del backend** (`Api/CatalogoFiltrosTest`, `Api/CatalogoModuloTest`,
`Api/CatalogoEscalaTest`):
- filtro por categoría con sus hijas; categoría inexistente devuelve vacío; hija bajo padre
  inactivo no aparece;
- búsqueda por nombre y por código;
- orden por nombre y por recientes, con desempate estable entre páginas;
- novedades: ventana y tope, también con todos los productos en la misma fecha;
- destacados;
- envoltura: `pagina`, `ultima_pagina`, `total`, `criterios`; página más allá de la última
  con `datos` vacío; `por_pagina` sobre el tope da 422;
- inexistente e inactivo dan 404;
- **sin credencial, ninguna respuesta del catálogo contiene las claves `precios`, `costo`,
  `costo_promedio`, `ganancia`, `stock` ni `stock_reservado`**, buscadas en todo el árbol
  JSON del listado y de la ficha;
- disponibilidad en sus tres niveles, sin el número;
- la URL de imagen usa el host del pedido; sin foto, la imagen por omisión;
- escala: las consultas de una página no crecen con las filas (técnica de `EscalaTest`), y
  el peso de la respuesta no crece con el catálogo (técnica de `PesoHtmlTest`);
- web: la casilla guarda y quita el destacado, con el dataset de permisos de
  `ProductoModuloTest`.

**Tareas de la app.**
- Modelos `Producto`, `ProductoFicha`, `Categoria`, `Pagina<T>`, `Criterios`, con
  serialización y un test cada uno contra el JSON de `docs/api.md`.
- `CatalogoRepository` (listado, ficha, categorías) sobre `ApiService`.
- `CatalogoViewModel`: criterios como estado inmutable; al cambiar uno, **descarta las
  páginas, cancela el pedido en vuelo** (`CancelToken`) y **descarta la respuesta cuyos
  `criterios` no coinciden**; carga la página siguiente al acercarse al final (reemplazado
  por el paginador numerado, D-21).
- Pantallas: Inicio (destacados y novedades en dos carruseles), Catálogo (grilla de
  `flutter_staggered_grid_view`, dos columnas en teléfono y más en tableta; filtros en hoja
  inferior; los activos como fichas), Búsqueda (300 ms entre teclas), Ficha (galería a
  pantalla completa, `Hero` desde la tarjeta, disponibilidad, «Iniciá sesión para ver el
  precio»), No encontrado.
- `cached_network_image` decodificando al tamaño de la tarjeta (`memCacheWidth`), D-15.

**Tests de la app.** Repositorio con API falsa; `CatalogoViewModel`: cambiar el filtro vuelve
a la página 1, una respuesta vieja se descarta, la página siguiente se pide una vez; widgets
de catálogo y ficha en los seis estados; el vacío por filtro y por falta de datos dicen
cosas distintas.

**Entregable.** El recorrido del visitante, completo y sin precios.

**Criterio de terminado.** Un visitante navega, filtra, busca, ordena y abre fichas contra el
backend real, y ninguna respuesta pública contiene precios.

**Cierra.** 1.1 a 1.10, 1.12, 1.13 (servidor), 1.14, 2.1 a 2.3, 2.6 (lectura), O-1.

**Documentos.** `docs/api.md`, `modelo-datos.md` §2, `trazabilidad.md` §15, §13.

### Tramo D — Cuenta y precios

**Objetivo.** Registro, sesión y perfil; y, con sesión, los dos precios y el orden por
precio.

**Tareas del backend.**
- `RolPermisoSeeder`: módulo `tienda` con `ver_precios`, `comprar` y `ver_pedidos`; el rol
  Cliente recibe `tienda.*`. El seeder ya es idempotente (`updateOrCreate` y `sync`), así que
  en una base existente se corre `php artisan db:seed --class=RolPermisoSeeder`. **Cuidado**:
  `sync` devuelve cada rol a su definición, y pisa lo que se haya cambiado a mano en la
  pantalla de roles.
- `RegistroRequest`: `nombre`, `apellido` (requeridos, máximo 100), `email` (requerido, único
  en `users`), `password` (con `confirmed` y la regla de `CambiarPasswordRequest`:
  `Password::min(8)->letters()->numbers()`), `telefono` opcional.
- `Api\V1\RegistroController@store`: arma los datos para `UsuarioService::crear()` con el rol
  Cliente, `activo = true` y el cliente `razon_social = "nombre apellido"`, `tipo_doc =
  'dni'`, `nro_doc = null`, `condicion_iva = 'consumidor_final'`, `email`, `telefono`. Devuelve
  `CredencialService::emitir()` con 201.
- `Api\V1\SesionController@store` (`SesionRequest`): verifica la contraseña sin abrir sesión
  web; credenciales incorrectas, cuenta inactiva o de gestión dan el **mismo 422**.
  `@destroy` revoca la credencial actual (204).
- `Api\V1\PerfilController@show`: nombre, apellido, correo, teléfono, razón social. Sin
  datos fiscales editables.
- Límite `tienda-acceso` en registro y sesión.
- Precios: `ProductoListadoResource` y `ProductoFichaResource` agregan `precios` con
  `$this->when($request->user()?->can('tienda.ver_precios'), …)`. `when` **omite la clave**.
- Orden por precio: `CatalogoRequest` admite `precio_asc` y `precio_desc`; el controlador
  responde 401 sin sesión y 403 sin permiso **antes** de consultar.

**Tests del backend** (`Api/CuentaTest`, `Api/PreciosTest`, `Api/FronteraGestionTest`):
- registro crea `user` con rol Cliente y su `cliente` con los valores por omisión; correo
  repetido, 422; contraseña débil, 422;
- inicio de sesión correcto; incorrecto, de gestión e inactivo dan el mismo 422; el límite
  da 429;
- cerrar sesión revoca: la misma credencial después da 401;
- credencial vencida (con `travel()`) da 401 `sesion_invalida`;
- perfil;
- precios: presentes con `tienda.ver_precios`; **ausentes sin credencial**; **ausentes con
  credencial de un rol sin el permiso**; en listado y ficha;
- orden por precio: 401 sin sesión, 403 sin permiso, correcto con permiso, por
  `precio_contado`;
- **frontera**: se recorren todas las rutas registradas con el middleware `gestion`; con
  sesión web de un Cliente y con su token Bearer, **ninguna da 200** y todas terminan en el
  login (HL-15);
- dataset de permisos en las dos direcciones para las tres claves de tienda.

**Tareas de la app.**
- `TokenStorage` (`flutter_secure_storage`), `AuthService` (registro, sesión, cierre),
  `SessionRepository` (expone si hay sesión y el usuario, como flujo). **Ningún otro
  componente ve la credencial** (regla 22).
- Pantallas: Inicio de sesión, Registro (errores del 422 junto a su campo; la contraseña
  nunca se guarda), Cuenta (perfil y cerrar sesión).
- La guarda vuelve a `desde` después del login (1.14).
- Precios en `TarjetaProducto` y en la ficha, con su rótulo; el orden por precio aparece
  sólo con sesión.
- Sesión vencida: el `AuthInterceptor` avisa, la pantalla actual muestra
  `EstadoSesionVencida` y lleva al login conservando la ruta.

**Tests de la app.** `SessionRepository` con servicio falso; modelos de vista de login y
registro (el botón no envía dos veces; errores por campo); la guarda; el modelo de credencial
contra el JSON del contrato; widget de la tarjeta con y sin precio.

**Entregable.** El recorrido del cliente hasta ver precios.

**Criterio de terminado.** Con sesión se ven los dos precios; sin sesión no viajan; la sesión
vencida lleva al login y vuelve a donde estaba.

**Cierra.** 1.11, 1.13 (con sesión), 1.14, 2.4, 3.1 a 3.8, 9.7, P-3, P-6 (precios).

**Documentos.** `docs/api.md`, `modelo-datos.md` (permisos de tienda), `trazabilidad.md`
§15, §13.

### Tramo E — Carrito y reserva

**Objetivo.** El carrito en el servidor, y la reserva de stock por la única puerta.

**Por qué es el tramo más delicado.** Es la primera escritura de stock que llega desde fuera
del mostrador, y la reserva baja el disponible que ve el cajero.

**Tareas del backend.**
- Migraciones:
  - `carritos`: `id`, `user_id` (FK), `estado` ENUM(`activo`, `en_checkout`, `convertido`,
    `abandonado`), `reservado_hasta` (timestamp null), timestamps; índices
    (`user_id`, `estado`) y (`estado`, `reservado_hasta`). `abandonado` se declara como en
    el diseño y no se usa en esta etapa: modificar un ENUM después reescribe la tabla.
  - `carrito_lineas`: `id`, `carrito_id` (FK, cascada), `producto_id` (FK), `cantidad`
    (unsigned int), `contado_referencia` y `lista_referencia` (decimal 12,2, null; D-11),
    timestamps; UNIQUE(`carrito_id`, `producto_id`).
- Modelos `Carrito` (relaciones `usuario`, `lineas`, `venta`) y `CarritoLinea`. Un carrito no
  se borra: se convierte.
- `StockService` (la única puerta, regla 4):

```php
// app/Services/StockService.php — firmas nuevas
public function reservar(Producto $producto, int $cantidad): void
public function liberar(Producto $producto, int $cantidad): void
public function descontar(Producto $producto, int $cantidad, Model $origen, ?User $usuario = null, int $deReserva = 0): MovimientoStock
```

  - `reservar()`: bloquea el producto, exige `stock_disponible >= cantidad` (si no,
    `StockInsuficienteException`), suma a `stock_reservado`. Sin asiento (D-5).
  - `liberar()`: bloquea, resta, **nunca baja de cero y nunca falla** (HL-4): un ajuste de
    inventario puede haber dejado el stock por debajo de lo reservado.
  - `descontar()`: con `$deReserva > 0`, primero consume esa parte de `stock_reservado` y
    valida contra `stock_disponible + $deReserva`. Usa `stock_disponible`, no la resta escrita
    (§1.2). Con `$deReserva = 0` se comporta como hoy: el mostrador no cambia.
  - Varios productos se bloquean **ordenados por `id`**, como `marcarPagada()`, para que dos
    checkouts no se bloqueen mutuamente.
- `App\Services\CarritoService`:
  - `delUsuario(User): Carrito`: el `activo` o `en_checkout` del usuario; si no hay, crea
    uno, con la fila del usuario bloqueada para que dos pedidos simultáneos no creen dos.
  - `agregar(User, int $productoId, int $cantidad)`: el producto tiene que estar en la tienda
    (si no, 404); sin disponible suficiente, 409 `stock_insuficiente`; si ya está, suma;
    respeta `carrito.cantidad_maxima`; escribe las referencias con el precio vigente.
  - `cambiarCantidad()`, `quitar()`, `vaciar()`: sobre renglones **del carrito del usuario**;
    un renglón ajeno es 404.
  - Toda escritura sobre un carrito en checkout lo **suelta** primero: libera la reserva y
    vuelve a `activo`.
  - `resumen(Carrito): array`: por renglón, el producto vigente, los dos precios
    unitarios y los dos subtotales; los dos totales; y los avisos (inactivo, sin stock,
    cantidad mayor que el disponible, precio distinto de la referencia). **No escribe nada**:
    un `GET` no cambia estado.
  - `iniciarCheckout(User): array`: con el carrito bloqueado, revalida (inactivo, precio
    contra la referencia). Si hay cambios, **actualiza las referencias, confirma la
    transacción y recién después** lanza `CarritoCambioException`: si la excepción saliera
    adentro, el rollback se llevaría la actualización y el segundo intento volvería a fallar.
    Sin cambios, reserva cada renglón, **todo o nada**, pasa a `en_checkout` y fija
    `reservado_hasta`. Si ya estaba en checkout vigente, devuelve el mismo resumen.
  - `soltarCheckout(Carrito)` y `soltarCheckoutsVencidos(): int`.
- Excepciones nuevas, hijas de `ReglaDeNegocioException`, con su `codigo` para el renderer:
  `CarritoCambioException` (lleva `cambios`) y `CheckoutVencidoException`.
- Endpoints: `Api\V1\CarritoController` (`show`, `vaciar`), `Api\V1\CarritoLineaController`
  (`store`, `update`, `destroy`), `Api\V1\CheckoutController@store`. Requests con
  `producto_id` y `cantidad` enteros, y **cualquier campo de importe `prohibited`**.
  `CarritoResource` y `ResumenCheckoutResource`. Todas con `can:tienda.comprar`.
- Comando `tienda:vencer-reservas` (`app/Console/Commands/VencerReservasTienda.php`), en
  `routes/console.php` con `everyMinute()->withoutOverlapping()`. En este tramo suelta los
  checkouts vencidos; F y G le agregan sus pasos. El porqué del minuto, en el comentario del
  programador.
- `docs/api.md`: los seis endpoints; **dónde se guarda el carrito y cómo se asocia al usuario**
  (4.8), escrito para la defensa.
- `modelo-datos.md` §4: las dos tablas, las referencias (D-11) y los dos dueños de la
  reserva (HL-4).

**Tests del backend** (`StockReservaTest`, `Api/CarritoTest`, `Api/CheckoutTest`,
`CarritoServiceTest`):
- `reservar()` suma y bloquea; sin disponible, lanza y no toca nada; con la escritura
  competidora inyectada por `DB::listen` en el `FOR UPDATE` (§1.3 I), no reserva de más;
- `liberar()` nunca deja `stock_reservado` negativo;
- `descontar()` con reserva: consume lo reservado y **no falla con el stock justo**; sin
  reserva, el comportamiento del mostrador no cambia (los tests de la Etapa 1 siguen igual);
- agregar suma cantidad; producto inactivo, 404; sin stock, 409; renglón ajeno, 404; vaciar;
- el carrito devuelve los dos importes, calculados con los precios vigentes;
- un importe en la solicitud da 422;
- checkout: reserva todo o nada; precio cambiado, 409 con `cambios` y el segundo intento
  pasa; escribir en un carrito en checkout libera la reserva;
- el comando suelta los checkouts vencidos;
- **invariante**: después de una secuencia de agregar, iniciar, soltar y vencer,
  `stock_reservado` de cada producto es igual a la suma de lo reservado por los carritos en
  checkout vigentes;
- dos usuarios no ven ni tocan el carrito del otro.

**Tareas de la app.**
- `CarritoRepository`: el carrito como flujo, para que la insignia de la barra inferior
  reaccione; agregar, cambiar, quitar, vaciar.
- `CarritoViewModel` y la pantalla: renglones con los dos importes; deslizar para quitar,
  con deshacer (vuelve a agregar la misma cantidad); selector de cantidad; **«Vaciar
  carrito» con confirmación**; avisos de cambios; «Continuar» inicia el checkout.
- En la ficha, con sesión: selector de cantidad y «Agregar» en la barra fija (2.5); sin
  disponibilidad, la barra lo dice y no ofrece agregar.
- Al cerrar sesión, el estado en memoria del carrito se descarta.

**Tests de la app.** Repositorio y modelo de vista con API falsa (agregar dos veces no envía
dos veces mientras corre; quitar y deshacer); widget del carrito en los seis estados; el
vacío del carrito; los avisos de cambio.

**Entregable.** Un carrito que se conserva entre aperturas y reserva al continuar.

**Criterio de terminado.** La invariante de la reserva se cumple en los tests, y el mostrador
sigue cobrando igual que antes.

**Cierra.** 2.5, 2.6 (escritura), 4.1 a 4.8, 4.9 y 4.11 (al iniciar el checkout), 4.10, 5.10
(reserva).

**Documentos.** `docs/api.md`, `modelo-datos.md` §4, `trazabilidad.md` §15, §13.

### Tramo F — Confirmación del pedido

**Objetivo.** Que confirmar produzca **la misma fila en `ventas`** que el mostrador, por
`VentaService`, idempotente y sin registros parciales.

**Tareas del backend.**
- Migración `add_tienda_to_ventas_table` (columnas nullable, sin reescribir datos):
  `carrito_id` (FK a `carritos`, **UNIQUE**, D-8), `medio_pago` ENUM(`contado`, `pasarela`)
  null (null en el mostrador), `reserva_vence` (timestamp null), y la dirección congelada:
  `entrega_calle`, `entrega_numero`, `entrega_piso_depto`, `entrega_codigo_postal`,
  `entrega_localidad`, `entrega_provincia`, con los largos de `direcciones`. Índices
  (`estado`, `reserva_vence`) y `canal`.
- `VentaService`:
  - `escribirLineas(Venta, array, string $lista = 'contado')`: cotiza con `precio_contado` o
    `precio_lista` (HL-14).
  - `crearOnline(Cliente $cliente, Carrito $carrito, string $medio, string $modoEntrega, ?array $direccion): Venta`:
    `cliente_id`, `usuario_id` nulo, `canal = 'online'`, **nace en `pendiente_pago`** (no es
    una transición: es el estado de nacimiento, como `presupuesto` en el mostrador),
    `medio_pago`, `modo_entrega`, la dirección, `costo_envio` (de `config` si es envío, cero
    si es retiro), `carrito_id`, `reserva_vence` (contado: `reservas.contado_horas`; pasarela:
    `reservas.pasarela_minutos`), los renglones con la lista del medio (`contado` →
    `precio_contado`, `pasarela` → `precio_lista`) y `recalcularTotales($venta, 0)`.
  - `marcarPagada(Venta, ?User)`: si la venta tiene reserva (`reserva_vence` no nulo),
    descuenta con `$deReserva = cantidad` y pone `reserva_vence` en nulo, en la misma
    transacción. Mientras la reserva no se haya liberado, consumirla es válido aunque su hora
    haya pasado (HL-10).
  - `cancelar()`: si hay reserva, la libera y pone `reserva_vence` en nulo.
  - `cancelarVencidos(): int`: los pedidos online en `pendiente_pago` con `reserva_vence`
    pasada **y sin intento pendiente**, cada uno en su transacción.
- `CarritoService::confirmar(User, array $datos): array{venta: Venta, creada: bool}`:
  bloquea el carrito del usuario por `carrito_id` (ajeno: 404). Si ya está `convertido`,
  devuelve su venta con `creada = false` (D-8). Si no está en checkout o la reserva venció:
  la suelta y 409 `checkout_vencido`. Revalida contra las referencias con el mismo patrón que
  `iniciarCheckout()`. Llama a `crearOnline()` y pasa el carrito a `convertido`, todo en una
  transacción: **la reserva cambia de dueño sin moverse** (HL-4).
- `Venta::faltantesDeStock()`: con reserva propia, compara contra `stock_disponible +
  cantidad` (§1.3 C). Lo usan la ficha y el cobro.
- `Venta::scopeDelCliente(Cliente)` y `scopeDeCanal(?string)`.
- `ConfirmarPedidoRequest`: `carrito_id`, `medio_pago` (`contado`, `pasarela`),
  `modo_entrega` (`retiro`, y `envio` sólo si `tienda.envio.habilitado`), la dirección
  `required_if` envío, y los importes `prohibited`. `Api\V1\PedidoController@store`: 201 si
  `creada`, 200 si no. `PedidoResource`.
- Perfil: agrega la dirección predeterminada, de sólo lectura, para proponerla en el envío.
- El comando suma `cancelarVencidos()` (D-1).
- **Web**:
  - **Ampliación 2**: en `ventas/index.blade.php`, la columna «Canal» y el filtro;
    `VentaFiltroRequest` acepta `canal` (`mostrador`, `online`); `Venta::scopeDeCanal()`.
  - `Venta::ESTADOS_EN_USO` suma `pendiente_pago`; **`VentaFiltrosTest` cambia** (D-2).
  - `ventas/show.blade.php`: el botón de cancelar dice «Cancelar el pedido» si el canal es
    online, y su confirmación avisa que libera la reserva; el mensaje de
    `VentaController::cancelar()` también.
- `Pago` y los comentarios del archivo tocado: «Etapa 2» de facturación o vales pasa a
  «Etapa 4» (§1.2).

**Tests del backend** (`Api/PedidoConfirmacionTest`, `VentaOnlineServiceTest`,
`VentaFiltrosTest`, `VentaModuloTest`):
- confirmar crea una venta `online`, sin vendedor, con `cliente_id`, en `pendiente_pago`, con
  `carrito_id`, renglones congelados **a contado o a lista según el medio**, impuestos
  congelados y `costo_envio` del envío;
- **dos confirmaciones del mismo carrito generan una venta**: la segunda responde 200 con el
  mismo número;
- **sin registros parciales**: con una falla inyectada en el último renglón, no queda venta,
  ni renglones, ni carrito convertido, y la reserva sigue en el carrito;
- precio cambiado: 409 `carrito_cambio`; checkout vencido: 409 `checkout_vencido`;
- un carrito ajeno da 404; un importe en la solicitud, 422;
- **una venta online cobrada en el mostrador** por `PagoService::cobrar()` consume la reserva,
  no falla con el stock justo y deja su asiento de `venta` en el kardex (la frase de la
  etapa);
- la pantalla de cobro no avisa faltantes falsos para un pedido reservado;
- cancelar en la web libera la reserva;
- el comando cancela un contado vencido y libera;
- la invariante de la reserva con dueños mezclados: carritos en checkout más pedidos
  pendientes;
- el ranking de vendedores no cuenta la venta online y el panel general sí (se fija lo que ya
  está documentado);
- filtro por canal en los dos niveles (filtros y módulo); `pendiente_pago` en el selector.

**Tareas de la app.**
- `CheckoutViewModel` y cuatro pasos con indicador: revisión (el resumen del checkout), tus
  datos (el perfil), entrega (retiro, o envío con la dirección predeterminada propuesta y
  editable sólo para este pedido), medio de pago (los dos importes; el elegido es el
  definitivo).
- «Confirmar» con `BotonAccion`; ante un timeout, el reintento manda **el mismo
  `carrito_id`** y recibe el mismo pedido.
- Contado: «Pedido V-00042 registrado. Lo pagás en el local antes del {fecha}». **No dice
  que se pagó** (P-4 de los requerimientos).
- Pasarela: pasa a la pantalla de pago (tramo G).
- 409 `carrito_cambio` o `checkout_vencido`: vuelve al carrito con el motivo a la vista.

**Tests de la app.** Modelo de vista: no confirma dos veces; el reintento usa el mismo
`carrito_id`; cada 409 lleva al carrito. Widgets de los pasos en los seis estados.

**Entregable.** Una compra de contado completa: del carrito a una venta online cobrable en
el mostrador.

**Criterio de terminado.** La venta online pasa por `VentaService` y `StockService`, y deja
el mismo asiento en el kardex que una de mostrador.

**Cierra.** 4.9, 4.11, 5.1 a 5.8, 5.10 (descuento y cancelación), 5.11, 5.12.

**Documentos.** `docs/api.md`, `modelo-datos.md` §5 (columnas nuevas con su porqué, máquina de
estados del canal online), `trazabilidad.md` §15, `pendientes-usabilidad.md` (#81 y #82),
§13.

### Tramo K — Cuenta editable

Se agregó al cerrar el tramo F (D-22, resuelve Q-2). Va **entre F y G**: la letra es la siguiente libre, no
el orden.

**Objetivo.** Que el cliente cargue y corrija sus datos desde la app, y que la compra los use ya cargados.

**Tareas del backend.**
- `GET /perfil` suma `tipo_doc` + `tipo_doc_texto`, `nro_doc` y `condicion_iva` + `condicion_iva_texto`
  (de sólo lectura). Antes no viajaban (`api.md` §7.2).
- `PATCH /api/v1/perfil`, protegida como `GET /perfil` (la cuenta propia, sin permiso aparte).
  `ActualizarPerfilRequest`: `nombre` y `apellido` (100 cada uno, juntos 150: son la razón social,
  HL-53), `telefono` (30, opcional), `tipo_doc` (`dni`, `cuit`, `cuil`) y `nro_doc` **juntos o ninguno**
  (DNI de 7 u 8 dígitos; CUIT o CUIL de 11 con su dígito verificador), y `direccion` con los largos de
  `direcciones` (o `null` para quitarla) y la provincia de la lista cerrada, también en `POST /pedidos`
  (HL-69). `email`, `condicion_iva`, `razon_social` e importes, `prohibited`.
- `PerfilService::actualizar(User, array)`: en una transacción, `users` (nombre y apellido), `clientes`
  (`razon_social` = nombre y apellido, teléfono y documento) y la dirección predeterminada (la crea, la
  cambia o la quita). Devuelve el perfil como `GET`. Un pedido ya hecho no cambia: su dirección está
  copiada (§5 de `modelo-datos.md`).
- El cajero sigue viendo y corrigiendo la ficha en la web: el mismo dato, dos entradas.

**Tests del backend** (`Api/PerfilEdicionTest`):
- edita nombre, apellido, teléfono, documento y dirección, y `GET /perfil` devuelve lo nuevo;
- la razón social sigue al nombre y al apellido;
- documento sin tipo (o tipo sin número), un DNI corto, un CUIT con el dígito verificador mal: 422 en su campo;
- `condicion_iva`, `email` o un importe en la solicitud: 422;
- editar la dirección no cambia la de un pedido ya confirmado;
- sin credencial, 401; una cuenta de gestión no llega (D-3).

**Tareas de la app.**
- `Perfil` lee los campos nuevos; `SessionRepository.actualizarPerfil()`.
- **Mi cuenta editable**: el mismo formulario que el paso 3, con «Guardar», y la dirección predeterminada
  (cargarla, cambiarla o quitarla; se sumó al hacer el lado de app, el contrato ya lo admitía).
- **Paso 3 de la confirmación («Tus datos»)**: los datos propuestos y editables; «Continuar» guarda en la
  cuenta si cambiaron (no toca el carrito: la reserva sigue). La dirección del paso 4 sigue valiendo
  sólo para el pedido, con «Guardarla en mi cuenta» como opción.
- La condición frente al IVA se ve y se explica («La carga CompuStack»); no se edita.
- La provincia, con un selector de la lista de `api.md` §5 (revisión 7, HL-69), en Mi cuenta y en el paso 4.

**Tests de la app.** Modelos contra el JSON nuevo; el modelo de vista del formulario (valida como el
servidor, no envía dos veces, cada 422 junto a su campo); el paso 3 guarda y sigue; widgets en los seis
estados.

**Entregable.** Una compra con los datos ya cargados se confirma sin escribir nada.

**Criterio de terminado.** Lo que se edita en la app es lo que ve el cajero en la ficha del cliente.

**Documentos.** `docs/api.md` (revisión nueva), `modelo-datos.md` (qué edita el cliente y qué no),
`trazabilidad.md` §15, §13, regla 34 de la app (Mi cuenta deja de ser de sólo lectura).

### Tramo G — Pagos

**Objetivo.** El flujo de pago completo contra una interfaz, con el simulador garantizando
la aprobación y Mercado Pago sumando la promoción. **Revisado por D-23:** la app paga sólo
por Mercado Pago, con sus cuentas de prueba; el simulador del backend se quita en un tramo
aparte.

**Tareas del backend.**
- Migraciones:
  - `intentos_pago`: `id`, `venta_id` (FK), `pasarela` ENUM(`simulador`, `mercadopago`),
    `estado` ENUM(`pendiente`, `aprobado`, `rechazado`, `vencido`, `error`), `importe`
    (decimal 12,2), `referencia` (uuid, UNIQUE: es la `external_reference`), `url_pago`
    (null), `payment_id` (null), `detalle` (null: el `status_detail` del proveedor),
    `vence_at`, `resuelto_at` (null), `acreditado_at` (null), timestamps; índice
    (`venta_id`, `estado`). `vencido` dice que el plazo pasó sin respuesta, y `error` que no
    se pudo iniciar: ninguno afirma un rechazo que no ocurrió (regla 16).
  - `pagos`: `intento_pago_id` (FK null, **UNIQUE**; HL-2).
  - `pagos.metodo`: `simulador` al final del ENUM, con `ALTER TABLE … MODIFY`, como la
    migración de `entregada_parcial` (D-13).
- `Pago::METODOS` suma «Simulador — no es cobro real»; `METODOS_EN_USO` no cambia (§1.3 G); el
  docblock de `Pago` actualiza el motivo de por qué `mercadopago` no se ofrece en el mostrador.
- `app/Pagos/`:
  - `Pasarela` (interfaz): `iniciar(IntentoPago): Inicio` y `consultar(IntentoPago): Consulta`.
  - `Inicio` (`url`, `referencia`) y `Consulta` (`estado`, `paymentId`, `detalle`), inmutables.
  - `PasarelaSimulador`: `iniciar()` no tiene URL; `consultar()` devuelve el estado guardado.
  - `PasarelaMercadoPago`: con el cliente `Http` de Laravel, **sin el SDK** (sería una
    librería nueva). `iniciar()` crea una preferencia de Checkout Pro con un ítem («Pedido
    V-00042», importe del pedido), `external_reference = referencia` y `expiration_date_to =
    vence_at`, y guarda `init_point`. `consultar()` busca los pagos por `external_reference` y
    traduce `approved` → aprobado, `rejected` y `cancelled` → rechazado, `pending` e
    `in_process` → pendiente. Un error de red deja el intento pendiente: no inventa un
    resultado.
  - `Pasarelas::para(string): Pasarela`, resuelto por el contenedor desde la configuración.
- `config/services.php`: `mercadopago.access_token` desde `env('MERCADOPAGO_ACCESS_TOKEN')`.
  `.env.example` con la clave vacía. **Ningún valor de prueba en el repositorio.**
- `App\Services\IntentoPagoService`:
  - `iniciar(Venta, string $pasarela): array{intento, creado}`: con la venta bloqueada, exige
    canal online, `pendiente_pago`, medio `pasarela`, pasarela habilitada y reserva vigente;
    si hay un intento pendiente, lo devuelve (§6.6). Si no, crea uno con `importe = saldo`,
    `vence_at` y extiende `reserva_vence` a `vence_at` si quedaba antes (D-9, §1.3 E).
    **Después**, fuera de la transacción, llama a `iniciar()` de la pasarela: una llamada
    HTTP no se hace con la venta bloqueada. Si falla, el intento queda en `error`.
  - `verificar(IntentoPago): IntentoPago`: si está pendiente, consulta afuera; adentro,
    bloquea y relee, y aplica el resultado. Un aprobado se guarda **en su propia transacción
    antes de acreditar**: si la acreditación fallara, la aprobación no se pierde (§1.3 E).
    Pendiente con `vence_at` pasado pasa a `vencido`.
  - `resolverSimulado(IntentoPago, string $resultado)`: sólo con el simulador y pendiente;
    aplica el mismo camino.
  - `verificarVencidos(): int` y `reintentarAcreditaciones(): int`, para el comando.
- `PagoService::acreditarIntento(IntentoPago): Venta` (HL-3): bloquea la venta como primera
  lectura; si ya hay un pago con ese `intento_pago_id`, no escribe nada; pregunta a la
  máquina de estados; exige `saldo === importe` en centavos; escribe el pago (`metodo` =
  pasarela, `monto`, `mp_payment_id` si es Mercado Pago, `intento_pago_id`, `usuario_id`
  nulo, `fecha` = `resuelto_at`); llama a `marcarPagada($venta, null)` y marca
  `acreditado_at`. Los pasos comunes con `cobrar()` pasan a métodos privados compartidos;
  `cobrar()` no cambia de firma ni de comportamiento.
- `Venta::estadoDelPago(): string`, derivado (regla 9):

| Situación | Estado del pago | Texto para el cliente |
| --- | --- | --- |
| La venta está en `pagada` o un estado posterior | `aprobado` | «Pagado» |
| Pendiente, medio contado | `pendiente` | «Se paga en el local» |
| Pendiente, último intento pendiente | `pendiente` | «Esperando la confirmación del pago» |
| Pendiente, último intento aprobado sin acreditar | `en_revision` | «Pago recibido, en revisión» |
| Pendiente, último intento rechazado | `rechazado` | «Pago rechazado: podés reintentar» |
| Pendiente, sin intentos, o el último vencido o con error | `pendiente` | «Falta pagar» |
| Cancelada | `sin_cobrar` | «No se cobró» |

- Endpoints: `Api\V1\PagoPedidoController` (`store`, `index`, `show`) y
  `Api\V1\SimuladorController` (`aprobar`, `rechazar`), estos últimos registrados **sólo si
  `tienda.pagos.simulador` está activo**. Los pedidos se buscan con `delCliente()`; los
  intentos, dentro de su pedido: ajeno, 404. `IntentoPagoResource` con `pasarela_texto`.
- El comando suma `verificarVencidos()` y `reintentarAcreditaciones()`, **antes** de
  `cancelarVencidos()`: primero se resuelve lo que está en el aire.
- `docs/api.md`: los cinco endpoints, la tabla de estados del pago y la política ante pago
  fallido (D-9), escrita para la defensa (5.10).
- `modelo-datos.md` §5: `intentos_pago` y por qué no es `pagos`; `pagos.intento_pago_id`; el
  valor del simulador.

**Tests del backend** (`Api/PagoTest`, `IntentoPagoServiceTest`, `PasarelaMercadoPagoTest`,
`PagoServiceTest` ampliado):
- simulador: iniciar, aprobar → pedido `pagada`, un pago `simulador` con `intento_pago_id`,
  stock descontado desde la reserva, asiento en el kardex sin usuario;
- simulador: rechazar → **ninguna fila en `pagos`**, intento `rechazado`, reserva intacta,
  estado del pago `rechazado`; reintentar crea **otro intento sobre el mismo pedido**;
- dos `POST` de intento seguidos devuelven el mismo intento pendiente;
- acreditar dos veces el mismo intento escribe un pago;
- un intento aprobado cuya acreditación falla queda `aprobado` con `acreditado_at` nulo, y el
  comando lo acredita después;
- un intento pendiente vencido pasa a `vencido`, y el comando después cancela el pedido y
  libera la reserva; **una reserva con intento pendiente no se cancela**;
- Mercado Pago con `Http::fake()`: la preferencia lleva `external_reference` y vencimiento; la
  consulta traduce cada estado; un error de red deja pendiente; **el regreso del usuario no
  cambia nada**: sólo la consulta;
- pedido ajeno, intento ajeno e intento del simulador ajeno: 404;
- un pedido de contado no admite intentos (409);
- `cobrar()` del mostrador sigue igual (los tests de la Etapa 1 sin cambios);
- el panel muestra el texto del simulador.

**Tareas de la app.**
- `PagoRepository` (iniciar, consultar intento, listar intentos) y `PagoViewModel`.
- Pantalla de pago: el número de pedido, el importe y una sola acción, «Pagar con Mercado Pago»
  (D-23: sin simulador ni elección de pasarela).
- **Mercado Pago**: abre `url_pago` con `NavegadorExterno` en una pestaña personalizada
  (D-19). Cuando la app vuelve al frente (`AppLifecycleState.resumed`), **consulta el
  intento**; mientras esté pendiente, lo dice y ofrece «Volver a consultar». El regreso no
  confirma nada (6.9).
- Cobra a precio de lista. Si la app se reabre en la pantalla de pago, retoma el último intento.
- Resultado: aprobado (con movimiento), rechazado (con «Reintentar»), pendiente, en revisión.

**Tests de la app.** Modelo de vista: reintentar crea un intento nuevo; al volver de Mercado
Pago consulta (con `NavegadorExterno` falso); el rechazo ofrece reintento. Widgets de la
pantalla de pago y del resultado en los seis estados.

**Prueba de punta a punta con Mercado Pago**, a mano y anotada en `docs/pruebas-etapa-2.md`:
con las cuentas de prueba, un pago aprobado y uno rechazado (Mercado Pago tiene tarjetas de
prueba para cada resultado), y en los dos el estado del pedido verificado contra lo que dice
el proveedor.

**Entregable.** Pagar por Mercado Pago de prueba, con aprobación y rechazo (D-23).

**Criterio de terminado.** Un pago rechazado deja su intento y ninguna fila en `pagos`; un
reintento aprobado deja un solo pago y un solo descuento de stock; y **un pago real de prueba
con Mercado Pago llega a `pagada` por la consulta al proveedor**.

**Cierra.** 5.9, 5.10 (pago fallido), 6.1 a 6.11, 7.5 (pagos), P-7 (pago rechazado).

**Documentos.** `docs/api.md`, `modelo-datos.md` §5 y §10, `trazabilidad.md` §15, §13.

### Tramo H — Consulta de compras

**Objetivo.** Que el cliente vea sus pedidos, y sólo los suyos.

**Tareas del backend.**
- `Api\V1\PedidoController@index` y `@show`, con `can:tienda.ver_pedidos`, siempre sobre
  `Venta::query()->delCliente($request->user()->cliente)`: **el filtro por dueño es la consulta
  misma**, no una comprobación después de leer. Un pedido ajeno no se encuentra: 404, igual
  que uno inexistente.
- Sólo `canal = 'online'`: las compras del mismo cliente en el mostrador no son pedidos de la
  app. Se dice en el contrato.
- `PedidoListadoResource`: `id`, `numero`, `fecha`, `total`, `estado` y `estado_texto`,
  `estado_pago` y `estado_pago_texto`, cantidad de productos. Paginado, del más nuevo al más
  viejo, desempatando por `id`.
- `PedidoDetalleResource`: los renglones **congelados** (`descripcion`, `cantidad`,
  `precio_unitario`, `total`), subtotal, envío, total, medio, entrega con la dirección
  congelada, los dos estados, los intentos, y el vencimiento si está pendiente («Pagalo
  antes del…»).
- `TextosTienda`: `pendiente_pago` se lee «Esperando el pago».
- **Comprobante** (D-22): `GET /pedidos/{id}/comprobante`, con `can:tienda.ver_pedidos` y la misma
  consulta por dueño, devuelve `url` y `vence`: una ruta firmada con `URL::temporarySignedRoute`
  (unos minutos) que sirve el mismo PDF de la web (`ventas.pdf`, el comprobante de una venta cobrada).
  Un pedido no pagado o cancelado: 409 `regla_de_negocio`. El PDF no muestra costo ni vendedor (HL-1).
  El detalle suma `comprobante_disponible`.

**Tests del backend** (`Api/PedidoConsultaTest`, `Api/PedidoEscalaTest`):
- **un cliente pide el pedido de otro y recibe 404**, igual que un número que no existe;
- el listado trae sólo los propios, ordenados, paginados;
- el detalle muestra el precio congelado aunque el precio del producto haya cambiado;
- los dos estados por separado, en cada situación de la tabla de G;
- las consultas del listado no crecen con los pedidos;
- el comprobante: pagado da una URL firmada que baja el PDF; ajeno, 404; no pagado, 409; la URL vencida
  o tocada, 403.

**Tareas de la app.** `PedidoRepository`; Mis pedidos (lista paginada, «traer para
actualizar»); Detalle del pedido (renglones, los dos estados como insignias con texto, y
«Pagar» o «Reintentar» si corresponde); **«Descargar comprobante»** si el pedido está pagado: pide la
URL y la abre con `NavegadorExterno` (sin librería nueva; el token no viaja en la URL).

**Tests de la app.** Modelos contra el JSON del contrato; modelo de vista del listado
(paginado); widgets en los seis estados.

**Entregable.** La compra se ve de punta a punta, del catálogo a la consulta.

**Criterio de terminado.** El test del pedido ajeno está en verde y nombrado así en el
informe de pruebas.

**Cierra.** 5.8, 5.9, 7.1 a 7.5, P-4, P-6 (operaciones).

**Documentos.** `docs/api.md`, `trazabilidad.md` §15, §13.

### Tramo I — Pruebas, documentación y demostración

**Objetivo.** Que la etapa se pueda presentar, demostrar y justificar.

**Tareas.**
- `docs/api.md`: revisión final, con todos los endpoints y sus ejemplos verificados contra
  las respuestas reales.
- `trazabilidad.md` §15 completa, con el bloque «Cierre de la Etapa 2».
- §13 de este plan: la matriz con cada fila en «hecho».
- `docs/pruebas-etapa-2.md`: qué se probó en cada repositorio, cuántos tests, los que importan
  más (precio omitido, frontera de gestión, pedido ajeno, idempotencia, venta online por los
  servicios, intento rechazado fuera de `pagos`, invariante de la reserva), dificultades y
  pendientes.
- `docs/seguridad-etapa-2.md`: **el riesgo detectado y su solución** (P-9). El candidato
  natural es §1.3 A: la cuenta desactivada que seguía operando por la API. Se cuenta qué se
  encontró, cómo, qué podía pasar, qué se hizo y qué test lo fija.
- `docs/demostracion-etapa-2.md`: el guion de la defensa, en el orden de §2.3, con los datos
  del seeder:
  1. visitante: catálogo, filtro, búsqueda, ficha sin precio;
  2. registro e inicio de sesión: aparecen los precios;
  3. compra con **Mercado Pago de prueba**: pago con la cuenta compradora, regreso a la app y
     verificación por consulta al proveedor (la promoción);
  4. compra por Mercado Pago de prueba con **rechazo** (titular `OTHE`), reintento y aprobación (D-23);
  5. consulta de los pedidos y de sus pagos;
  6. compra de contado y su cobro en la web, con el pedido encontrado por el filtro de canal;
  7. **error de conexión**: se apaga `php artisan serve` y la app lo dice;
  8. sesión vencida, con `SANCTUM_EXPIRATION` corto;
  9. pedido ajeno: 404 desde una herramienta HTTP;
  10. el kardex: la venta online dejó el mismo asiento que una de mostrador.
- `README.md` del backend: `php artisan schedule:work` (HL-10), `serve --host=0.0.0.0`
  (HL-12), `storage:link`, las variables nuevas de `.env.example`.
- `plan-etapa-2.md`: la sección de cierre, como la §8 de la Fase 7.5.
- Ensayo completo del guion, dos veces, en el equipo de la defensa.

**Criterio de terminado.** Cada fila de la §2 está cumplida y señalada en la matriz, y el guion
se recorrió entero sin tocar código.

**Cierra.** 8.10, 9.11, O-3, P-1 a P-10, S-1, hito 6.

---

### Tramo J — Contenido que invita a comprar (opcional)

**Objetivo.** Que Inicio y la ficha del producto dejen de ser «una lista y un dato» y tengan
con qué atraer al cliente. **No es requisito de la consigna ni de aprobación**: se hace sólo si
sobra tiempo después de I, y se puede cortar en cualquier punto sin que nada de la etapa
dependa de él. Si entra, el guion de la defensa (tramo I) se ensaya de nuevo con las pantallas
ya enriquecidas.

**Regla de este tramo.** Sólo contenido **real**, que sale del catálogo o de los ajustes de la
tienda: nada de reseñas, «quedan 2», descuentos ni urgencia inventados. El visitante sigue sin
ver precios (regla 21): ninguna pieza nueva los muestra por fuera de `Precio`. Cada pieza
respeta los seis estados y lo de CLAUDE.md «Interfaz» (29 a 34).

**Tareas, por orden de provecho y de menor costo. Todas con el contrato actual (sólo app):**
- **Inicio.**
  - Atajos a las categorías raíz, con su ícono, arriba de los carruseles (el mismo árbol de
    `GET /categorias` que el menú).
  - Un carrusel «Novedades» y otro «Destacados» con tarjetas más grandes, y el título de cada
    sección con «Ver todo».
  - Un bloque de confianza, con lo que la tienda de verdad ofrece: retiro en el local, y
    envío sólo si `tienda.envio.habilitado`. Texto del servidor o de los ajustes, no escrito
    a mano en la vista.
- **Ficha.**
  - «Productos de la misma categoría», con `GET /productos?categoria_id=` sin el producto
    actual.
  - La descripción en secciones plegables, y la disponibilidad con más peso visual.
  - El botón principal («Agregar al carrito») fijo abajo desde E; hasta entonces, el aviso de
    precio. El carrito y la compra siguen siendo de E y F.
- **Detalles que dan sensación de cuidado:** transiciones y entradas encadenadas
  (`flutter_animate`), vacíos con `lottie`, fotos que se ven antes de cargar completas (esqueleto
  con la forma real). Ya están en las librerías fijadas.

**Si hace falta contrato (backend primero, sólo si se decide):** las especificaciones técnicas
de la ficha (tabla de atributos), las marcas para filtrar, y un producto «relacionado»
elegido por la tienda y no sólo por categoría. Cada uno es un cambio de `docs/api.md` con su
test en Pest **antes** de tocar la app, y se declara en la defensa como ampliación.

**Criterio de terminado.** Las pantallas de Inicio y de la ficha se recorren con datos del
seeder sin huecos ni piezas vacías, ningún dato es inventado, `flutter analyze` sin
advertencias y `flutter test` en verde.

**Cierra.** Nada de la matriz: es una mejora declarada, no un requisito.

---

## 8. Orden y dependencias

```
A ──► C ──► D ──► E ──► F ──► K ──► G ──► H ──► I ──► J (opcional)
B ──┘                     └────────────────► H
```

| Dependencia | Por qué |
| --- | --- |
| **A primero** | La consigna pone el contrato primero, y con dos repositorios es lo único compartido. Fijarlo después es reescribir los dos lados |
| **B en paralelo con A** | Es todo del repositorio de la app y sólo necesita la §6 escrita. Su prueba de conexión usa `GET /categorias`, que llega en C: hasta entonces se prueba contra cualquier ruta de `api/*`, que ya responde JSON |
| **A y B antes que C** | Sin contrato, cliente HTTP, tema y navegación, cada pantalla los improvisa |
| **C antes que D** | Primero el catálogo sin precios, después el precio: así el test de omisión existe antes de que exista algo que omitir |
| **D antes que E, F, G y H** | Los cuatro necesitan sesión y permisos |
| **E antes que F** | No hay confirmación sin checkout ni reserva |
| **F antes que G y H** | No hay pago sin pedido, ni consulta sin algo que consultar |
| **K después de F y antes de G** | Edita lo que la confirmación ya muestra (el paso 3). El pago no lo necesita, pero la compra completa del G conviene ensayarla con los datos ya cargados |
| **G antes que H**, en parte | El detalle muestra el estado del pago. El listado de H puede empezar después de F |
| **De cada funcionalidad, primero el backend** | Con sus tests en Pest; la app consume algo que ya está fijado |
| **J, al final y opcional** | Es contenido sobre pantallas que ya funcionan: nada depende de él, y se corta cuando se acabe el tiempo |
| **I es transversal** | El contrato, la trazabilidad y la matriz se llenan desde A; I los cierra y ensaya |

---

## 9. Qué se recorta si el tiempo no alcanza

**Casi nada: los nueve requisitos son de aprobación.** Admiten recorte, en este orden:

| Orden | Qué | Cómo queda | Qué se declara |
| --- | --- | --- | --- |
| 0 | El tramo J completo (contenido que invita a comprar) | Inicio y la ficha quedan como las dejaron C y D | Nada: es opcional |
| 1 | El refinamiento: `Hero`, animaciones encadenadas, vacíos con `lottie` | Las pantallas funcionan con transiciones simples | Nada: no es requisito |
| 2 | El envío a domicilio | `tienda.envio.habilitado = false`: sólo retiro, costo cero | «La tienda trabaja con retiro en el local», en el contrato y en la defensa |

**No se recorta nunca:** el precio omitido en el servidor, la idempotencia, el acceso sólo a lo
propio, los importes del servidor, la revalidación, el registro de intentos, el contrato
documentado, los seis estados, la reserva por `StockService`, el test de la frontera de
gestión y **Mercado Pago** (D-20).

---

## 10. Las bases para la Etapa 3

La Etapa 3 probablemente se fusione con ésta. Lo que se deja preparado, y lo que se evita por
eso:

| Se deja | Para qué | Se evita |
| --- | --- | --- |
| `/api/v1` versionado | Que la web, u otro cliente, consuma la misma API sin romper la app | Una ruta sin versión que no se puede cambiar con una app instalada |
| API Resources | Una sola serialización por recurso | Arreglos armados a mano en el controlador |
| Controladores que sólo traducen | Que la web pueda llamar a los mismos servicios o a la misma API | Un sistema paralelo con reglas copiadas |
| `docs/api.md` completo y con revisiones | La base del OpenAPI | Documentar después lo que ya está en uso |
| `CredencialService` como única clase que conoce Sanctum | Cambiar a JWT, si hiciera falta, toca esa clase y `AuthService` en la app | Credenciales leídas en diez lugares |
| Permisos de tienda por acción | Un cliente nuevo de la API se gobierna con los mismos permisos | Reglas de acceso por ámbito escritas en cada controlador |
| Forma única de error y de paginado | Cualquier cliente se escribe una vez | Un formato por endpoint |
| La interfaz `Pasarela` | Agregar otro proveedor es otra implementación | Mercado Pago atado al pedido |

---

## 11. Riesgos

| Riesgo | Por qué | Cómo se acota |
| --- | --- | --- |
| **La etapa se calcula como «hacer una app»** | Más de un tercio es Laravel, y los servicios se extienden | Cada tramo hace primero el backend; los tramos E, F y G son mayormente backend |
| **La API se vuelve un sistema paralelo** | Es tentador poner lógica en el controlador | Los controladores sólo traducen; la frase de la etapa como criterio de F |
| **La reserva rompe el stock** | Primera escritura de stock desde fuera del mostrador; concurrencia | Por `StockService`, con bloqueo ordenado por `id`, test de concurrencia por inyección y la invariante de dueños |
| **Plata aprobada sin registrar** | Mercado Pago aprueba afuera y el descuento puede fallar adentro | La reserva no vence con un intento pendiente; la aprobación se guarda antes de acreditar; el comando reintenta (§1.3 E) |
| **Mercado Pago llega tarde** | Es obligatorio (D-20) y es la integración con más piezas ajenas: cuentas, aplicaciones, credenciales y una API que cambia | Las cuentas se crean y **una preferencia de prueba se crea a mano antes del tramo E** (§14.2): el riesgo se descubre temprano, no en G. Sin SDK, sólo el cliente `Http` de Laravel |
| **Mercado Pago falla en la defensa** | Depende de internet y de un servicio ajeno | Sin el simulador (D-23) no hay otro camino en vivo: se prueba la compra el día anterior y en la defensa, antes de empezar. Si falla, la prueba de punta a punta anotada en el informe respalda que funcionó |
| **No hay internet el día de la defensa** | Mercado Pago necesita salida a internet desde el backend | Se prueba la red antes. Plan B: el punto de acceso del teléfono. Cambia la IP de la máquina, y la pantalla de ajustes de la app lo resuelve sin recompilar |
| **La tarea programada no corre** | En Windows no hay cron (HL-10) | `schedule:work` en el guion, y la confirmación y el cobro miran su propio vencimiento |
| **`RolPermisoSeeder` pisa roles editados** | `sync` devuelve cada rol a su definición | Se avisa en el tramo D; en la base de la defensa se corre antes de cualquier ajuste manual |
| **forui está antes de la 1.0** | Un cambio menor puede romper código | Versión fija, envuelta en `ui/comun/` |
| **Flutter se mueve rápido** | Las versiones son de hoy | `pubspec.lock` versionado, sin actualizar cerca de una entrega |
| **Las imágenes rompen la escala o no cargan** | Un solo tamaño (D-15) y `APP_URL=localhost` | URL con el host del pedido (A), decodificación al tamaño de la tarjeta, test de peso de la respuesta |
| **Nombres repetidos en los tests** | Pest carga todo en un proceso, con 28 funciones globales | Prefijo `tienda` en los helpers nuevos |
| **El contrato y la app se desincronizan** | Dos repositorios | Revisión numerada en `docs/api.md`, JSON de ejemplo copiados a los tests de la app, revisión nombrada en el README |
| **Tráfico en claro en una compilación de entrega** | La política olvidada en `src/main` | Sólo en `src/debug` (HL-11): el release no puede, por construcción |

---

## 12. Preguntas abiertas

### 12.1 Resueltas

| # | Pregunta | Respuesta |
| --- | --- | --- |
| 1 | Sanctum o JWT | Sanctum (D-7) |
| 2 | La descripción breve | Derivada de la completa (D-12) |
| 3 | El simulador en `pagos.metodo` | Valor nuevo al final del ENUM (D-13), más `pagos.intento_pago_id` (HL-2) |
| 4 | El mecanismo de idempotencia | El carrito: `ventas.carrito_id` UNIQUE (D-8) |
| 5 | La política ante cancelación, rechazo y reserva vencida | D-1 y D-9: el rechazo conserva la reserva; el vencimiento cancela el pedido; la cancelación libera |
| 6 | Cambiar de medio de pago | No: queda fijo (D-10); Etapa 4 |
| 7 | Con qué precio se ordena | `precio_contado` (D-14) |
| 8 | Miniaturas | No se generan (D-15) |
| 9 | Cuándo se reserva | Al iniciar el checkout (D-16) |
| 10 | Vincular cuentas con clientes de mostrador | Etapa 4 (D-17) |
| — | La reserva del contado | 48 horas y cancelación al vencer (D-1) |
| — | Permisos de tienda | Sí, uno por acción (D-6) |
| — | Dónde vive la app | Repositorio propio (D-4) |
| 11 | Nombre, identificador e ícono | CompuStack, `ar.compustack.tienda`, logo sobre blanco crema (D-18) |
| 12 | `url_launcher` | Entra (D-19) |
| 13 | La promoción | Es puntaje extra de la cátedra; se hace igual: Mercado Pago es obligatorio (D-20) |

Las D-12 a D-17 se tomaron por omisión, con la recomendación: se pueden revisar antes del tramo
que las usa.

### 12.2 Las que quedan

**Una, de números y no de diseño.** Si durante un tramo aparece algo que puede ir para dos
lados, se plantea y se decide antes de escribir código, y se agrega acá con su número.

- **Q-1. Valores de partida de `config/tienda.php`.** El plan fija las claves pero no estos
  números, y el tramo A los dejó marcados `// a revisar`: `novedades.dias` (30),
  `novedades.tope` (10), `disponibilidad.ultimas_unidades` (3), `pagos.intento_minutos` (20),
  `envio.costo` (`0.00`), `carrito.cantidad_maxima` (10) y `descripcion_breve.palabras` (25).
  **Confirmados en el tramo C** (backend), medidos con los 20.012 productos de la base: `novedades.dias`
  30 y `novedades.tope` 10 (el tope es el que acota: todo el catálogo se creó en dos fechas),
  `disponibilidad.ultimas_unidades` 3 (188 productos, menos del 1 %, caen en «últimas unidades») y
  `descripcion_breve.palabras` 25, a los que el tramo suma `descripcion_breve.caracteres` 250 (pendiente
  #83). **`carrito.cantidad_maxima` 10, confirmado en el tramo E**: alcanza para una compra de hogar o pyme y frena
  el carrito que acapara stock. **`envio.costo` 8.000, decidido en el tramo F**: un costo fijo distinto de cero,
  para que el envío se vea en el total y en los tests; los envíos calculados son de la Etapa 4.
  **`pagos.intento_minutos` 20, decidido en el tramo G**: igual que `reservas.pasarela_minutos`, un solo plazo;
  si el intento termina después que la reserva, la reserva se estira hasta él (D-9). Q-1 queda cerrada.

---

## 13. Matriz de seguimiento

Herramienta propia (S-1): **no** es la tabla que pide la consigna, que es `trazabilidad.md`
§15. Se actualiza al cerrar cada tramo.

| Funcionalidad | Pantalla de la app | Endpoint | Acceso | Tramo | Estado |
| --- | --- | --- | --- | --- | --- |
| Catálogo paginado | Catálogo | `GET /productos` | Sesión opcional | C | Hecho |
| Filtro por categoría | Catálogo (hoja de filtros) | `GET /categorias`, `GET /productos?categoria_id` | Pública | C | Hecho |
| Búsqueda | Búsqueda | `GET /productos?q` | Pública | C | Hecho |
| Orden por nombre y fecha | Catálogo | `GET /productos?orden` | Pública | C | Hecho |
| Orden por precio | Catálogo | `GET /productos?orden=precio_*` | `tienda.ver_precios` | D | Hecho |
| Novedades y destacados | Inicio | `GET /productos?seccion` | Pública | C | Hecho |
| Destacado administrado | — (web: formulario del producto) | — | `producto.editar` | C | Hecho |
| Ficha | Ficha | `GET /productos/{id}` | Sesión opcional | C | Hecho |
| Precios | Catálogo, Ficha | `GET /productos`, `/productos/{id}` | `tienda.ver_precios` | D | Hecho |
| Registro | Registro | `POST /registro` | Pública, con límite | D | Hecho |
| Inicio y cierre de sesión | Inicio de sesión, Perfil | `POST` y `DELETE /sesion` | Pública / protegida | D | Hecho |
| Datos básicos | Mi cuenta | `GET /perfil` | Protegida | D | Hecho |
| Editar la cuenta | Mi cuenta, Confirmación (paso 3) | `PATCH /perfil` | Protegida | K | Hecho |
| Sesión vencida | Todas | Cualquier protegida (401) | — | B, D | Hecho (B: el mecanismo; D: de punta a punta, `sesion_vencida_test.dart`; HL-78) |
| Carrito | Carrito, Ficha, Agregado | `/carrito`, `/carrito/lineas` | `tienda.comprar` | E | Hecho |
| Vaciar | Carrito | `DELETE /carrito` | `tienda.comprar` | E | Hecho |
| Checkout y reserva | Carrito (paso 1 de 5, reservado) | `POST /carrito/checkout` | `tienda.comprar` | E | Hecho |
| Confirmar pedido | Confirmación (pasos 2 a 5), Pedido registrado | `POST /pedidos` | `tienda.comprar` | F | Hecho |
| Canal en ventas | — (web: listado de ventas) | — | `venta.ver` | F | Hecho |
| Cobro de contado | — (web: cobro) | — | `venta.cobrar` | F | Hecho |
| Pago por pasarela | Pago, Resultado | `POST /pedidos/{id}/pagos` | `tienda.comprar` | G | Hecho |
| Resultado del pago | Resultado, Detalle | `GET /pedidos/{id}/pagos/{intento}` | `tienda.ver_pedidos` | G | Hecho |
| Mis pedidos | Mis pedidos | `GET /pedidos` | `tienda.ver_pedidos` | H | Hecho |
| Detalle del pedido | Detalle | `GET /pedidos/{id}` | `tienda.ver_pedidos` | H | Hecho |
| Comprobante del pedido | Detalle | `GET /pedidos/{id}/comprobante` | `tienda.ver_pedidos` | H | Hecho |
| Dirección del servidor | Ajustes (Cuenta → Servidor) | `GET /categorias` (sólo como prueba) | — | B | Hecho |

---

## 14. Lo que queda por completar

El plan está cerrado. Esto es lo que falta para arrancar y para terminar la etapa.

### 14.1 Antes del tramo A

- [x] Conseguir el **logo de CompuStack** en buena resolución (SVG, o PNG de al menos
      1024 × 1024 con fondo transparente): el repositorio no tiene ninguno, y el ícono de la
      app sale de ahí (D-18). Hecho: `assets/marca/logo.svg` en la app; el ícono adaptativo es
      un vector armado con sus trazados.
- [x] Confirmar el tono de **blanco crema** del ícono: se mantiene `#FAF7F0`.
- [x] Revisar las decisiones por omisión **D-12 a D-17**: sin objeciones al empezar el tramo A,
      se siguió el plan.
- [x] Registrar en `pendientes-usabilidad.md` el **#81** (la pantalla de roles ofrece el
      módulo `tienda` a roles de gestión) y el **#82** (la web muestra las horas en UTC,
      HL-16).
- [x] Crear el repositorio de la app y anotar su dirección en el README del backend
      (`github.com/ivan545353/compustack-app`).

### 14.2 Preparativos que no son código

- [ ] Instalar Flutter estable y un emulador Android; confirmar qué teléfono se usa en la
      defensa.
- [ ] Juntar las fotos del seeder de la tienda (propias o de uso libre), una o dos por
      producto de demostración.
- [ ] **Antes del tramo E**: dos cuentas de prueba de Mercado Pago (vendedora y compradora),
      **cada una con su aplicación** en el panel de desarrolladores, y el token de acceso de
      prueba de la vendedora en el `.env`, nunca en el repositorio. Crear **una preferencia de
      prueba a mano** y pagarla con la compradora: si algo de Mercado Pago no funciona, se
      descubre ahora (D-20).
- [ ] Confirmar que la máquina de la defensa tiene salida a internet, y tener el punto de
      acceso del teléfono como plan B.

### 14.3 Lo que se verifica al empezar cada tramo

Cosas que este plan no pudo verificar desde el código, y que se confirman antes de escribir:

| Tramo | Qué verificar |
| --- | --- |
| A | Que `php artisan install:api` instale la versión de Sanctum compatible con Laravel 12 sin tocar otras dependencias; qué migración crea |
| B | Las versiones vigentes de cada paquete de la lista y su compatibilidad con el Flutter estable; la API de `forui` en la versión fijada |
| C | Cómo usan `imagenes` los productos editados a mano (rutas, orden); que el tope de novedades y el umbral de disponibilidad tengan sentido con el catálogo de demostración |
| D | Que nadie haya editado roles a mano en la base que se va a usar, antes de correr `RolPermisoSeeder` |
| G | Los nombres de campo vigentes de la API de preferencias y de búsqueda de pagos de Mercado Pago, contra su documentación en ese momento |

### 14.4 Estado de los tramos

| Tramo | Backend | App | Documentos | Estado |
| --- | --- | --- | --- | --- |
| A — Contrato y capa de API | [x] | — | [x] `api.md` r1, `modelo-datos`, `trazabilidad` | Cerrado |
| B — Cimientos de la app | — | [x] | [x] `README`, `ARQUITECTURA`, `trazabilidad` §15 | Cerrado |
| C — Catálogo y detalle | [x] | [x] | [x] `api.md` r2, `modelo-datos`, `trazabilidad` §15 (los dos lados), `pendientes` #83 y #84, `README` y `ARQUITECTURA` de la app | Cerrado |
| D — Cuenta y precios | [x] | [x] | [x] `api.md` r3, `modelo-datos` (permisos de tienda), `trazabilidad` §15 (los dos lados), D-21, HL-55 y HL-56, `README` y `ARQUITECTURA` de la app | Cerrado |
| E — Carrito y reserva | [x] | [x] | [x] `api.md` r4, `modelo-datos` §4, `trazabilidad` §15 (los dos lados), HL-57 a HL-60, Q-1 (`cantidad_maxima`), reglas 31 y 32 de la app, `README` y `ARQUITECTURA` de la app | Cerrado |
| F — Confirmación del pedido | [x] | [x] | [x] `api.md` r5, `modelo-datos` §4 y §5, `trazabilidad` §15 (los dos lados), HL-61 a HL-64, Q-1 (`envio.costo`), D-22 y el tramo K, `README` y `ARQUITECTURA` de la app | Cerrado |
| K — Cuenta editable (entre F y G) | [x] | [x] | [x] `api.md` r6 y r7, `modelo-datos` §1, `trazabilidad` §15 (los dos lados), HL-65 a HL-69, regla 34 de la app, `README` y `ARQUITECTURA` de la app | Cerrado |
| G — Pagos | [x] | [x] | [x] `api.md` r8, `modelo-datos` §5 y §10, `trazabilidad` §15 (los dos lados, y las filas de HL-69 del K), HL-70 a HL-74, Q-1 cerrada (`intento_minutos`), D-23 (sin simulador en la app), `README` y `ARQUITECTURA` de la app | Cerrado (Mercado Pago anduvo de punta a punta, anotado en `pruebas-etapa-2.md`; quitar el simulador del backend es el pendiente #85) |
| H — Consulta de compras | [x] | [x] | [x] `api.md` r9, `trazabilidad` §15 (los dos lados), HL-75 a HL-77, `README` y `ARQUITECTURA` de la app | Cerrado (el test del pedido ajeno, nombrado en `pruebas-etapa-2.md`; el comprobante desde el teléfono se prueba en el ensayo) |
| I — Pruebas, documentación y demostración | [x] `config/tienda.php` y `phpunit.xml` (HL-80) | — | [x] `api.md` r10, `pruebas-etapa-2`, `seguridad-etapa-2`, `demostracion-etapa-2`, `README`, `trazabilidad` §15 (cierre), §13, HL-78 a HL-80, `pendientes` #85, §15 de este plan | Cerrado (los dos ensayos del guion quedan a cargo del autor, fuera de la sesión: §14.5 y §14.6) |
| J — Contenido que invita a comprar (opcional) | — | [ ] | [ ] | Opcional, al final |

### 14.5 Al cerrar la etapa

- [x] Cada fila de la §2 con su tramo cerrado, y la matriz (§13) toda en «Hecho».
- [x] `docs/api.md` en su revisión final (r10, verificada contra el servidor).
- [x] `trazabilidad.md` §15 con el bloque de cierre.
- [x] `docs/pruebas-etapa-2.md`, `docs/seguridad-etapa-2.md` y `docs/demostracion-etapa-2.md`
      escritos.
- [ ] El guion de la defensa ensayado dos veces sin tocar código.
- [x] Una sección «Cierre de la Etapa 2» al final de este plan, como la §8 de la Fase 7.5: qué
      se hizo, qué se recortó y por qué, qué quedó pendiente con su número.

### 14.6 Próximo paso

Los **dos ensayos del guion** (`demostracion-etapa-2.md`) en el equipo de la defensa, sin tocar
código, a cargo del autor; lo que encuentren, como HL desde el HL-81. Después, si hay tiempo, el tramo J (opcional).

---

## 15. Cierre de la Etapa 2

**La Etapa 2 hizo sus diez tramos obligatorios** (A a I, más el K entre el F y el G) y dejó el J, que
es opcional, para después de los ensayos. Al cerrar, las dos suites pasaban en verde: **2.217 tests**
en el backend (6.251 aserciones) y **956** en la app. El contrato (`docs/api.md`, revisión 10) se
verificó contra el servidor, ruta por ruta.

**Qué se hizo.** Una tienda móvil en Flutter sobre una API `/api/v1` que reutiliza los servicios de la
Etapa 1: el pedido de la app es una venta con `canal = online`, se cobra por `PagoService` y deja el
mismo asiento en el kardex. Cada fila de la §2 tiene su tramo cerrado, y la matriz (§13) está toda en
«Hecho». La promoción (6.3) se cumple con Mercado Pago de prueba, verificado por consulta al proveedor.

**Qué se recortó y por qué.**

| Qué | Por qué |
| --- | --- |
| El simulador en la app (D-23) | «Simulador» quería decir la simulación que ya dan las cuentas de prueba de Mercado Pago; una opción que el servidor puede tener apagada dejaba una salida sin destino |
| Cambiar la contraseña desde la app | Quedó fuera del K: la consigna pide registro, inicio, consulta y cierre de sesión |
| El tramo J | Es opcional (§9, orden 0) |
| La facturación, los envíos, los vales y los banners | No es recorte: la consigna no los pide (Etapa 4, §5) |

**Qué quedó pendiente.**

- **#85** de `pendientes-usabilidad.md`: quitar el simulador del backend (ya apagado por omisión, HL-80).
- **Los dos ensayos del guion** (`demostracion-etapa-2.md`) en el equipo de la defensa: es lo único que
  falta del criterio de terminado del tramo I. Ahí se prueban también el comprobante desde el teléfono
  y la duda del total que aparece dos veces en el detalle.
- Los huecos de test anotados en `pruebas-etapa-2.md` §5.

**Lo que hereda la Etapa 3.** Las bases de la §10: la API versionada, los Resources, `CredencialService`
como única clase que conoce Sanctum, los permisos de tienda por acción y la interfaz `Pasarela`.
