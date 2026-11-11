# Modelo de datos — Sistema de gestión + tienda online

25 tablas. Cada sección explica **qué resuelve** y **por qué no se modeló de otra forma**.

**El modelo completo es el destino, no lo que existe hoy.** Qué tablas crea cada etapa está
en la §10. Desde el cierre de la Etapa 1 el alcance cambió: la Etapa 2 es la tienda móvil
con su carrito y sus pagos, y la facturación electrónica, los envíos, los vales y los
banners pasaron a la **Etapa 4**. Las secciones de esas tablas se conservan como diseño,
con una línea al principio que dice de qué etapa son.

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

**Los permisos de la tienda** *(Etapa 2, tramo D; D-6).* El módulo `tienda` suma tres claves, una por
acción: `tienda.ver_precios` (los dos precios y el orden por precio), `tienda.comprar` (carrito,
confirmación y pago; lo usan los tramos E a G) y `tienda.ver_pedidos` (la consulta de compras; tramo H).
El rol Cliente recibe `tienda.*`; sigue sin ningún permiso de gestión. El Administrador también las
recibe por su `'*'`, sin efecto: la API rechaza las cuentas de gestión (D-3). Son filas de `permisos`
como cualquier otra: no hay columna nueva ni migración, las crea `RolPermisoSeeder`.

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

`ventas.cliente_id` es nullable: la venta rápida de mostrador a consumidor final no necesita identificar a nadie, y ARCA acepta doc tipo 99 para esos casos.

**Por qué no tiene `activo`.** Es la única excepción del proyecto a "lo que está
referenciado no se borra: se desactiva". La baja lógica existe para lo que
aparece en listas de las que uno elige —marcas, categorías, productos—, donde
hay que dejar de ofrecerlo sin borrar el historial. Un cliente no se elige de un
desplegable: se busca. Esconderlo de un buscador es lo contrario de lo que se
necesita. Así que la baja de un cliente referenciado **se rechaza** con un
motivo, que conserva el dato igual y además lo explica. Si el negocio pidiera
ocultar a quien dejó de comprar, ahí se justifica la columna.

**Con un límite.** ARCA exige identificar al comprador cuando la operación con consumidor final supera cierto monto, y ese umbral se actualiza periódicamente. Conviene guardarlo como parámetro de configuración, no hardcodearlo: si el total supera el valor vigente, la app obliga a cargar los datos del cliente antes de facturar. Hay que confirmar el número vigente al momento de implementar.

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
las declaraciones a ARCA, que están fuera del alcance del proyecto. Para
identificar a la persona y atribuirle operaciones, el DNI alcanza. Si alguna
etapa incorporara liquidación, se agrega como columna nullable de `empleados`
sin tocar ninguna relación, más la validación de que contenga el DNI y de su
dígito verificador.

### `direcciones`
`id, cliente_id FK, calle, numero, piso_depto, codigo_postal, localidad, provincia, es_predeterminada`

Sólo para envíos. Un cliente puede tener varias; la que se usa se **copia** al pedido, no se referencia. En la Etapa 2 la app no administra direcciones: el envío mínimo copia la dirección en la propia venta (§5). Las varias direcciones desde la app y la tabla `envios` (§7) son de la Etapa 4.

**Invariante:** si el cliente tiene al menos una dirección, exactamente una
tiene `es_predeterminada`. El esquema no puede expresarlo —un `boolean` con
`DEFAULT false` acepta tres marcadas o ninguna— así que la garantiza
`DireccionService`, igual que la invariante rol↔satélite. Los dos estados
inválidos hacen daño: con tres marcadas el armado de envíos no sabe cuál usar, y
con ninguna no tiene nada que ofrecer. Toda operación bloquea la fila del
**cliente** —no las de las direcciones— para que dos pestañas simultáneas no
dejen dos marcadas, y para que el alta de la primera también se serialice,
cuando todavía no hay ninguna fila de dirección que bloquear.

### `personal_access_tokens`

*(Etapa 2, tramo A. La crea Sanctum; la usa `App\Services\CredencialService`, que es la única clase que lo sabe.)*

| Campo                        | Tipo               | Nota                                                           |
| ---------------------------- | ------------------ | -------------------------------------------------------------- |
| id                           | bigint PK          |                                                                |
| tokenable_type, tokenable_id | varchar + bigint   | la cuenta dueña (`App\Models\User`); índice compuesto          |
| name                         | text               | `app-tienda`                                                   |
| token                        | varchar(64) UNIQUE | **hash** de la credencial: la credencial en claro no se guarda |
| abilities                    | text null          | `["*"]`; no se usa para permisos (eso es `rol_permiso`)        |
| last_used_at                 | timestamp null     |                                                                |
| expires_at                   | timestamp null     | siempre se escribe: emisión + `SANCTUM_EXPIRATION`             |

**Por qué una fila por credencial.** Es lo que hace posible *revocar*: en el sistema original el
perfil viajaba dentro del JWT y desactivar a alguien no lo desconectaba hasta que el token vencía
(A-5). Acá, desactivar o dar de baja una cuenta borra sus filas (`UsuarioService`, en la misma
transacción), y cada pedido además revalida que la cuenta esté activa. Desde el tramo D, cambiar o
restablecer la contraseña también las borra (`UsuarioService::cambiarPassword()`): quien la cambia
porque se la sacaron espera que el otro quede afuera, también en la app.

**Revocar borra la fila.** No choca con la regla de no borrar hechos del negocio: una credencial no
es un hecho del negocio sino un permiso de acceso, y un permiso revocado no tiene historia que
conservar. Lo que se guarda como historia (quién vendió, quién cobró) sigue en `ventas` y `pagos`.

**No tiene clave foránea** (es polimórfica), así que no se borra sola con la cuenta: por eso
`UsuarioService::eliminar()` las revoca antes de borrar la fila de `users`.

---

## 2. Catálogo

### `categorias`
`id, parent_id FK null, nombre, slug, orden, peso_default_gramos, ganancia null, activo`

`ganancia` es el nivel del medio de la cascada global → categoría → producto, y es
nullable porque null significa «heredá», que no es lo mismo que cero: una ganancia de
cero sería regalar el producto. La resolución sube por el árbol —poner la ganancia en
«Componentes» alcanza a los SSD— porque los productos viven en las hojas, y si no subiera
habría que cargarla hoja por hoja, que es el trabajo que el motor viene a sacar. Cuesta a
lo sumo tres consultas, que es `PROFUNDIDAD_MAXIMA`.

`parent_id` da la jerarquía que pediste (Componentes > Almacenamiento > SSD). Es autorreferencia simple, no árbol anidado: con dos o tres niveles no justifica la complejidad de un nested set.

`peso_default_gramos` resuelve el requerimiento 42 sin relevar producto por producto: la cotización de envío —Etapa 4— usa el peso de la categoría salvo que el producto tenga el suyo.

### `marcas`
`id, nombre, slug, logo, activo`

Tabla mínima, existe sólo para filtrar en la tienda.

### `productos`
| Campo                  | Tipo               | Por qué                                                              |
| ---------------------- | ------------------ | -------------------------------------------------------------------- |
| id                     | bigint PK          |                                                                      |
| categoria_id, marca_id | FK                 |                                                                      |
| codigo                 | varchar(30) UNIQUE |                                                                      |
| nombre, descripcion    | varchar / text     |                                                                      |
| imagenes               | json               | array de rutas                                                       |
| **precio_lista**       | decimal(12,2)      | tarjeta / cuotas. **Derivado**: el de contado con el recargo adentro |
| **precio_contado**     | decimal(12,2)      | efectivo / transferencia / QR. Es el precio del mostrador            |
| alicuota_iva           | decimal(4,2)       | el valor, no una FK: lo fija la ley (ver `impuestos`)                |
| costo_promedio         | decimal(12,2)      | costo ponderado, se recalcula al recibir mercadería                  |
| stock                  | int                | saldo real en depósito                                               |
| stock_reservado        | int                | comprometido por checkouts en curso                                  |
| stock_minimo           | int                | dispara la orden de compra                                           |
| cantidad_reposicion    | int                | cuánto pedir cuando se dispara                                       |
| peso_gramos            | int null           | si es null, usa el de la categoría                                   |
| destacado              | boolean            | destaque manual (requerimiento 54); se tilda en el formulario con `producto.editar` |
| activo                 | boolean            | baja lógica                                                          |
| **ganancia**           | decimal(6,3) null  | multiplicador propio; null = hereda de la categoría                  |
| **precio_manual**      | boolean            | lo saca del recálculo: una promoción, una liquidación                |
| costo_promedio         | decimal(12,2)      | **costo NETO, sin IVA**; ponderado, se recalcula al recibir          |

