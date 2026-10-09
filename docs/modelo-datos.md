# Modelo de datos — Sistema de gestión + tienda online

25 tablas. Cada sección explica **qué resuelve** y **por qué no se modeló de otra forma**.

Convenciones: todo importe es `decimal(12,2)` (nunca `float`, ver A-17 de la auditoría). Todas las tablas llevan `created_at` / `updated_at`. Los borrados son lógicos (`activo` o `deleted_at`) salvo donde se indique.

---

## 1. Identidad y control de acceso

### `users`
| Campo             | Tipo                | Nota                             |
| ----------------- | ------------------- | -------------------------------- |
| id                | bigint PK           |                                  |
| nombre, apellido  | varchar(100)        |                                  |
| email             | varchar(150) UNIQUE | también es el usuario de login   |
| password          | varchar(255)        | bcrypt                           |
| rol_id            | bigint FK           |                                  |
| activo            | boolean             | controla si puede iniciar sesión |
| email_verified_at | timestamp null      |                                  |

### `roles`
| Campo       | Tipo               | Nota                                                     |
| ----------- | ------------------ | -------------------------------------------------------- |
| id          | bigint PK          |                                                          |
| nombre      | varchar(50) UNIQUE | Administrador, Administrativo, Vendedor, Cajero, Cliente |
| descripcion | varchar(150)       |                                                          |
| ambito      | enum               | `gestion`, `tienda`                                      |
| es_sistema  | boolean            | protege los roles base del borrado                       |

### `permisos`
| Campo       | Tipo               | Nota                                                  |
| ----------- | ------------------ | ----------------------------------------------------- |
| id          | bigint PK          |                                                       |
| clave       | varchar(60) UNIQUE | `venta.anular`, `compra.aprobar`, `producto.eliminar` |
| modulo      | varchar(40)        | agrupador para la pantalla de asignación              |
| descripcion | varchar(150)       | texto que ve el administrador                         |

### `rol_permiso`
`rol_id FK, permiso_id FK` — clave primaria compuesta.

**Por qué vuelven las tablas de permisos.** La consigna de la Etapa 1 pide explícitamente un módulo de *administración de roles y permisos*. Eso es un requisito funcional con pantallas: el administrador tiene que poder ver y modificar qué hace cada rol sin que nadie recompile nada.

**Por qué no se replica el modelo anterior.** El sistema original tenía `perfiles`, `modulos` y `permisos` con cuatro banderas CRUD por par (perfil, módulo). Ese diseño produjo el hallazgo C-2 de la auditoría: toda acción que no fuera crear, leer, actualizar o borrar caía en un `?? "can_update"` por defecto. Un vendedor podía anular ventas cobradas porque anular no era ninguna de las cuatro y heredaba el permiso de actualizar.

La causa de fondo es que el negocio tiene acciones que no son CRUD, y un modelo de cuatro banderas no puede expresarlas. Por eso los permisos ahora son **claves nombradas**: existe `venta.anular` como permiso propio y se otorga o no se otorga. Lo que no está asignado, no se puede hacer. Sin excepciones ni valores por defecto.

**Por qué `ambito` y no comparar el nombre del rol.** El sistema necesita saber si un usuario va a la tienda o al sistema de gestión, y la invariante de `empleados` necesita saber si el rol es de cliente. Resolverlo comparando `rol.nombre === 'Cliente'` sería repetir el error del frontend original, que decidía la visibilidad del menú con un `perfil === 'Administrador'` escrito a mano. Renombrar el rol rompería el sistema en silencio. `ambito` es un dato explícito que no depende de cómo se llame el rol.

**Por qué `es_sistema`.** Como el módulo permite administrar roles, alguien puede borrar el rol Administrador y dejar el sistema sin nadie que lo administre. La bandera impide borrar los cinco roles base.

**Por qué los clientes viven en la misma tabla que los empleados.** La alternativa (tabla separada) obliga a duplicar login, recuperación de contraseña, verificación de mail y sesión. Un solo `users` es menos código y menos superficie de error. La aplicación decide qué mostrar leyendo `roles.ambito`: `tienda` → catálogo y carrito, `gestion` → sistema de gestión (requerimiento 15).

`users` no guarda ningún dato específico de clientes ni de empleados. Es el núcleo de acceso; los datos particulares de cada tipo cuelgan de `clientes` y `empleados`.

### `clientes`
| Campo           | Tipo                  | Nota                                                                 |
| --------------- | --------------------- | -------------------------------------------------------------------- |
| id              | bigint PK             |                                                                      |
| user_id         | bigint FK null UNIQUE | null = cliente de mostrador sin cuenta                               |
| razon_social    | varchar(150)          | nombre o razón social                                                |
| tipo_doc        | enum                  | `dni`, `cuit`, `cuil`                                                |
| nro_doc         | varchar(15)           |                                                                      |
| condicion_iva   | enum                  | `responsable_inscripto`, `monotributo`, `consumidor_final`, `exento` |
| email, telefono | varchar               |                                                                      |

**Por qué está separada de `users`.** Tres razones concretas:

1. Un cliente de mostrador **no tiene cuenta**. Si los datos fiscales vivieran en `users`, cada venta presencial exigiría crear un usuario con contraseña que nadie va a usar.
2. Los datos fiscales (CUIT, condición IVA) sólo aplican a clientes. Meterlos en `users` deja cinco columnas siempre nulas para todos los empleados.
3. `condicion_iva` es la que decide si emitís **Factura A o B**. Es un dato del sujeto de la operación, no de la cuenta de acceso.

`ventas.cliente_id` es nullable: la venta rápida de mostrador a consumidor final no necesita identificar a nadie, y AFIP acepta doc tipo 99 para esos casos.

**Por qué no tiene `activo`.** Es la única excepción del proyecto a "lo que está
referenciado no se borra: se desactiva". La baja lógica existe para lo que
aparece en listas de las que uno elige —marcas, categorías, productos—, donde
hay que dejar de ofrecerlo sin borrar el historial. Un cliente no se elige de un
desplegable: se busca. Esconderlo de un buscador es lo contrario de lo que se
necesita. Así que la baja de un cliente referenciado **se rechaza** con un
motivo, que conserva el dato igual y además lo explica. Si el negocio pidiera
ocultar a quien dejó de comprar, ahí se justifica la columna.

