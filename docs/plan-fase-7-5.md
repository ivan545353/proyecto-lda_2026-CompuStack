# Plan de la Fase 7.5 — Escala, parametrización y usabilidad

Fase intermedia entre la Fase 7 y la Fase 8 de cierre. **Cerrada**: fue la última fase de la Etapa 1. La Fase 8 no se hizo —la Etapa 1 ya se había presentado sin ella—, y lo que le quedaba está en la §8 de este documento.

**De dónde sale.** De la devolución de un revisor sobre la demostración de la Etapa 1: catorce observaciones. Diez son defectos o huecos; tres son funcionalidad nueva; una es un compromiso de documentación.

**Alcance:** motor de precios parametrizable, ajuste masivo, búsqueda remota de entidades, carga de renglones por buscador, sugeridos de reposición, entregas y remito del proveedor, impresión configurable, recorte del panel, y un conjunto de arreglos cortos.

**Fuera de alcance:** facturación con CAE, remito fiscal RG 1415, tienda online, cuenta corriente y desacople de entrega y cobro. Cuando se escribió, todo eso era Etapa 2. **Desde el cierre de la Etapa 1**, la tienda online es la Etapa 2 (la app Flutter, con su carrito y sus pagos) y lo demás pasó a la **Etapa 4**, la de mejoras: la consigna de la Etapa 2 no lo pide. El detalle está en `modelo-datos.md` §10.

---

## 1. Los tres ejes

Las catorce observaciones no son catorce problemas distintos. Caen en tres ejes, y dentro de cada eje comparten la solución.

| Eje                     | Puntos del revisor | Qué tienen en común                                                                      |
| ----------------------- | ------------------ | ---------------------------------------------------------------------------------------- |
| **Escala**              | 1, 4, 6, 7         | Hay cosas que crecen con los datos y hoy viajan enteras al navegador o a una sola página |
| **Usabilidad de carga** | 1, 2, 7, 13, 14    | El formulario no acompaña el gesto real: hace tipear lo que el sistema ya sabe           |
| **Parametrización**     | 5, 6               | Lo que el negocio cambia —impuestos, ganancia, recargos— está fijo en el código          |

Quedan afuera cuatro: **8 y 9** son correcciones de una línea, **3 y 12** son funcionalidad nueva, **10** es documentación.

**Una observación sobre el estado del proyecto.** Varios de los puntos del revisor ya estaban registrados como pendientes antes de que él los encontrara: el #32 y el #39 son el peso del catálogo en el HTML, el #30 es la repetición de producto en el armador, el #33 es el aviso de los productos sin proveedor, y el #50 es el tope de 500 filas de la exportación. Esta fase los cierra, y el cierre se anota en `pendientes-usabilidad.md` con el tramo que lo resolvió.

---

## 2. Decisiones tomadas

Se discutieron y se cerraron antes de empezar. No se reabren durante la fase; si alguna resulta equivocada al implementarla, se plantea y se decide de nuevo antes de escribir código, no en el medio.

1. **El costo que se carga es neto de IVA.** El IVA de compra es crédito fiscal, no costo. Si se carga con IVA y después se multiplica por la alícuota, el precio sale un 21 % de más. Hoy no consta en ninguna parte si `orden_compra_lineas.costo_unitario` es neto o no: con el precio tipeado a mano la ambigüedad no hacía daño, con el precio calculado el mismo número da dos precios distintos.
2. **La fase se numera 7.5.** Intermedia, para no renumerar los documentos de las fases cerradas. La Fase 8 iba a ser el cierre; no se hizo (ver §8).
3. **El stock sigue saliendo al cobrar.** La entrega del tramo H documenta el acto físico y no mueve stock. Desacoplar entrega y cobro mueve el invariante central de la Fase 6 y reescribe sus tests: es Etapa 4, junto con la facturación. **La Etapa 2 hereda esta decisión**: la venta de la app también descuenta el stock al cobrarse, y mientras tanto lo reserva con vencimiento (decisión 9).
4. **Las cotizaciones (tramo J) van al final y son recortables.** Es lo único de la fase que agrega una función en lugar de corregir un defecto.
5. **La ganancia es en cascada:** global → categoría → producto. Cada nivel hereda si no define. Un cable y una notebook no se venden con el mismo multiplicador.
6. **Hay precio manual por producto.** Una casilla que lo saca del recálculo, para poder fijar un precio a mano —una promoción, igualar a la competencia, liquidar— sin que el motor lo pise en el próximo ajuste.
7. **El precio de lista sale del formulario del producto** y pasa a ser un porcentaje de recargo en la pantalla de factores. En la base `precio_lista` significa «tarjeta y cuotas», así que ese recargo es el costo financiero de vender en cuotas.
8. **No hay fórmula personalizada.** Factores editables sí; intérprete de expresiones no: no se puede auditar, es una superficie de ataque, y rompería el desglose en neto más IVA contenido que exige la Ley 27.743. La consigna es **configurable, no programable**.
9. **No se reserva stock al emitir el presupuesto.** Sólo se avisa, en tres momentos, y el bloqueo sigue estando sólo en el cobro. Un presupuesto puede no convertirse nunca en venta, así que reservar mercadería por cada cotización dejaría el depósito bloqueado por ventas que no van a pasar. `productos.stock_reservado` es para los checkouts de la Etapa 2, donde sí hay un pago en curso.

---

## 3. Orden y dependencias

```
Base            Modelo           Pantallas          Cierre
A Escala   ──▶  C Precios  ──▶   D Ajuste     ──▶   K Impresión
B Arreglos      E Buscador       F Renglones        L Panel
H Entregas                       G Sugeridos        M Documentos
                                 I Cobro
                                 J Cotización
```

Dentro de una banda los tramos no se esperan entre sí.

- **A va primero y no se discute.** Sin datos a escala, los tramos de escala se «terminan» sin que nadie pueda comprobar que funcionan. Es el tramo que convierte las opiniones en mediciones.
- **C antes que F.** El motor de precios cambia *qué contiene* un renglón y F cambia *cómo se agrega*. Si F va primero, la plantilla del renglón se rehace dos veces.
- **E antes que F, G, I y J.** Los cuatro usan el buscador remoto. Hacerlo una vez y bien evita cuatro implementaciones parecidas.
- **A antes que K.** La impresión del catálogo completo no se puede dar por terminada sin 20.000 productos contra los que probarla.
- **C antes que K y L.** El PDF va a querer imprimir el desglose de impuestos, y el panel muestra margen, que cambia de definición con el motor nuevo. Recortar el panel antes sería recortarlo dos veces.
- **B y H flotan.** No dependen de nada ni nada depende de ellos. B va primero porque son tres arreglos de una tarde y arranca la fase con tramos cerrados.

---

## 4. Los trece tramos

Cada tramo entrega su código, **sus tests** y la actualización de los documentos que toca. Un tramo sin tests no está terminado, y ninguno se cierra sin correr `php artisan test` completo.

### Tramo A — Escala medible

**Objetivo.** Poder reproducir los problemas de escala antes de intentar arreglarlos.

**Por qué va primero.** Con el catálogo de la demostración todos los puntos de escala se ven bien. Los pendientes #32 y #39 lo dicen con esas palabras: «con el catálogo de la demostración son unos kilobytes». No se puede arreglar lo que no se puede medir.

**Tareas.**
- Seeder de volumen: 20.000 productos, 5.000 clientes, 300 proveedores, 10.000 ventas con sus líneas y pagos. **Separado del seeder de demostración y con su propio comando**, para que se cargue cuando se quiere medir y no en cada `migrate:fresh`.
- Índices de búsqueda. `Producto::scopeBuscar()` usa `Like::contiene()`, o sea `LIKE '%texto%'`: un comodín al principio no puede usar un índice, así que con 20.000 productos cada tecla del buscador recorre la tabla entera. Opciones, en orden: buscar el código por prefijo (`LIKE 'texto%'`, que sí usa el índice) y dejar el nombre con comodín doble; índice FULLTEXT en `nombre`; o medir y decidir con el número. Lo mismo para las razones sociales de clientes y proveedores.
- El índice de `pagos` del pendiente #51: hoy sólo existe `(venta_id, fecha)`, y con `venta_id` adelante no sirve a la agregación por fecha ni por método que hace el panel.
- Medición de los ocho listados y del panel, anotada.
- Tests que cuentan consultas con `DB::listen()`. Un N+1 no rompe ningún test: sólo hace la pantalla más lenta, y nadie se entera hasta que hay datos.

**Entregable.** `docs/escala.md`: qué se probó, con cuántos datos, cuánto tardó y dónde está el techo.

**Criterio de terminado.** El listado de productos con 20.000 filas abre en menos de un segundo, y hay un test que fija la cantidad de consultas sin importar el tamaño del conjunto.

### Tramo B — Arreglos cortos

**Objetivo.** Cerrar tres observaciones que no dependen de nada.