**Por qué ya no hay `proveedor_id`.** La tenía, con el criterio «un producto, un
proveedor». Apareció el requerimiento de comprarle a quien tenga el mejor precio,
y un producto con un solo proveedor no puede expresar eso. La columna se eliminó
en la Fase 5 y la reemplaza la pivote `producto_proveedor` (ver §3).
**Por qué dos precios y no una tabla de listas.** Dijiste que el precio es uno solo y
que el arreglo mayorista es informal (16), pero también que querés el esquema actual de
precio de lista distinto al de contado (38). Eso son exactamente dos números por
producto, no dos listas de precios. Dos columnas resuelven el 100 % del caso; una tabla
`listas_precio` agregaría una entidad y un join a cada consulta de catálogo para modelar
lo mismo.

En la tienda online se cobra siempre `precio_lista`: Checkout Pro recibe el monto antes
de saber con qué se va a pagar, así que el recargo de tarjeta tiene que estar **adentro**
del precio. Ése es el motivo por el que existen dos números y no uno.

**En el mostrador el precio es uno: `precio_contado`.** La versión anterior de este
documento decía que ahí el cajero elegía entre los dos, y es falso: en una sucursal el
recargo por tarjeta lo aplica el posnet al cobrar, o se arregla informalmente, y el
sistema no calcula lo que no controla. Se corrigió en la Fase 6, y la consecuencia es que
**la venta de mostrador no tiene modalidad de precio**: no hay nada que elegir, nada que
guardar y nada que recordar. Lo único que el mostrador tiene para mover el total es el
descuento, con su tope por rol (A-12).

**Desde la Fase 7.5 los dos precios se calculan.** `precio_contado` sale del motor
—costo neto × ganancia × factores, más los impuestos— y `precio_lista` sale de aplicarle
el recargo de `politica_precios`. Ninguno de los dos se tipea, salvo que el producto
tenga `precio_manual`. Eso cambia qué significa cada columna: antes eran dos datos que
alguien cargaba, ahora son dos resultados persistidos. **Se persisten y no se calculan al
vuelo** por la misma razón por la que `venta_lineas` congela sus importes: el listado del
catálogo con 20.000 filas no puede hacer una cuenta con cuatro tablas por renglón, y un
precio que cambia solo entre que se consulta y se cobra no es un precio.

**`precio_manual` existe porque el motor no puede tener razón siempre.** Una promoción,
igualar a la competencia, liquidar un discontinuado: son precios que decide una persona y
que un recálculo no debe pisar. Los 20.011 productos que ya existían cuando se agregó la
columna entraron con la marca en `true`, así que la migración no cambió ningún precio.

**Un producto nuevo nace siempre con `precio_manual`.** No es una preferencia: al darlo
de alta su `costo_promedio` es cero —esa columna sólo la mueve `StockService` al recibir
mercadería— así que el motor no tendría con qué calcular. Recién cuando el producto
recibe su primera compra tiene sentido pasarlo al cálculo automático. El formulario lo
dice y el Form Request lo exige.

**El costo es NETO de IVA, y hasta la Fase 7.5 eso no constaba en ninguna parte.** El IVA
de una compra es crédito fiscal, no costo: el comercio lo descuenta de lo que debe. Si el
costo se cargara con IVA adentro y después el motor lo multiplicara por la alícuota, el
precio saldría un 21 % de más. Con el precio tipeado a mano la ambigüedad no hacía daño
—el operador compensaba sin saberlo—; con el precio calculado, el mismo número da dos
precios distintos. Alcanza a tres columnas, que significan lo mismo:
`orden_compra_lineas.costo_unitario`, `producto_proveedor.costo_ultimo` y
`productos.costo_promedio`, que es el promedio ponderado de las primeras.

**El sistema asume que el comercio es Responsable Inscripto.** Es una suposición del
motor de precios y hay que escribirla porque callarla sería peor. Un monotributista no
discrimina IVA en sus ventas y **el IVA de sus compras sí es costo suyo**: para él, el
costo neto es el costo con IVA, y el motor funcionaría al revés. Soportar los dos casos
es una bandera en `politica_precios` y una rama en `MotorPrecios`; no se implementó
porque el comercio relevado es Responsable Inscripto, pero el día que haga falta se sabe
dónde va.

**Por qué no hay variantes ni atributos técnicos.** En el bloque anterior te recomendé separar producto de variante. **Cambio esa recomendación** a la luz de tus respuestas: pediste explícitamente no explotar el modelo, y descartaste las fichas técnicas (15). Separar producto/variante duplica la complejidad de carrito, stock, órdenes de compra y kardex para ganar sólo una agrupación visual en la tienda ("mismo SSD, tres capacidades"). Con un `codigo` por capacidad ya tenés el control de stock correcto.

Si más adelante querés agrupar, se agrega una columna nullable `grupo_id` y una tabla `grupos_producto`. No requiere migrar datos ni tocar ventas.

**Por qué `stock` y `stock_reservado` como columnas y no una tabla de reservas.** El stock disponible es `stock - stock_reservado`, una resta de dos enteros en la misma fila. Con una tabla de reservas, cada consulta de catálogo necesitaría un `SUM()` sobre reservas vigentes — costoso y con riesgo de contar reservas vencidas. Un job que libera los checkouts expirados mantiene la columna al día.

**Por qué `costo_promedio` vive acá.** El dashboard necesita margen (requerimiento 14) y sin costo no hay margen. Promedio ponderado es el método más simple que da un número correcto: al recibir mercadería, `costo_nuevo = (stock × costo_actual + cantidad × costo_compra) / (stock + cantidad)`.

**`costo_promedio` tiene dos escritores desde el tramo D, y los dos están en
`StockService`.** `recibirCompra()` lo recalcula como promedio ponderado al recibir
mercadería, y `fijarCosto()` lo escribe sin mover stock, que es lo que el ajuste masivo
necesita cuando el mayorista aumentó su lista. La columna sigue teniendo un solo dueño
—está fuera de `$fillable` y ninguna otra clase la toca—, pero la afirmación «toda
variación deja un movimiento en el kardex» se lee ahora en dos partes: el stock no se
escribe desde afuera, y el stock que se escribe deja asiento. Un cambio de costo no mueve
ninguna unidad, así que su fila de kardex tendría cantidad cero y el mismo saldo que la
anterior: un asiento que no registra ningún movimiento, en un libro donde cada fila
registra uno. El rastro de ese cambio va donde corresponde, que es `historial_precios`
con su `costo_anterior` y su lote.

**Lo que la tienda muestra de un producto se deriva, no se guarda (Etapa 2, tramo C).** Hay tres
datos que la API publica y que no tienen columna:

- **La novedad.** No hay `es_novedad`: es novedad el producto de la tienda creado dentro de
  `novedades.dias` (30) y entre los `novedades.tope` (10) más recientes de ésos, con desempate por
  `created_at` y `id`. Una columna se desactualizaría sola con el paso de los días, y un `created_at`
  ya existe. La ventana sola no alcanza —el catálogo de volumen se cargó en dos días, así que todos
  serían «nuevos»—, por eso además hay tope. `Producto::idsNovedades()` es la única definición y la
  usan la sección y la marca de cada fila.