**Con un límite.** AFIP exige identificar al comprador cuando la operación con consumidor final supera cierto monto, y ese umbral se actualiza periódicamente. Conviene guardarlo como parámetro de configuración, no hardcodearlo: si el total supera el valor vigente, la app obliga a cargar los datos del cliente antes de facturar. Hay que confirmar el número vigente al momento de implementar.

### `empleados`
| Campo         | Tipo               | Nota          |
| ------------- | ------------------ | ------------- |
| id            | bigint PK          |               |
| user_id       | bigint FK UNIQUE   |               |
| legajo        | varchar(20) UNIQUE |               |
| dni           | varchar(15)        |               |
| telefono      | varchar(30)        |               |
| fecha_ingreso | date               |               |
| fecha_baja    | date null          | null = activo |

**Por qué existe como tabla y no como columnas en `users`.** Es el mismo argumento que justifica `clientes`: si los datos laborales vivieran en `users`, quedarían nulos en cada fila de cliente — y los clientes van a ser la enorme mayoría del padrón. La simetría es deliberada: `users` guarda credenciales, nombre para mostrar y rol; todo lo demás vive en el satélite que corresponda.

**Por qué el cargo es el rol y no una columna de esta tabla.** La alternativa sería que `users` apuntara a un rol genérico `empleado` y que el cargo concreto viviera acá. No se hizo porque el cargo *es* lo que determina los permisos: un cajero y un vendedor se distinguen exactamente por lo que pueden hacer. Separarlos obligaría a resolver la autorización contra dos tablas distintas y crearía dos lugares donde buscar la misma respuesta.

**Invariante:** el usuario tiene fila en `empleados` si y sólo si su rol es de ámbito `gestion`, y tiene fila en `clientes` si y sólo si es de ámbito `tienda`. Se garantiza en la capa de aplicación, creando ambas filas en la misma transacción.

**`fecha_baja` no reemplaza a `users.activo`.** Son cosas distintas: `activo` controla si puede iniciar sesión, `fecha_baja` es el dato laboral. Un empleado dado de baja conserva su historial de ventas y sigue apareciendo en los reportes del período en que trabajó.

**Por qué no está el CUIL.** Sólo hace falta para la liquidación de sueldos y
las declaraciones a AFIP, que están fuera del alcance de las tres etapas. Para
identificar a la persona y atribuirle operaciones, el DNI alcanza. Si alguna
etapa incorporara liquidación, se agrega como columna nullable de `empleados`
sin tocar ninguna relación, más la validación de que contenga el DNI y de su
dígito verificador.

### `direcciones`
`id, cliente_id FK, calle, numero, piso_depto, codigo_postal, localidad, provincia, es_predeterminada`

Sólo para envíos. Un cliente puede tener varias; la que se usa se **copia** al envío (ver §7), no se referencia.

**Invariante:** si el cliente tiene al menos una dirección, exactamente una
tiene `es_predeterminada`. El esquema no puede expresarlo —un `boolean` con
`DEFAULT false` acepta tres marcadas o ninguna— así que la garantiza
`DireccionService`, igual que la invariante rol↔satélite. Los dos estados
inválidos hacen daño: con tres marcadas el armado de envíos no sabe cuál usar, y
con ninguna no tiene nada que ofrecer. Toda operación bloquea la fila del
**cliente** —no las de las direcciones— para que dos pestañas simultáneas no
dejen dos marcadas, y para que el alta de la primera también se serialice,
cuando todavía no hay ninguna fila de dirección que bloquear.

---

## 2. Catálogo

### `categorias`
`id, parent_id FK null, nombre, slug, orden, peso_default_gramos, activo`

`parent_id` da la jerarquía que pediste (Componentes > Almacenamiento > SSD). Es autorreferencia simple, no árbol anidado: con dos o tres niveles no justifica la complejidad de un nested set.

`peso_default_gramos` resuelve el requerimiento 42 sin relevar producto por producto: Zipnova cotiza con el peso de la categoría salvo que el producto tenga el suyo.

### `marcas`
`id, nombre, slug, logo, activo`

Tabla mínima, existe sólo para filtrar en la tienda.

### `productos`
| Campo                  | Tipo               | Por qué                                             |
| ---------------------- | ------------------ | --------------------------------------------------- |
| id                     | bigint PK          |                                                     |
| categoria_id, marca_id | FK                 |                                                     |
| codigo                 | varchar(30) UNIQUE |                                                     |
| nombre, descripcion    | varchar / text     |                                                     |
| imagenes               | json               | array de rutas                                      |
| **precio_lista**       | decimal(12,2)      | precio con tarjeta / cuotas                         |
| **precio_contado**     | decimal(12,2)      | efectivo / transferencia / QR                       |
| alicuota_iva           | decimal(4,2)       | 21.00, 10.50, 0.00                                  |
| costo_promedio         | decimal(12,2)      | costo ponderado, se recalcula al recibir mercadería |
| stock                  | int                | saldo real en depósito                              |
| stock_reservado        | int                | comprometido por checkouts en curso                 |
| stock_minimo           | int                | dispara la orden de compra                          |
| cantidad_reposicion    | int                | cuánto pedir cuando se dispara                      |
| peso_gramos            | int null           | si es null, usa el de la categoría                  |
| destacado              | boolean            | destaque manual (requerimiento 54)                  |
| activo                 | boolean            | baja lógica                                         |

**Por qué ya no hay `proveedor_id`.** La tenía, con el criterio «un producto, un
proveedor». Apareció el requerimiento de comprarle a quien tenga el mejor precio,
y un producto con un solo proveedor no puede expresar eso. La columna se eliminó
en la Fase 5 y la reemplaza la pivote `producto_proveedor` (ver §3).
**Por qué dos precios y no una tabla de listas.** Dijiste que el precio es uno solo y que el arreglo mayorista es informal (16), pero también que querés el esquema actual de precio de lista distinto al de contado (38). Eso son exactamente dos números por producto, no dos listas de precios. Dos columnas resuelven el 100% del caso; una tabla `listas_precio` agregaría una entidad y un join a cada consulta de catálogo para modelar lo mismo.

En la tienda online se cobra siempre `precio_lista`: Checkout Pro recibe el monto antes de saber con qué se va a pagar, así que el recargo de tarjeta tiene que estar **adentro** del precio. Ése es el motivo por el que existen dos números y no uno.