**Tareas.**
- **Stock anterior en el kardex** (punto 8). No hace falta migración: `movimientos_stock` guarda `cantidad` **con signo** y `stock_resultante`, y los cuatro métodos de `StockService` respetan eso —incluido `ajustar()`, que guarda la diferencia y no el conteo—. Así que `stock_anterior = stock_resultante − cantidad` es una identidad exacta, garantizada por el único lugar que escribe las dos columnas. Se resuelve con un accesor en el modelo. Se muestra como **«12 → 15»** con el «+3» en el medio, en las tres pantallas donde aparece el kardex: movimientos de stock, ficha de la orden de compra y pestaña de stock del producto.
- **Columnas duplicadas en proveedores** (punto 9). En `proveedores/index.blade.php` la columna «Vinculado a» muestra `productos_count` y `ordenes_compra_count`, y la columna «Productos» vuelve a mostrar `productos_count`. No se borra una: se separa lo que cada una quiso decir. **Productos** = cuántos productos se le compran, enlazado al catálogo filtrado por ese proveedor. **Órdenes** = cuántas tiene, enlazado al listado de compras filtrado. De paso cierra el pendiente #8 para proveedores.
- **Renombrar «Consumidor final (sin identificar)» a «Sin identificar»** (punto 13, primera mitad). Se tocan dos lugares y **no se toca un tercero**:

| Qué                                    | Dónde                      | ¿Se renombra?                                                               |
| -------------------------------------- | -------------------------- | --------------------------------------------------------------------------- |
| La opción del selector de cliente      | `ventas/form.blade.php`    | **Sí**                                                                      |
| El texto de la ficha de la venta       | `Venta::clienteTexto()`    | **Sí**                                                                      |
| La condición fiscal `consumidor_final` | `Cliente::CONDICIONES_IVA` | **No.** Es una categoría de ARCA y decide si va factura A o B en la Etapa 4 |

**Criterio de terminado.** Las tres pantallas del kardex muestran «12 → 15», las dos columnas de proveedores dicen cosas distintas y enlazan, y `Cliente::CONDICIONES_IVA` quedó intacta.

### Tramo C — Motor de precios

**Objetivo.** Que el usuario cargue un solo número —el costo neto— y el sistema calcule el precio de venta a partir de factores que se editan en pantalla.

**Punto de partida.** `productos` tiene `precio_lista` (tarjeta y cuotas), `precio_contado` (efectivo, transferencia y QR), `alicuota_iva` con omisión en 21,00 y `costo_promedio`, que recalcula `StockService::recibirCompra()` como promedio ponderado. Los dos precios **los tipea el usuario a mano**, y las alícuotas están fijas en `Producto::ALICUOTAS_IVA`. En cambio `venta_lineas` **ya congela** `precio_unitario`, `alicuota_iva`, `costo_unitario`, `neto`, `iva` y `total`: media pieza del desglose ya está.

**Las dos clases de factor.** Conflatirlas es el error de diseño que hay que evitar:

| Clase                        | Ejemplos                                                        | Dónde actúa                   | ¿Va en el ticket?              |
| ---------------------------- | --------------------------------------------------------------- | ----------------------------- | ------------------------------ |
| **Forman el neto**           | Ganancia, IIBB que el comercio absorbe, flete, costo financiero | Sobre el costo, antes del IVA | **No.** Van adentro del precio |
| **Se aplican sobre el neto** | IVA, impuestos internos, percepciones                           | Después, y se itemizan        | **Sí, por ley**                |

Cada factor declara su **clase** y su **orden**, y de ahí sale el desglose solo. Detalle fino: para **recuperar** un impuesto del 3 % sobre las ventas no se multiplica por 1,03, se **divide por 0,97** — multiplicando se recupera 2,91 %. Si el factor declara su clase, el motor lo hace bien sin que nadie lo piense.

**El cálculo, en tres etapas.**

```
1.  neto  = costo_neto × ganancia × Π(factores de costo)
2.  final = redondear( neto × Π(1 + alícuota de cada impuesto) )
3.  neto_final = final ÷ Π(1 + alícuota)       iva = final − neto_final
```

Se redondea **el precio final** y el desglose se deriva hacia atrás, nunca al revés. Si se redondea el neto y después se suma el IVA, el ticket no cierra con lo que se cobró, y eso es justo lo que mira la Ley 27.743.

Con el ejemplo del mostrador: 20.000 × 2,5 = 50.000 de neto; × 1,21 = 60.500; desglose 50.000 + 10.500. Da lo mismo que la fórmula de toda la vida, pero soporta N impuestos, redondea bien y el ticket suma.

**Markup o margen.** Las dos convenciones son estándar: markup sobre costo (`neto = costo × (1 + m)`) y margen sobre venta (`neto = costo ÷ (1 − m)`). Un multiplicador de 2,5 es markup del 150 % y margen del 60 %. El campo que se carga es **el multiplicador** —es como piensa el mostrador— y al lado se muestra **el margen equivalente**, calculado. Un campo para escribir, dos números para leer.

**La ley.** Ley 27.743, reglamentada por la RG 5614/2024 de ARCA. Desde el 1/1/2025 para grandes empresas y obligatoria para todos desde el 1/4/2025, en toda venta a consumidor final el comprobante muestra, bajo el título «Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)», dos importes: **IVA Contenido** y **Otros Impuestos Nacionales Indirectos**. Esa es la clasificación que el modelo necesita: cada factor es IVA, es otro impuesto nacional indirecto, o no se muestra. No se implementa la facturación acá, pero el modelo queda listo.

**Tareas.**
- Tablas de impuestos y factores, con clase, orden, alícuota y si se muestra en el comprobante. Precargados: 21 %, 10,5 %, 27 % y exento. Editables, borrables y ampliables desde la pantalla. La ganancia no se puede borrar, sólo editar.
- Declarar que el costo es neto: en el rótulo del campo, en el modelo y en `modelo-datos.md`.
- Servicio de cálculo con las tres etapas y la política de redondeo (múltiplo y modo).
- Cascada de ganancia global → categoría → producto.
- `precio_manual` por producto.
- Historial de precios: fecha, precio anterior, precio nuevo, motivo y usuario. Es lo que hace posible el tramo D, y en particular deshacer un ajuste.
- Módulo **Precios** (el noveno), con permiso propio y sólo para administración: pantalla de factores, y **simulador** —se carga un costo y se ve el precio con su desglose, sin guardar nada—.
- Desglose en la ficha del producto: del costo neto al precio final, factor por factor, con el margen equivalente al lado del multiplicador.
- Sacar el precio de lista del formulario del producto; pasa a ser un recargo en la pantalla de factores.
- **Congelar el desglose completo por renglón.** Hoy `venta_lineas` guarda un `iva`; con N impuestos hace falta una tabla hija —renglón, factor, nombre, alícuota, importe—. Es la misma lógica por la que `costo_unitario` se congela: el ticket reimpreso el año que viene tiene que decir lo que se cobró entonces.
- Permiso `precio.ver_costo`, que gobierna el desglose en la ficha, las columnas de costo en los listados y las columnas de costo en los PDF. Si el vendedor no lo tiene, no lo ve en ninguna de las tres partes.

**Migración sobre datos existentes.** `productos` ya tiene filas. La migración **no cambia ningún precio**: los productos existentes entran con `precio_manual` activado, y nada se recalcula hasta que alguien lo pida.

**Suposición que hay que escribir en los documentos.** El sistema asume que el comercio es **Responsable Inscripto**. Un monotributista no cobra IVA y el IVA de compra sí es costo suyo, así que el motor funcionaría al revés. No se implementa, pero callarlo sería peor.

**Criterio de terminado.** Un producto de costo 20.000 con ganancia 2,5 e IVA 21 % da 60.500, y el desglose suma exacto. Un impuesto nuevo se crea desde la pantalla y aparece en el desglose sin tocar código. Un producto con `precio_manual` no cambia al recalcular.

### Tramo D — Ajuste masivo de precios

**Objetivo.** Que un aumento general o el de un rubro no se cargue producto por producto.

**Los recortes.**

| Recorte                               | Caso real                                                                     |
| ------------------------------------- | ----------------------------------------------------------------------------- |
| Todos los productos                   | Inflación general                                                             |
| Una categoría **y sus subcategorías** | El aumento de las memorias. `scopeDeCategoria()` ya incluye los descendientes |
| Una marca                             | Un fabricante actualizó su lista                                              |
| **Un proveedor**                      | El más frecuente: llega la lista nueva de un mayorista                        |
| Selección manual del listado          | Tres productos puntuales, con casillas                                        |
| Por rango de costo o de precio        | «Todo lo de menos de $10.000»                                                 |
| Cruzados                              | Categoría **y** proveedor a la vez                                            |

**Las operaciones.** Variar el costo un porcentaje (lo correcto cuando el proveedor aumentó: sube el costo y el motor recalcula el precio solo); variar el precio un porcentaje; fijar el multiplicador de ganancia del conjunto; recalcular desde los factores sin cambiar nada más; redondear a múltiplo de X.