- **La descripción breve.** Las primeras `descripcion_breve.palabras` (25) palabras de
  `descripcion`, con «…» si se cortó (D-12), y nunca más de `descripcion_breve.caracteres` (250): un
  texto sin espacios sería una sola «palabra». Una columna `descripcion_breve` pediría un campo más en el
  formulario del producto —una tercera ampliación de la web— y dos textos que mantener a la par.
- **La disponibilidad.** Tres niveles (`disponible`, `ultimas_unidades`, `sin_stock`) calculados sobre
  `stock − stock_reservado` con el umbral `disponibilidad.ultimas_unidades` (3). El número nunca sale
  del servidor.

Y una regla de **visibilidad**: la tienda sólo muestra un producto activo cuya categoría sea activa *y*
tenga todos sus ancestros activos. `CategoriaService` puede desactivar un padre sin tocar a sus hijas,
así que `activo = 1` en la hija no alcanza; `Categoria::idsVisiblesEnTienda()` lo resuelve con una
consulta sobre una tabla chica (69 filas hoy), una vez por pedido.

**`imagenes` es una lista ordenada de rutas relativas al disco `public`** (`productos/xxx.jpg`):
el orden es el de la galería y la primera es la imagen principal. Vacía o `null` significa «sin foto», y
la API responde entonces con la imagen por omisión, nunca con `null`.

### `movimientos_stock`
`id, producto_id FK, tipo, cantidad (con signo), stock_resultante, origen_type, origen_id, usuario_id FK null, motivo, remito_numero null, remito_fecha null, created_at`

`tipo`: `venta`, `devolucion`, `compra`, `ajuste`, `reserva_liberada`.

**Por qué existe.** Es el kardex simple que aceptaste (23), y resuelve A-13. Hoy el stock se pisa con `UPDATE productos SET stock = stock - :cant` y no queda rastro de nada. Con esta tabla podés responder "¿por qué este producto tiene 3 unidades?" y "¿quién lo ajustó?", que es la pregunta que aparece siempre.

`origen_type` + `origen_id` es polimórfico (apunta a una venta, un comprobante de NC o una orden de compra). Un solo par de columnas cubre los tres casos sin tres FKs nullables.

**Por qué no hay tabla de recepciones de mercadería.** La recepción parcial (29) se resuelve con `cantidad_recibida` en la línea de la orden de compra, y **el historial de cada recepción ya está acá**: cada entrada genera un movimiento con su fecha y su usuario. La tabla de recepciones sería redundante.

**Por qué el remito del proveedor vive en el asiento y no en la orden** (tramo H de la
Fase 7.5). El plan lo ponía en `ordenes_compra`, pero una orden recibida en dos veces llega
con dos remitos, y un solo par de columnas haría que la segunda recepción pisara el
primero. El asiento ya es el registro de cada recepción, así que el remito se congela ahí
junto con la cantidad que trajo; la ficha de la orden y el kardex lo leen de la misma fila,
sin consultas de más. Es opcional, porque el proveedor informal puede no mandarlo, y va
completo (número y fecha) o no va. Los asientos anteriores quedan en null, «no consta». El
remito no lo emitimos, así que no hay CAI ni numeración que cumplir: se guarda el número
tal como vino. La alícuota no se carga en la recepción, porque es del producto desde el
tramo C, y cargarla otra vez sería una segunda fuente del mismo dato.

**Por qué no están las dimensiones.** El hallazgo M-22 pedía «peso y dimensiones»
para la cotización de envíos. El peso está —`productos.peso_gramos`, con
`categorias.peso_default_gramos` como respaldo—; las dimensiones no se crearon, y es
una decisión, no un olvido. Sirven únicamente a la cotización de Zipnova, que es de
la Etapa 4, y son tres columnas enteras nullables, sin clave foránea y sin `ENUM`:
agregarlas es un `ADD COLUMN` que no migra datos ni reescribe la tabla. No caen bajo
la regla que obliga a declarar todas las columnas desde el inicio, porque esa regla
existe para no reescribir una tabla por un `ENUM` incompleto, no para prohibir una
columna nullable más adelante.

Hay una pregunta que sí conviene responder **antes** de escribir la cotización de la
Etapa 4: si Zipnova cotiza por peso declarado o por peso volumétrico. Si es
volumétrico, las tres columnas (`largo_mm`, `ancho_mm`, `alto_mm`) hacen falta y
además hay que relevar las medidas producto por producto, que es trabajo de carga de
datos y no de esquema. Si cotiza por peso declarado, no se agregan nunca.

Por el mismo criterio, `peso_gramos` y `destacado` existen en la tabla pero no se
cargan desde el formulario de la Etapa 1: el peso sirve al envío y el destaque a la
vidriera de la tienda. **`destacado` entra en la Etapa 2**, porque la consigna pide que los
destacados se identifiquen «a partir de información administrada en el backend»: es una
casilla en el formulario del producto, una de las dos excepciones declaradas a «no se
amplifica la web». **`peso_gramos` sigue sin formulario** hasta la Etapa 4, que es la del
envío calculado. La columna está para no tocar la tabla después.

**La novedad no tiene columna.** La consigna de la Etapa 2 pide un criterio de novedad
definido y documentado, y el dato ya existe: `created_at`, la fecha de alta. El criterio es
una ventana con tope, configurable en `config/`. Una columna `novedad` marcada a mano sería
un segundo destacado con otro nombre.

**Una sola descripción.** `descripcion` es la completa, para la ficha. La consigna de la
Etapa 2 pide además una breve para el listado; si es una columna o se deriva de la completa
lo decide el plan de esa etapa.

### `impuestos`
| Campo       | Tipo         | Por qué                                                         |
| ----------- | ------------ | --------------------------------------------------------------- |
| id          | bigint PK    |                                                                 |
| nombre      | varchar(60)  | UNIQUE. Es el texto que se imprime en el desglose               |
| alicuota    | decimal(4,2) | mismo tipo que `productos.alicuota_iva`, a propósito            |
| alcance     | enum         | `producto` (las alícuotas de IVA), `general` (todo el catálogo) |
| comprobante | enum         | `iva`, `otros_nacionales`, `no_se_muestra` (Ley 27.743)         |
| orden       | smallint     | el orden del desglose                                           |
| activo      | boolean      | baja lógica                                                     |

Lo que se aplica **sobre** el neto y se itemiza. Los de alcance `producto` son el catálogo
de alícuotas de IVA, de las que cada producto elige una; los de alcance `general` entran
en el precio de todos.

**Por qué el producto guarda el número y no una clave foránea.** La alícuota la fija la
ley, no el negocio. Con una FK, editar la fila «IVA 21 %» a 27 % le cambiaría el IVA en
silencio a cada producto que la apuntara, y eso es justo lo que el congelamiento de
`venta_lineas.alicuota_iva` existe para impedir. Acá el catálogo gobierna **qué se puede
elegir**; el valor elegido vive en el producto. La contrapartida es que el vínculo no es
referencial, y por eso el Form Request impide repetir una alícuota y la baja de una que
algún producto usa es lógica, no física.

**Por qué el alcance no se puede cambiar después de creado.** Si una alícuota de IVA
pasara a `general`, el motor se la sumaría a todos los productos *además* del IVA propio
de cada uno: los precios saldrían con IVA dos veces y el síntoma sería un número más
alto, no un error. El Form Request lo declara prohibido en la edición.

`comprobante` es la clasificación de la Ley 27.743, reglamentada por la RG 5614/2024 de
ARCA: en toda venta a consumidor final el comprobante muestra **IVA contenido** y **otros
impuestos nacionales indirectos** por separado. No se implementa la facturación acá, pero
el dato que esa facturación va a necesitar se guarda desde ahora.

