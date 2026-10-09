# Auditoría técnica — Sistema de gestión de tienda informática

Alcance: backend PHP (MVC + Pipeline + DAO/DTO), frontend Angular 21, dump SQL `lp_2025`.
Objetivo: identificar qué **no** repetir en la migración a Laravel + Flutter.

Severidad: **C** crítico · **A** alto · **M** medio · **B** bajo

**Cierre:** los hallazgos resueltos en la migración llevan al final una línea
`> **Cerrado** — Fase N. Cómo.` Un hallazgo sin esa línea está abierto.

---

## 1. Seguridad

### C-1 · Se filtran los hashes de contraseña de todos los usuarios
`UserDto::toArray()` incluye la clave `"clave"`. `UserController::getCurrent()` hace `unset($data["clave"])`, pero `load()` **no**. Peor: `UserDao::list()` hace `SELECT SQL_CALC_FOUND_ROWS u.*` y el service devuelve las filas crudas.

Resultado: `GET /user/list` devuelve el hash bcrypt de **todos** los usuarios a cualquiera con `can_read` sobre el módulo `user`. `GET /user/load/{id}` hace lo mismo por usuario.

El bug de fondo no es el `unset` faltante, es que **el DTO de escritura y el de lectura son el mismo objeto**. En Laravel esto se corrige separando `FormRequest` (entrada) de `JsonResource` (salida) y marcando `password` en `$hidden`.

> **Cerrado** — Fase 4. Entrada y salida son objetos distintos: `PersonalRequest`
> valida, el modelo `User` devuelve con `$hidden`. Pero `$hidden` sólo actúa al
> serializar, así que en una vista Blade el hash se imprimiría igual: el listado
> además **no selecciona la columna**, que es la corrección literal del
> `SELECT u.*`. Los dos niveles tienen su test, y son dos tests distintos
> porque el primero pasa aunque la columna se traiga de la base.

### C-2 · Fallback permisivo en el middleware de autorización
```php
$columna = self::MAPA_PERMISOS[$action] ?? "can_update";
```
Toda acción fuera del CRUD (`cobrar`, `updateEstado`, `enable`, `disable`, `reset`, `perfiles`) cae en `can_update`. Con los permisos del dump, el perfil **Vendedor** tiene `can_update = 1` sobre el módulo `sale`, así que puede ejecutar `cobrar` y `updateEstado` — incluido anular ventas ya cobradas, pese a que su `can_delete` es 0.

Además el módulo se deduce del nombre del controlador (`$controller` → `modulos.nombre`), lo que acopla el routing a datos de la tabla `modulos`: renombrar un controlador rompe silenciosamente los permisos, sin error.

> **Cerrado** — Fase 2. `Gate::before` concede si el rol tiene la clave y devuelve
> `null` si no la tiene; como no hay ninguna otra regla definida, `null` deniega.
> No hay valor por omisión que heredar: una acción que nadie asignó no se puede
> hacer. Además el módulo ya no se deduce del nombre del controlador —cada ruta
> declara su permiso con `can:` en `routes/web.php`—, así que renombrar un
> controlador no puede cambiar quién accede. Lo fija el test «una accion sin
> permiso definido se deniega», y cada módulo repite el dataset de permisos en las
> dos direcciones: con todos los permisos del módulo menos el de la ruta se
> deniega, y con sólo ése se permite. Sin la primera mitad, una ruta protegida por
> el permiso equivocado pasaría desapercibida.

### C-3 · Escalada de privilegios vía `user/update`
`UserController::update()` construye el `UserDto` directamente desde el body, que incluye `perfil_id`, y no compara el `id` del body contra el `usuarioID` del token. Un usuario con `can_update` sobre `user` puede reasignarse a sí mismo (o a cualquiera) el perfil Administrador. No hay separación entre "editar mi cuenta" y "editar a otro".

> **Cerrado** — Fase 4. El rol no es un campo del formulario de edición y
> enviarlo se rechaza (`prohibited`), en vez de ignorarse en silencio. Cambiarlo
> es una acción aparte con permiso propio (`usuario.cambiar_rol`), que prohíbe
> la autoasignación comparando identidades —lo que el original nunca hacía— y
> sólo admite roles del mismo ámbito. `UsuarioService::actualizar` enumera los
> campos uno por uno y no escribe `rol_id` ni `password` aunque lleguen: es la
> segunda barrera, la que va a proteger a la API de la Etapa 3.

### A-4 · Secretos versionados
- `JWT_SECRET = '2896bd45d7c219ccec38199c54734628'` en `AppConfig.php`, commiteado.
- Credenciales de base hardcodeadas en `Connection.php`: usuario `root`, password vacío.
- No hay `.gitignore` en el repo del backend → `app/vendor/` completo versionado.

Rotar el secreto hoy implica invalidar todas las sesiones y editar código. En Laravel esto va a `.env` + `config/`
> **Cerrado** — Fases 0 y 1. El `JWT_SECRET` y las credenciales de `Connection.php`
> se reemplazaron por marcadores **antes del primer commit**, así que no están en
> ningún punto del historial, y `legacy/README.md` documenta qué se modificó y por
> qué. La configuración vive en `.env`, ignorado por Git, con `.env.example`
> versionado sin un solo valor real: ni contraseñas, ni correos personales. El
> `.gitignore` excluye `vendor/`, `node_modules/` y `storage/*.key`. La Fase 8
> vuelve a revisar el historial completo, que es lo único que este cierre no puede
> probar por sí mismo.

### A-5 · El token no se puede revocar
`AuthenticationService::logout()` es un no-op con comentario explicando que el logout es del lado del cliente. No hay `jti`, ni blacklist, ni refresh token. Consecuencias:
- Deshabilitar un usuario (`disable`) **no lo desloguea**: su token sigue siendo válido hasta 1 hora.
- Cambiar el perfil de un usuario no surte efecto hasta que expire el token, porque el perfil viaja *dentro* del JWT y `AuthorizationHandlerMiddleware` lo lee de ahí.
- No se revalida `estado` ni `resetPass` en cada request, sólo en el login.

Con clientes y pagos reales esto pasa de molestia a riesgo.
> **Cerrado** — Fase 2. No hay token: la sesión vive en una cookie y el servidor la
> puede invalidar. El rol ya no viaja dentro de una credencial —los permisos se
> resuelven en cada request contra la tabla, con caché por rol que `RolService`
> invalida al guardar la asignación—, así que cambiarle el rol a alguien surte
> efecto de inmediato en lugar de esperar una hora. `VerificarUsuarioActivo` corre
> en todo el grupo `web` y no sólo en el login: deshabilitar una cuenta en curso la
> desconecta en el próximo request, y lo prueban los tests «deshabilitar un usuario
> lo desconecta en el proximo request» y «la cache de permisos se invalida al
> cambiar el rol».

### A-6 · Sin límite de intentos en el login
`POST /authentication/login` no tiene rate limiting, captcha ni bloqueo por intentos. Fuerza bruta libre contra bcrypt.
> **Cerrado** — Fase 2. `throttle:5,1` en el POST de login y en el de
> restablecimiento, que son las dos rutas públicas que reciben credenciales. Lo
> verifica el test «el login limita los intentos». Además el mensaje de error no
> revela si falló el correo o la contraseña, para no convertir el login en un
> verificador de cuentas existentes: lo cubre «rechaza credenciales incorrectas sin
> revelar cual fallo».
> 
### M-7 · CORS con origen hardcodeado
`Access-Control-Allow-Origin: http://localhost:4200` fijo en el código. No configurable por entorno, y con Flutter (web/móvil) el origen cambia.