**Las tres salvaguardas.** Un botón que cambia 5.000 precios sin red es un botón peligroso.

1. **Previsualización obligatoria.** Antes de aplicar: cuántos productos toca, tabla de «precio actual → precio nuevo», y **alerta de los que quedarían por debajo del costo**. Nada se escribe hasta que se confirma.
2. **Lote reversible.** Cada ajuste es un lote con fecha, usuario, criterio y porcentaje, y el historial guarda el valor anterior de cada producto. **Deshacer el último ajuste** es posible, y es la función que más tranquilidad da. Es además el mismo principio que el resto del sistema: no se pisa un hecho, se escribe el siguiente.
3. **Respeta el precio manual.** Los productos marcados quedan afuera por omisión, con una casilla para incluirlos.

**Dos cosas técnicas.** El ajuste corre en tandas con `chunkById()` —no se cargan 20.000 modelos en memoria— y **no toca los precios congelados** en ventas ya emitidas, que es lo que hace que un presupuesto de la semana pasada siga diciendo lo que decía.

**Opcional.** El programador de tareas ya está andando (la reposición corre a las 07:00), así que un ajuste se puede dejar programado para el 1º del mes. No es obligatorio; la infraestructura ya está.

**Criterio de terminado.** Un aumento del 15 % en una categoría con 3.000 productos se previsualiza, se aplica y **se deshace**, y los presupuestos emitidos antes no cambian.

### Tramo E — Buscador remoto

**Objetivo.** Que el HTML de una pantalla no crezca con el catálogo.

**El problema.** `selects-buscables.js` arranca un Tom Select sobre cada `<select data-buscable>` con `maxOptions: null` —todas las opciones—, y `ventas/_linea.blade.php`, `compras/_linea.blade.php` y `compras/_linea-pedido.blade.php` renderizan **un `<select>` completo de productos por renglón**. Con mil productos y cinco renglones son cinco mil `<option>`. El comentario del propio archivo ya lo anticipa: «listas de cientos de filas se van a buscar en el servidor con un modo remoto (`data-buscable-url`). Esta función queda preparada para recibirlo».

**Lo que Tom Select ofrece.** `load(query, callback)` pide al servidor; `shouldLoad(query)` evita la consulta de una sola letra; `loadThrottle` espera antes de pedir; `maxOptions` topea el desplegable; y el plugin `virtual_scroll` pagina con `firstUrl()` y `setNextUrl()`.

**Tareas.**
- Endpoint de búsqueda por entidad, con permisos y con LIMIT. Devuelve lo que hace falta para decidir: código, nombre, disponible y precio. Eso cierra de paso los pendientes #32 y #39, porque el precio y el mapa de proveedores dejan de viajar en el HTML.
- `data-buscable-url` en el componente, con `shouldLoad` de dos caracteres y `loadThrottle`.
- Cliente y proveedor pasan a remoto.

**Qué queda como está.** Las listas cerradas y cortas (estado, alícuota, método de pago) siguen nativas, y los selectores únicos sobre listas chicas (marcas, categorías, roles) siguen con Tom Select local. El archivo ya razona bien ese criterio.

**Criterio de terminado.** El HTML de la pantalla de ventas no crece con el catálogo, medido contra el seeder del tramo A.

### Tramo F — Carga de renglones

**Objetivo.** Cambiar el gesto de cargar un documento, y avisar del faltante de stock antes del cobro.

**La forma nueva.** Un solo buscador arriba de la tabla, y el renglón se agrega ya con el producto puesto:

1. Se escribe código o nombre; el servidor devuelve hasta 20 resultados con código, nombre, disponible y precio.
2. Se elige uno y **se agrega un renglón** con el producto fijo —texto, no selector—, la cantidad en 1 y el foco en la cantidad.
3. El buscador excluye los productos ya cargados. Así el pendiente #30 deja de existir: no se puede elegir dos veces lo que no se ofrece dos veces.
4. El renglón se quita con un botón. No hay selector que mantener.

Ganancias: un gesto en lugar de dos; teclado puro (buscar, Enter, cantidad, Enter, y el foco vuelve al buscador); y **lector de código de barras gratis** — si el buscador detecta coincidencia exacta de `codigo`, agrega el renglón directo.

**El aviso de stock** (punto 2). Hoy no hay nada al cargar el presupuesto, y la pantalla de cobro sí avisa antes de tipear, con `PagoController::problemasQueVanAFallar()`. El hueco es el primer momento. Se avisa en tres y se bloquea en uno:

| Momento                   | Qué pasa                                                              | ¿Bloquea? |
| ------------------------- | --------------------------------------------------------------------- | --------- |
| Al elegir el producto     | El resultado muestra el disponible; si se pide más, el campo se marca | No        |
| Al guardar el presupuesto | Aviso con el conteo: «2 renglones superan el disponible»              | No        |
| En la ficha y el listado  | Insignia «sin stock suficiente»                                       | No        |
| Al cobrar                 | Lo de hoy: aviso arriba y rechazo del servidor                        | **Sí**    |

El presupuesto debe seguir permitiéndolo: cotizar algo que todavía no llegó es un caso de negocio legítimo. Lo que el sistema tiene que hacer no es prohibir, es no dejar llegar al cobro sin saberlo. Es la misma heurística de la sección 1 de `pendientes-usabilidad.md`.

**Criterio de terminado.** Diez renglones cargados sin tocar el mouse. Un presupuesto que supera el stock avisa al cargarlo, no al cobrarlo. Los tests de la Fase 6 siguen pasando sin tocarlos.

### Tramo G — Sugeridos de reposición

**Objetivo.** Que armar un pedido no obligue a revisar el catálogo producto por producto.

**La consulta ya existe.** El modelo tiene los tres scopes que puso la reposición automática: `scopeStockCritico()`, `scopeReponibles()` y `scopeSinPedidoEnCurso()`. El tercero es el que evita el error más caro —pedir dos veces lo mismo porque la mercadería ya viene en camino—. El comando de las 07:00 ya los usa; el armador manual, no.

**Tareas.**
- Pestaña **«Sugeridos»** en el armador, que abre por omisión, con los críticos sin pedido en curso. Cada fila: stock actual, mínimo, cuánto conviene pedir (`cantidad_reposicion`), proveedor habitual y su último costo.
- Casillas y botones «agregar los seleccionados» y «agregar todos los de este proveedor».
- El aviso del pendiente #33: «3 productos no se pueden pedir porque no tienen proveedor», enlazado al catálogo filtrado.
- Insignia con el conteo en el menú de Compras.

**Una sola consulta para los tres consumidores** —el comando, el armador y el panel—: si cada uno define «crítico» a su manera, el sistema dice tres cosas distintas del mismo producto.

**Criterio de terminado.** Armar el pedido de todos los críticos en dos clics, sin pedir lo que ya viene en camino.

### Tramo H — Entregas y remito del proveedor

**Objetivo.** Registrar el remito que entra y documentar la entrega que sale.

**El marco legal.** El remito está regulado por la RG 1415 de ARCA: se emite antes de que la mercadería se mueva, la acompaña hasta el destino y lleva **CAI**. La RG 5678/2025 habilitó el remito digital —ya no hay que imprimirlo, y se cayeron las copias «ORIGINAL» y «DUPLICADO»— pero **el CAI sigue siendo obligatorio**. Un remito fiscal necesita ARCA, igual que la factura: eso es Etapa 4.

**La mitad barata: el remito del proveedor.** No lo emitimos, lo recibimos, así que no hay CAI ni numeración que cumplir. Hoy `ordenes_compra` no tiene ningún campo de remito. Se agregan `remito_numero` y `remito_fecha` en la recepción, el campo en el formulario junto a las cantidades, y el número visible en la ficha de la orden y en el asiento del kardex. Cierra además el circuito del IVA del tramo C: el remito del proveedor es donde viene la alícuota.

**La mitad valiosa: el comprobante de entrega.** El modelo ya tiene la entrega medio construida: `ventas.estado` tiene `en_preparacion`, `despachada`, `lista_retiro` y `entregada` desde la Fase 1; `entregada_parcial` se agregó por migración propia; las transiciones `pagada → entregada` y `despachada → entregada` están declaradas; y la ruta, el permiso `venta.entregar` y `VentaService::entregar()` existen. Falta `venta_lineas.cantidad_entregada` —que `modelo-datos.md` ya tiene analizado como `ADD COLUMN` nullable barato— y las transiciones que lleguen a `entregada_parcial`, que hoy no alcanza ninguna.

El comprobante de entrega es **no fiscal**, con numeración interna, fecha, quién entregó y qué renglones, y declara en el pie que **no es un remito RG 1415 y no lleva CAI** — igual que el PDF de la orden de compra declara que no es un comprobante fiscal.

**Criterio de terminado.** Una venta se entrega en dos veces, el estado queda en `entregada_parcial` y después en `entregada`, y el comprobante declara lo que no es.

### Tramo I — Usabilidad de carga

**Objetivo.** Sacar dos fricciones del mostrador.