### `factores_costo`
| Campo      | Tipo         | Por qué                                    |
| ---------- | ------------ | ------------------------------------------ |
| id         | bigint PK    |                                            |
| nombre     | varchar(60)  | UNIQUE                                     |
| modo       | enum         | `recargo` (× 1 + %) o `recupero` (÷ 1 − %) |
| porcentaje | decimal(6,3) | tres decimales: un flete de 2,375 % existe |
| orden      | smallint     |                                            |
| activo     | boolean      |                                            |

Lo que **forma** el neto y no se itemiza: va adentro del precio. Flete, ingresos brutos
que el comercio absorbe, lo que el negocio agregue. Arranca vacía.

**`modo` es la columna que evita el error clásico.** Para recuperar un impuesto del 3 %
que se calcula *sobre la venta* no se multiplica por 1,03 —eso recupera 2,91 %— sino que
se divide por 0,97. Declarado en el dato, el motor lo hace bien sin que nadie lo piense
al cargar el factor, y la pantalla muestra la operación resultante al lado de cada fila.

**Por qué se borran de verdad y los impuestos no.** A un factor no lo referencia ninguna
fila: vive adentro de un precio ya calculado, y el desglose congelado del renglón guarda
impuestos y no factores, porque los factores no van en el comprobante. Borrarlo no deja
nada colgando; lo único que hay que decir —y los mensajes lo dicen— es que los precios
que ya lo tenían adentro no cambian hasta que se recalculen.

### `politica_precios`
| Campo             | Tipo          | Por qué                                 |
| ----------------- | ------------- | --------------------------------------- |
| id                | tinyint PK    | sin autoincremento; CHECK (id = 1)      |
| ganancia_global   | decimal(6,3)  | la raíz de la cascada                   |
| recargo_lista     | decimal(6,3)  | el costo financiero de vender en cuotas |
| redondeo_multiplo | decimal(10,2) | 0,01 es la identidad                    |
| redondeo_modo     | enum          | `cercano`, `arriba`, `abajo`            |

Una sola fila. **Por qué columnas y no filas de `factores_costo`:** el plan pide que la
ganancia no se pueda borrar, sólo editar, y una columna no se borra desde ninguna
pantalla. La regla queda garantizada por el esquema en lugar de por una bandera que
alguien puede olvidarse de mirar.

**Por qué una tabla de una sola fila, después de A-18.** El hallazgo A-18 criticaba
`venta_numeracion`, que era una tabla de una fila **sin clave primaria** usada para
generar un número que tampoco era único: el defecto no era tener una fila, era no tener
cómo garantizarlo y usarla para algo que necesitaba atomicidad. Acá hay clave primaria y
una restricción `CHECK (id = 1)` que la base hace cumplir, y lo que guarda son tres
parámetros de lectura. Hay un test que intenta insertar la segunda fila y espera que la
base la rechace.

### `historial_precios`
`id, producto_id FK cascade, usuario_id FK null, precio_contado_anterior, precio_contado_nuevo, precio_lista_anterior, precio_lista_nuevo, motivo enum, created_at`

`motivo`: `alta`, `recalculo`, `manual`, `ajuste_masivo`, `deshacer`.

Es el invariante «nada se pisa, se escribe el hecho siguiente» aplicado al precio, y es lo
que hace posible **deshacer un ajuste masivo** en el tramo D.

**Sin `updated_at`:** un hecho pasado no se edita.

**En cascada con el producto, no RESTRICT.** Si retuviera, un producto que alguna vez
cambió de precio no se podría borrar nunca y `ProductoService` perdería la baja física que
hoy hace cuando el producto no tiene ventas, órdenes ni movimientos. El historial de un
precio no significa nada sin su producto; lo que retiene a un producto siguen siendo esas
tres referencias.

**Los dos valores del tramo D ya están declarados en el ENUM.** Es la única tabla nueva de
la fase que crece de verdad —una fila por cambio de precio sobre 20.011 productos—, y
agregarle un valor cuando esté llena reescribe una tabla grande. Declararlos con la tabla
vacía fue gratis. Es el mismo criterio con el que `ventas.estado` declaró
`entregada_parcial` en la Fase 6, y el contrario al de `impuestos`, que va a tener siempre
un puñado de filas y por eso **no** declara valores de más.

**`lote_id` lo agregó el tramo D**, y fue el `ADD COLUMN` barato que este documento
anticipó: una clave foránea nullable que no migra datos ni reescribe nada. Null se lee
exacto —ese cambio de precio no vino de un ajuste masivo— y así quedan para siempre los
asientos de alta, de recálculo y de precio cargado a mano. Es RESTRICT y no cascade: un
lote con cambios colgados no se puede borrar, porque borrarlo dejaría esos cambios de
precio sin el acto que los explica, que es lo único que la columna aporta.

**El historial registra también el costo y la ganancia, desde el tramo D.** Cuatro
columnas nullables más: `costo_anterior`, `costo_nuevo`, `ganancia_anterior` y
`ganancia_nuevo`. Dos de las cinco operaciones del ajuste masivo no escriben un precio
—variar el costo un porcentaje y fijar el multiplicador de ganancia del conjunto— y sin
el valor anterior de esas columnas deshacer el ajuste sería aproximado: dividir por el
mismo porcentaje no devuelve el costo original cuando hubo redondeo, y el criterio de
terminado del tramo pide que un ajuste **se deshaga**, no que se deshaga casi.

Las cuatro admiten null, y null significa «este cambio no tocó eso». El caso ambiguo es
`ganancia_anterior`, porque null también es un valor legítimo de `productos.ganancia`,
donde significa «heredá de la categoría»: **lo desambigua el lote y no la columna**. Si
`lotes_ajuste.operacion` es `ganancia`, la fila tocó la ganancia y hay que restaurar lo
que diga `ganancia_anterior`, incluso si es null; con cualquier otra operación la
ganancia no se toca. Una quinta columna «tocó la ganancia» sería guardar dos veces la
misma verdad.

Los tipos son los de las columnas que copian —`productos.costo_promedio` es decimal(12,2)
y `productos.ganancia` decimal(6,3)—, y eso lo fija un test: un valor que entra en el
producto y no entra en su historial sería un historial que no puede contar lo que pasó.

**Una precisión sobre el costo de esos `ADD COLUMN`.** «No reescribe nada» es exacto
cuando la columna se agrega **al final** de la tabla: MariaDB sólo hace instantáneo el
`ADD COLUMN` en esa posición. Las cinco que agregó el tramo D llevan `AFTER`, para que la
tabla se lea en el orden en que se piensa —el lote junto al usuario, el costo junto a los
precios—, así que en MariaDB 10.4 reconstruyeron la tabla. Fue gratis de todas formas,
porque `historial_precios` estaba casi vacía: el seeder de volumen escribe productos sin
pasar por `PrecioService::asentarAlta()`. Si alguna vez hay que agregarle una columna con
la tabla llena, va al final y sin `AFTER`.
---

### `lotes_ajuste`
`id, usuario_id FK null, operacion enum, porcentaje null, ganancia null, redondeo_multiplo null, criterio json, criterio_texto, alcanzados, omitidos, revierte_a FK null, created_at`

`operacion`: `costo`, `precio`, `ganancia`, `recalculo`, `redondeo`, `deshacer`.

Un ajuste masivo de precios, y es la segunda salvaguarda del tramo D. Lo que vuelve
seguro un botón que cambia cinco mil precios no es la confirmación: es que el ajuste
quede como un hecho con nombre al que se puede volver. El valor anterior de cada producto
ya lo guardaba `historial_precios`; lo que faltaba era saber **qué filas de ese historial
pertenecen al mismo acto**, y eso es esta tabla más `historial_precios.lote_id`.

**El ENUM declara sus seis valores de una vez**, `deshacer` incluido, con el mismo
criterio con el que `historial_precios` declaró los suyos estando vacío: agregar un valor
más adelante reescribe la tabla.