> **Cerrado** — Fase 2. No hay CORS que configurar: con Blade el origen es el
> mismo, así que la cabecera desapareció junto con el `CorsHandlerMiddleware`. Esto
> no es una excusa para la Etapa 3: cuando la API tenga que servir a Flutter, los
> orígenes se declaran en `config/cors.php` leyendo `.env`, nunca escritos en el
> código, que es lo que el hallazgo señalaba.
> 
### M-8 · Token en `localStorage`
`AuthService` guarda el JWT en `localStorage`, accesible desde cualquier script. En Flutter esto se resuelve con `flutter_secure_storage` (Keychain/Keystore), que es un cambio de plataforma a favor.

> **Cerrado** — Fase 2. No hay nada en `localStorage` que robar: la sesión viaja en
> una cookie `HttpOnly`, inaccesible desde cualquier script de la página, con
> `SameSite=lax`. Queda para la revisión de la Fase 8 poner
> `SESSION_SECURE_COOKIE=true` en el entorno de producción, que es un asunto de
> transporte y no de acceso desde scripts.
---

## 2. Lógica de negocio y concurrencia

### C-9 · No existe máquina de estados en ventas
`SaleDao::updateEstado()` acepta **cualquier** transición. La única lógica es:
```php
if ($actual === "presupuesto" && $nuevoEstado === "confirmada")  → descontar stock
elseif (in_array($actual, ["confirmada","cobrada"]) && $nuevo === "anulada") → reponer
// cualquier otro caso: sólo UPDATE estado
```
Agujeros concretos:

| Transición                | Qué pasa                                                       | Qué debería pasar       |
| ------------------------- | -------------------------------------------------------------- | ----------------------- |
| `presupuesto → cobrada`   | Se marca cobrada **sin descontar stock** y sin pago registrado | Rechazar                |
| `anulada → confirmada`    | Revive la venta sin volver a descontar stock                   | Rechazar                |
| `cobrada → confirmada`    | Deja pagos registrados sobre una venta no cobrada              | Rechazar                |
| `confirmada → confirmada` | Descuenta stock de nuevo                                       | Rechazar (idempotencia) |

`SaleService::updateEstado()` sólo valida que el estado destino esté en la lista de válidos; nunca valida el origen. Este es el defecto más grave del módulo que hoy "funciona perfectamente".

> **Cerrado** — Fase 6. `App\Support\MaquinaEstadosVenta` declara la tabla completa de
> transiciones, y **lo que no está en la tabla no se puede**. Los cuatro agujeros de
> arriba están tapados por la misma pieza, y cada uno tiene su caso de test:
>
> - `presupuesto` sólo llega a `pagada` o a `cancelada`, así que
>   `presupuesto → entregada` se rechaza. Era el peor de los cuatro: el
>   `presupuesto → cobrada` del original marcaba la venta como cobrada **sin descontar
>   stock y sin un solo pago registrado**, simplemente porque la cadena de `if` no
>   contemplaba el caso y caía en el `UPDATE` pelado.
> - `cancelada` y `devuelta` son listas vacías: `anulada → confirmada` no revive nada.
> - `entregada → pagada` tampoco existe, así que no quedan pagos registrados sobre una
>   venta que el sistema considera no cobrada.
> - `pagada → pagada` no está declarado, y **ésa es la idempotencia**: no se consigue
>   con una bandera ni con un chequeo extra en el servicio, se consigue con que el
>   destino no esté en la lista del origen.
>
> Hay una segunda mitad del hallazgo que una tabla sola no cubre: en el original el
> estado destino **viajaba en la petición** (`SaleService::updateEstado($id, $nuevo)`).
> Poner una tabla delante de esa misma puerta cambia cuáles se rechazan, no quién
> decide a dónde va la venta. Acá cada transición es un método con su nombre y sus
> efectos —`cancelar()` pasa `'cancelada'` escrito en el código—, `cambiarEstado()` es
> **privado**, y `estado` está fuera de `$fillable`, así que un `update()` con ese
> campo lo descarta. Son tres barreras que apuntan al mismo defecto desde lugares
> distintos y las tres tienen test, incluido uno que afirma por reflexión que
> `cambiarEstado()` sigue siendo privado.
>
> El `?? []` del lookup es la forma de C-2 en este rincón del sistema: un estado que no
> figura en la tabla no habilita nada por descarte. Y `entregada_parcial` está
> declarado en el ENUM pero no en la tabla, así que es inalcanzable: es el valor que la
> venta con faltante necesitaría, declarado ahora porque agregarlo después reescribe la
> tabla.
>
> La segunda mitad de la fase no agrega reglas acá: agrega el **llamador** de dos
> transiciones que ya están declaradas, el pase a `pagada` —que descuenta stock— y la
> devolución.

### C-10 · Sobrepago posible (TOCTOU)
La validación del monto está en el service, **fuera** de la transacción:
```php
// SaleService::cobrar()
if ($monto > (float)$venta["saldo"] + 0.001) throw ...
$dao->registrarPago(...);   // recién acá abre transacción y hace FOR UPDATE
```
Dos requests concurrentes leen el mismo saldo, ambos pasan la validación, ambos insertan. Con Mercado Pago reintentando webhooks esto deja de ser teórico. Falta además **clave de idempotencia** en `pagos`: no hay forma de distinguir un reintento de un pago nuevo.

> La técnica ya rige en todo lugar donde una decisión depende de un estado que otra
> petición puede cambiar: los cuatro métodos de `StockService` bloquean la fila del
> producto **antes** de comparar contra el disponible, `CompraService` bloquea la orden
> antes de leer su estado, y en ventas `actualizar()`, `recotizar()` y `cancelar()`
> hacen lo mismo con la fila de la venta.
>
> La distinción quedó escrita en `VentaService::crear()`, en el comentario que explica
> por qué el tope de descuento **sí** se valida afuera de la transacción: no depende de
> nada que otra petición pueda cambiar mientras tanto. El saldo de un pago sí depende,
> y por eso tiene que leerse después de bloquear. Validar afuera lo que depende del
> estado es exactamente este hallazgo.
>
> **Cerrado** — Fase 6. `PagoService::cobrar()` bloquea la fila de la venta y **recién
> después** lee el saldo, que es la inversión exacta del original: ahí la comparación
> vivía en el servicio, fuera de la transacción, y el `FOR UPDATE` llegaba al insertar.
>
> El candado es la fila de `ventas` y **no** las de `pagos`, y es a propósito: un pago
> que todavía no existe no se puede bloquear, así que el único lugar donde dos cobros de
> la misma venta se pueden serializar es la fila del padre. Es el mismo razonamiento que
> `DireccionService`, que bloquea la fila del cliente para que el alta de la primera
> dirección también se serialice.
>
> De ahí se desprende una invariante que el docblock del servicio deja escrita: **el
> `lockForUpdate()` tiene que ser la primera lectura de la venta dentro de la
> transacción**. Si antes hubiera una lectura sin lock, la transacción se quedaría con
> esa foto y el saldo leído después podría ser el de antes de que la otra petición
> insertara su pago. Tres tests la sostienen, y cada uno se pone en rojo con un cambio
> distinto:
>
> - «la venta se bloquea antes de leer el saldo» compara la posición de las dos
>   consultas en el log: si el lock desaparece o se corre debajo del `SUM`, falla;
> - «el saldo sale de los pagos ya registrados y no del total» falla si alguien compara
>   el monto contra `ventas.total` en lugar de contra el saldo;
> - «dos cobros concurrentes no exceden el saldo» inyecta el pago de la otra petición en
>   el instante exacto en que el lock se toma, y afirma que el cobro por el total se
>   rechaza. Si el saldo se leyera antes del lock, ese cobro se aceptaría y quedarían
>   dos cobros completos sobre una venta.
>
> **La segunda mitad del hallazgo —la clave de idempotencia— tiene su mecanismo, y son
> dos niveles que cubren dos cosas distintas.** El **mismo cobro** dos veces —doble
> clic, dos pestañas, el botón «atrás» y volver a enviar— lo ataja el estado:
> `pagada → pagada` no está declarado, así que el segundo intento no llega a escribir
> nada. Es el cuarto agujero de C-9 haciendo el trabajo de C-10. El **mismo pago
> externo** dos veces lo ataja `pagos.mp_payment_id`, con su índice único desde la Fase
> 1: `cobrar()` lo busca **dentro del lock** y, si ese pago ya está acreditado, devuelve
> la venta sin escribir nada. Sin ese camino el índice convertiría un reintento de
> webhook en un error del servidor y Mercado Pago seguiría reintentando; con él, el
> reintento es un no-op, que es la definición de idempotente. Un mensaje que mezcla
> pagos acreditados y nuevos se rechaza entero en lugar de escribir la mitad.
>
> La comparación de importes se hace en **centavos enteros**. La tolerancia de `0.001`
> que tenía el plan de acción es lo que uno escribe cuando ya sospecha que comparar
> flotantes está mal; en centavos el problema no existe, porque son enteros.
>
> **Y lo que no se hizo es parte del cierre.** El saldo **no** se valida en
> `PagoRequest`. La doctrina del proyecto es que cada regla que el usuario puede violar
> viva en el servicio *además* del Form Request, y el tope de descuento está en los dos
> a propósito. Acá no, y la diferencia es el hallazgo: el tope depende de lo que llegó y
> del rol de quien lo manda, y nadie puede cambiar ninguna de las dos cosas mientras
> tanto; el saldo depende de los pagos ya registrados, que otra petición puede estar
> escribiendo. Un Form Request no puede bloquear una fila, así que cualquier
> comprobación del saldo escrita ahí sería una lectura sin lock seguida de una decisión
> — una copia del hallazgo dentro del código que lo corrige, y alguien que la leyera
> podría concluir que la del servicio es la redundante. El precio es que ese error no
> cae en el campo sino arriba de la pantalla, con `withInput()` conservando lo cargado,
> y es el precio correcto.