**Alta de cliente desde la venta** (punto 13, segunda mitad). Ventana flotante con los campos de `clientes/crear`. Dos requisitos: usa **el mismo `ClienteRequest`** que el formulario normal —si se duplican las reglas, en seis meses validan distinto; el servidor devuelve 422 y el diálogo muestra los errores en sus campos—, y al guardar el cliente nuevo **se inyecta en el buscador y queda elegido**, sin recargar ni perder los renglones. Lo mismo, simétricamente, para crear un proveedor desde la orden de compra.

**El cobro** (punto 14). Hoy la pantalla muestra tres campos de importe vacíos —efectivo, transferencia y QR— y hay que tipear el monto en el correcto. La pantalla ya hace bien lo difícil: avisa arriba lo que va a fallar, usa `text` con `inputmode="decimal"` en lugar de `number`, y funciona sin una línea de JavaScript. Falta:

1. **Botón «Total» por medio de pago**, que llena el campo con el saldo exacto. Resuelve el 90 % del problema.
2. **Efectivo precargado con el saldo**, que es el caso más común.
3. **Botones de billetes** —2.000, 10.000, 20.000— que calculan el vuelto en pantalla.
4. **Enter envía.**
5. Los medios adicionales **plegados detrás de «dividir el pago»**.

Sobre el vuelto: la pantalla dice textual «el sistema no registra el vuelto», y está bien — el pago imputado es lo que vale la venta. Esto **no lo cambia**: el vuelto se **muestra** como ayuda y no se guarda en ninguna fila.

**Criterio de terminado.** Cobrar una venta al total: un clic y Enter. Crear un cliente sin salir de la venta ni perder los renglones cargados.

### Tramo J — Pedido de cotización *(recortable)*

**Objetivo.** Mantener al día la lista de precios por proveedor, que hoy nadie mantiene.

`producto_proveedor` guarda el costo de cada proveedor por producto y no hay ningún flujo que lo actualice. El pedido de cotización es justamente ese flujo.

**Tareas.** Se eligen productos y proveedores; al guardar se genera **una cotización por proveedor**, igual que «armar pedido» genera una orden por proveedor. PDF por cotización — el sistema **no lo manda**, misma regla que la orden de compra: lo manda la persona y después declara que lo mandó. Cuando el proveedor contesta se cargan los precios, y al guardar **actualizan `producto_proveedor`**. Y la pantalla que lo corona: **comparar** producto × proveedor, elegir el mejor de cada uno y **generar la orden de compra** desde ahí.

**Por qué es el primero que se recorta.** Son dos tablas nuevas, un módulo con sus nueve piezas y tres pantallas, y es lo único de la fase que agrega una función en lugar de corregir un defecto.

**Criterio de terminado.** Se piden precios a tres proveedores, se cargan las respuestas y la orden sale del comparador.

### Tramo K — Impresión configurable

**Punto de partida.** Hay tres PDF para ocho módulos: la orden de compra (Fase 5), el comprobante de venta y el listado de ventas con sus filtros (Fase 7). Los otros seis —productos, clientes, proveedores, categorías, marcas y movimientos de stock— no tienen ninguno. El molde ya está: el comprobante y el listado resuelven las dos formas que hacen falta, un documento y una tabla, y el listado ya imprime los filtros aplicados en el encabezado.

**El límite de filas.** `VentaController::TOPE_EXPORTACION = 500`. El documento se porta bien —declara que recortó, y el total del pie sale de todas las coincidencias y no de las impresas— pero el recorte está, y el catálogo completo no puede tenerlo: una casa de insumos imprime su lista para tenerla en papel cuando se cae el sistema. El pendiente #50 ya propone la salida.

El límite de fondo no se negocia: **dompdf arma todo el documento en memoria antes de escribir el archivo**. Con 20.000 renglones eso no es lento, es que no termina. Por eso **dos motores**:

| Qué se imprime                                          | Motor                                           | Por qué                                                           |
| ------------------------------------------------------- | ----------------------------------------------- | ----------------------------------------------------------------- |
| **Documentos**: orden, comprobante, entrega, cotización | dompdf con vista Blade, como hoy                | Una o dos páginas con diseño. Blade es la herramienta correcta    |
| **Listados masivos**: catálogo, kardex, ventas del año  | Escritor por filas, en tandas con `chunkById()` | Memoria constante: escribe y suelta. Da igual si son 500 o 50.000 |

Hay que **medirlo**, no suponerlo: probar el catálogo con 20.000 productos y anotar tiempo y memoria en `docs/escala.md`. Si dompdf aguanta con una plantilla pelada, se usa uno solo y se escribe por qué.

**Las opciones de personalización.** Un servicio único, y cada módulo declara qué columnas tiene y cuáles piden permiso — misma idea que `patron-modulo.md`: una definición por módulo, un motor compartido. Las que entran:

| Opción                                                                   | Para qué caso real                                                        |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------- |
| Qué columnas incluir                                                     | La lista para el cliente no lleva costo; la interna sí                    |
| Orientación y tamaño de hoja                                             | Un listado de 9 columnas no entra en A4 vertical                          |
| Incluir costos y margen                                                  | **Protegido por `precio.ver_costo`**                                      |
| Densidad (compacta / normal)                                             | Compacta mete el doble de filas: para 400 hojas de catálogo, importa      |
| Agrupar por categoría, proveedor o marca, con subtotales y salto de hoja | Es como se usa una lista en papel: se busca por rubro                     |
| Ordenar por campo y dirección                                            | El catálogo por código para buscar, por nombre para leer                  |
| Totales y conteo de filas                                                | Cerrar una lista sin sumarla a mano                                       |
| Logo y encabezado de la empresa                                          | Para la pared no hace falta; para mandar sí                               |
| Código del proveedor                                                     | Para pedir por teléfono se usa el código de él                            |
| Marca de agua «BORRADOR» / «COPIA»                                       | Que un presupuesto impreso no se confunda con uno aprobado                |
| Sólo activos / incluir dados de baja                                     | Un inventario físico necesita los discontinuados que siguen en el estante |
| Sólo stock crítico                                                       | La hoja para ir a pedir                                                   |

Y cuatro que van **siempre**, sin preguntar: encabezado de tabla repetido en cada hoja, filtros aplicados impresos, fecha y hora con quién lo emitió, y «hoja X de Y».

**Quedan afuera.** Código de barras impreso (Etapa 4); guardar la configuración como plantilla (es otra tabla y otra pantalla); mandarlo por correo (sin SMTP el sistema afirmaría algo que no hizo).

**La pantalla.** Un diálogo «Opciones de impresión» desde el botón del listado, con los filtros ya puestos y las opciones por omisión elegidas: imprimir sin tocar nada tiene que seguir siendo un clic.

**Criterio de terminado.** El catálogo completo imprime con 20.000 productos, con el tiempo y la memoria anotados en `docs/escala.md`. Los ocho módulos imprimen. Un vendedor sin `precio.ver_costo` no puede sacar un PDF con costos. Cierra el pendiente #50.

### Tramo L — Recorte del panel

**Punto de partida.** El panel general tiene hoy **ocho tarjetas** (cobrado, facturado, ventas cobradas, ticket promedio, margen bruto, descuentos otorgados, stock crítico y órdenes abiertas), **dos gráficos** (facturado por día, cobrado por medio de pago) y **cuatro tablas** (ranking de vendedores, productos más vendidos, y las dos que repiten los gráficos). Catorce bloques en una pantalla.

**La pregunta que el panel tiene que contestar** es una sola: **«¿hay algo que requiera mi atención hoy?»**. Todo lo que no contesta eso es un informe, y los informes van en los módulos con sus filtros puestos.

**Qué se recorta.**

1. **Las dos tablas que repiten los gráficos no se borran: se pliegan.** Están por accesibilidad —el `aria-label` de cada gráfico dice «los mismos números están en la tabla»— así que borrarlas rompería esa promesa. Van detrás de un «ver los números».
2. **Ranking de vendedores y productos más vendidos salen del panel** y se vuelven listados con sus filtros. Se miran una vez por mes, no todos los días.
3. **De las ocho tarjetas quedan cinco**: cobrado, facturado y margen bruto arriba; stock crítico y órdenes abiertas en la zona de atención. Ventas cobradas y ticket promedio se leen del listado.
4. **Un gráfico, no dos.** Facturado por día es la tendencia; cobrado por medio de pago es un corte y vive mejor como tabla plegada.
5. **Cada número con su comparación.** Hoy cada tarjeta tiene una nota que aclara qué significa —está bien— pero ninguna dice contra qué. «$2.400.000» no dice nada; «$2.400.000, 12 % más que el mes pasado» sí. El selector de período ya existe, así que es una segunda consulta con las fechas corridas.

**Lo que falta y es barato.** `panel/_tarjeta.blade.php` dibuja un rótulo, un número y una nota, **sin enlace**. Así «Stock crítico: 12» informa y deja a pie: hay que ir al catálogo y filtrar a mano. Que cada número de la zona de atención sea un enlace al listado ya filtrado es lo que separa un panel decorativo de uno accionable, y es donde aterrizan el punto 2 —presupuestos que no se van a poder cobrar— y el punto 7 —lo que hay que pedir—.