**Un parámetro por operación, y los otros en null.** Cada operación usa uno de los tres
—porcentaje, multiplicador de ganancia o múltiplo de redondeo— y el servicio escribe sólo
ése. Guardar un porcentaje en un lote de ganancia dejaría anotado un número que no
participó del cálculo, y el lote es el hecho al que se vuelve para deshacer: lo que dice
tiene que ser lo que pasó. El porcentaje admite signo, porque una baja de precios es un
ajuste masivo como cualquier otro.

**`criterio_texto` está congelado a propósito.** El json guarda el recorte tal como lo
mandó la pantalla, pero una categoría se renombra y un proveedor se da de baja: el lote
del mes pasado tiene que seguir diciendo «Categoría Memorias y sus subcategorías» aunque
hoy esa categoría se llame distinto. Es el mismo motivo por el que `venta_lineas` congela
su precio.

**Los dos conteos se guardan y no se derivan.** `alcanzados` y `omitidos` son del
momento: el conjunto que hoy tiene 3.000 productos mañana tiene otros, así que
recalcularlos después daría un número distinto del que pasó.

**Que un lote ya se deshizo no es una bandera.** Se deriva de que exista otro lote con
`revierte_a` apuntándolo, que es el invariante «el estado se deriva de los datos, no lo
elige el operador». Una columna `deshecho` sería un segundo lugar donde vive la misma
verdad, y dos lugares pueden discrepar. `revierte_a` es autorreferencial y RESTRICT: un
lote es un hecho y no se borra.

**Sin `updated_at`:** un ajuste es un hecho pasado. Si se revierte, lo que se escribe es
otro lote con `operacion = deshacer`, igual que una venta cobrada se devuelve en lugar de
anularse. Los dos conteos se completan al final de la misma transacción que escribe el
ajuste, así que nadie ve nunca la fila intermedia.

**Sólo se puede deshacer el último, y es una limitación del dato y no de la pantalla.** El
precio anterior que guarda el historial vale mientras no haya pasado nada después:
revertir el lote de la semana pasada devolvería precios que dos ajustes posteriores ya
movieron. Por el mismo motivo, un producto cuyo precio cambió después del lote **no** se
revierte y se cuenta aparte, porque devolverlo pisaría ese hecho posterior.

**Sin factory**, por el mismo motivo que `movimientos_stock`: un lote sin los cambios de
precio que dice haber hecho sería una fila que afirma algo que no pasó. Los tests lo crean
llamando al servicio.

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

El remito del proveedor no es una columna de la orden: vive en cada asiento de recepción,
porque una orden parcial trae más de uno (ver `movimientos_stock`).

**Por qué `borrador` y no envío directo.** Lo aceptaste en el punto 27, pero vale dejar el motivo escrito: una compra que se dispara y se envía sola es un compromiso de plata sin supervisión. Si un ajuste de stock mal cargado deja un producto en cero, el sistema pide mercadería que no hace falta. El borrador convierte el automatismo en una sugerencia.

**Por qué el número de orden es el `id` y no una columna propia.** El proveedor se
refiere al pedido por un número, y el PDF lo imprime como `OC-00042`, derivado del
`id` con relleno de ceros. No se agrega una columna `numero`: la orden de compra no
es un comprobante fiscal —no la gobierna ARCA, no necesita ser correlativa sin
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

El ENUM conserva sus tres valores en la base, así que si alguna etapa vuelve a
automatizar el envío no hace falta migración. Y si mañana un proveedor chico
quiere entrar al sistema, se le crea un rol con sus permisos y se le muestran sus
órdenes — sin tocar el modelo.

---

## 4. Carrito

### `carritos`
`id, user_id FK, estado, reservado_hasta timestamp null, timestamps` — Etapa 2 (tramo E). Índices
(`user_id`, `estado`), para encontrar el vigente, y (`estado`, `reservado_hasta`), para los vencidos.

`session_token varchar null` — Etapa 4 (carrito anónimo, ver abajo)

`estado`: `activo`, `en_checkout`, `convertido`, `abandonado`. Una cuenta tiene un solo carrito
**vigente** (`activo` o `en_checkout`); para que dos pedidos simultáneos no creen dos, `CarritoService`
bloquea la fila de `users` antes de buscarlo. `abandonado` se declara y no se usa en esta etapa: agregar
un valor a un ENUM después reescribe la tabla. Un carrito no se borra: se convierte en pedido (tramo F),
y el convertido apunta a su venta por `ventas.carrito_id`. El siguiente carrito de la cuenta es uno nuevo.

### `carrito_lineas`
`id, carrito_id FK (cascada), producto_id FK, cantidad unsigned, contado_referencia decimal(12,2) null,
lista_referencia decimal(12,2) null, timestamps` — UNIQUE(carrito_id, producto_id): agregar un producto
que ya está suma cantidad en el mismo renglón.

**Por qué el carrito no es una venta en estado "carrito".** Si el carrito fuera una venta, la tabla de ventas se llenaría de carritos abandonados — filas que nadie decidió comprar mezcladas con documentos comerciales, y todas las métricas del dashboard tendrían que filtrarlas. Separarlo mantiene `ventas` limpia: una fila ahí significa que alguien decidió comprar.

**El carrito es de la cuenta, no del dispositivo.** La consigna de la Etapa 2 hace exclusivos de quien inició sesión el selector de cantidad y el «agregar al carrito», así que `user_id` es obligatorio y el carrito vive en el servidor. En el teléfono sólo queda la credencial de sesión, en almacenamiento seguro: otro usuario del mismo dispositivo no llega al carrito ajeno porque no tiene esa credencial. El diseño original preveía un carrito anónimo con `session_token`, que se asociaba al `user_id` al iniciar sesión; queda para la Etapa 4 junto con la fusión de los dos carritos.

**La reserva de stock** ocurre al pasar a `en_checkout`: se suma la cantidad a `productos.stock_reservado` y se fija `reservado_hasta = now() + 20 min`. Es lo que evita que dos clientes compren la última unidad. Tres cosas que la Etapa 2 tiene que respetar:

- **Pasa por `StockService`**, como todo lo que toca el stock (A-13): `reservar()` (bloquea, exige el
  disponible, suma), `liberar()` (bloquea y resta, **nunca baja de cero ni falla**: un ajuste de
  inventario pudo dejar el stock por debajo de lo reservado) y `descontar(..., $deReserva)`, que
  consume la reserva propia de la venta. **La reserva no deja asiento en el kardex** (D-5): el kardex
  dice qué entró y salió del depósito, y una reserva no mueve mercadería. Varios productos se reservan
  y se liberan ordenados por `id`, para que dos checkouts no se bloqueen mutuamente.
- **La liberación de las vencidas corre cada minuto**, no con la reposición de las 07:00: una reserva de veinte minutos liberada una vez por día bloquea la mercadería hasta el día siguiente.
- **Una reserva baja el disponible que ve el mostrador**, porque el disponible es `stock - stock_reservado` (`Producto::DISPONIBLE`). Es lo correcto —esa unidad está comprometida— y los avisos de faltante del tramo F de la Fase 7.5 ya lo cuentan así.

**La reserva tiene dos dueños sucesivos** (HL-4): primero el carrito en checkout, después el pedido
(tramo F, `ventas.reserva_vence`). Cambia de dueño en la transacción de la confirmación, sin moverse.
La invariante, con test: **`stock_reservado` de cada producto = lo reservado por los carritos en
checkout vigentes + lo de los pedidos pendientes con reserva**. Toda escritura sobre un carrito en
checkout lo suelta primero (libera y vuelve a `activo`): así la reserva siempre coincide con los renglones.

**El carrito no guarda precios.** Se recalculan contra `productos` al mostrar y al cerrar el checkout. Congelar el precio al agregar al carrito significa vender a un precio viejo si el carrito quedó tres días abierto.