### A-11 · Anular no revierte los pagos
`confirmada/cobrada → anulada` repone stock pero deja las filas de `pagos` intactas. No hay nota de crédito, ni devolución, ni marca de reversión. Contablemente queda plata cobrada sobre una venta inexistente.

> **Cerrado** — Fase 6, y por un camino distinto del que el hallazgo sugería. Vale la
> pena leer primero por qué cambió el camino.
>
> `MaquinaEstadosVenta` no declara `pagada → cancelada`. Una venta cobrada no se
> anula: **se devuelve**, y es la devolución la que tiene que revertir los pagos y
> reponer el stock. El original permitía `confirmada/cobrada → anulada`, reponía stock
> y dejaba las filas de `pagos` intactas; acá esa transición no existe, así que no hay
> ningún camino que deshaga una venta cobrada sin pasar por donde se devuelve el
> dinero. Dos estados que significaran «deshecha» con mecánicas distintas serían peor
> que uno.
>
> `VentaService::devolver()` es ese camino. Repone el stock por `StockService`, revierte
> los pagos y deriva el estado, todo en una transacción, y es el **único** camino que
> deshace una venta cobrada: como `pagada → cancelada` no existe, no hay forma de llegar
> a un estado que signifique «deshecha» sin pasar por donde se devuelve el dinero.
>
> **La reversión es un contra-asiento**: una fila de pago con monto negativo, no una
> edición ni un borrado de la original. Es el mismo criterio que
> `movimientos_stock.cantidad`, que guarda la cantidad con signo —un asiento por hecho—
> y tiene tres consecuencias que valen más que la elección en sí: `SUM(monto)` sigue
> respondiendo «cuánta plata quedó de esta venta» sin filtrar nada ni mirar ninguna
> columna de estado; una devolución parcial produce una reversión parcial sin inventar
> ninguna entidad; y la ficha muestra los dos hechos uno debajo del otro, que es lo que
> hace evidente que nada se tapó. `EsquemaDatosTest` afirma contra la base que
> `pagos.monto` no es `unsigned`, porque si alguien lo declarara así la reversión
> fallaría en mitad de la transacción de la devolución.
>
> **El monto lo calcula el servidor y nunca se escribe.** `DevolucionRequest` declara
> `monto` como `prohibited`, así que mandarlo se rechaza en lugar de ignorarse: si el
> operador pudiera tipearlo, podría devolver más de lo que entró, que es este hallazgo
> con otra forma. Es la parte proporcional de lo que vuelve, con su parte proporcional
> del descuento —si no, devolver de a poco saldría más caro que devolver todo junto— y
> con una excepción: la devolución que **completa** la venta devuelve lo que quedó
> cobrado y no la cuenta proporcional, para que el libro cierre en cero exacto y no en
> un centavo por el redondeo de cada parte.
>
> **El estado también se deriva**, de comparar `cantidad_devuelta` contra `cantidad` en
> cada línea: `devuelta_parcial` mientras quede algo, `devuelta` cuando no quede nada.
> No lo elige el operador, y `estado` está declarado `prohibited` en el formulario. Una
> venta en `devuelta_parcial` sigue admitiendo devoluciones —`devuelta_parcial →
> devuelta_parcial` está declarado a propósito—, que es el caso de quien devuelve el
> mouse en enero y la fuente en marzo. Y `cantidad_devuelta` es un **contador** y no una
> bandera: sin él, alguien devuelve dos unidades tres veces y se lleva seis.
>
> **El medio por el que vuelve la plata sí lo elige quien devuelve**, y es la única
> decisión de la devolución que no se deriva. Una venta cobrada por transferencia se
> puede devolver en efectivo de la caja, y escribir un negativo en transferencia
> afirmaría un hecho que no ocurrió: cómo volvió la plata es un dato del mundo que el
> sistema conoce sólo si se lo dicen. Es el mismo argumento con el que la Fase 5 decidió
> no marcar una orden como «enviada» sin un SMTP que lo respalde. La pantalla propone el
> medio del cobro más grande y deja cambiarlo. La consecuencia es que el neto de un
> medio puede quedar negativo en un período, y eso es correcto: es lo que un libro de
> caja tiene que poder decir.
>
> Queda afuera la **nota de crédito**, que es el documento fiscal de la devolución y es
> de la Etapa 2. En la Etapa 1 la devolución mueve `cantidad_devuelta`, repone stock por
> el kardex, escribe el contra-asiento y cambia el estado; el comprobante llega con
> AFIP, y `comprobantes` y `comprobante_lineas` ya están justificadas en
> `modelo-datos.md` para que la NC pueda llevar su propio detalle y cubrir sólo parte de
> la venta.

### A-12 · Descuento sin control por rol
`descuentoPorcentaje` lo fija el cliente y se valida sólo `0 ≤ pct ≤ 100`. Cualquier vendedor puede cargar 100% de descuento. En el dump ya hay una venta al 50% (`id 19`). No hay tope por perfil ni flujo de autorización.