**En el mostrador el precio es uno: `precio_contado`.** La versión anterior de este documento decía que ahí el cajero elegía entre los dos, y es falso: en una sucursal el recargo por tarjeta lo aplica el posnet al cobrar, o se arregla informalmente, y el sistema no calcula lo que no controla. Se corrigió en la Fase 6, y la consecuencia es que **la venta de mostrador no tiene modalidad de precio**: no hay nada que elegir, nada que guardar y nada que recordar. Lo único que el mostrador tiene para mover el total es el descuento, con su tope por rol (A-12).

El precio aplicado queda congelado en `venta_lineas.precio_unitario` igual que antes, y es lo que hace reproducible una venta después de un aumento.

**Por qué no hay variantes ni atributos técnicos.** En el bloque anterior te recomendé separar producto de variante. **Cambio esa recomendación** a la luz de tus respuestas: pediste explícitamente no explotar el modelo, y descartaste las fichas técnicas (15). Separar producto/variante duplica la complejidad de carrito, stock, órdenes de compra y kardex para ganar sólo una agrupación visual en la tienda ("mismo SSD, tres capacidades"). Con un `codigo` por capacidad ya tenés el control de stock correcto.

Si más adelante querés agrupar, se agrega una columna nullable `grupo_id` y una tabla `grupos_producto`. No requiere migrar datos ni tocar ventas.

**Por qué `stock` y `stock_reservado` como columnas y no una tabla de reservas.** El stock disponible es `stock - stock_reservado`, una resta de dos enteros en la misma fila. Con una tabla de reservas, cada consulta de catálogo necesitaría un `SUM()` sobre reservas vigentes — costoso y con riesgo de contar reservas vencidas. Un job que libera los checkouts expirados mantiene la columna al día.

**Por qué `costo_promedio` vive acá.** El dashboard necesita margen (requerimiento 14) y sin costo no hay margen. Promedio ponderado es el método más simple que da un número correcto: al recibir mercadería, `costo_nuevo = (stock × costo_actual + cantidad × costo_compra) / (stock + cantidad)`.

### `movimientos_stock`
`id, producto_id FK, tipo, cantidad (con signo), stock_resultante, origen_type, origen_id, usuario_id FK null, motivo, created_at`

`tipo`: `venta`, `devolucion`, `compra`, `ajuste`, `reserva_liberada`.

**Por qué existe.** Es el kardex simple que aceptaste (23), y resuelve A-13. Hoy el stock se pisa con `UPDATE productos SET stock = stock - :cant` y no queda rastro de nada. Con esta tabla podés responder "¿por qué este producto tiene 3 unidades?" y "¿quién lo ajustó?", que es la pregunta que aparece siempre.

`origen_type` + `origen_id` es polimórfico (apunta a una venta, un comprobante de NC o una orden de compra). Un solo par de columnas cubre los tres casos sin tres FKs nullables.

**Por qué no hay tabla de recepciones de mercadería.** La recepción parcial (29) se resuelve con `cantidad_recibida` en la línea de la orden de compra, y **el historial de cada recepción ya está acá**: cada entrada genera un movimiento con su fecha y su usuario. La tabla de recepciones sería redundante.

**Por qué no están las dimensiones.** El hallazgo M-22 pedía «peso y dimensiones»
para la cotización de envíos. El peso está —`productos.peso_gramos`, con
`categorias.peso_default_gramos` como respaldo—; las dimensiones no se crearon, y es
una decisión, no un olvido. Sirven únicamente a la cotización de Zipnova, que es de
la Etapa 2, y son tres columnas enteras nullables, sin clave foránea y sin `ENUM`:
agregarlas es un `ADD COLUMN` que no migra datos ni reescribe la tabla. No caen bajo
la regla que obliga a declarar todas las columnas desde el inicio, porque esa regla
existe para no reescribir una tabla por un `ENUM` incompleto, no para prohibir una
columna nullable más adelante.

Hay una pregunta que sí conviene responder **antes** de escribir la cotización de la
Etapa 2: si Zipnova cotiza por peso declarado o por peso volumétrico. Si es
volumétrico, las tres columnas (`largo_mm`, `ancho_mm`, `alto_mm`) hacen falta y
además hay que relevar las medidas producto por producto, que es trabajo de carga de
datos y no de esquema. Si cotiza por peso declarado, no se agregan nunca.

Por el mismo criterio, `peso_gramos` y `destacado` existen en la tabla pero no se
cargan desde el formulario de la Etapa 1: el peso sirve al envío y el destaque a la
vidriera de la tienda, y las dos pantallas son de la Etapa 2. La columna está para no
tocar la tabla después; el campo aparece cuando aparezca su módulo.

---

## 3. Proveedores y reposición automática

### `proveedores`
| Campo                     | Tipo         | Nota                                |
| ------------------------- | ------------ | ----------------------------------- |
| id                        | bigint PK    |                                     |
| razon_social, cuit        | varchar      |                                     |
| email, telefono, contacto | varchar      |                                     |
| **canal_pedido**          | enum         | `email`, `portal_externo`, `manual` |
| portal_url                | varchar null | sólo si canal = portal_externo      |
| plazo_entrega_dias        | int          | informativo                         |
| activo                    | boolean      |                                     |

**Cómo resuelve el problema que planteaste en el punto 3.** Describiste tres realidades: el proveedor grande que sólo opera por su propio portal, el chico que podría entrar al sistema, y el informal al que se le pide por mail o WhatsApp. Darle login a los proveedores implicaría autenticación externa, permisos, y un panel entero — para una minoría de casos, y sin resolver al proveedor grande, que nunca va a usar tu sistema.

La salida es **desacoplar el proceso interno del canal de entrega**. El sistema siempre hace lo mismo: detecta stock crítico → genera la orden de compra en borrador → el administrativo la aprueba. Lo único que cambia es el último paso:

- `email` → el sistema envía el PDF automáticamente y marca la orden como enviada.
- `portal_externo` → la app muestra la orden con el listado listo para copiar y un botón al `portal_url`. El administrativo la carga allá y marca "enviada" a mano.
- `manual` → genera el PDF y un texto plano para pegar en WhatsApp.

Una columna enum reemplaza a un subsistema de autenticación completo, y el proceso interno, las métricas y el control de recepción son idénticos en los tres casos. Si mañana un proveedor chico quiere entrar, se le crea un rol nuevo con sus permisos y se le muestran sus órdenes — sin tocar el modelo.

### `producto_proveedor`

`producto_id FK, proveedor_id FK, costo_ultimo, codigo_proveedor, es_preferido` —
clave primaria compuesta `(producto_id, proveedor_id)`.