**Guarda una referencia, que no es un precio** (D-11). `contado_referencia` y `lista_referencia` son
los precios que la persona vio al agregar. Nunca se cobran: sólo dicen contra qué comparar para
«informar si cambió el precio». El carrito los muestra como aviso, y el checkout, si difieren, responde
409 `carrito_cambio` con los cambios y **actualiza la referencia antes de responder** (confirma la
transacción y recién después lanza): el segundo intento, ya revisado, pasa. Son `null`-ables porque
la referencia es un dato de ayuda, no del documento.

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
| vale_id       | FK null      | Etapa 4                         |
| subtotal      | decimal      | suma de líneas                  |
| descuento     | decimal      | monto del vale                  |
| costo_envio   | decimal      | 0 si retira                     |
| total         | decimal      |                                 |
| observaciones | varchar null |                                 |
| carrito_id    | FK null UNIQUE | el carrito del que nació (tramo F); null en el mostrador |
| medio_pago    | enum null    | `contado`, `pasarela`; null en el mostrador |
| reserva_vence | timestamp null | hasta cuándo el pedido retiene su stock |
| entrega_calle … entrega_provincia | varchar null | la dirección del envío, copiada |

**Lo que la Etapa 2 agrega a `ventas` (tramo F).** Todo `nullable` y sin reescribir datos: el
mostrador no cambia y sus 25.000 filas quedan como estaban. Índices (`estado`, `reserva_vence`), para
los pedidos vencidos que la tarea busca cada minuto, y `canal`, para el filtro del listado.

- **`carrito_id` UNIQUE es la idempotencia de la confirmación** (D-8). Un segundo `POST /pedidos` del
  mismo carrito no puede crear otra venta: la base lo impide, sin tabla ni cabecera aparte. Además
  deja escrito de qué carrito salió cada pedido.
- **`medio_pago` decide la lista** con la que se cotizan los renglones (HL-14): `contado` →
  `precio_contado` (efectivo o transferencia, en el local), `pasarela` → `precio_lista`. Es nulo en el
  mostrador porque allá el medio se elige al cobrar, en `pagos`, y puede ser más de uno.
- **`reserva_vence` dice si el pedido todavía retiene stock**, no sólo hasta cuándo. No nulo: las
  unidades del pedido están en `stock_reservado`. Al cobrarse, `marcarPagada()` las consume
  (`descontar(..., $deReserva)`) y lo pone en nulo; al cancelarse o vencer, se liberan y también. Una
  reserva cuya hora pasó y que nadie liberó **sigue siendo del pedido** (HL-10): cobrarla es válido.
  Plazos: 48 horas de contado (`reservas.contado_horas`), 20 minutos por pasarela
  (`reservas.pasarela_minutos`).
- **La dirección se copia, no se referencia**: editar después las direcciones del cliente no cambia
  adónde se mandó un pedido. Lleva los largos de `direcciones`. `costo_envio` sale de
  `tienda.envio.costo` (8.000, Q-1) con envío, y es cero con retiro.

**El pedido online en la máquina de estados.** Nace en `pendiente_pago`, que **no es una
transición** sino su estado de nacimiento, como `presupuesto` en el mostrador. Desde ahí:
`pagada` (cobrado en el local, o acreditado por la pasarela en el tramo G) o `cancelada` (a mano en la
web, o por la tarea cuando la reserva vence). Ninguna transición nueva: la tabla ya las declaraba. El
pedido no se edita —no es un presupuesto—, así que el precio no se recotiza: es el que se aceptó.

**La lista depende del medio.** El precio de una venta online depende del medio de pago: **contado** (`precio_contado`) para efectivo o transferencia, **lista** (`precio_lista`, «tarjeta y cuotas») para Mercado Pago. Desde el tramo F, `VentaService::escribirLineas()` recibe la lista; el mostrador sigue cotizando a contado.

**Por qué no hay columna `numero`.** El número de venta es el `id`, formateado
`V-00042`, por el mismo criterio que las órdenes de compra: la venta de la Etapa 1 no
es un comprobante fiscal, no la gobierna ARCA y no necesita ser correlativa sin
huecos, y la clave primaria ya garantiza unicidad por construcción —sin contador, sin
tabla auxiliar y sin condición de carrera—. Es la lección de A-18 aplicada: el sistema
original tenía `ventas.numero` sin índice único y `venta_numeracion`, una tabla de una
sola fila sin clave primaria, para generar un número que tampoco era único. La
numeración fiscal, por punto de venta y tipo de comprobante, vivirá en `comprobantes` y
la gobierna ARCA (ver §6, Etapa 4).

### `venta_lineas`
`id, venta_id FK, producto_id FK, descripcion, cantidad, cantidad_devuelta, cantidad_entregada null, precio_unitario, alicuota_iva, costo_unitario, neto, iva, total`

**Por qué se congela tanto en la línea.** `precio_unitario` y `alicuota_iva` congelados es lo único que hace que una venta de hace seis meses siga siendo reproducible después de un aumento. Esto el sistema actual ya lo hacía bien y se conserva.

`costo_unitario` es nuevo: sin él, el margen del dashboard se calcularía contra el costo de hoy, no el del día de la venta, y daría números falsos cada vez que cambia un costo.

`neto` e `iva` se persisten en vez de calcularse al vuelo porque son los importes que se declaran a ARCA. Recalcularlos con una división por `1 + alicuota` en cada consulta introduce diferencias de redondeo de centavos contra lo que ya se declaró.

`cantidad_devuelta` (default 0) habilita la devolución parcial. Es el contador que impide devolver más de lo comprado: la validación es `cantidad_devuelta + cantidad_a_devolver <= cantidad`. Sin esa restricción, alguien devuelve dos unidades tres veces y se lleva seis. Vive en la línea y no en una tabla aparte porque es un saldo, no un evento — el historial de cada devolución está en las notas de crédito.

`cantidad_entregada` (tramo H de la Fase 7.5) es el gemelo de `cantidad_devuelta`: el saldo
que habilita la entrega en dos veces, con el historial de cada entrega en `entregas`. **Se
aparta de lo que este documento había previsto** («`ADD COLUMN` con default 0»): con un
default 0 desde el principio, las 25.025 líneas existentes dirían «cero entregadas» en
ventas que la Fase 6 ya había entregado, y en las devueltas no se sabe si llegaron a
salir. Así que la columna es nullable, las líneas viejas quedan en null («no consta») y el
DEFAULT 0 se fija en un segundo paso, para que las nuevas nazcan con un dato. El único
lugar que traduce null a cero es `VentaLinea::cantidadPendienteDeEntregar()`, y es cierto
ahí: sólo se entrega desde `pagada` o `entregada_parcial`, y en una venta `pagada` de la
Fase 6 no salió nada, porque esa entrega era total y la pasaba a `entregada`. En la primera
entrega de una venta, sus renglones en null pasan a número.

### `venta_linea_impuestos`
`id, venta_linea_id FK cascade, nombre, alicuota, importe, comprobante, orden, created_at`

El desglose de impuestos de cada renglón, congelado. `venta_lineas` guarda un solo `iva`,
que alcanzaba cuando el IVA era el único impuesto; con N impuestos hace falta una fila por
cada uno, con el nombre y la alícuota **copiados**. Es la misma razón por la que la línea
copia la descripción del producto y el costo del día: un comprobante reimpreso el año que
viene tiene que decir lo que se cobró entonces, aunque el impuesto se haya borrado o haya
cambiado de alícuota.

**`venta_lineas.iva` se queda y sigue significando lo mismo:** el IVA, y nada más. Es el
importe que se declara, así que sumarle los impuestos internos lo falsearía. Sale de los
hijos cuya clase de comprobante es `iva`, y con un solo impuesto da exactamente el mismo
número que antes.

**Esto corrigió un cálculo que estaba mal esperando a que alguien lo activara.** Hasta la
Fase 7.5 el neto de la línea salía de dividir el total por `1 + alicuota_iva`. Con un
impuesto general del 10 % adentro del precio, esa división le atribuye ese 10 % al neto: en
un renglón de 1.331 el neto daría 1.100 en lugar de 1.000. No era un problema futuro —
aparecía apenas alguien creara un impuesto general desde la pantalla—, así que la
descomposición pasa a hacerse con el conjunto completo de impuestos, con la misma cuenta
con la que se formó el precio.