> **Cerrado** — Fase 6. El vendedor carga un **porcentaje**, que es como se negocia un
> descuento, y el sistema calcula y guarda el **monto** en `ventas.descuento`, que es
> lo que la columna es. El porcentaje se recupera derivándolo del monto cuando la
> pantalla lo necesita.
>
> El tope depende del permiso: sin `venta.autorizar_descuento` rige
> `config('venta.descuento.tope_general')` —10 %—, con él `tope_autorizado` —30 %—, y
> **el 100 % no se alcanza por ningún camino**. De los cinco roles de sistema sólo el
> Administrador tiene ese permiso; el Vendedor, que es quien cargaba el 50 % de la
> venta 19 del dump, no.
>
> Se valida en **dos lugares a propósito**: en `VentaRequest`, para que el error caiga
> en el campo y `old()` conserve el cliente, las observaciones y todas las líneas que
> el usuario ya había cargado; y en `VentaService`, que es la barrera que va a heredar
> la API de la Etapa 3. Los dos leen el mismo número por el mismo camino
> —`VentaService::topeDeDescuento()`—, y la ayuda del campo en la pantalla sale de ese
> mismo método, así que los tres no se pueden desincronizar.
>
> El tope vive en configuración y no escrito en el código porque es un parámetro del
> negocio: el dueño puede querer moverlo sin que nadie recompile nada, que es el mismo
> argumento por el que los permisos están en la base.
>
> Queda sin hacer, y anotado en los pendientes con su motivo, el **flujo de
> autorización** que el hallazgo también nombra. Hoy el vendedor que necesita más
> recibe un mensaje con el tope y alguien con el permiso tiene que cargar la venta. Un
> flujo de solicitud y aprobación es un módulo, no un campo.

### A-13 · Stock sin libro de movimientos
`productos.stock` es una columna mutable que se pisa con `UPDATE productos SET stock = stock - :cant`. No hay kardex. No se puede: auditar quién movió qué, reconstruir el stock a una fecha, distinguir una venta de un ajuste o de una recepción de mercadería, ni implementar reservas.

Esto es bloqueante para los requerimientos 5 (carrito), 9 (venta online) y 11 (compra automática): sin reserva de stock, dos clientes compran la última unidad.

> **Cerrado** — Fase 5. `movimientos_stock` es el kardex: un asiento por
> movimiento, con `tipo` (`venta`, `devolucion`, `compra`, `ajuste`,
> `reserva_liberada`), la cantidad **con signo**, el `stock_resultante`, el
> usuario, el motivo y un `origen` polimórfico que apunta a la venta o a la orden
> de compra que lo produjo. El índice `(producto_id, created_at)` es el que
> permite reconstruir el stock a una fecha, que era una de las cuatro cosas que
> el hallazgo decía que no se podían hacer.
>
> Las otras tres las cierra `StockService`, que es **la única puerta** por la que
> se mueve el stock: `descontar()`, `reponer()`, `recibirCompra()` y `ajustar()`.
> `stock`, `stock_reservado` y `costo_promedio` están fuera de `$fillable`, así
> que ningún formulario ni ningún `update()` masivo los puede tocar, y el método
> que escribe la fila del kardex es **privado**: no hay forma de registrar un
> movimiento sin mover el stock, que sería un asiento que afirma algo falso, ni
> de mover el stock sin registrarlo.
>
> El requerimiento 11, que el hallazgo nombraba como bloqueado, está
> implementado: `compras:generar-reposicion` compara el **disponible** contra el
> stock mínimo y deja una orden en borrador por proveedor. Pudo escribirse
> justamente porque el kardex existe: sin él, «stock disponible» no era un
> número confiable.
>
> Queda afuera a propósito la **reserva**: la columna `stock_reservado` y el tipo
> `reserva_liberada` ya existen y todo lo que lee stock lo hace sobre el
> disponible —`stock - stock_reservado`—, pero quién reserva y cuándo se libera
> es parte del flujo de la venta, y eso es la Fase 6. El hallazgo señalaba que
> sin reservas dos clientes compran la última unidad; la mitad estructural está
> hecha, la mitad del flujo tiene su fase.

### M-14 · Precio recalculado al editar un presupuesto
`resolverPreciosYTotales()` relee el precio desde `productos` en cada `save` **y** en cada `update`. Un presupuesto emitido cambia de total si el precio del producto cambia antes de confirmarlo. `detalle_ventas.precio_unit` guarda el precio congelado, pero `SaleDao::update()` borra todas las líneas y las reinserta con el precio nuevo.

Nota positiva: **releer el precio del servidor y nunca confiar en el que manda el cliente es correcto** y hay que conservarlo. Lo que falta es distinguir "cotizar" de "recotizar".

> **Cerrado** — Fase 6. Lo que el original hacía bien se conservó igual: el precio se
> lee de la base y **nunca** del formulario. Y se endureció: `VentaRequest` declara
> `lineas.*.precio_unitario` como `prohibited`, así que mandarlo a mano no se ignora en
> silencio sino que se rechaza con un mensaje, y `venta_lineas` tiene en `$fillable`
> sólo `producto_id` y `cantidad` —los seis campos congelados los escribe el servidor
> por asignación directa—. Tres barreras, y la única que informa es la de arriba:
> descartar en silencio un dato que alguien mandó es la doctrina de M-31 al revés.
>
> Lo que faltaba era distinguir **cotizar** de **recotizar**, y ahora son dos
> operaciones distintas:
>
> - **`crear()` cotiza**: la línea nace con el precio, la alícuota y el costo del
>   momento.
> - **`actualizar()` ajusta las líneas en lugar de reemplazarlas.** La que ya estaba
>   conserva su `precio_unitario` y sólo recalcula los importes si cambió la cantidad;
>   la que entra se cotiza al precio de hoy; la que salió del pedido se borra. Es el
>   contrapunto exacto de `CompraService::actualizarBorrador()`, que **sí** borra y
>   reinserta, y la diferencia está escrita en el docblock de ese método: la misma
>   técnica está bien o mal según si la fila tiene estado propio. La línea de compra no
>   lo tiene, porque el formulario carga el costo; la de venta sí —su precio congelado,
>   que no está en ningún otro lado—. `SaleDao::update()` borraba y reinsertaba
>   releyendo el precio, y eso **es** este hallazgo.
> - **`recotizar()` es una acción explícita**, con su botón, su confirmación y su
>   permiso, que relee todos los precios y **devuelve qué cambió**: producto por
>   producto, de cuánto a cuánto, y el total viejo contra el nuevo, para que la
>   pantalla lo informe antes de que el presupuesto vuelva al cliente. Conserva el
>   **porcentaje** de descuento y no el monto, así que un 10 % sigue siendo un 10 %
>   sobre el subtotal nuevo.
>
> Recotizar no estaba mal; recotizar sin que nadie lo pidiera ni se enterara, sí.
>
> El test que fija el hallazgo afirma que la línea conserva el **mismo id** después de
> editar, y no sólo el mismo precio: si el producto no cambió de precio, el precio
> coincidiría por casualidad aunque la fila se hubiera borrado y reinsertado. El id no.

### M-15 · `moverStock` en modo reponer no bloquea la fila
El modo `descontar` hace `SELECT ... FOR UPDATE`; el modo `reponer` va directo al `UPDATE`. Inconsistente.

> **Cerrado** — Fase 5. Los cuatro métodos públicos de `StockService` empiezan
> igual: `DB::transaction` y `Producto::lockForUpdate()->findOrFail()`. No hay un
> modo que bloquee y otro que no, porque el bloqueo no es del modo sino de la
> fila: dos peticiones que tocan el mismo producto se serializan sea para
> descontar, para reponer, para recibir mercadería o para ajustar inventario.
>
> La inconsistencia del original venía de pensar el lock como protección del
> **negocio** —«vender de menos es grave, reponer de más no»— y no de la fila. Y
> era falsa incluso en sus propios términos: dos reposiciones simultáneas sin
> lock pierden una, porque las dos leen el mismo stock previo y la segunda
> escribe sobre la primera. El `stock_resultante` del kardex lo deja a la vista,
> y los tests del servicio lo verifican buscando `for update` en el log de
> consultas.