**Los paneles por rol se mantienen.** Cada rol ve lo que puede resolver.

**Criterio de terminado.** Ningún número de atención sin enlace, ninguno sin comparación, y cada gráfico conserva su tabla accesible.

### Tramo M — Cierre documental

**Cómo terminó.** No se hizo como tramo. La facturación electrónica salió del alcance de la
Etapa 2, así que `docs/arca.md` dejó de ser un compromiso de esta fase y pasó a la Etapa 4.
Lo demás se hizo al cerrar la Etapa 1, al reconciliar los documentos con el alcance nuevo:
AFIP → ARCA en las dos vistas que lo decían y en los documentos, `modelo-datos.md` y su
§10, `plan-migracion.md` con esta fase, y `trazabilidad.md`. Las líneas de cierre de los
puntos del revisor no se copiaron a `auditoria.md`: el cuadro de la §7 es ese registro, y
duplicarlo daría dos lugares donde decir lo mismo. Lo que quedó como estaba está en la §8.

**Tareas, como se planearon.**
- `docs/arca.md`: el instructivo de facturación electrónica, paso a paso, para cuando se implemente. Clave privada y CSR; subirlo a ARCA y obtener el certificado; delegar el servicio al certificado; **WSAA** y el ticket de acceso que vence; **WSFEv1**, `FECAESolicitar`, tipos de comprobante y punto de venta; homologación contra producción; el CAE, su vencimiento y qué hacer cuando el servicio no responde.
- **AFIP → ARCA** en todo el proyecto. El organismo se llama ARCA desde octubre de 2024, y los documentos y el pie del comprobante todavía dicen AFIP.
- `auditoria.md`: los hallazgos nuevos con su línea de cierre y el test que los fija.
- `modelo-datos.md`: las tablas nuevas, y sobre todo **la declaración de que el costo es neto**.
- `pendientes-usabilidad.md`: cerrar #30, #32, #33, #39 y #50.
- `plan-migracion.md`: incorporar esta fase a la lista.
- `trazabilidad.md`: los componentes nuevos.

**Criterio de terminado.** Los hallazgos del revisor tienen su línea de cierre, como los 36 anteriores.

---

## 5. Qué se recorta si el tiempo no alcanza

En este orden:

1. **J — Cotizaciones.** Primero y sin dudar: es lo único que agrega una función en lugar de corregir un defecto. El punto 12 queda registrado como decisión documentada.
2. **El comprobante de entrega de H**, quedándose sólo con el remito del proveedor, que es la mitad barata y la que cierra el circuito del IVA.
3. **Las opciones de impresión de K que no sean las cinco primeras.** El motor y el catálogo sin tope se quedan; agrupar por proveedor puede esperar.
4. **Los botones de billetes del cobro**, quedándose con «Total» y el efectivo precargado, que es el 90 % del beneficio.

**No se recorta por ningún motivo:** A, C y el aviso de stock de F. A porque sin él no se puede demostrar nada de lo que el revisor pidió; C porque es el cambio de fondo; el aviso de stock porque es el único hueco que el proyecto no tenía registrado.

---

## 6. Riesgos

| Riesgo                                                           | Por qué puede pasar                                                                     | Cómo se acota                                                                                                                                  |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **La fase es más grande que cualquiera de las anteriores**       | Trece tramos, seis tablas nuevas y un módulo nuevo                                      | El orden de recorte está escrito y empieza por lo que no corrige nada. Cada tramo cierra solo: si la fase se corta en G, lo entregado funciona |
| **El motor de precios toca una tabla con datos**                 | `productos` ya tiene filas y pasa a tener costo neto, precio manual y ganancia heredada | La migración no cambia ningún precio: los productos existentes entran con `precio_manual` activado                                             |
| **Rehacer la carga de renglones puede romper la venta**          | F toca la pantalla más probada del sistema                                              | Los tests de la Fase 6 son la red: no se tocan y tienen que seguir pasando. Si F los rompe, es F lo que está mal                               |
| **El PDF del catálogo completo puede no ser posible con dompdf** | La Fase 7 ya chocó con esto: el tope de 500 existe por eso                              | Por eso A va primero. Si el número dice que no, el escritor por filas ya está previsto, y queda justificado con la medición                    |

**Un riesgo que no es técnico.** El proyecto vale por su disciplina: cada decisión tiene escrito su porqué. Una fase de este tamaño, hecha con apuro, es la que rompe esa disciplina — se escribe el código y los documentos quedan para después. Si hay que elegir entre un tramo más y dejar los documentos al día, **se dejan los documentos al día**. Un tramo menos se explica en treinta segundos; un `modelo-datos.md` que ya no describe el modelo le saca al proyecto lo mejor que tiene.

## 7. Estado de los catorce puntos del revisor

La fase se trabaja un tramo por chat, así que este cuadro es el que evita reconstruir
de memoria qué punto cerró cada tramo. **Cada tramo lo actualiza al cerrar**, y el
tramo M lo usa para escribir las líneas de cierre en `auditoria.md`.

| Punto | Qué pide                                                                  | Tramo                                         | Estado                               |
| ----- | ------------------------------------------------------------------------- | --------------------------------------------- | ------------------------------------ |
| 1     | Escala y carga de renglones: el catálogo entero viaja al navegador        | **E** la escala · **F** la carga              | **Cerrado**                          |
| 2     | Nadie avisa del faltante de stock al cargar el presupuesto                | **F**                                         | **Cerrado**                          |
| 3     | Funcionalidad nueva — ⟨no transcrito⟩                                     | —                                             | **No transcrito al cierre**          |
| 4     | Escala                                                                    | A midió · **E** resolvió                      | **Cerrado**                          | Medido |
| 5     | Parametrización: impuestos, ganancia y recargos fijos en el código        | **C**                                         | **Cerrado**                          |
| 6     | Escala y parametrización: el precio se corrige producto por producto      | **C** el motor · **D** el ajuste masivo       | **Cerrado**                          |
| 7     | Lo que hay que pedir no está a mano                                       | **G** la pantalla · **L** el enlace del panel | **Cerrado**                          |
| 8     | El kardex no muestra el stock que había antes del movimiento              | **B**                                         | **Cerrado**                          |
| 9     | Dos columnas duplicadas en el listado de proveedores                      | **B**                                         | **Cerrado**                          |
| 10    | Compromiso de documentación: el instructivo de facturación                | Etapa 4 (`docs/arca.md`)                      | **Trasladado a la Etapa 4**          |
| 11    | ⟨no transcrito⟩                                                           | —                                             | **No transcrito al cierre**          |
| 12    | Funcionalidad nueva: pedido de cotización                                 | J (recortable)                                | **Recortado — decisión documentada** |
| 13    | «Consumidor final (sin identificar)», y el alta de cliente desde la venta | **B** la primera mitad · **I** la segunda     | **Cerrado**                          |
| 14    | La pantalla de cobro hace tipear lo que el sistema ya sabe                | **I**                                         | **Cerrado**                          |

**De dónde sale cada fila, y qué falta.** *Al cierre de la Etapa 1:* los puntos 3 y 11
nunca se transcribieron de la devolución, y quedan marcados como tales en lugar de
«Abiertos» para siempre. El 10 se trasladó a la Etapa 4 con la facturación. *Lo que
sigue es como se escribió durante la fase.* La sección 1 de este documento agrupa los
catorce puntos por eje y nombra explícitamente el 2, el 8, el 9, el 10, el 12, el 13
y el 14; los ejes ubican al 1, 4, 5, 6 y 7. **El texto literal de los puntos 3 y 11 no
está transcrito en ningún documento del proyecto**, así que quedan marcados en lugar
de completados a ojo: hay que copiarlos de la devolución del revisor.

**Lo que el tramo A cerró y no es un punto del revisor.** La medición encontró un
defecto que nadie había registrado —una consulta a la base por cada `@can` de la
pantalla— y lo cerró. Está en `docs/escala.md` §3.1 y como pendiente #53. No entra en
este cuadro porque no lo pidió el revisor: lo encontró el instrumento que el tramo A
construyó, que es el mejor argumento a favor de haberlo construido.

**Lo que el tramo C cerró y tampoco es un punto del revisor.** Al escribir el desglose
congelado por renglón apareció un cálculo que estaba mal esperando a que alguien lo
activara: `venta_lineas.neto` salía de dividir el total por `1 + alicuota_iva`, y con un
impuesto general adentro del precio esa división le atribuye ese impuesto al neto —en un
renglón de 1.331, un neto de 1.100 en lugar de 1.000—. No estaba roto porque hasta este
tramo no había forma de crear un impuesto general; lo habría estado el día que alguien
usara la pantalla nueva. Se cerró en el mismo tramo que lo habilitaba, descomponiendo con
el conjunto completo de impuestos, y lo fija `VentaDesgloseTest`. No entra en el cuadro
porque no lo pidió el revisor: lo encontró el cambio que lo volvía posible.