Las ventas emitidas antes de la migración no tienen filas acá y se siguen leyendo por
`venta_lineas.iva`, que no se tocó.

### `entregas`
`id, venta_id FK, usuario_id FK null, created_at`

### `entrega_lineas`
`id, entrega_id FK, venta_linea_id FK, cantidad` — UNIQUE(entrega_id, venta_linea_id)

Cada vez que mercadería de una venta sale del local, con qué renglones y cuántas unidades.
**Por qué hacen falta además del saldo de la línea:** con dos entregas, el saldo dice cuánto
salió en total, pero no qué salió la primera vez ni quién lo entregó, y el comprobante de la
primera tiene que poder regenerarse igual. Es la misma división que la devolución: el saldo
en la línea y el hecho en su propio documento.

**El comprobante de entrega es interno.** Su número es el `id` formateado `E-00042`, por el
mismo criterio que la venta y la orden de compra (A-18). No es un remito RG 1415, no lleva
CAI y el pie lo dice; el remito fiscal necesita ARCA y es de la Etapa 2. No lleva precios:
documenta mercadería, no plata. Imprime lo que quedaba por entregar **a esa fecha**, sumando
en la base las entregas hasta ésa inclusive, así que el papel no cambia cuando llega la
siguiente, y no se archiva, igual que el PDF de la orden. `usuario_id` null es «la registró
el sistema». Las dos tablas son de sólo agregado (sin `updated_at`, y el modelo rechaza
modificar y borrar), y la clave a `venta_lineas` es restrictiva: un renglón que salió del
local no se borra. **La entrega no mueve stock:** el stock sale al cobrar (decisión 3 del
plan de la Fase 7.5).

### Máquina de estados

```
mostrador:  presupuesto → pagada → entregada_parcial → entregada
                        ↘ cancelada      ↘ entregada ↗

online:     pendiente_pago → pagada → entregada_parcial → entregada      (Etapa 2)
                           ↘ cancelada      ↘ entregada ↗

            pagada → en_preparacion → despachada  → entregada                (Etapa 4)
                                    ↘ lista_retiro ↗

desde entregada:  → devuelta_parcial → devuelta
                  → devuelta
```

Reglas, todas validadas en el servidor (esto es lo que hoy no existe — hallazgo C-9):

- El stock se descuenta **una sola vez**, al entrar en `pagada`. La reserva del carrito se libera en el mismo movimiento.
- La venta online **nace** en `pendiente_pago`: no hay transición desde `presupuesto`.
- `pendiente_pago → pagada` lo dispara `PagoService` cuando el backend verificó el pago: consultándole a Mercado Pago, con el simulador, o con el cobro en el mostrador si se eligió contado. Nunca el cliente, nunca el regreso del navegador. **No hay webhook**: el backend corre en una red local sin dirección pública, así que el aviso de Mercado Pago no llegaría nunca.
- El **estado del pedido** es `ventas.estado`; el **estado del pago** se deriva de los intentos de pago (ver `pagos`). La consigna de la Etapa 2 pide distinguirlos.
- `en_preparacion`, `despachada` y `lista_retiro` siguen declarados y son de la Etapa 4, con el módulo de envíos. En la Etapa 2 el pedido online se entrega con la entrega del tramo H de la Fase 7.5, igual que uno de mostrador.
- **Nada se borra.** Una venta ya facturada no vuelve atrás: se emite una nota de crédito. Es tu punto 4, y es lo que exige la normativa de facturación.
- `cancelada` sólo desde `presupuesto` o `pendiente_pago`, es decir antes de que exista comprobante y antes de descontar stock. Después de facturar, el camino es la devolución.
- La devolución es **parcial o total según cuánto se devuelva**, no según lo que elija el operador. La venta pasa a `devuelta_parcial` mientras quede algo sin devolver, y a `devuelta` cuando todas las líneas alcanzan `cantidad_devuelta = cantidad`. El estado se deriva de las líneas, no se fija a mano.
- Una venta en `devuelta_parcial` **sigue admitiendo devoluciones**. Es el caso de quien devuelve el mouse en enero y la fuente en marzo.
- La entrega es **parcial o total según lo que salga**, igual que la devolución:
  `Venta::estadoDeEntrega()` compara `cantidad_entregada` contra `cantidad` y el servicio
  escribe ese resultado. `entregada_parcial` admite otra entrega parcial, la entrega del
  resto o la devolución, como `pagada`.
- **Venta con faltante (backorder): analizada y postergada.** Surgió una pregunta
real del negocio: si el cliente quiere 4 auriculares y hay 3, ¿se le venden los 3
y el cuarto se manda a pedir, en lugar de perder la venta? Y la variante de
mostrador: reservarle un producto sin stock para entregárselo cuando llegue.

No entra en la Etapa 1, y queda escrito qué haría falta, porque la mitad del costo
está en decisiones que la Fase 6 toma igual:

- `venta_lineas.cantidad_entregada` y el estado `entregada_parcial` **ya existen**: los
  agregó el tramo H de la Fase 7.5 para la entrega en dos veces, y el test que fijaba
  que el estado era inalcanzable se reemplazó por uno que fija sus dos orígenes. Para el
  backorder falta el resto de esta lista.
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

1. **`mp_payment_id` con índice único es el mecanismo de idempotencia.** Consultarle dos veces a Mercado Pago por el mismo pago, o reintentar una verificación, no puede acreditar dos veces: la restricción lo impide, y `PagoService::cobrar()` ya reconoce el reintento sin escribir nada. Es el escenario del hallazgo C-10.
2. `comision` y `neto_acreditado` (39) son datos del pago, no de la venta. Facturás $100.000 pero te acreditan $93.000; el dashboard de ingresos necesita distinguirlos.

`usuario_id` es el cajero que cobró — lo que permite separar "quién vendió" de "quién cobró" en las métricas. Es nullable: un pago que aprueba la pasarela no tiene cajero.

**`pagos` es el libro de la plata, no el registro de intentos.** El panel suma `SUM(pagos.monto)` y el saldo de la venta sale de ahí, así que una fila es plata que entró o que volvió. **Un intento de pago rechazado no puede ser una fila de `pagos`**: inflaría lo cobrado. La consigna de la Etapa 2 pide que los intentos —pendientes, aprobados y rechazados— queden registrados y asociados al pedido, así que van en una tabla propia, que diseña el plan de esa etapa; `pagos` recibe sólo el intento aprobado. El simulador de pagos tampoco cabe hoy: `metodo` no tiene un valor para él, y registrarlo como `mercadopago` afirmaría algo falso.

### `vales`
**Etapa 4.** La consigna de la Etapa 2 no los pide.

`id, codigo UNIQUE, tipo, valor, monto_minimo, usos_maximos, usos_actuales, vigencia_desde, vigencia_hasta, activo`

`tipo`: `porcentaje`, `envio_gratis`.

Aplicación: uno solo por venta (49), tope global de usos (48), sobre el total de la compra (47). `usos_actuales` se incrementa dentro de la misma transacción que crea la venta, con bloqueo de fila, para que el vale número 101 no se venda por una condición de carrera.

---

## 6. Facturación

**Etapa 4.** La facturación electrónica con ARCA necesita certificados y homologación, y la consigna de la Etapa 2 no la pide. Las tablas **no se crean**, ni vacías: crearlas sin quien las escriba no ahorra nada, y el diseño queda acá.

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
| arca_respuesta          | json null        | payload completo de WSFEv1                                   |
| motivo                  | varchar null     | razón de la NC                                               |

UNIQUE(tipo, punto_venta, numero).

**Por qué el comprobante es una tabla separada de la venta.** Son cosas distintas con ciclos de vida distintos. Una venta puede existir sin comprobante (presupuesto, o factura rechazada por ARCA y pendiente de reintento) y **un comprobante nunca cambia** una vez que tiene CAE. Mezclarlos obligaría a mantener campos fiscales nulos en toda venta no facturada, y a proteger contra la edición de una venta ya declarada.