### M-16 · Borrado físico de documentos financieros
`SaleDao::delete()` es un `DELETE` plano y `detalle_ventas` tiene `ON DELETE CASCADE`. Borrar una venta borra su historial completo. Lo mismo en productos y usuarios. Con facturación real esto es inadmisible: una vez emitido un comprobante, nada se borra.

> La política ya rige en los módulos que existen: el catálogo y los proveedores se
> desactivan si algo los referencia, las cuentas de personal con historial también, y
> un cliente referenciado rechaza la baja con un motivo.
>
> El núcleo del hallazgo —el borrado físico de un documento financiero— lo cerró la
> Fase 6, y la primera mitad puso la parte estructural: **no hay forma de borrar una
> venta**. No existe ruta, ni método de controlador, ni método de servicio que lo haga,
> y el modelo `Venta` tira `LogicException` en el evento `deleting`, igual que
> `MovimientoStock`. Es `LogicException` y no una excepción de negocio porque ninguna
> pantalla ofrece borrar: si eso se dispara, lo que hay es un error de programación.
>
> La guarda tiene un límite que conviene saber, porque es parte de entenderla: un
> borrado masivo por el query builder no dispara eventos de modelo. Lo que garantiza de
> verdad es que nada lo ofrece; la guarda ataja el `$venta->delete()`, que es la única
> forma en que alguien lo escribiría por accidente. Las cascadas `ON DELETE CASCADE` de
> `venta_lineas` y `pagos` siguen declaradas en el esquema y **no se alcanzan nunca**,
> porque no se puede borrar el padre.
>
> Lo que reemplaza al borrado es el estado. Un presupuesto que no se concreta queda
> `cancelada`, con sus líneas, su total y su número intactos, y el mensaje de la
> pantalla lo dice con palabras: «la venta no se borra, queda registrada».
>
> **Cerrado** — Fase 6. La segunda mitad completó las dos piezas que faltaban.
>
> La primera es el modelo **`Pago`**, que nace con la misma guarda que `Venta` y
> `MovimientoStock`: el evento `deleting` tira `LogicException`. Es `LogicException` y
> no una excepción de negocio por el mismo motivo que en los otros dos: ninguna pantalla
> ofrece borrar un pago, así que si eso se dispara lo que hay es un error de
> programación. Las tres guardas tienen su test, y las tres comparten el mismo límite,
> que conviene saber: un borrado masivo por el query builder no dispara eventos de
> modelo. Lo que garantizan de verdad es que nada lo ofrece; la guarda ataja el
> `$pago->delete()`, que es la única forma en que alguien lo escribiría por accidente.
>
> La segunda es el **camino de después de cobrar**, que era lo que el hallazgo dejaba
> sin respuesta: una venta cobrada no se borra ni se anula, **se devuelve**. Y la
> devolución no corrige nada —ni la venta, ni sus líneas, ni el pago original—: suma
> hechos. Incrementa `cantidad_devuelta`, escribe el asiento de reingreso en el kardex y
> le pone al cobro un pago en contra. Después de una devolución total, la ficha sigue
> mostrando el documento completo, el cobro original y su reversión al lado, y los
> importes de la venta sin tocar: lo devuelto se lee en `cantidad_devuelta` y en los
> contra-asientos, nunca modificando lo que el documento declaró.
>
> No hay ruta, ni método de controlador, ni método de servicio que borre una venta, una
> línea de venta o un pago. Las cascadas `ON DELETE CASCADE` de `venta_lineas` y `pagos`
> siguen declaradas en el esquema y **no se alcanzan nunca**, porque no se puede borrar
> el padre. No hay que quitarlas: hay que saber que están.

---

## 3. Modelo de datos

### A-17 · Dinero en punto flotante
`productos.precio` es `float(12,2)`. El resto del esquema usa `decimal(12,2)` correctamente. `float` acumula error de redondeo: un producto a 435345.00 se lee como `435344.99999...` según el caso. Todo importe debe ser `decimal` (o entero en centavos).

> **Cerrado** — Fases 1 y 3. Toda columna monetaria del esquema es `decimal(12,2)`,
> y el test «ninguna columna usa punto flotante» lo verifica consultando
> `information_schema` sobre MariaDB: no es una revisión a ojo de las migraciones,
> es una pregunta a la base real, y por eso las pruebas no corren en sqlite. Los
> modelos castean los importes a `decimal:2` y los enteros a `integer`.
> `App\Support\Importe` traduce el formato argentino —"1.500,50" ↔ "1500.50"— y
> rechaza lo ambiguo en lugar de adivinar: leer "1.500" como 1.5 guardaría un
> producto de mil quinientos pesos a uno con cincuenta sin reportar ningún error,
> que es donde este hallazgo se cruza con M-31.

### A-18 · Numeración de ventas frágil
- `ventas.numero` **no tiene índice UNIQUE**. Nada impide duplicados.
- `venta_numeracion` es una tabla de una sola fila **sin clave primaria**. `SELECT numero FROM venta_numeracion FOR UPDATE` sin `WHERE` funciona por accidente.
- Un solo contador global. Con facturación AFIP vas a necesitar numeración **por punto de venta y por tipo de comprobante**, correlativa y sin huecos.
> **Cerrado** — Fase 6. **El número de venta es el `id`**, formateado `V-00042`. No hay
> columna `numero` y no hay tabla de numeración: la clave primaria garantiza unicidad
> por construcción, sin contador, sin tabla auxiliar de una sola fila y sin condición
> de carrera. Los tres problemas que el hallazgo denuncia —`ventas.numero` sin índice
> único, `venta_numeracion` sin clave primaria, y un `SELECT ... FOR UPDATE` sin
> `WHERE` que funcionaba por accidente— desaparecen porque desaparece el mecanismo, no
> porque se lo haya arreglado.
>
> Es el mismo criterio que ya se había aplicado a `ordenes_compra` en la Fase 5, y la
> razón es la misma: **ninguno de los dos es un comprobante fiscal**. No los gobierna
> AFIP y no necesitan ser correlativos sin huecos. El formato lo arma
> `Venta::numeroDe()`, que es estático para que el kardex pueda nombrar el documento de
> origen sin cargarlo, y así la ficha y el kardex dicen `V-00042` igual.
>
> La cuarta parte del hallazgo —numeración **por punto de venta y por tipo de
> comprobante**, correlativa y sin huecos— es un requisito de la facturación y vive
> donde corresponde: la tabla `comprobantes`, con `UNIQUE (tipo, punto_venta, numero)`,
> en la Etapa 2. La fuente de verdad de ese número es AFIP, que se consulta con
> `FECompUltimoAutorizado` antes de cada emisión, y el UNIQUE local es la red de
> contención. Está justificado en `modelo-datos.md`, sección `comprobantes`.

### M-19 · Sin trazabilidad temporal
Ninguna tabla tiene `created_at` / `updated_at` / `created_by`. `usuarios.fechaAlta` es lo único, y es `date` (sin hora). Para el dashboard del requerimiento 14 y para cualquier auditoría, esto hace falta en todas las tablas.