**Qué cerró el tramo D del punto 6.** El motor del tramo C recalculaba un producto; el
tramo D lo volvió una operación sobre un conjunto, con las tres salvaguardas que el plan
pedía: previsualización obligatoria con los conteos del conjunto completo y la alerta de
los que quedarían por debajo del costo, lote reversible con su criterio congelado, y
respeto del precio manual con su casilla y su número antes de aplicar. Entraron los siete
recortes y las cinco operaciones. El criterio de terminado se verificó de punta a punta:
un aumento en una categoría se previsualiza, se aplica y se deshace, y los presupuestos
emitidos antes siguen diciendo lo que decían, porque el renglón congela su precio desde la
Fase 6.

**Lo que el tramo D dejó afuera a propósito.** El ajuste programado para el 1º del mes: el
plan lo declara opcional, la infraestructura del programador ya está, y meterlo era hacer
crecer el alcance. Y el filtro por estado como octavo recorte: el ajuste alcanza también a
los productos dados de baja —un discontinuado que sigue en el estante se sigue vendiendo— y
la pantalla lo dice con esas palabras en lugar de ofrecer un recorte que el plan no pide.

**Qué cerró el tramo E.** El punto 4 completo y la mitad de escala del punto 1: el
catálogo dejó de viajar al navegador. Las tres pantallas que lo mandaban —formulario
de venta, armador de pedido y filtro del kardex— pasaron de 14.994 KB de HTML por
carga a 119,5 KB, y los 392 MB de pico del armador son 48. Era el único número de toda
la medición del tramo A que describía una falla y no una molestia, porque el límite
por omisión de PHP son 512 MB. Entraron el endpoint con su contrato —cuatro búsquedas,
cada una con el permiso de ver su entidad, tope de veinte con aviso de recorte, y el
costo sólo con `precio.ver_costo`—, el modo remoto del componente, las cuatro
pantallas convertidas y el test que fija el peso del HTML. Cerró además los pendientes
#32, #39 y #52, y de paso el #54. La medición completa está en `docs/escala.md` §6.

**Por qué el punto 1 queda «cerrado a medias» y no cerrado.** La §1 de este documento
lo pone en los dos ejes a la vez: es escala y es usabilidad de carga. La escala la
cerró E y está medida; lo que falta es el gesto —un solo buscador arriba de la tabla
en lugar de un selector por renglón— y eso es el tramo F, que ahora puede hacerlo
porque el buscador existe. Se usa la misma fórmula que el punto 13, que está a medias
entre B e I desde que la fase empezó.

**Lo que el tramo E dejó afuera a propósito.** Los selectores de proveedor: 303
opciones que crecen con el padrón y no con el catálogo, con una decisión pendiente que
no correspondía tomar apurado —el filtro del catálogo necesita ofrecer los inactivos y
el endpoint devuelve sólo activos, y son dos preguntas distintas—. Queda como pendiente
#57. Y el selector de productos en la pantalla del ajuste masivo, que el tramo D dejó
anotado como continuación natural: el buscador ya está, así que se puede hacer, pero es
alcance nuevo y no un defecto, y hacerlo de paso habría sido hacer crecer el tramo
mientras se escribía.

**Lo que el tramo E cerró y no es un punto del revisor.** Una filtración de datos que
nadie había registrado: `compras/_linea.blade.php` ponía el `costo_promedio` de cada
producto en un atributo `data-` de cada opción, para cualquiera con `compra.crear` y
sin mirar `precio.ver_costo`. El permiso existía desde el tramo C y gobernaba el
desglose de la ficha, las columnas de costo de los listados y las de los PDF; esta
cuarta puerta no estaba cerrada. Se cerró en el mismo tramo que la encontró, y lo fija
el test del endpoint que exige que sin el permiso **la clave no esté** —ausente, no en
null, porque null significaría «no tiene costo cargado» y eso sería afirmar un hecho
que el servidor no contestó—.

**Lo que el tramo D cerró y no es un punto del revisor.** Dos defectos propios, los dos
encontrados por sus tests: un aumento de costo que no movía el precio no dejaba asiento y
quedaba fuera del «deshacer», y el porcentaje negativo escrito en formato argentino —«-7,5»,
tal como la pantalla lo pide— lo rechazaba el servidor. Están contados en
`trazabilidad.md` §14.

**Qué cerró el tramo F.** El punto 2 completo y la mitad de usabilidad del punto 1.
El gesto de cargar un documento cambió en las dos pantallas donde se cargan
renglones: un solo buscador arriba de la tabla, y el renglón nace con el producto
puesto —texto fijo, no un selector—, la cantidad en 1 y el foco en la cantidad. El
buscador no ofrece lo que ya está cargado, y de ahí que el pendiente #30 deje de
existir: no por una validación nueva, sino porque no queda selector donde elegir dos
veces lo mismo. Entró el único parámetro que el tramo le agrega al contrato del
tramo E, `excluir[]`, con su tope de cincuenta y su decisión de no exigir que los
ids existan. Y el aviso de faltante quedó en los tres momentos que el plan pedía
—al elegir el producto, al guardar y en la ficha y el listado— con el bloqueo
intacto donde estaba, en el cobro.

**Qué cerró el tramo G.** La mitad de pantalla del punto 7: lo que hay que pedir está a
mano. El sistema ya lo sabía —la reposición de las 07:00 lo calcula con tres scopes— y
la persona que armaba un pedido no tenía forma de enterarse sin recorrer el catálogo
producto por producto. Ahora «Armar pedido» entra por una pantalla que lista lo que está
por debajo del mínimo y todavía no se pidió, agrupado por el proveedor al que conviene
pedirle cada uno, con un botón por grupo: el pedido de un proveedor son dos clics —el
botón del grupo y guardar—. El armador a mano queda como la otra pestaña. Entró también
el aviso del pendiente #33, con su conteo y su enlace al catálogo filtrado por un filtro
nuevo, `?stock=sin_proveedor`.

**Una sola definición de «lo que hay que pedir», que era la condición del tramo.** El
plan pedía una consulta para los tres consumidores, y había dos distintas: el comando
encadenaba `reponibles()->stockCritico()->sinPedidoEnCurso()` y el panel escribía
`where('activo', true)->stockCritico()`. Un producto con `stock_minimo = 0` y stock en
cero entraba en el conteo del panel —porque 0 ≤ 0— y el comando lo ignoraba a propósito,
así que el panel decía un número y el comando generaba otro sin que nadie pudiera
explicar la diferencia. Ahora la composición vive una vez, en
`Producto::scopeParaReponer()`, y la usan el comando, la pantalla y el panel. Es el mismo
movimiento que el tramo F hizo con «quién supera el disponible».

**Por qué el punto 7 queda cerrado a medias.** Falta el enlace del panel: la tarjeta que
cuenta lo que hay que reponer todavía no lleva a la pantalla que lo muestra. Ese enlace es
del tramo L, que es el dueño del recorte del panel, y el tramo G le dejó las dos piezas
que necesita —el conteo ya cuenta lo mismo que la pantalla, y la tarjeta cambió de rótulo
a «Para reponer» para que el enlace no prometa otra cosa—.

**Lo que el tramo G no pudo hacer como el plan lo pedía, y por qué.** El plan pedía una
pestaña «Sugeridos» dentro del armador. No se puede sin romper `PesoHtmlTest`: ese test
exige que `/compras/pedido` pese exactamente lo mismo con cinco productos que con sesenta,
y los productos que el test crea son **todos** sugeridos —`ProductoFactory` deja el stock
en cero con un mínimo entre 2 y 10—, así que cualquier forma de rendirlos ahí la hace
crecer con el catálogo. Las dos pantallas se presentan como pestañas que son enlaces: el
`<h1>` dice «Armar pedido» en las dos, cada una tiene su URL, y el peso del armador no
depende de nada que crezca. Es la decisión que el tramo tomó primero y la que condicionó
todo lo demás.

**Lo que el tramo G cerró y no es un punto del revisor.** El conteo del panel contaba como
crítico lo que no se repone solo, dicho arriba. Y la selección de a página del catálogo
—las casillas del ajuste masivo del tramo D— estaba escrita contra los ids de esa pantalla:
la pantalla nueva necesitaba el mismo gesto, así que el bloque de `app.js` se generalizó en
lugar de duplicarse. Las dos pantallas eligen de a página con el mismo código, y las
casillas se ven: miden un 35 % más, quedan negras como el resto del sistema en lugar de
celestes, y la fila marcada se tiñe. Un gesto que es la acción principal de dos pantallas
no podía seguir siendo un cuadradito de 16 píxeles.