**Por qué se abrió un modelo que estaba cerrado.** Es la única excepción a la regla
de no tocar el modelo de datos, y tiene un motivo que la regla misma admite:
apareció un requerimiento que el esquema no podía expresar. `productos.proveedor_id`
decía «un producto, un proveedor»; el negocio necesita comprarle a quien tenga el
mejor precio, comparar entre proveedores antes de pedir, y que la reposición
automática sepa a cuál pedirle. Ninguna de las tres cosas entra en una columna.

**Por qué una pivote con columnas propias y no una tabla de precios.** Lo que hace
falta no es un histórico de precios sino el estado actual del vínculo: cuánto cobró
la última vez, con qué código identifica el producto, y si es el elegido. Son tres
datos por par, no una serie. El histórico ya existe y está en otro lado: cada
`orden_compra_lineas` guarda su `costo_unitario` congelado, así que la evolución del
precio se reconstruye desde las órdenes sin una tabla más.

**Por qué la clave primaria es compuesta.** El vínculo se identifica por sus dos
extremos y no tiene vida propia: no hay nada que pueda apuntar a «el vínculo 47».
Es el mismo criterio de `rol_permiso`. Un `id` propio permitiría dos filas para el
mismo par, que es justamente el estado que no debe existir.

**Por qué `costo_ultimo` y no alcanza `productos.costo_promedio`.**
`costo_promedio` es el promedio ponderado de todas las compras del producto a todos
los proveedores: sirve para el margen de la venta y no es el precio de ninguno.
Comparar proveedores con ese número no compara nada. `costo_ultimo` se graba al
recibir mercadería, desde `CompraService`, con el costo de la línea de esa orden.

**Por qué `es_preferido` y no un orden de prioridad.** La pregunta que el sistema
hace es una sola: a quién le pido. Un orden entre cinco proveedores obliga a
mantener cinco posiciones para responder una pregunta binaria. Y que el preferido
gane sobre el precio es a propósito: a veces el barato entrega en tres semanas, y
eso no es más barato.

**Invariante:** si el producto tiene al menos un proveedor, exactamente uno está
marcado como preferido. El esquema no puede expresarla —un `boolean` con
`DEFAULT false` acepta tres marcados o ninguno— así que la garantiza
`ProductoProveedorService`, igual que `DireccionService` con la dirección
predeterminada. Toda operación bloquea la fila del **producto**, no las de la
pivote, para que dos pestañas no dejen dos marcados y para que el alta del primer
vínculo también se serialice, cuando todavía no hay ninguna fila que bloquear.

**Por qué no tiene `activo`.** Un vínculo no se desactiva: se quita. La baja lógica
existe para lo que aparece en listas de las que uno elige, y un vínculo no se elige
de una lista. Agregar la columna sumaría un estado a filtrar en todas las consultas
de la pivote para resolver algo que ya está resuelto de otra forma: desvincular se
**rechaza** mientras haya un pedido en curso de ese par, y el dato que sólo vivía
acá —`codigo_proveedor`— se congela en la línea de la orden al escribirla, así que
quitar el vínculo no cambia ningún documento ya emitido.

**Cómo se migró, y por qué así.** Con disciplina expand/contract: primero se agregó
la pivote y las dos estructuras convivieron sincronizadas, después se migraron los
consumidores uno por uno, y al final se eliminó `productos.proveedor_id`. El paso
que suele saltearse es el último, y saltearlo deja dos fuentes para la misma
pregunta.

### `ordenes_compra`
`id, proveedor_id FK, estado, total_estimado, usuario_creo_id, usuario_aprobo_id null, fecha_aprobacion null, fecha_envio null, observaciones`

`estado`: `borrador` → `aprobada` → `enviada` → `recibida_parcial` → `recibida`, más `cancelada`.

**Por qué `borrador` y no envío directo.** Lo aceptaste en el punto 27, pero vale dejar el motivo escrito: una compra que se dispara y se envía sola es un compromiso de plata sin supervisión. Si un ajuste de stock mal cargado deja un producto en cero, el sistema pide mercadería que no hace falta. El borrador convierte el automatismo en una sugerencia.

**Por qué el número de orden es el `id` y no una columna propia.** El proveedor se
refiere al pedido por un número, y el PDF lo imprime como `OC-00042`, derivado del
`id` con relleno de ceros. No se agrega una columna `numero`: la orden de compra no
es un comprobante fiscal —no la gobierna AFIP, no necesita ser correlativa sin
huecos— y la clave primaria ya garantiza unicidad por construcción, sin contador,
sin tabla auxiliar y sin condición de carrera. Es la lección de A-18 aplicada: el
sistema original tenía `venta_numeracion`, una tabla de una sola fila sin clave
primaria, para generar un número que `ventas.numero` tampoco garantizaba único.

**Por qué el PDF no se archiva.** El documento se regenera a demanda desde la
orden, y no se guarda el archivo. Es posible porque **el PDF imprime el pedido, no
el estado de la orden**: lleva `cantidad_pedida` y `costo_unitario`, nunca
`cantidad_recibida`. Las líneas quedan congeladas cuando la orden deja de ser
borrador, y lo único que cambia después es la cantidad recibida, que el documento
no muestra — así que el PDF regenerado dentro de un año es idéntico al que recibió
el proveedor. Guardar el archivo traería los problemas de siempre con los binarios:
una fila que puede apuntar a un archivo inexistente, backups más pesados y una
copia que puede divergir del dato.

**Por qué no hay `usuario_envio_id`.** Marcar la orden como enviada lo hace una
persona, no el sistema, y la tabla no registra quién. Se decidió no agregar la
columna: la orden de compra no es un documento fiscal, el responsable del
compromiso es quien la aprueba —y eso sí queda en `usuario_aprobo_id` con su
fecha—, y la acción de marcarla enviada se exige con el mismo permiso que
aprobarla (`compra.aprobar`), así que quien la marca es necesariamente alguien con
esa autoridad. `fecha_envio` responde el cuándo. Si el negocio pidiera separar las
dos responsabilidades, se agrega la columna nullable más el permiso `compra.enviar`,
sin tocar ninguna relación.

**El disparador de reposición** es un job diario: productos con `stock - stock_reservado <= stock_minimo` que no tengan ya una orden abierta, agrupados por el proveedor que `Producto::proveedorParaReponer()` elige para cada uno —el preferido, y si no hay ninguno marcado, el de menor `costo_ultimo` entre los activos—, generan una orden en borrador con `cantidad_reposicion` por línea. Fijo, como pediste (26). Un producto que se repone y no tiene ningún proveedor activo no se puede pedir: el comando lo informa en lugar de omitirlo en silencio, y el listado del catálogo lo marca.