> **Cerrado** — Fase 1. Las diecisiete tablas llevan `created_at` y `updated_at`, y
> el test «toda tabla del dominio tiene marcas de tiempo» recorre la lista completa
> y falla nombrando la que falte. No hay un `created_by` general, y es a propósito:
> la autoría se guarda donde la pregunta aparece y con el nombre de lo que esa
> persona hizo —`movimientos_stock.usuario_id` responde quién ajustó el stock,
> `ordenes_compra.usuario_creo_id` y `usuario_aprobo_id` separan quién pidió de
> quién autorizó, `ventas.usuario_id` quién vendió y `pagos.usuario_id` quién
> cobró—. Una columna genérica en cada tabla diría menos.
> 
### M-20 · El modelo de permisos no llega
`permisos` son 4 flags CRUD por (perfil, módulo). No modela acciones que no son CRUD — cobrar, anular, autorizar descuento, aprobar orden de compra, emitir nota de crédito. El fallback a `can_update` (C-2) es el síntoma de esa limitación, no la causa.

Con el nuevo esquema de perfiles (cliente / administrador / empleado con subtipos vendedor-cajero-administrativo + proveedor), un modelo de flags por módulo se vuelve inmanejable. Corresponde pasar a permisos nombrados (`sale.void`, `purchase_order.approve`, `discount.override`).

> **Cerrado** — Fases 1 y 2. Los permisos son claves nombradas en una tabla
> (`venta.anular`, `compra.aprobar`, `stock.ajustar`), no cuatro banderas por
> módulo, así que una acción que no es CRUD tiene su propio permiso y no hereda
> nada de nadie. `RolPermisoSeeder` es la fuente de verdad y aborta con excepción
> si una clave no existe: un permiso mal escrito rompe el seeder en lugar de
> concederse o ignorarse en silencio. La tabla `modulos` desapareció, y con ella el
> acoplamiento entre el routing y los datos.

### M-21 · `resetPass` bloquea sin salida
`AuthenticationService` rechaza el login si `resetPass != 0` con "Su clave ha caducado", pero no hay endpoint público para restablecerla. El usuario queda bloqueado hasta que un admin lo destrabe. Falta el flujo de recuperación por email — que con clientes reales pasa a ser obligatorio.

> **Cerrado** — Fase 4, en dos mitades. Quien recuerda su contraseña la cambia
> en `/cuenta/password`, una ruta sin ningún permiso: exigirlo dejaría sin
> salida a un rol al que se le olvidara asignarlo, que es el hallazgo otra vez.
> Quien la olvidó recibe de un administrador un enlace de un solo uso
> (`usuario.resetear_password`), con el token hasheado por el broker de Laravel
> y vencimiento de una hora. El administrador nunca conoce la contraseña, así
> que no puede operar el sistema con la identidad de otro. La recuperación por
> correo sin intervención de un administrador queda para la Etapa 2, cuando
> haya SMTP; las dos rutas públicas del flujo ya existen y se llaman
> `password.reset` y `password.store`, los nombres que la notificación
> `ResetPassword` de Laravel tiene escritos.

### M-22 · Faltan campos que los nuevos requerimientos exigen
El catálogo actual (`nombre, codigo, descripcion, categoriaId, precio, stock`) no soporta:
- imágenes (requerimiento 5, 9 — una tienda sin fotos no vende),
- peso y dimensiones (requerimiento 18 — Zipnova los necesita para cotizar),
- costo de compra (requerimiento 14 — sin costo no hay margen),
- alícuota de IVA (requerimiento 8 — sin alícuota no hay factura),
- stock mínimo / punto de reposición (requerimiento 11),
- marca, atributos técnicos, variantes.

`categorias` es una lista plana (`id`, `nombre`), sin jerarquía ni orden ni imagen.

> **Cerrado** — Fases 1 y 3. De los seis puntos, cuatro entraron al esquema y se
> cargan desde la pantalla: imágenes (`productos.imagenes`, con vista previa y
> orden por arrastre), costo de compra (`costo_promedio`, que recalcula la
> recepción de mercadería), alícuota de IVA, y stock mínimo con cantidad de
> reposición. La marca pasó a ser tabla propia, y `categorias` dejó de ser una
> lista plana: tiene `parent_id`, `orden` y `peso_default_gramos`.
>
> Los otros tres quedaron fuera del alcance de la Etapa 1 a propósito, cada uno
> con su motivo escrito:
>
> - `peso_gramos` y `destacado` **son columnas del esquema** desde la Fase 1, pero
>   no se exponen en el formulario: sirven a la cotización de envíos y al destaque
>   de la tienda, que son de la Etapa 2. Está anotado en el docblock de
>   `ProductoRequest`, y `ProductoService` no las escribe porque enumera los campos
>   uno por uno.
> - Las **dimensiones** no se crearon como columnas, y es la única parte del
>   hallazgo que no está en la base. El motivo está en `modelo-datos.md`, sección
>   `productos`: son tres columnas nullables, baratas de agregar cuando exista el
>   módulo que las usa.
> - **Variantes y atributos técnicos** se descartaron al cerrar el modelo: separar
>   producto de variante duplicaría la complejidad de carrito, stock, órdenes de
>   compra y kardex para ganar sólo una agrupación visual en la tienda, y con un
>   `codigo` por capacidad el control de stock ya es correcto. También en
>   `modelo-datos.md`.

### B-23 · Datos basura en el dump
`productos` contiene `asdasdas` y `afsadgasdgsdfg`; `ventas` tiene clientes `dasdasdasdasd` y `kjkhejkrg`. Sin soft-delete ni validación de contenido, la base de pruebas y la de producción son la misma. La `unique key` sobre `(nombre, categoriaId)` además impide dos productos homónimos de marcas distintas en la misma categoría.

> **Cerrado** — Fases 1 y 3. Los tres problemas que el hallazgo nombra tienen
> respuestas distintas:
>
> - **Los datos basura no pueden volver a entrar por la pantalla.** La validación vive
>   en Form Requests que rechazan la petición entera y explican qué está mal, en lugar
>   de los setters que convertían en cadena vacía lo que no validaba (M-31). Un
>   `asdasdas` sigue siendo un nombre aceptable —ningún sistema puede decidir que un
>   nombre propio es absurdo— pero un producto sin código, sin precio o con un precio
>   ilegible ya no se guarda, y un cliente sin razón social tampoco.
> - **La base de pruebas y la de producción dejaron de ser la misma.** Los datos
>   ficticios los generan seeders y factories, la suite corre sobre una base aparte
>   —`lda_2026_testing`, declarada en `phpunit.xml`— y `migrate:fresh --seed`
>   reconstruye la demostración desde cero. No hay que limpiar basura de la base real
>   porque las pruebas no se cargan ahí.
> - **La `unique key (nombre, categoriaId)`** que impedía dos productos homónimos de
>   marcas distintas en la misma categoría no existe más. La unicidad del catálogo es
>   `productos.codigo`, que es lo que identifica un producto de verdad, y la marca pasó
>   a ser una tabla propia en lugar de un texto dentro del nombre.


---

## 4. Arquitectura y calidad

### A-24 · Filtros que no filtran
Tres desajustes silenciosos entre controller y DAO:

| Controller manda                                | DAO espera                                                 | Efecto                             |
| ----------------------------------------------- | ---------------------------------------------------------- | ---------------------------------- |
| `ItemController::list` → `categoriaId`, `limit` | `codigo`, `nombre`, `categoria`, `stock`, `limit`+`offset` | Ningún filtro se aplica            |
| `CategoryController::list` → `estado`, `limit`  | `nombre`, `limit`+`offset`                                 | `estado` no existe ni como columna |
| `UserController::list` → `nombres`, `limit`     | `perfil_id`, `estado`, `limit`                             | El filtro por nombre se ignora     |

Además `ItemDao::list` y `CategoryDao::list` sólo aplican `LIMIT` si vienen `limit` **y** `offset`, y ningún controller manda `offset` → el límite nunca se aplica.

Que estos bugs convivan con la versión "final" es el indicador más claro de la falta de tests.