**Lo que el tramo G dejó afuera a propósito.** El gesto de carga del tramo F no se llevó al
armador: su unicidad es el par (producto, proveedor), así que excluir por id sería más
restrictivo que el servidor y haría desaparecer un producto que sí se le puede pedir a otro
proveedor. Hace falta un `excluir_pares[]`, que es un parámetro más grande y toca el
contrato del tramo E; queda como pendiente #65 y los sugeridos no lo necesitan, porque no
agregan filas con un buscador. Tampoco se agregó un filtro por proveedor a la pantalla
—pendiente #64—: el proveedor habitual lo decide una regla de PHP, así que filtrar por eso
en SQL pide recorrer todo el conjunto en memoria, y un filtro por «quién lo provee» sería
otra pregunta y mostraría productos que se le van a pedir a otro. Y los sugeridos elegidos
reemplazan lo que el armador tuviera cargado en lugar de sumarse, porque el armador no
guarda estado entre pantallas: pendiente #63.

**El costo que el tramo agregó, dicho sin adornos.** La insignia del menú es una consulta
más en **toda** pantalla del sistema para quien tiene `compra.aprobar`, y alrededor de
1,1 KB más de HTML en todas, por el ítem nuevo del menú y la insignia. No se cachea a
propósito: un conteo guardado un minuto afirma algo que puede ya no ser cierto. Está medido
y anotado en `escala.md` §8.

**Por qué el punto 1 queda cerrado y no a medias.** La §1 lo pone en los dos ejes a
la vez. La escala la cerró el tramo E y está medida; el gesto lo cierra este tramo,
y de paso la pantalla adelgazó otra vez, porque el `<select>` por renglón que el
tramo E había dejado con una sola opción ya no existe. Las dos mitades están, así
que la fórmula del punto 13 no hace falta.

**Cómo se resolvió el aviso de stock, que es la parte que no se recorta.** La
pregunta «¿qué renglón pide más de lo que hay?» tiene una sola respuesta,
`Venta::faltantesDeStock()`, y la usan la ficha, el listado y la pantalla de cobro
—que antes tenía su propia resta—. El listado la repite en SQL con
`VentaLinea::scopeSuperanElDisponible()`, porque contarlo en PHP sería un N+1 por
fila y `EscalaTest` lo rechazaría; la resta sigue viviendo en un solo lugar,
`Producto::DISPONIBLE`, y un test obliga a los dos lenguajes a dar el mismo número.
Al guardar, el mensaje **cambia de color en lugar de sumar un segundo recuadro**: si
hay faltantes es azul y lo cuenta, porque un verde afirmaría que quedó todo listo y
lo que quedó es un presupuesto que hoy no se puede cobrar. La insignia se muestra
sólo en presupuestos: una venta cobrada ya descontó su stock, y compararla contra el
disponible de hoy avisaría de un problema que no existe.

**Lo que el tramo F cerró y no es un punto del revisor.** Tres cosas, y las tres las
encontró el cambio que las volvía visibles. La primera: el buscador de la orden de
compra recortaba por el proveedor **guardado** y no por el elegido, así que cambiar
el selector dejaba ofreciendo los productos del proveedor anterior — justo lo que el
docblock de `OrdenCompraRequest::provistos()` prometía que la pantalla evitaba. La
segunda: el renglón de compras ya no publica ningún costo en el HTML, y eso cierra
por construcción la puerta que el tramo E cerró con una comprobación de
`precio.ver_costo`, porque el costo sugerido ahora llega con el resultado de la
búsqueda y ahí el endpoint ya decide si corresponde. La tercera, menor: los campos
de cantidad y de costo de los dos renglones no tenían su error enlazado por
`aria-describedby`, y el rótulo oculto decía «Cantidad» diez veces sin decir de qué
—con un selector por fila el nombre del producto no existía hasta elegirlo; con el
producto en texto, sí—.

**Lo que el tramo F dejó afuera a propósito.** El **armador de pedido** no se tocó, y
la razón no es el tiempo: su unicidad no es el producto sino el par
(producto, proveedor) —`PedidoCompraRequest::withValidator()` lo dice—, así que
excluir por id sería más restrictivo que la regla del servidor y dejaría de ofrecer
un producto que sí se le puede pedir a otro proveedor. Excluir pares es un parámetro
distinto y más grande, y el pendiente #30 nombra sólo el formulario de la orden.
Tampoco se hizo **sólo lectura el proveedor del borrador**, aunque la pregunta se
planteó: el borrador es una sugerencia esperando autorización, y volver a apuntar la
misma canasta a otro distribuidor cuando la lista de uno aumentó es la corrección más
común a lo que propone la reposición de las 07:00. Quitarla habría costado más que lo
que arregla, y el defecto real —que la pantalla no avisa qué renglones el proveedor
nuevo no provee— queda como pendiente #61. Y queda el **tope de cincuenta
renglones**, que el buscador sigue ofreciendo y el servidor rechaza: es pendiente
#60, y no se hizo porque publicar ese número a la vista pide una constante en dos
Form Requests que este tramo no tenía por qué abrir.


**Qué cerró el tramo H.** El criterio de terminado completo. Una venta se entrega en dos
veces y queda en `entregada_parcial` y después en `entregada`. Cada entrega deja su
comprobante interno, que declara en el pie que no es un remito RG 1415 ni lleva CAI. Una
recepción registra el remito del proveedor, y su número se ve en la ficha de la orden y
en el kardex. La entrega no mueve stock: el stock sigue saliendo al cobrar (decisión 3).
No tiene fila en el cuadro porque el texto literal de los puntos 3 y 11 no está
transcrito; si la entrega es uno de ellos, se marca al copiarlo de la devolución.

**Lo que el tramo H decidió distinto del plan.** El remito vive en el asiento de cada
recepción y no en `ordenes_compra`: con una recepción parcial, la segunda habría pisado el
remito de la primera. Y hay una tabla de entregas que el plan no nombraba, porque con un
solo saldo en la línea el comprobante de la primera entrega no se puede regenerar
después de la segunda.

**Lo que el tramo H dejó afuera a propósito.** El remito fiscal con CAI, que es Etapa 4.
El #37, porque corregir el costo en la recepción es otra decisión, aunque la pantalla es la
misma. Y tres pendientes nuevos, del #66 al #68.