### `orden_compra_lineas`
`id, orden_compra_id FK, producto_id FK, cantidad_pedida, cantidad_recibida (default 0), costo_unitario`

`cantidad_recibida < cantidad_pedida` es la recepción parcial. Cuando todas las líneas se completan, la orden pasa a `recibida`. Cada recepción genera un movimiento de stock y recalcula `costo_promedio`.

**El disparador de reposición** es un job diario: productos con `stock - stock_reservado <= stock_minimo` que no tengan ya una orden abierta, agrupados por `proveedor_id`, generan una orden en borrador con `cantidad_reposicion` por línea. Fijo, como pediste (26).

el proveedor grande que sólo opera por su propio portal, el chico que
podría entrar al sistema, y el informal al que se le pide por mail o WhatsApp.
Darle login a los proveedores implicaría autenticación externa, permisos y un
panel entero — para una minoría de casos, y sin resolver al proveedor grande, que
nunca va a usar tu sistema.

La salida es **desacoplar el proceso interno del canal de entrega**. El sistema
siempre hace lo mismo: detecta stock crítico → genera la orden de compra en
borrador → el administrativo la aprueba → la orden aprobada produce un **PDF
formal** del pedido. Una columna enum reemplaza a un subsistema de autenticación
completo, y el proceso interno, las métricas y el control de recepción son
idénticos en los tres casos.

**`canal_pedido` es un dato del proveedor, no una bandera de control.** No
ramifica código: la orden aprobada genera siempre el mismo PDF, lo descarga una
persona y es esa persona la que lo hace llegar. El canal es lo que la pantalla usa
para decir cuál es el próximo paso, y de lo que depende que se muestre
`portal_url`:

- `email` → «Mandale el PDF a `ventas@…`», con el correo a la vista.
- `portal_externo` → «Subí el PDF a su portal», con el enlace a `portal_url`.
- `manual` → «Pasale el PDF por teléfono o WhatsApp», con el teléfono.

**Por qué el sistema no manda el correo.** Se evaluó que el canal `email`
disparara el envío automático y marcara la orden como enviada. Se descartó por una
razón que no es de comodidad: sin un servidor SMTP —y en la Etapa 1 no hay uno—,
el correo va a un archivo de log y la orden quedaría marcada como «enviada» con el
proveedor sin enterarse de nada. El sistema estaría afirmando un hecho del mundo
que no puede verificar, y la regla del proyecto es que el resultado dice lo que
pasó de verdad. Además elimina un modo de falla sin buena salida: un SMTP que
rechaza el mensaje deja la orden en un estado que nadie sabe si hay que reintentar.

El ENUM conserva sus tres valores en la base, así que si la Etapa 2 vuelve a
automatizar el envío no hace falta migración. Y si mañana un proveedor chico
quiere entrar al sistema, se le crea un rol con sus permisos y se le muestran sus
órdenes — sin tocar el modelo.

---

## 4. Carrito

### `carritos`
`id, user_id FK null, session_token varchar null, estado, reservado_hasta timestamp null`

`estado`: `activo`, `en_checkout`, `convertido`, `abandonado`.

### `carrito_lineas`
`id, carrito_id FK, producto_id FK, cantidad` — UNIQUE(carrito_id, producto_id)

**Por qué el carrito no es una venta en estado "carrito".** Dijiste que el cliente navega y arma el carrito sin cuenta (5). Si el carrito fuera una venta, la tabla de ventas se llenaría de carritos abandonados de visitantes anónimos — miles de filas basura mezcladas con documentos comerciales, y todas las métricas del dashboard tendrían que filtrarlas. Separarlo mantiene `ventas` limpia: una fila ahí significa que alguien decidió comprar.

`session_token` permite el carrito anónimo. Al registrarse o iniciar sesión en el checkout, el carrito se asocia al `user_id`.

**La reserva de stock** ocurre al pasar a `en_checkout`: se suma la cantidad a `productos.stock_reservado` y se fija `reservado_hasta = now() + 20 min`. Un job libera los vencidos. Es lo que evita que dos clientes compren la última unidad — el problema que hoy no está resuelto porque el stock recién se descuenta al confirmar.

**El carrito no guarda precios.** Se recalculan contra `productos` al mostrar y al cerrar el checkout. Congelar el precio al agregar al carrito significa vender a un precio viejo si el carrito quedó tres días abierto.

---

## 5. Ventas

### `ventas`
| Campo         | Tipo         | Nota                            |
| ------------- | ------------ | ------------------------------- |
| id            | bigint PK    |                                 |
| canal         | enum         | `mostrador`, `online`           |
| cliente_id    | FK null      | null = consumidor final anónimo |
| usuario_id    | FK null      | vendedor; null en ventas online |
| estado        | enum         | ver máquina de estados          |
| modo_entrega  | enum         | `retiro`, `envio`               |
| vale_id       | FK null      |                                 |
| subtotal      | decimal      | suma de líneas                  |
| descuento     | decimal      | monto del vale                  |
| costo_envio   | decimal      | 0 si retira                     |
| total         | decimal      |                                 |
| observaciones | varchar null |                                 |

**Por qué no hay columna `numero`.** El número de venta es el `id`, formateado
`V-00042`, por el mismo criterio que las órdenes de compra: la venta de la Etapa 1 no
es un comprobante fiscal, no la gobierna AFIP y no necesita ser correlativa sin
huecos, y la clave primaria ya garantiza unicidad por construcción —sin contador, sin
tabla auxiliar y sin condición de carrera—. Es la lección de A-18 aplicada: el sistema
original tenía `ventas.numero` sin índice único y `venta_numeracion`, una tabla de una
sola fila sin clave primaria, para generar un número que tampoco era único. La
numeración fiscal, por punto de venta y tipo de comprobante, vive en `comprobantes` y
la gobierna AFIP (ver §6).

### `venta_lineas`
`id, venta_id FK, producto_id FK, descripcion, cantidad, cantidad_devuelta, precio_unitario, alicuota_iva, costo_unitario, neto, iva, total`

**Por qué se congela tanto en la línea.** `precio_unitario` y `alicuota_iva` congelados es lo único que hace que una venta de hace seis meses siga siendo reproducible después de un aumento. Esto el sistema actual ya lo hacía bien y se conserva.