> **Cerrado** — Fase 3. Cada filtro del listado es un scope del modelo con el mismo
> nombre que el parámetro de la URL, y el juego completo está declarado además en un
> Form Request de filtros: ya existe un lugar donde consta qué filtros acepta cada
> listado, que es justamente lo que faltaba para que el desajuste tuviera dónde
> detectarse. Un filtro inválido redirige al listado limpio con un aviso, en vez de
> ignorarse. Y hay dos niveles de test por módulo —`{Modulo}FiltrosTest` prueba que
> el scope filtra, `{Modulo}ModuloTest` que el parámetro de la URL llega al scope—,
> porque el bug original era exactamente que la pieza funcionaba y el cableado no:
> un solo nivel no lo habría encontrado en once commits.

### A-25 · Sin paginación
Todos los listados devuelven la tabla completa. Se emite `SQL_CALC_FOUND_ROWS` pero `foundRows()` **nunca se llama** desde ningún service — y es una cláusula deprecada desde MySQL 8.0.17. Con catálogo real y tienda pública esto no sobrevive.

> **Cerrado** — Fase 3. Todo listado usa `paginate(15)` con `withQueryString()`,
> así que la página 2 conserva los filtros en lugar de perderlos. No quedó ningún
> `SQL_CALC_FOUND_ROWS` —cláusula deprecada que además nunca se leía—: el total lo
> da el paginador. Cada módulo tiene un test que verifica el tamaño de página, el
> total y que el enlace a la página siguiente arrastre el filtro.

### A-26 · El dashboard se calcula en el navegador
`HomeComponent` hace `forkJoin` de categorías + productos + ventas + usuarios **completos** y luego cuenta con `.length` y `.filter` en el cliente:
```ts
this.sinStock.set(res.productos.filter(p => p.stock === 0).length);
this.ventasHoy.set(res.ventas.filter(v => v.fecha.slice(0,10) === hoy && v.estado !== 'anulada').length);
```
Descarga toda la base para mostrar cinco números, y de paso expone datos que el usuario no debería ver. El requerimiento 14 (dashboard con métricas por rol) es exactamente donde este patrón hay que reemplazarlo por endpoints de agregación en el servidor.

> La mitad general del hallazgo ya rige: los conteos de los listados se calculan en
> la base con `withCount()` y nunca se traen filas para contarlas en PHP. La otra
> mitad —el panel con métricas por rol— la cierra la Fase 7, que es donde existen
> esas consultas agregadas.

### M-27 · Routing por convención, sin verbos HTTP
El `.htaccess` mapea `^([a-zA-Z]+)/([a-zA-Z]+)/([a-zA-Z0-9]+)$` a `controller/action/id`, y `RouterHandlerMiddleware` hace `ucfirst($controller) . "Controller"` + `method_exists`. El método HTTP nunca se valida: `save` responde a GET igual que a POST. Tres segmentos máximo, ids sólo alfanuméricos, sin rutas anidadas. El router de Laravel resuelve esto de fábrica.

> **Cerrado** — Fase 2. `routes/web.php` declara cada ruta con su verbo, su nombre
> y su permiso. Ya no hay `ucfirst()` sobre un segmento de la URL ni
> `method_exists`, así que `store` no responde a GET, el nombre del controlador dejó
> de ser parte de la URL, y las rutas anidadas —las direcciones de un cliente— se
> escriben sin pelear con el límite de tres segmentos.

### M-28 · Sin inyección de dependencias
Cada método instancia lo suyo: `new SaleService()` en el controller, `new SaleDao(Connection::get())` dentro de cada método del service. `SaleService::resolverPreciosYTotales()` crea su propio `ItemDao`. No hay forma de sustituir dependencias → los services son intesteables sin base de datos real.

> **Cerrado** — Fase 3. Los controladores reciben su servicio por el constructor y
> el contenedor lo resuelve; ningún método hace `new` de su dependencia. Eso es lo
> que vuelve testeable la lógica de negocio sin pasar por HTTP, y es por lo que
> existen los `{Modulo}ServiceTest`: prueban reglas de negocio llamando al servicio
> directo, algo imposible con un `new SaleDao(Connection::get())` escrito adentro de
> cada método.

### M-29 · Contrato de DAO inconsistente
`ItemDao::load()` y `CategoryDao::load()` devuelven `(new Dto($data))->toArray()`; `UserDao::load()` y `SaleDao::load()` devuelven el array crudo de PDO. El DAO a veces conoce el DTO y a veces no. Además `SaleDao` guarda `$lastVentaId` propio porque `BaseDao::getLastInsertId()` es global a la conexión y no sirve tras insertar los detalles.

> **Cerrado** — Fases 1 y 3. No hay DAOs: Eloquent devuelve siempre modelos y los
> servicios devuelven modelos, así que no queda un contrato que cada clase
> interprete a su manera —a veces el DTO, a veces el arreglo crudo de PDO—. El
> `$lastVentaId` propio de `SaleDao` tampoco tiene equivalente: las relaciones de
> Eloquent insertan los hijos sabiendo cuál es el padre.

### M-30 · Excepciones sin tipar
Existen `NotFoundException`, `ValidationException`, `AuthorizationException` — pero `ItemDao`, `CategoryDao`, `UserDao`, `SaleDao` y `ItemService` lanzan `\Exception` plana. `ExceptionHandlerMiddleware` la mapea a **400**, así que "producto no encontrado" responde 400 en vez de 404. El frontend no puede distinguir un error de validación de un recurso inexistente.

> **Cerrado** — Fases 3 y 4. Cada caso tiene su excepción y su código HTTP:
> `findOrFail` devuelve 404, la validación falla con redirect y errores en sesión, y
> `ReglaDeNegocioException` cubre la regla que el usuario violó y puede corregir. Se
> traduce a mensaje en un solo lugar, `bootstrap/app.php`, y por eso no hay un
> `try/catch` en ningún controlador. Nada mapea todo a 400, así que «producto no
> encontrado» ya no se confunde con un error de validación.

### M-31 · Setters que corrompen en silencio
```php
public function setNombre(string $n): void { $this->nombre = (strlen(trim($n)) <= 100) ? trim($n) : ""; }
public function setCorreo(string $c): void { $this->correo = filter_var(...) ? trim($c) : ""; }
```
Un nombre de 101 caracteres se convierte en cadena vacía. Un email inválido se convierte en cadena vacía, y el service después informa *"El correo es obligatorio"* — mensaje que no describe el problema real. La validación pertenece a una capa que pueda **rechazar**, no a un setter que pueda **vaciar**.

Extras del mismo tipo: `ItemDto` usa `precio = 9999999` como default si falta el campo, y trunca `descripcion` a 255 cuando la columna es `TEXT`.

> **Cerrado** — Fase 3. La validación vive en Form Requests, que rechazan la
> petición entera, conservan con `old()` lo que el usuario escribió y explican qué
> está mal; no quedó un solo setter que convierta en cadena vacía lo que no valida.
> `prepareForValidation()` normaliza el formato y no el contenido: saca los guiones
> de un CUIT, pero si hay letras siguen ahí y la regla las rechaza, porque convertir
> "30-ABC" en "30" y guardarlo es este hallazgo exacto. Tampoco quedaron valores por
> omisión que tapen un campo faltante, como el `precio = 9999999` de `ItemDto`, ni
> truncados silenciosos de texto.

### A-32 · Cobertura de tests ≈ 0
- `tests/` del backend son scripts procedurales con `echo` y `require_once '../../app/...'`, **rotos**: referencian `CategoriaDao`, `ProductoDto`, `UsuarioDto`, `PerfilUsuario` — clases renombradas a `CategoryDao`, `ItemDto`, `UserDto`. No compilan.
- Frontend: un solo `.spec.ts`, el generado por el CLI.