**Qué cerró el tramo I.** Los puntos 13 y 14. Un cliente se crea desde la venta en una
ventana que pasa por el mismo `ClienteRequest` que `clientes/crear`, muestra los errores en
sus campos y lo deja elegido en el buscador sin recargar ni perder los renglones. Sin
JavaScript, el botón es un enlace a la pantalla de clientes y el `<noscript>` dice qué se
pierde. En el cobro, el efectivo llega con el saldo en el value que rinde Blade, cada medio
tiene su «Total», los billetes calculan el vuelto en pantalla sin guardarlo y una suma en
vivo nombra qué medio lleva cuánto. Cobrar al total en efectivo es Enter, sin un clic; por
otro medio son dos clics y Enter, porque el plan pliega los adicionales (pendiente #70).

**Lo que el tramo I decidió distinto del plan.** El alta de proveedor desde la orden no se
hizo: un proveedor recién creado no provee nada, así que la orden quedaría elegida y sin
poder guardarse (pendiente #69). La precarga del efectivo contradice el pendiente #41, que
la había descartado; ganó el plan, con dos contrapesos escritos en el cierre del #41.
«Dividir el pago» es un `<details>` y no un `collapse` de Bootstrap, porque un `collapse`
sin JavaScript queda cerrado para siempre y el cobro tiene que funcionar sin una línea de
JavaScript. Nace abierto si trae un importe o un error, para no plegar nada ya cargado.

**Lo que el tramo I cerró y no es un punto del revisor.** En `clientes/form`, el error del
tipo de documento, del correo y del teléfono no estaba enlazado por `aria-describedby`. Al
pasar los campos a un parcial compartido con la ventana se corrigió en un solo lugar.

**Por qué el tramo J se recortó.** El punto 12 queda como decisión documentada y no como
deuda. Se decidió al abrir el tramo, antes de leer código, y es el recorte que la §5 pone
primero «sin dudar»: la decisión 4 de la §2 ya lo había mandado al final por ser lo único de
la fase que agrega una función en lugar de corregir un defecto. Costaba dos tablas, un módulo
con sus nueve piezas, tres pantallas y una migración sobre `producto_proveedor`, que es una
tabla con datos. Esa migración además pedía una decisión de fondo antes de escribirse:
`costo_ultimo` es lo que se pagó al recibir, y un precio cotizado es lo que el proveedor dice
que va a cobrar. Tomarla con apuro es el único riesgo no técnico que nombra la §6, y la fase
estaba corta de tiempo. **Qué queda como estaba:** `costo_ultimo` lo escribe la recepción
con `ProductoProveedorService::registrarCosto()`, o alguien a mano, vínculo por vínculo,
desde los proveedores del producto. No hay forma de cargar de una vez la lista de un
proveedor. Queda anotado como pendiente #71, con la decisión de la columna planteada para
quien lo retome. El #69 sigue abierto, porque cargar la respuesta de una cotización era el
lugar natural para cerrarlo. El tramo K no imprime cotizaciones, aunque la tabla de motores
de su sección las nombre.


**Qué cerró el tramo K.** El criterio de terminado completo. Los ocho módulos imprimen
desde el botón de su listado, con los filtros puestos: el catálogo, ventas, compras,
clientes, proveedores, categorías, marcas y el kardex. El catálogo de 20.012 productos sale
entero como planilla. Un vendedor sin `precio.ver_costo` no puede sacar costos: la columna
no le aparece, y pedirla igual se rechaza. El #50 está cerrado. Las cuatro cosas que van
siempre van siempre: el encabezado de la tabla en cada hoja, los filtros aplicados, la fecha
y quién lo emitió, y «Hoja X de Y». Imprimir sin tocar nada es un clic, con JavaScript y
sin él. Los puntos 3 y 11 siguen sin transcribir; si alguno es la impresión, se marca al
copiarlo de la devolución.

**Lo que el tramo K decidió distinto del plan.** No se midió. El plan pedía medir el PDF
del catálogo y anotarlo en `escala.md`, pero la §8 de ese documento había cerrado las
mediciones. Por eso la salida es la que no necesita un número: dompdf con su tope de 500
para el papel, y un CSV escrito por filas para el listado completo, que es lo que el #50 ya
proponía. Lo que se pierde es la lista en papel de 20.000 renglones con el formato del
sistema: sale de la planilla. **Para el cierre:** la línea del tramo K en la §9 de
`escala.md` («se mide en su tramo») quedó vieja, y no se tocó porque ese documento está
cerrado. Y agrupar es por categoría y por marca: por proveedor no, como deja la §5, porque
un producto que se le compra a dos aparecería en los dos grupos.

**Lo que el tramo K dejó afuera a propósito.** Las siete opciones que la §5, punto 3,
permite recortar. **Ordenar por campo:** el PDF sigue el orden de la pantalla, y la planilla
se ordena con un clic. **Totales y conteo:** como opción no, pero el conteo va siempre y las
sumas van donde el listado las declara (ventas y compras), así que el caso real quedó
cubierto. **Logo de la empresa:** el encabezado con el nombre y el CUIT va siempre; el
sistema no tiene un logo cargado. **Código del proveedor:** vive en el par (producto,
proveedor) y sólo tiene sentido agrupando por proveedor, que quedó afuera. **Marca de agua:**
es de los documentos, y el tramo no los tocó. **Activos o dados de baja, y stock crítico:**
ya son filtros del listado, y el papel imprime lo que el listado filtra. Si un día se
imprime «lo que hay que pedir», es `Producto::scopeParaReponer()` y no otra definición.

**Lo que el tramo K cambió de fases anteriores.** Con permiso, tres tests de
`VentaModuloTest`. Fijaban que el listado declara sus filtros, que dice cuándo no tiene
ninguno y que, cuando recorta, el total es de todas las coincidencias. Rendían
`ventas/listado-pdf` con cinco variables armadas a mano. La vista se borró porque la
reemplaza la compartida, y los tres tests se reescribieron contra el motor con las mismas
tres reglas: el de recorte ahora usa ventas reales y lee el total de la base. El test de
la ruta, y `ventas.exportar` con su `venta.ver`, no cambiaron.

**Lo que el tramo K cerró y no es un punto del revisor.** `CobroPantallaTest` fallaba antes
del tramo. La pantalla de cobro había perdido la frase «el vuelto no se registra», que el
plan del tramo I pide textual. Se devolvió adentro del mismo recuadro, así que la pantalla
sigue con una sola advertencia. Y el tramo dejó cuatro pendientes, del #72 al #75.


**Qué cerró el tramo L.** El punto 7 entero: la tarjeta «Para reponer» del panel general
es un enlace a `compras.sugeridos`, que cuenta con el mismo `Producto::scopeParaReponer()`,
y sólo es enlace para quien tiene `compra.crear`. Y el criterio de terminado del plan. El
panel general pasó de catorce bloques a ocho: tres números del período, dos de atención, un
gráfico con su tabla plegada, la tabla plegada de medios de pago y el acceso a los informes.
Cada número del período dice contra qué fechas se compara y con qué número. Cada número de
atención es un enlace al listado ya filtrado, para quien puede verlo: `?estado=abiertas` y
`?origen=reposicion` son filtros nuevos de compras, con sus tests. El gráfico conserva su
tabla, en un `<details>`. El ranking de vendedores y los más vendidos son dos listados
paginados con su período (`ventas.vendedores` y `ventas.productos`). De paso se cerró el
#36: la tarjeta de órdenes abiertas dice cuántos borradores dejó la reposición esperando
aprobación.

**Lo que el tramo L decidió distinto del plan.**
- **Las instantáneas no se comparan.** «Para reponer», «órdenes abiertas» y los presupuestos
  abiertos son la foto de hoy, y el sistema no guarda cuántos había antes. Compararlos sería
  inventar el número de antes (invariante 16), así que la pantalla dice «no se comparan con
  antes».
- **Lo que salió del panel no se perdió.** «Ventas cobradas» y «ticket promedio» no se leen
  del listado de ventas, como decía el plan, porque ese listado no los suma: van en la fila de
  totales del ranking. Los descuentos otorgados van en la nota del margen bruto, que es lo
  que el #49 necesita para que el número no engañe.
- **Los informes piden `panel.ver_global` y no un permiso nuevo.** Es la misma información
  del conjunto, que ya veía la misma gente; `venta.ver` no alcanza porque lo tiene el
  Vendedor. Y reusan `PanelFiltroRequest`, porque el contrato es el mismo: el período.

**Lo que el tramo L dejó afuera a propósito.** Los presupuestos que no se van a poder
cobrar en el panel: necesitan un filtro nuevo en ventas, y el punto 2 ya lo cerró el tramo F
(#77). Imprimir los informes: el motor del tramo K imprime filas de un modelo, y una
agregación no entra sin trabajo (#78). Y los «menos vendidos» (#48). El panel propio no se
recortó, porque el plan recorta el general: sus seis tarjetas ganaron comparación, y los
presupuestos abiertos, su enlace.

**Lo que el tramo L cambió de fases anteriores.** Con permiso, cuatro tests: tres de
`PanelServiceTest` y uno de `PanelModuloTest`, que leían el ranking y los más vendidos del
panel. Sus tres reglas (agrupar y ordenar por vendedor, descontar lo devuelto y excluir lo
que volvió entero, que un presupuesto no cuente) se mudaron a `InformeVentasFiltrosTest`
con las mismas aserciones. El test del panel fija ahora lo contrario: que ya no los calcula.

**Para el cierre.** El panel general hace 12 consultas y no las 10 de la línea de base de
`escala.md` §8. Salieron dos (el ranking y los más vendidos) y entraron cuatro: las tres del
período anterior y la de los borradores de la reposición. No se midió, y no lo fija ningún
test: el panel no está en `EscalaTest`, que cuenta consultas contra filas. Se anota con la
línea vieja del tramo K en la §9 de ese documento. Ninguna de las dos hace falta para la
Etapa 2. Los puntos 3 y 11 siguen sin transcribir. El tramo dejó cinco pendientes, del #76
al #80.

---

## 8. Cierre de la fase y de la Etapa 1

**La Fase 7.5 cerró once de sus trece tramos** (A a L), recortó el J con su decisión
escrita y no hizo el M como tramo. Al cerrar, la suite completa pasaba en verde: unas 1.190
definiciones de test y más de 1.450 casos.

**La Fase 8 no se hizo.** La Etapa 1 se presentó antes, sin ella, y sus tareas quedaron
así:

| Tarea de la Fase 8 | Cómo quedó |
| --- | --- |
| Repaso de la trazabilidad | Se fue haciendo tramo por tramo: `trazabilidad.md` §14 |
| Revisión de seguridad contra la auditoría | Cada hallazgo tiene su línea de cierre en `auditoria.md`. Tres ajustes de entorno quedaron anotados ahí, sin hacer: revisar el historial completo de Git, `SESSION_SECURE_COOKIE` y `LOG_LEVEL` en producción. Sin despliegue no hay producción, así que se hacen cuando la haya |
| README completo y resumen ejecutivo | Fuera de este documento |
| Datos para la demostración | Los seeders de demostración y `datos:volumen` |
| Auditoría de usabilidad del cierre | No se hizo. `pendientes-usabilidad.md` queda como lista de decisiones abiertas, numerada hasta el #80 |

**Lo que hereda la Etapa 2.** Las decisiones 1 (costo neto), 3 (el stock sale al cobrar),
7 (`precio_lista` es «tarjeta y cuotas») y 9 (`stock_reservado` es de los checkouts) siguen
valiendo. La 7 define además el precio de la venta online: **contado** para efectivo o
transferencia, **lista** para Mercado Pago.

**Dos números que quedaron viejos y no se corrigieron**, porque `escala.md` se cerró en su
§8: la línea del tramo K en su §9 («se mide en su tramo»: no se midió, la salida fue el
CSV) y el panel general, que hace 12 consultas y no las 10 de la línea de base.