Además una venta puede tener varios comprobantes: la factura más una o más notas de crédito. `comprobante_asociado_id` es el vínculo que ARCA exige entre la NC y la factura original.

**Por qué `punto_venta` es un número y no una tabla.** Son dos valores fijos que ARCA te asigna. Una tabla de dos filas agrega un join a cada emisión para guardar un entero.

**Por qué la numeración no usa un contador propio.** El sistema actual tiene `venta_numeracion`, una tabla de una fila sin clave primaria, y `ventas.numero` sin índice único (hallazgo A-18). Acá el número correlativo se consulta a ARCA con `FECompUltimoAutorizado` antes de cada emisión, y el `UNIQUE(tipo, punto_venta, numero)` es la red de contención local. La fuente de verdad de la numeración fiscal es ARCA, no tu base.

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

**Etapa 4.** La Etapa 2 entra con el mínimo que pide su consigna —retiro o envío, la dirección congelada en la venta y un costo fijo o cero desde `config/`—, que no necesita esta tabla.

### `envios`
`id, venta_id FK UNIQUE, calle, numero, piso_depto, codigo_postal, localidad, provincia, costo, zipnova_envio_id, tracking_url, estado, fecha_despacho`

**Por qué la dirección se copia en vez de referenciar `direcciones`.** Si el cliente edita su dirección el año que viene, el envío de hoy tiene que seguir diciendo a dónde se mandó realmente. Es el mismo principio que congelar el precio en la línea de venta.

`tracking_url` guarda el link del correo (45): en vez de replicar los estados de la mensajería, se le muestra al cliente el seguimiento del propio servicio.

**Sólo envío a domicilio** (43). El retiro en local no crea fila acá: es `ventas.modo_entrega = 'retiro'` con `costo_envio = 0`.

---

## 8. Contenido de la tienda

**Etapa 4** para los banners. La vidriera de la Etapa 2 son los destacados y las novedades, que sí pide la consigna.

### `banners`
`id, imagen, link, orden, visible_desde, visible_hasta, activo`

Imagen y link (51), con programación por fechas (52). Los administra el administrativo (requerimiento 7).

**No hay tabla de promociones.** Dijiste que la promoción es un destaque visual, no un descuento real (53). El destaque manual es `productos.destacado`; el automático por más vendidos es una consulta agregada sobre `venta_lineas`, sin tabla (Etapa 4: la consigna pide que los destacados sean información administrada en el backend, y los más vendidos no lo son).

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

**Desde el tramo L de la Fase 7.5 no todas viven en el panel, y ninguna cambió de
definición.** El ranking de vendedores y los más vendidos son dos listados paginados
(`InformeVentasService`). «Ventas cobradas» y «ticket promedio» se leen en la fila de
totales del ranking, que **suma las ventas con vendedor**: desde la Etapa 2, las de la app
no tienen vendedor y no entran ahí, mientras que el facturado del panel general sí las
cuenta. Es a propósito —un ranking de vendedores no tiene dónde poner una venta sin
vendedor— y la fila lo dice como «todos los vendedores», no como «todas las ventas». Los descuentos otorgados van en la nota del margen bruto. Cada
número del período se compara con el período anterior de igual largo, y ese número lo
cuenta una segunda consulta con las fechas corridas, no una resta. Las instantáneas
(«para reponer», órdenes abiertas) no se comparan, porque no hay ninguna tabla que guarde
cuántas había antes.

**«Neto acreditado» no se puede calcular en la Etapa 1**, y no es «null por ahora» sino
null por construcción: la informa Mercado Pago, no hay integración, y `mercadopago` no
se ofrece como medio de cobro en el mostrador (`Pago::METODOS_EN_USO`), así que ningún
pago de la Etapa 1 es capaz de tenerla. No se muestra en el panel: un recuadro en cero
afirmaría que no se acreditó nada, que es exactamente lo que el proyecto no hace. La
Etapa 2 integra Mercado Pago **en modo de prueba**; si la consulta del pago trae la
comisión, se guarda, y el panel sigue sin mostrarla mientras sean pagos de prueba.

Todo se calcula **en el servidor** y se devuelve ya agregado. El sistema original
descargaba las tablas completas al navegador y contaba con `.filter()` (hallazgo
A-26); ese patrón no se repite, y hay un test que lo verifica sobre el log de
consultas.

---

---

## 10. Alcance por etapa

El modelo completo es el destino; no todas las tablas se crean a la vez. La Etapa 1 creó
**veintiséis**: las dieciocho de las Fases 1 a 6 y ocho de la Fase 7.5 (precios, impuestos
congelados por renglón, lotes de ajuste y entregas).

| Etapa 1 (backend Laravel, cerrada)                                                | Etapa 2 (tienda móvil)                                                | Etapa 4 (mejoras)                    |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------ |
| `users`, `roles`, `permisos`, `rol_permiso`                                       | `carritos`, `carrito_lineas`                                          | `comprobantes`, `comprobante_lineas` |
| `clientes`, `empleados`, `direcciones`                                            | La tabla de intentos de pago                                          | `envios`                             |
| `categorias`, `marcas`, `productos`, `producto_proveedor`                         | `personal_access_tokens` (credenciales de la API; tramo A)            | `vales` y `ventas.vale_id`           |
| `movimientos_stock`                                                               | Columnas de entrega y de idempotencia en `ventas` (las decide su plan) | `banners`                            |
| `proveedores`, `ordenes_compra`, `orden_compra_lineas`                            |                                                                       | `carritos.session_token`             |
| `ventas`, `venta_lineas`, `pagos`                                                 |                                                                       |                                      |
| `impuestos`, `factores_costo`, `politica_precios`, `historial_precios`, `lotes_ajuste` |                                                                       |                                      |
| `venta_linea_impuestos`, `entregas`, `entrega_lineas`                             |                                                                       |                                      |

**Por qué la Etapa 4 existe.** La consigna de la Etapa 2 define qué entra, y lo que no
pide —facturación con ARCA, envíos calculados, vales, banners, el carrito anónimo— no se
construye en esa etapa aunque esté diseñado acá. Es alcance, no falta de tiempo: mezclar lo
pedido con lo deseable haría imposible saber si la etapa está aprobada. El diseño se
conserva porque las decisiones que lo justifican siguen valiendo.

**Regla para no pagar migraciones dolorosas después.** Las tablas de la Etapa 1 se crean con **todas** las columnas que van a necesitar alguna vez, incluidos los `ENUM` con su juego completo de valores. En Laravel agregar una tabla o una columna nullable es barato; modificar un `ENUM` reescribe la tabla entera.

Casos concretos:

- `ventas.estado` declara sus valores aunque la Etapa 1 sólo use `presupuesto`, `pagada`, `entregada_parcial`, `entregada`, `cancelada` y las dos devoluciones. Eran diez; el tramo H de la Fase 7.5 agregó `entregada_parcial`, cuando la tabla todavía no tenía datos que reescribir.
- `ventas.canal` declara `mostrador` y `online` aunque la Etapa 1 sólo use el primero. El segundo es el de la app.
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
| Ventas             | `ventas`, `venta_lineas`, `venta_linea_impuestos`, `pagos`, `vales`                 |
| Facturación        | `comprobantes`, `comprobante_lineas`                                                |
| Logística          | `envios`                                                                            |
| Contenido          | `banners`                                                                           |
| Precios            | `impuestos`, `factores_costo`, `politica_precios`, `historial_precios`              |


**Qué se eliminó respecto del modelo anterior:** `modulos` (los permisos ya no se agrupan por módulo sino por acción nombrada) y `venta_numeracion` (la numeración fiscal la gobierna ARCA). `perfiles` y `permisos` se rediseñaron, no se eliminaron.

**Qué se conservó:** la separación cabecera/líneas en ventas, el congelamiento de precios en la línea, y los pagos como tabla propia. Eran las decisiones correctas del sistema actual.