`costo_unitario` es nuevo: sin él, el margen del dashboard se calcularía contra el costo de hoy, no el del día de la venta, y daría números falsos cada vez que cambia un costo.

`neto` e `iva` se persisten en vez de calcularse al vuelo porque son los importes que se declaran a AFIP. Recalcularlos con una división por `1 + alicuota` en cada consulta introduce diferencias de redondeo de centavos contra lo que ya se declaró.

`cantidad_devuelta` (default 0) habilita la devolución parcial. Es el contador que impide devolver más de lo comprado: la validación es `cantidad_devuelta + cantidad_a_devolver <= cantidad`. Sin esa restricción, alguien devuelve dos unidades tres veces y se lleva seis. Vive en la línea y no en una tabla aparte porque es un saldo, no un evento — el historial de cada devolución está en las notas de crédito.

### Máquina de estados

```
mostrador:  presupuesto → pagada → entregada
                        ↘ cancelada

online:     pendiente_pago → pagada → en_preparacion → despachada  → entregada
                                                     ↘ lista_retiro ↗
                           ↘ cancelada

desde entregada:  → devuelta_parcial → devuelta
                  → devuelta
```

Reglas, todas validadas en el servidor (esto es lo que hoy no existe — hallazgo C-9):

- El stock se descuenta **una sola vez**, al entrar en `pagada`. La reserva del carrito se libera en el mismo movimiento.
- `pendiente_pago → pagada` sólo lo dispara el webhook de Mercado Pago. Nunca el cliente, nunca el redirect del navegador.
- **Nada se borra.** Una venta ya facturada no vuelve atrás: se emite una nota de crédito. Es tu punto 4, y es lo que exige la normativa de facturación.
- `cancelada` sólo desde `presupuesto` o `pendiente_pago`, es decir antes de que exista comprobante y antes de descontar stock. Después de facturar, el camino es la devolución.
- La devolución es **parcial o total según cuánto se devuelva**, no según lo que elija el operador. La venta pasa a `devuelta_parcial` mientras quede algo sin devolver, y a `devuelta` cuando todas las líneas alcanzan `cantidad_devuelta = cantidad`. El estado se deriva de las líneas, no se fija a mano.
- Una venta en `devuelta_parcial` **sigue admitiendo devoluciones**. Es el caso de quien devuelve el mouse en enero y la fuente en marzo.
- **Venta con faltante (backorder): analizada y postergada.** Surgió una pregunta
real del negocio: si el cliente quiere 4 auriculares y hay 3, ¿se le venden los 3
y el cuarto se manda a pedir, en lugar de perder la venta? Y la variante de
mostrador: reservarle un producto sin stock para entregárselo cuando llegue.

No entra en la Etapa 1, y queda escrito qué haría falta, porque la mitad del costo
está en decisiones que la Fase 6 toma igual:

- `venta_lineas` necesita `cantidad_entregada` para distinguir lo entregado de lo
  pendiente. Es un `ADD COLUMN` con default 0, barato en cualquier momento.
- La línea de venta necesita apuntar a la línea de orden de compra que repone esa
  unidad, o cuando la mercadería llega nadie sabe que era de un cliente. FK
  nullable a una tabla que ya existe, también barato.
- `ventas.estado` necesitaba un valor más (`entregada_parcial`), y **eso reescribe la
  tabla**: era la única pieza que encarecía esperar. **Se declaró en la Fase 6**, con
  la tabla todavía vacía, y por eso se pudo poner en su lugar lógico del ENUM —antes
  de `entregada`— en lugar de pegado al final, que es lo único que MariaDB puede hacer
  sin reescribir. `MaquinaEstadosVenta` **no lo alcanza desde ningún origen**, así que
  es un estado declarado e inalcanzable, con su test afirmándolo: el día que alguien
  implemente el backorder, ese test le va a fallar, y eso es exactamente lo que tiene
  que pasar.
- La reserva de mostrador **con seña reabre una decisión ya tomada**: los pagos
  parciales están fuera del alcance. Sin seña es un presupuesto que espera
  mercadería, y eso ya se puede hacer con lo que hay.

La promesa al cliente se puede hacer honesta sin nada nuevo: el plazo sale del
proveedor que `Producto::proveedorParaReponer()` elige →
`proveedores.plazo_entrega_dias`, así que la pantalla puede decir cuántos días, en
lugar de «demora un poco más».

**Por qué `presupuesto` sigue existiendo.** Es el flujo actual de mostrador y funciona. Un presupuesto no tiene valor fiscal: no genera comprobante ni descuenta stock. Se convierte en venta cuando se cobra, o se cancela.

**Por qué una sola tabla para mostrador y online.** Lo confirmaste (31). Son el mismo hecho económico con distinto origen. Duplicarlas obligaría a duplicar líneas, pagos, comprobantes, devoluciones y todas las consultas del dashboard. `canal` es una columna.

### `pagos`
`id, venta_id FK, metodo, monto, comision, neto_acreditado, cuotas, mp_payment_id varchar UNIQUE null, mp_status, usuario_id FK null, fecha`

`metodo`: `efectivo`, `transferencia`, `qr`, `mercadopago`.

**Por qué sigue siendo tabla aparte si eliminaste los pagos parciales.** Por dos motivos que no dependen de eso:

1. **`mp_payment_id` con índice único es el mecanismo de idempotencia.** Mercado Pago reintenta webhooks. Sin esa restricción, un reintento acredita dos veces el mismo pago. Es exactamente el escenario del hallazgo C-10, que en el sistema actual está abierto.
2. `comision` y `neto_acreditado` (39) son datos del pago, no de la venta. Facturás $100.000 pero te acreditan $93.000; el dashboard de ingresos necesita distinguirlos.

`usuario_id` es el cajero que cobró — lo que permite separar "quién vendió" de "quién cobró" en las métricas.

### `vales`
`id, codigo UNIQUE, tipo, valor, monto_minimo, usos_maximos, usos_actuales, vigencia_desde, vigencia_hasta, activo`

`tipo`: `porcentaje`, `envio_gratis`.

Aplicación: uno solo por venta (49), tope global de usos (48), sobre el total de la compra (47). `usos_actuales` se incrementa dentro de la misma transacción que crea la venta, con bloqueo de fila, para que el vale número 101 no se venda por una condición de carrera.

---

## 6. Facturación