Sin tests, los bugs C-9, A-24 y C-1 pasaron once commits sin detectarse.

> Cada fase entrega sus tests y ninguno de los archivos originales sobrevivió: se
> reescribieron de cero con Pest sobre MariaDB. El cierre de este hallazgo es la
> Fase 8, que es donde se revisa la cobertura completa contra esta lista.

### M-33 · Frontend sin control de acceso por rol
`app.routes.ts` aplica sólo `authGuard` (¿hay token vigente?). No hay guard por perfil: cualquier usuario logueado puede navegar a `/user` o `/user/create`; el backend rechaza, pero la UI queda rota. Y el control de visibilidad es un string mágico:
```ts
readonly esAdmin = computed(() => this._payload()?.perfil === 'Administrador');
```
La tabla `permisos` existe en la base pero **el frontend nunca la consulta**: no hay endpoint que devuelva los permisos del usuario. Los permisos son dinámicos en el backend y hardcodeados en el front.

> **Cerrado** — Fase 2. La interfaz se construye con `@can` sobre los mismos
> permisos que protegen las rutas, así que lo que no se puede hacer no se ofrece, y
> el control real sigue estando en la ruta. En ningún lugar del sistema se compara
> el nombre de un rol para decidir algo: para eso está `roles.ambito`, un dato
> explícito que no depende de cómo se llame el rol, así que renombrarlo no rompe
> nada en silencio. El test de cada módulo verifica que quien sólo puede ver no
> recibe los enlaces de alta, edición ni baja.

### M-34 · Sin entornos ni lazy loading
`API_URL` hardcodeado en `api.constants.ts`, sin `environment.ts` → no hay build de producción posible sin editar código. Todas las rutas importan sus componentes de forma eager. `.angular/` (caché de build) y `FRONT IVANSITO.zip` están versionados.

> **Cerrado** — Fases 0 y 1. La configuración por entorno es `.env`, así que no hay
> ninguna URL ni credencial escrita en el código y un build de producción no exige
> editar archivos. `.angular/` y el comprimido versionado por error se excluyeron al
> importar el legacy. El lazy loading no tiene equivalente y no hace falta: con
> Blade cada pantalla es una respuesta del servidor, no hay bundle que partir.

### B-35 · Envelope de respuesta con metadatos de routing
```json
{ "controller": "...", "action": "...", "error": "", "message": "", "result": "" }
```
`controller` y `action` son detalles internos que el cliente no necesita. `result` se inicializa como `""` y según el endpoint devuelve `""`, un objeto o un array → el cliente no puede tipar la respuesta sin defensas. Los errores viajan con HTTP 200 en algunos flujos porque `send()` se llama sin `setStatus`.

> **Cerrado** — Fase 2. No hay envelope: los controladores devuelven vistas y
> redirecciones, y el estado viaja en el código HTTP. Desaparecieron `controller` y
> `action` del cuerpo de la respuesta, y con ellos el `result` que era `""`, un
> objeto o un arreglo según el endpoint. La API de la Etapa 3 va a usar
> `JsonResource`, que tipa la salida, en lugar de un envelope armado a mano.

### B-36 · Logging inexistente
`APP_FILE_LOG_ERRORS` y `APP_FILE_LOG_ACCESS` están definidos pero **nunca se usan**. El `error_log()` del `ExceptionHandlerMiddleware` está comentado. `log.txt` en la raíz es una nota manual de una línea. Ante un 500 en producción no queda rastro.

> **Cerrado** — Fase 1. El logging es el de Laravel: canal `stack` configurado en
> `.env`, y toda excepción no atendida queda en `storage/logs/laravel.log` con su
> traza. Ya no hay constantes de log definidas que nadie usa, ni un `error_log()`
> comentado, ni un `log.txt` escrito a mano. Queda para la Fase 8 bajar `LOG_LEVEL`
> de `debug` a `warning` en producción.
---

## 5. Qué conviene conservar

No todo se tira. Estas decisiones están bien y hay que llevarlas:

1. **Recalcular precios y totales en el servidor**, ignorando lo que manda el cliente (`resolverPreciosYTotales`). Es la defensa correcta contra manipulación de precios; en Laravel se mantiene igual.
2. **Congelar `precio_unit` y `subtotal` en `detalle_ventas`**. La línea de venta guarda el precio del momento, no una referencia al producto. Correcto — sólo falta que el `update` no lo pise.
3. **Pagos como tabla separada con múltiples filas por venta**. Ya soporta pagos parciales y multi-método (la venta 25 tiene transferencia + mercadopago). Es una buena base para Mercado Pago.
4. **Permisos en base de datos, no en código.** La idea de que un administrador ajuste permisos sin deploy es correcta; lo que falla es la granularidad (M-20).
5. **`ExceptionHandlerMiddleware` no filtra detalles de `PDOException` al cliente.** Buen reflejo.
6. **Separación de capas controller → service → DAO** y la disciplina de DTOs. Se traduce a Laravel casi 1:1: Controller → FormRequest → Service/Action → Eloquent → JsonResource.
7. **El flujo `presupuesto → confirmada → cobrada`** es el modelo de negocio correcto. Lo que falta es formalizarlo como máquina de estados con transiciones explícitas.
8. **El `Pipeline` de middlewares** es conceptualmente el middleware stack de Laravel. El concepto se conserva; el código se descarta porque el framework ya lo trae.

---

## 6. Resumen por prioridad

| #    | Hallazgo                                                      | Sev. | Impacto en la migración                                |
| ---- | ------------------------------------------------------------- | ---- | ------------------------------------------------------ |
| C-1  | Hashes de contraseña expuestos en `/user/list` y `/user/load` | C    | Separar DTO entrada/salida desde el día 1              |
| C-2  | Autorización con fallback `can_update`                        | C    | Rediseñar permisos como acciones nombradas             |
| C-3  | Escalada de privilegios vía `perfil_id` en el body            | C    | Autorización a nivel de política, no de módulo         |
| C-9  | Transiciones de estado de venta sin validar                   | C    | Máquina de estados formal, bloqueante para facturación |
| C-10 | Sobrepago por validación fuera de transacción                 | C    | Bloqueante para webhooks de Mercado Pago               |
| A-4  | Secretos y credenciales versionados                           | A    | `.env` desde el inicio                                 |
| A-5  | JWT irrevocable, perfil embebido en el token                  | A    | Sanctum + permisos consultados en cada request         |
| A-11 | Anulación no revierte pagos                                   | A    | Notas de crédito en el nuevo modelo                    |
| A-12 | Descuento del 100% sin autorización                           | A    | Tope por rol + vales controlados                       |
| A-13 | Stock sin kardex ni reservas                                  | A    | Bloqueante para carrito y compra automática            |
| A-17 | `productos.precio` en `float`                                 | A    | Todo `decimal` en el esquema nuevo                     |
| A-18 | `ventas.numero` sin UNIQUE, contador único                    | A    | Numeración por punto de venta y tipo (AFIP)            |
| A-24 | Filtros rotos en tres módulos                                 | A    | Tests desde el inicio                                  |
| A-25 | Sin paginación                                                | A    | Paginación por defecto en toda colección               |
| A-26 | Dashboard calculado en el cliente                             | A    | Endpoints de agregación por rol                        |
| A-32 | Cobertura de tests ≈ 0                                        | A    | Definir mínimo obligatorio                             |
| M-20 | Permisos CRUD insuficientes                                   | M    | Rediseño del modelo de perfiles                        |
| M-22 | Faltan campos para tienda, envíos y facturación               | M    | Entra en el modelado nuevo                             |