### `comprobantes`
| Campo                   | Tipo             | Nota                                                         |
| ----------------------- | ---------------- | ------------------------------------------------------------ |
| id                      | bigint PK        |                                                              |
| venta_id                | FK               |                                                              |
| tipo                    | enum             | `factura_a`, `factura_b`, `nota_credito_a`, `nota_credito_b` |
| punto_venta             | smallint         | 1 = mostrador, 2 = online                                    |
| numero                  | int              | correlativo por (tipo, punto_venta)                          |
| comprobante_asociado_id | FK null          | la factura que la NC acredita                                |
| fecha_emision           | date             |                                                              |
| neto, iva, total        | decimal          |                                                              |
| cae                     | varchar(14) null |                                                              |
| cae_vencimiento         | date null        |                                                              |
| estado                  | enum             | `pendiente`, `autorizado`, `rechazado`                       |
| afip_respuesta          | json null        | payload completo de WSFEv1                                   |
| motivo                  | varchar null     | razón de la NC                                               |

UNIQUE(tipo, punto_venta, numero).

**Por qué el comprobante es una tabla separada de la venta.** Son cosas distintas con ciclos de vida distintos. Una venta puede existir sin comprobante (presupuesto, o factura rechazada por AFIP y pendiente de reintento) y **un comprobante nunca cambia** una vez que tiene CAE. Mezclarlos obligaría a mantener campos fiscales nulos en toda venta no facturada, y a proteger contra la edición de una venta ya declarada.

Además una venta puede tener varios comprobantes: la factura más una o más notas de crédito. `comprobante_asociado_id` es el vínculo que AFIP exige entre la NC y la factura original.

**Por qué `punto_venta` es un número y no una tabla.** Son dos valores fijos que AFIP te asigna. Una tabla de dos filas agrega un join a cada emisión para guardar un entero.

**Por qué la numeración no usa un contador propio.** El sistema actual tiene `venta_numeracion`, una tabla de una fila sin clave primaria, y `ventas.numero` sin índice único (hallazgo A-18). Acá el número correlativo lo consulta AFIP con `FECompUltimoAutorizado` antes de cada emisión, y el `UNIQUE(tipo, punto_venta, numero)` es la red de contención local. La fuente de verdad de la numeración fiscal es AFIP, no tu base.

**Tipo A o B**: se decide por `cliente.condicion_iva`. Responsable inscripto → A (IVA discriminado). Consumidor final o monotributista → B.

### `comprobante_lineas`
`id, comprobante_id FK, producto_id FK null, descripcion, cantidad, precio_unitario, alicuota_iva, neto, iva, total`

**Por qué el comprobante tiene sus propias líneas si ya están en `venta_lineas`.** Tres razones:

1. **Inmutabilidad legal.** El comprobante emitido tiene que poder reimprimirse idéntico dentro de diez años, sin depender de que nadie haya tocado la venta.
2. **Las notas de crédito parciales las necesitan igual.** Si devolvés dos de cinco unidades, la NC lleva sus propias líneas. La tabla existe de todas formas — poblarla también para las facturas no agrega ninguna entidad.
3. **Un solo camino de código.** Armar el payload de WSFEv1 y el PDF lee siempre de la misma tabla, sin ramificar entre factura y NC.

El costo es duplicar unas pocas filas por venta. Con el volumen de una tienda, es irrelevante frente al riesgo de un comprobante que no se puede reproducir.

**Devoluciones, totales y parciales.** No hay tabla de devoluciones: una devolución **es** una nota de crédito. Una tabla intermedia sería una copia de la NC con otro nombre.

El flujo, dentro de una sola transacción:

1. El empleado selecciona qué líneas y qué cantidades se devuelven.
2. Se valida contra `venta_lineas.cantidad_devuelta` que no se exceda lo comprado.
3. Se emite la NC con **sólo esas líneas y esas cantidades**, asociada a la factura original vía `comprobante_asociado_id`.
4. Se incrementa `cantidad_devuelta` en cada línea afectada.
5. Se generan los movimientos de stock de reingreso, con `origen` apuntando a la NC.
6. Si el pago fue con Mercado Pago, se dispara el reembolso parcial por su API.
7. La venta pasa a `devuelta_parcial` o `devuelta` según el saldo restante.

Que las líneas de la NC vivan en `comprobante_lineas` es lo que hace posible la devolución parcial sin entidades nuevas: la nota de crédito lleva su propio detalle, distinto del de la factura. Es la segunda justificación de esa tabla, además de la inmutabilidad legal.

**Impacto en las métricas.** El dashboard debe restar lo devuelto de los ingresos y del margen, no ignorarlo. Con `cantidad_devuelta` en la línea, la cantidad efectivamente vendida es `cantidad - cantidad_devuelta` — un solo campo resuelve el ranking de productos, el ranking de vendedores y el cálculo de ingresos sin consultar las notas de crédito.

---

## 7. Envíos

### `envios`
`id, venta_id FK UNIQUE, calle, numero, piso_depto, codigo_postal, localidad, provincia, costo, zipnova_envio_id, tracking_url, estado, fecha_despacho`

**Por qué la dirección se copia en vez de referenciar `direcciones`.** Si el cliente edita su dirección el año que viene, el envío de hoy tiene que seguir diciendo a dónde se mandó realmente. Es el mismo principio que congelar el precio en la línea de venta.

`tracking_url` guarda el link del correo (45): en vez de replicar los estados de la mensajería, se le muestra al cliente el seguimiento del propio servicio.

**Sólo envío a domicilio** (43). El retiro en local no crea fila acá: es `ventas.modo_entrega = 'retiro'` con `costo_envio = 0`.

---

## 8. Contenido de la tienda

### `banners`
`id, imagen, link, orden, visible_desde, visible_hasta, activo`

Imagen y link (51), con programación por fechas (52). Los administra el administrativo (requerimiento 7).

**No hay tabla de promociones.** Dijiste que la promoción es un destaque visual, no un descuento real (53). El destaque manual es `productos.destacado`; el automático por más vendidos es una consulta agregada sobre `venta_lineas`, sin tabla.

---

## 9. Dashboard

**No agrega ninguna tabla.** Todas las métricas confirmadas salen por agregación:

| Métrica                           | Origen                                                                                          | Estado            |
| --------------------------------- | ----------------------------------------------------------------------------------------------- | ----------------- |
| Ventas del vendedor en el período | `ventas WHERE usuario_id = X AND estado IN (Venta::ESTADOS_VENDIDOS)`                           | Implementada      |
| Cobros del cajero en el período   | `pagos WHERE usuario_id = X`, por `pagos.fecha`                                                 | Implementada      |
| Ticket promedio                   | `AVG(total)` sobre lo anterior                                                                  | Implementada      |
| Ranking de vendedores             | `GROUP BY usuario_id`                                                                           | Implementada      |
| Facturado                         | `SUM(ventas.total)` por `ventas.created_at` — **no** descuenta devoluciones                     | Implementada      |
| Cobrado                           | `SUM(pagos.monto)` por `pagos.fecha` — **sí** las descuenta                                     | Implementada      |
| Cobrado por medio de pago         | `GROUP BY pagos.metodo`                                                                         | Implementada      |
| Facturado por día                 | `GROUP BY DATE(ventas.created_at)`                                                              | Implementada      |
| Descuentos otorgados              | `SUM(ventas.descuento)`                                                                         | Implementada      |
| Margen bruto                      | `SUM((cantidad − cantidad_devuelta) × (precio_unitario − costo_unitario))` sobre `venta_lineas` | Implementada      |
| Stock crítico                     | `productos WHERE stock − stock_reservado <= stock_minimo`                                       | Implementada      |
| Órdenes de compra abiertas        | `ordenes_compra WHERE estado IN (ESTADOS_ABIERTOS)`                                             | Implementada      |
| Más vendidos                      | `GROUP BY producto_id` sobre `venta_lineas`                                                     | Implementada      |
| Menos vendidos                    | La pregunta inversa, que no sale de la misma consulta                                           | Pendiente #48     |
| Neto acreditado                   | `SUM(pagos.neto_acreditado)`                                                                    | **No disponible** |

**«Facturado» y «cobrado» no son lo mismo y el panel no los mezcla.** El primero dice
cuánto se vendió y no sabe nada de devoluciones; el segundo dice cuánta plata quedó, y
sí las descuenta, porque el contra-asiento de la devolución es una fila de monto
negativo que entra en la misma suma. Se filtran además por columnas de tablas
distintas: una devolución de abril sobre una venta de marzo baja la caja de abril y no
toca la de marzo. Van en recuadros separados, con su aclaración, y nunca sumados.

**El margen es bruto**: los importes congelados de la línea no conocen
`ventas.descuento`, que vive en la cabecera. La pantalla lo dice en el rótulo y muestra
los descuentos otorgados al lado, en lugar de informar un número optimista sin aclarar
qué le falta restar (pendiente #49). Y arrastra el costo **acordado** y no el
facturado, que es el pendiente #37.

**«Neto acreditado» no se puede calcular en la Etapa 1**, y no es «null por ahora» sino
null por construcción: la informa Mercado Pago, no hay integración, y `mercadopago` no
se ofrece como medio de cobro (`Pago::METODOS_EN_USO`), así que ningún pago que el
sistema pueda registrar es capaz de tenerla. No se muestra en el panel: un recuadro en
cero afirmaría que no se acreditó nada, que es exactamente lo que el proyecto no hace.

Todo se calcula **en el servidor** y se devuelve ya agregado. El sistema original
descargaba las tablas completas al navegador y contaba con `.filter()` (hallazgo
A-26); ese patrón no se repite, y hay un test que lo verifica sobre el log de
consultas.

---

---

## 10. Alcance por etapa

El modelo completo es el destino; no todas las tablas se crean a la vez. La Etapa 1 crea dieciocho.

| Etapa 1 (backend Laravel)                   | Etapa 2/3 (tienda, pagos, facturación, envíos) |
| ------------------------------------------- | ---------------------------------------------- |
| `users`, `roles`, `permisos`, `rol_permiso` | `carritos`, `carrito_lineas`                   |
| `clientes`, `empleados`, `direcciones`      | `comprobantes`, `comprobante_lineas`           |
| `categorias`, `marcas`, `productos`         | `envios`                                       |
| `movimientos_stock`                         | `vales`                                        |
| `proveedores`, `producto_proveedor`         | `banners`                                      |
| `ordenes_compra`, `orden_compra_lineas`     |                                                |
| `ventas`, `venta_lineas`, `pagos`           |                                                |

**Regla para no pagar migraciones dolorosas después.** Las tablas de la Etapa 1 se crean con **todas** las columnas que van a necesitar alguna vez, incluidos los `ENUM` con su juego completo de valores. En Laravel agregar una tabla o una columna nullable es barato; modificar un `ENUM` reescribe la tabla entera.

Casos concretos:

- `ventas.estado` declara los diez valores aunque la Etapa 1 sólo use `presupuesto`, `pagada`, `entregada` y `cancelada`.
- `ventas.canal` declara `mostrador` y `online` aunque sólo se use el primero.
- `pagos` se crea con `comision`, `neto_acreditado`, `cuotas` y `mp_payment_id` con índice único, todas nulas hasta que llegue Mercado Pago. Cuando llegue, no hay que tocar la tabla.
- `venta_lineas.cantidad_devuelta` existe desde el inicio con valor cero.

**Única excepción:** las claves foráneas que apuntan a tablas de etapas siguientes (`ventas.vale_id`) se agregan cuando exista la tabla destino, porque una FK no puede referenciar algo inexistente. Es un `ADD COLUMN` nullable, sin migración de datos.

## 11. Resumen

| Área               | Tablas                                                                              |
| ------------------ | ----------------------------------------------------------------------------------- |
| Identidad y acceso | `users`, `roles`, `permisos`, `rol_permiso`, `clientes`, `empleados`, `direcciones` |
| Catálogo           | `categorias`, `marcas`, `productos`                                                 |
| Stock              | `movimientos_stock`                                                                 |
| Compras            | `proveedores`, `producto_proveedor`, `ordenes_compra`, `orden_compra_lineas`        |
| Carrito            | `carritos`, `carrito_lineas`                                                        |
| Ventas             | `ventas`, `venta_lineas`, `pagos`, `vales`                                          |
| Facturación        | `comprobantes`, `comprobante_lineas`                                                |
| Logística          | `envios`                                                                            |
| Contenido          | `banners`                                                                           |

**Qué se eliminó respecto del modelo anterior:** `modulos` (los permisos ya no se agrupan por módulo sino por acción nombrada) y `venta_numeracion` (la numeración fiscal la gobierna AFIP). `perfiles` y `permisos` se rediseñaron, no se eliminaron.

**Qué se conservó:** la separación cabecera/líneas en ventas, el congelamiento de precios en la línea, y los pagos como tabla propia. Eran las decisiones correctas del sistema actual.
