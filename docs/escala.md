# Escala — qué se midió, con cuántos datos y dónde está el techo

Entregable del tramo A de la Fase 7.5.

**Para qué existe.** Las cuatro observaciones de escala del revisor —y los pendientes
#32, #39 y #50, que ya estaban registrados— decían que ciertas pantallas crecen con
los datos. Con el catálogo de la demostración, once productos, ninguna de las dos
afirmaciones se puede comprobar: todo anda bien. Este documento es el registro de
haberlo medido con datos de verdad, y de qué se decidió hacer y no hacer con cada
número.

**La conclusión de una línea:** el problema de escala de este sistema **no eran los
índices**. Era una consulta por `@can` en pantalla, y el peso del HTML de tres
pantallas que mandan el catálogo entero al navegador.

---

## 1. Con qué se midió

### El instrumento

`php artisan escala:medir`, en `app/Console/Commands/MedirEscala.php`. Le pega a la
aplicación **por el kernel HTTP** con un usuario autenticado, así que lo que informa
incluye el middleware, el Form Request de filtros, el controlador, los scopes y el
render de Blade. Medir sólo la consulta habría dicho que todo estaba bien incluso con
cinco megabytes de HTML viajando por pantalla.

De cada pantalla informa seis cosas: el código HTTP —una redirección al login mediría
la pantalla equivocada—, la cantidad de consultas, el tiempo de SQL, el tiempo total,
el peso del HTML y el pico de memoria. Descarta la primera visita de cada pantalla,
que paga la compilación de la vista, e informa la **mediana** de las repeticiones: un
promedio se corre con una sola repetición lenta.

No se agregó ninguna librería. No hay debugbar ni Telescope en el proyecto, y
`DB::listen()` cuenta consultas sin dependencias nuevas.

### Los datos

`php artisan datos:volumen`, separado del seeder de demostración y con su propio
comando, para que veinte mil productos no se carguen en cada `migrate:fresh --seed`.

| Tabla                     | Filas                          |
| ------------------------- | ------------------------------ |
| productos                 | 20.011                         |
| producto_proveedor        | 40.034                         |
| clientes                  | 5.008                          |
| proveedores               | 303                            |
| categorías                | 69 (en tres niveles, 36 hojas) |
| marcas                    | 40                             |
| órdenes de compra         | 2.007                          |
| líneas de orden de compra | 7.012                          |
| ventas                    | 10.016                         |
| líneas de venta           | 25.023                         |
| pagos                     | 10.141                         |
| movimientos de stock      | 47.035                         |

Más 10 operadores de volumen —2 administrativos, 6 vendedores y 2 cajeros— a los que
se atribuyen las operaciones, con su fila en `empleados` y sin acceso al sistema.

**Nada de esto es aleatorio.** Cada valor se deriva del índice de la fila con
aritmética modular: ni un `random_int` ni un `fake()`. Dos corridas del seeder
producen la misma tabla, así que dos mediciones del mismo listado son comparables y
una mejora de medio segundo es una mejora y no una variación de la muestra. Es el
mismo razonamiento que ya estaba escrito en `ProveedorFactory`, donde un canal
sorteado volvía no deterministas dos tests.

**Las operaciones se escribieron en un solo hilo cronológico**, intercalando compras y
ventas, porque `movimientos_stock.stock_resultante` es un saldo: escribir todas las
compras primero dejaría el kardex de cada producto contando una historia que no
cierra. Verificado después de cargar: **ningún producto quedó con stock negativo**, y
las 25.000 líneas de venta son exactamente las esperadas, lo que prueba que ninguna
venta cobrada se escribió contra un stock que no tenía.

### El entorno

- PHP 8.2.12
- MariaDB 10.4.32-MariaDB
- Usuario de la medición: el administrador, que tiene todos los permisos. Es el peor
  caso: cada `@can` que el usuario aprueba es un botón que se renderiza.
- Tres repeticiones por pantalla.

### Dos cosas que la medición NO incluye

1. **La recuperación del usuario de la sesión y su rol.** El comando autentica una vez
   y reusa la instancia, así que esas consultas se pagan en la primera visita y no en
   las medidas. En una petición real hay que sumar unas tres consultas constantes.
2. **La memoria es el pico del proceso informado por el sistema operativo**, en
   bloques gruesos. Sirve para comparar entre pantallas y para ver un orden de
   magnitud, no como medida exacta.

---

## 2. Las mediciones

La columna «después» es posterior al arreglo de permisos de la sección 3.1 y anterior
al índice de la sección 5, que se midió por separado.

| Pantalla                     | Consultas    | SQL (ms)      | Total (ms)            | HTML (KB)   | Memoria (MB) |
| ---------------------------- | ------------ | ------------- | --------------------- | ----------- | ------------ |
| Catálogo                     | 106 → **11** | 51,4 → 17,9   | 118,4 → **40,8**      | 125,2       | 40           |
| Catálogo, búsqueda por texto | 106 → **11** | 56,1 → 30,4   | 100,0 → 56,5          | 126,3       | 42           |
| Catálogo, por categoría      | 110 → **15** | 34,6 → 14,9   | 80,6 → 42,2           | 126,3       | 44           |
| Catálogo, stock crítico      | 106 → **11** | 30,6 → 16,3   | 72,7 → 43,4           | 130,2       | 46           |
| Clientes                     | 47 → **2**   | 12,8 → 5,1    | 26,8 → 16,2           | 58,6        | 46           |
| Clientes, búsqueda por texto | 47 → **2**   | 15,1 → 8,3    | 30,9 → 18,9           | 59,9        | 46           |
| Proveedores                  | 47 → **2**   | 13,9 → 5,6    | 29,5 → 19,2           | 73,8        | 46           |
| Categorías                   | 51 → **6**   | 11,9 → 3,7    | 30,5 → 19,8           | 57,0        | 46           |
| Marcas                       | 47 → **2**   | 11,6 → 2,9    | 27,2 → 13,5           | 53,0        | 46           |
| Kardex                       | 20 → **6**   | 98,9 → 89,6   | 717,1 → 715,3         | **3.572,2** | 104          |
| Kardex de un producto        | 22 → **7**   | 84,5 → 67,9   | 679,1 → 710,1         | **3.572,1** | 104          |
| Compras                      | 50 → **4**   | 16,0 → 2,6    | 50,8 → 26,1           | 87,8        | 92           |
| Ventas                       | 22 → **5**   | 11,5 → 4,0    | 28,2 → 17,4           | 46,2        | 92           |
| Ventas entregadas            | 20 → **5**   | 9,4 → 4,3     | 26,7 → 17,8           | 46,7        | 92           |
| Panel general                | 24 → **9**   | 59,1 → 48,3   | 76,3 → 59,3           | 32,7        | 92           |
| Formulario de venta          | 17 → **2**   | 134,4 → 124,4 | 1.207,0 → **1.157,2** | **5.249,2** | 126          |
| Armador de pedido            | 17 → **3**   | 371,6 → 322,4 | 6.078,0 → **5.822,4** | **6.173,2** | **392**      |

**El criterio de terminado del tramo está cumplido con veinticinco veces de margen.**
El plan pedía que el listado de productos con 20.000 filas abriera en menos de un
segundo: abre en 40,8 ms.

**Esta tabla es del tramo A y no se actualiza.** Es el registro de un momento: el
sistema antes del motor de precios, del ajuste masivo y del buscador remoto. La
segunda corrida, después del tramo E, está en la §6, y ahí está dicho fila por fila
qué es comparable y qué no. Pisar estos números haría perder justamente lo que el
documento existe para conservar.

### Qué no se midió

El panel propio del vendedor y del cajero, los PDF, la exportación del listado de
ventas y el login. Los dos primeros son del tramo L y del K; la exportación la mide el
tramo K contra su propio criterio. Queda dicho para que nadie lea esta tabla como si
fuera el sistema entero.

---

## 3. Los tres hallazgos

### 3.1. Una consulta a la base por cada `@can` de la pantalla

**El más grande, y el que nadie había visto en seis fases.**

`User::clavesDePermiso()` llamaba a `Cache::remember()` en cada invocación, y
`Gate::before` la invoca una vez por cada `@can`. Con `CACHE_STORE=database` —lo que
dice el `.env`— cada lectura de la caché es un `SELECT` a la tabla `cache`. El
resultado: cada botón de cada fila de cada listado era una consulta, y las dieciséis
comprobaciones del navbar las pagaba **toda** pantalla del sistema.

La correlación con la medición es exacta:

| Pantalla                      | `@can` dentro de la fila | Filas renderizadas | Consultas |
| ----------------------------- | ------------------------ | ------------------ | --------- |
| Catálogo                      | 4                        | 20                 | 106       |
| Categorías                    | 2                        | 15                 | 51        |
| Clientes, proveedores, marcas | 2                        | 15                 | 47        |
| Compras                       | 2                        | 15                 | 50        |
| Ventas                        | 0                        | 15                 | 22        |
| Kardex                        | 0                        | 15                 | 20        |
| Formulario de venta, armador  | 0                        | —                  | 17        |

**No crecía con el catálogo: crecía con los botones de la fila y con el tamaño de la
página.** Por eso veinte mil productos no lo revelaron y por eso nunca se notó.

**Por qué los tests no lo encontraron.** `phpunit.xml` fija `CACHE_STORE=array`, y con
la caché en memoria el defecto **no existe**: `Cache::remember()` no consulta nada.
Los tests de los ocho módulos pasaban porque medían un sistema que no era el de
producción. Es la lección del hallazgo, más allá del arreglo.

**Cómo se cerró.** `clavesDePermiso()` memoriza el resultado en la instancia del
usuario, que es la misma durante toda la petición. No cambia nada de la autorización
—la regla de `AppServiceProvider` sigue concediendo sólo si el rol tiene la clave— ni
de la invalidación, que sigue siendo la de `RolService::olvidarCache()`. Lo único que
cambia es cuántas veces se lee lo mismo.

La memoria es **por instancia y no estática** a propósito: una propiedad estática
sobreviviría entre tests en el mismo proceso de Pest y podría contestar con los
permisos de un rol que ya no existe.

**Qué lo fija.** `tests/Feature/EscalaTest.php`, con `config(['cache.default' =>
'database'])` en su `beforeEach`. Es la línea que importa: sin ella el test pasaría
igual con el defecto puesto.

### 3.2. Tres pantallas mandan el catálogo entero en el HTML

| Pantalla            | HTML     | Total | Memoria | Pendiente      |
| ------------------- | -------- | ----- | ------- | -------------- |
| Armador de pedido   | 6.173 KB | 5,8 s | 392 MB  | #32            |
| Formulario de venta | 5.249 KB | 1,2 s | 126 MB  | #39            |
| Kardex              | 3.572 KB | 0,7 s | 104 MB  | **#52, nuevo** |

Las dos primeras estaban registradas y descritas con esas palabras: «con el catálogo
de la demostración son unos kilobytes». Con veinte mil productos son cinco y seis
megabytes por carga de pantalla.

**La tercera es un hallazgo de esta medición.** `StockController::index` carga
`Producto::orderBy('nombre')->get(['id','codigo','nombre'])` completo para armar el
`<select>` del filtro: 20.011 opciones, 3,5 MB. Nadie lo había registrado porque el
kardex no tiene un mapa de precios en JSON como las otras dos: tiene un selector, y un
selector parece inofensivo. Queda como pendiente #52.

**Las tres las cerró el tramo E**, con el buscador remoto, y la medición está en la
§6: las tres juntas mandaban casi quince megabytes de HTML por carga de pantalla y
ahora mandan ciento diecinueve kilobytes. No se tocaron en el tramo A: arreglar el
peso del HTML antes de tener el endpoint de búsqueda habría sido hacer el tramo E dos
veces.

**El peso del HTML ya tiene un test que lo fija**, y hasta el tramo E no lo tenía a
propósito: mientras crecía con el catálogo, un test que afirmara que no crece habría
sido un test rojo. Es `tests/Feature/PesoHtmlTest.php`, lo escribió el tramo E que lo
volvió cierto, y rinde cada pantalla dos veces —con cinco productos y con sesenta—
exigiendo que el HTML pese **exactamente lo mismo**. Compara en lugar de fijar un
número de kilobytes, por la misma razón que `EscalaTest` compara consultas: un tope
absoluto se rompe con cualquier cambio legítimo de la pantalla y entonces se sube sin
pensar.

### 3.3. El techo de memoria del armador de pedido

392 MB de pico con 20.000 productos, contra 40 MB del catálogo. El límite por omisión
de PHP son 512 MB.

**Eso no es una pantalla lenta: es una pantalla que con el doble de catálogo deja de
funcionar.** Es el único número de toda la medición que describe una falla y no una
molestia, y es la razón por la que el tramo E no es opcional.

---

## 4. Lo que se decidió NO hacer

### 4.1. Los índices de búsqueda

El plan del tramo ofrecía tres caminos para `Producto::scopeBuscar()`, que usa
`LIKE '%texto%'` y por lo tanto no puede usar ningún índice: buscar el código por
prefijo, un índice FULLTEXT en el nombre, o **medir y decidir con el número**.

El número, aislado en `tinker` sobre 20.011 productos:

| Consulta                                                       | Tiempo  |
| -------------------------------------------------------------- | ------- |
| `Producto::buscar('cartuchos')->paginate(20)`                  | 17,1 ms |
| `Producto::buscar('VOL-19')->paginate(20)` (mil coincidencias) | 42,8 ms |
| `Cliente::buscar('gutierrez')->paginate(15)` sobre 5.008 filas | 5,6 ms  |

**No se agrega ningún índice de búsqueda.** El recorrido completo de la tabla existe y
está medido, pero cuesta entre 17 y 43 ms en una pantalla que tarda 56 ms en total. El
índice por prefijo **cambiaría la función**: buscar «artu» dejaría de encontrar
«cartuchos», que es como busca una persona que no recuerda el código. Pagar eso por 30
ms que nadie percibe es un mal canje, y un FULLTEXT es otra estructura para mantener
por la misma ganancia.

**La pendiente queda escrita con su pendiente.** El recorrido es O(n): a 200.000
productos serían unos 300 ms de SQL y ahí sí hay que volver, con este documento
delante y la misma medición repetida.

Una advertencia sobre cómo se midió, porque hace falta para repetirlo: la **primera**
medición de una sesión de `tinker` dio 149 ms en una consulta sin filtro, que es la
más barata de todas. No era una medición: era el calentamiento de la sesión. Por eso
el comando descarta la primera visita de cada pantalla, y por eso el ayudante que se
use en `tinker` tiene que llamar al bloque una vez antes de medirlo.

### 4.2. El segundo índice de `pagos`

Se escribió `(usuario_id, fecha, monto)` para el panel del cajero y **se quitó antes de
commitear**. Dos razones: `usuario_id` ya tiene índice por su clave foránea, así que
esa consulta no recorre la tabla hoy; y sobre todo, **el panel del cajero no está entre
las pantallas medidas**. Un índice sin un número que lo sostenga es exactamente la
decisión que este tramo vino a dejar de tomar.

---

## 5. El único índice que entró — pendiente #51

`pagos` se creó con `(venta_id, fecha)`, que responde «los pagos de esta venta» —la
ficha de la venta— y nada más. Un índice compuesto sólo se usa desde su primera columna
hacia la derecha, y las agregaciones del panel no filtran por `venta_id`: filtran por
rango de `fecha` sobre todas las ventas.

Se agregó `(fecha, metodo, monto)`. `monto` va dentro del índice para que la suma no
tenga que ir a buscar las filas.

**Qué cambió y qué no**, medido con 10.141 pagos:

|                               | Antes                                          | Después                                                         |
| ----------------------------- | ---------------------------------------------- | --------------------------------------------------------------- |
| `SUM(monto)` del período      | 3,1 ms                                         | 4,2 ms                                                          |
| Agregación por método de pago | 8,3 ms                                         | 7,3 ms                                                          |
| `type`                        | `ALL`                                          | `range`                                                         |
| `key`                         | `null`                                         | `pagos_fecha_metodo_monto_index`                                |
| Filas estimadas               | 10.143                                         | 5.071                                                           |
| `Extra`                       | `Using where; Using temporary; Using filesort` | `Using where; **Using index**; Using temporary; Using filesort` |

**El reloj no mejoró.** Diez mil filas entran en memoria y recorrerlas cuesta tres
milisegundos; las diferencias de un milisegundo son ruido. Lo que sí cambió, y es lo
único que justifica el índice, es el plan: dejó de recorrer la tabla, y `Using index`
significa que la agregación se resuelve leyendo sólo el índice.

**Queda escrito como lo que es: una apuesta a la pendiente, no una ganancia medida.**
El recorrido completo crece con los cobros y el índice no. Se conserva porque el plan
del tramo lo pide nombrando esta causa exacta, porque el `EXPLAIN` confirma que la
causa era real, y porque no cambia ninguna función ni ningún resultado: sólo el plan.
El índice viejo se conserva, que es para lo que se creó.

---

## 6. El tramo E: el HTML dejó de crecer con el catálogo

Segunda corrida del mismo instrumento: el mismo comando, el mismo usuario
administrador, las mismas tres repeticiones, el mismo PHP 8.2.12 y la misma MariaDB
10.4.32.

**Con una diferencia de muestra que hay que escribir para que nadie la descubra
después.** Entre las dos corridas se usó el sistema, así que las tablas no tienen
exactamente las mismas filas: 20.012 productos en lugar de 20.011, 47.037
movimientos en lugar de 47.035 y 2.008 órdenes en lugar de 2.007. Es una fila de
diferencia donde hay veinte mil: irrelevante para las magnitudes de esta sección, y
mencionarlo cuesta menos que dejar que alguien se pregunte por qué los totales no
coinciden.

### 6.1. Las tres pantallas que mandaban el catálogo

| Pantalla              | HTML (KB)          | Total (ms)         | Memoria (MB)     | Consultas |
| --------------------- | ------------------ | ------------------ | ---------------- | --------- |
| Formulario de venta   | 5.249,2 → **19,2** | 1.157,2 → **5,2**  | 126 → **48**     | 2 → **1** |
| Armador de pedido     | 6.173,2 → **53,0** | 5.822,4 → **18,8** | **392** → **48** | 3 → **2** |
| Kardex                | 3.572,2 → **47,3** | 715,3 → **24,9**   | 104 → **48**     | 6 → **5** |
| Kardex de un producto | 3.572,1 → **47,5** | 710,1 → **11,7**   | 104 → **48**     | 7 → **6** |

Las tres juntas mandaban **14.994 KB de HTML por carga de pantalla**. Ahora mandan
**119,5 KB**: ciento veinticinco veces menos, y da la casualidad de que las tres
sumadas pesan hoy lo mismo que pesa sola la pantalla del ajuste masivo de precios.

El formulario de venta pesa **273 veces menos** y abre **222 veces más rápido**. El
armador pesa 116 veces menos y abre 310 veces más rápido.

**Y el número que importaba de verdad: los 392 MB del armador son 48.** Era el único
de toda la medición del tramo A que describía una falla y no una molestia —el límite
por omisión de PHP son 512 MB, así que con el doble de catálogo esa pantalla dejaba
de funcionar—. El techo duro no se corrió: se fue. Lo que queda es el piso del
proceso: las veintiuna pantallas medidas informan entre 42 y 48 MB, que es lo que
pesa la aplicación cargada y no lo que pesa ninguna pantalla.

**Las consultas bajaron en una por pantalla, y es la misma en los tres casos:** la
que traía el catálogo para armar el desplegable. Lo que quedó en su lugar es un
`exists()` —«¿hay algo que vender?», «¿hay algo que se le pueda pedir a alguien?»—
que no crece con los datos. El formulario de venta hace **una sola consulta**.

**Qué pesa lo que todavía pesa.** De los 53,0 KB del armador, unos trece son el
selector de proveedor, que rinde los 303 activos y se queda local a propósito: no
crece con el catálogo sino con el padrón, es el único campo de esa pantalla donde la
mejora progresiva sigue funcionando entera, y el JavaScript lo reconstruye con los
proveedores del producto elegido. Los 47 KB del kardex son sus quince filas de
movimientos.

### 6.2. Lo que no se tocó, y es la prueba de que la medición sirve

| Pantalla   | HTML antes | HTML ahora | Diferencia |
| ---------- | ---------- | ---------- | ---------- |
| Clientes   | 58,6       | 59,0       | +0,4       |
| Categorías | 57,0       | 57,4       | +0,4       |
| Marcas     | 53,0       | 53,3       | +0,3       |
| Compras    | 87,8       | 88,2       | +0,4       |
| Ventas     | 46,2       | 46,5       | +0,3       |

Cinco pantallas que el tramo E no toca se movieron menos de medio kilobyte cada una.
Eso es lo que permite afirmar que los casi quince megabytes que desaparecieron de las
otras tres son el cambio y no una variación de la muestra. Es el mismo razonamiento
por el que el seeder de volumen es determinista.

### 6.3. Tres filas que cambiaron y no son del tramo E

Las tres se anotan porque leer esta tabla como «antes y después del tramo E» sería
atribuirle cosas que no hizo.

**El catálogo pasó de 125,2 a 198,7 KB y de 11 a 12 consultas.** Creció un 59 %, y no
es de este tramo: la tabla del tramo A es anterior a los tramos B, C y D, y esa
pantalla los recibió a los tres. El tramo B le agregó el filtro `?proveedor_id=`, que
rinde los 303 proveedores en un `<select>` y es exactamente la consulta número doce;
el tramo D le agregó las casillas de selección del ajuste masivo en cada fila. **No
crece con el catálogo** —pagina de veinte en veinte— pero son diez kilobytes por fila
renderizada, y eso es material para el recorte del tramo L, no para este.

**Proveedores pasó de 73,8 a 89,4 KB.** Es el tramo B: las columnas «Productos» y
«Órdenes» separadas, cada una enlazada a su listado filtrado.

**El panel general pasó de 32,7 a 14,0 KB y de 59,3 a 20,6 ms, con las mismas 9
consultas, y no es comparable.** El panel agrega sobre un período, y los datos de
volumen se cargaron hace tiempo: lo más probable es que el período por omisión ya no
contenga ventas, así que los gráficos y las cuatro tablas rinden vacío. No se
verificó, y no hace falta verificarlo acá: lo que hace falta es que quede escrito
que **el tramo L tiene que medir el panel con datos dentro de su período**, o va a
recortar una pantalla mirando los números de una pantalla vacía.

### 6.4. Las cuatro pantallas de precios — pendiente #54

El tramo D entregó el módulo Precios sin medir, porque el instrumento es de otro
tramo. Entraron en `MedirEscala::PANTALLAS` y se miden desde ahora en cada corrida.

| Pantalla                 | Consultas | SQL (ms) | Total (ms) | HTML (KB) |
| ------------------------ | --------- | -------- | ---------- | --------- |
| Precios                  | 5         | 5,8      | 11,3       | 32,0      |
| Formulario de producto   | **11**    | 2,4      | 15,5       | 52,6      |
| Ajuste masivo de precios | 5         | 1,6      | 18,1       | **119,5** |
| Lotes de ajuste          | 7         | 1,3      | 6,5        | 20,8      |

**Ninguna tiene un problema de escala**, y eso es lo que el pendiente pedía
establecer. Dos números quedan anotados para mirarlos con intención, no porque
molesten hoy:

- **Los 119,5 KB del ajuste masivo son la pantalla más pesada del sistema después
  del catálogo.** `AjusteMasivoController::opciones()` rinde los tres selectores de
  recorte —69 categorías con su ruta, 40 marcas y 303 proveedores: 412 opciones— y la
  pantalla tiene además sus tres pasos y su previsualización. No crece con el
  catálogo, así que no es del tramo E; crece con el padrón de proveedores.
- **Las 11 consultas del formulario de producto** son las de cualquier pantalla del
  sistema y el doble de las del catálogo. No se investigó de dónde salen, y queda
  dicho que no se investigó: el número está anotado y la causa es de quien lo mire.

### 6.5. Qué fija el criterio, y qué no

La medición dice **cuánto** cambió; no dice que no vaya a volver. Eso lo dice el test:

- `tests/Feature/PesoHtmlTest.php` exige que el HTML de las cuatro pantallas del
  tramo pese **exactamente lo mismo** con cinco productos que con sesenta. Un solo
  `<option>` por producto que quede en alguna hace fallar el test.
- `tests/Feature/EscalaTest.php` sigue fijando que las consultas no crecen con las
  filas, y no se tocó.
- `tests/Feature/BusquedaTest.php` fija el contrato del endpoint: su permiso en las
  dos direcciones de C-2, su tope de veinte resultados con el aviso de recorte, y que
  el costo viaja sólo con `precio.ver_costo`.

---

## 7. El tramo F: el gesto de carga, y una medición que no mejora nada

Tercera corrida del mismo instrumento: el mismo comando, el mismo usuario
administrador, las mismas tres repeticiones, el mismo PHP 8.2.12 y la misma MariaDB
10.4.32.

**La diferencia de muestra, otra vez, y más grande que la anterior.** Entre la
corrida del tramo E y ésta el sistema se usó bastante, porque probar el tramo F es
cargar renglones: hay ventas, presupuestos y borradores nuevos. Eso no cambia las
magnitudes pero sí mueve los listados, que muestran las quince filas más recientes.
El caso más visible es **Compras, que pasó de 88,2 a 89,8 KB sin que el tramo F
toque esa pantalla**: son órdenes distintas en la primera página, con razones
sociales de otro largo. Mencionarlo cuesta menos que dejar que alguien lo lea como
un efecto del tramo.

### 7.1. Las tres pantallas de carga

| Pantalla              | HTML (KB)       | Total (ms)      | Consultas | Memoria (MB) |
| --------------------- | --------------- | --------------- | --------- | ------------ |
| Formulario de venta   | 19,2 → **19,7** | 5,2 → **5,7**   | 1 → **1** | 48 → **48**  |
| Armador de pedido     | 53,0 → **52,9** | 18,8 → **25,8** | 2 → **2** | 48 → **48**  |
| Kardex                | 47,3 → **47,1** | 24,9 → **33,7** | 5 → **5** | 48 → **48**  |
| Kardex de un producto | 47,5 → **47,3** | 11,7 → **19,3** | 6 → **6** | 48 → **48**  |

**El formulario de venta pesa medio kilobyte más que antes del tramo, y está bien.**
Conviene decirlo sin adornos porque es lo contrario de lo que un cambio de este
tamaño sugiere. El tramo E ya había sacado de esa pantalla todo lo que crecía con el
catálogo; lo que el tramo F cambió es el **gesto**, no el peso. Y en el saldo de
bytes el gesto nuevo cuesta un poco más: desaparecieron el `<select>` de la fila
plantilla, su opción vacía y el botón «Agregar producto» —unos seiscientos bytes—, y
entraron el buscador con su rótulo, su ayuda y sus cinco atributos `data-`, más el
campo oculto, el rótulo del renglón y los dos textos que ahora nombran el producto
para un lector de pantalla. Da unos quinientos bytes de más. Es una pantalla, no una
por producto: **lo que importaba era que no creciera con el catálogo, y eso no
cambió** — lo fija `PesoHtmlTest`, no esta tabla.

El armador y los dos kardex bajaron entre uno y dos décimos de kilobyte, que es
ruido: el tramo F no los toca más que a través de `selects-buscables.js`, y ese
archivo corre en el navegador.

### 7.2. Lo que la columna de milisegundos no puede decir

**El kardex tardó 8,8 ms más y el tramo no le tocó ni una línea del servidor.** El
armador, 7 ms más, y lo único que cambió ahí es JavaScript, que esta medición no
ejecuta. Los dos son la misma cosa: **la varianza de esta máquina entre corridas es
de varios milisegundos**, y el instrumento promedia tres repeticiones de una sola
corrida, no tres corridas. El listado de ventas lo muestra todavía mejor: se midió
en 4,8 ms de SQL justo después de agregarle la subconsulta, y en esta corrida —con
el mismo código— da 7,3.

Así que la regla para leer estas tablas, que vale de acá en adelante: **por debajo de
unos diez milisegundos esta medición no atribuye nada.** Lo que sí atribuye es la
cantidad de consultas, el peso del HTML y la memoria, que son deterministas. Es el
mismo razonamiento por el que el §5 justificó el índice de `pagos` por el plan de
ejecución y no por el reloj.

### 7.3. El listado de ventas y su subconsulta

| Pantalla          | HTML (KB)       | Consultas | SQL (ms)      |
| ----------------- | --------------- | --------- | ------------- |
| Ventas            | 46,5 → **47,5** | 5 → **5** | 4,0 → **7,3** |
| Ventas entregadas | 46,7 → **47,3** | 5 → **5** | 4,3 → **7,3** |

La insignia «sin stock suficiente» necesita saber, por cada presupuesto de la
página, cuántos de sus renglones piden más de lo disponible. Contarlo en PHP sería
una consulta por fila y `EscalaTest` lo rechazaría, así que lo cuenta la base en una
subconsulta correlacionada del mismo `SELECT`, con `withCount`.

**Las cinco consultas siguen siendo cinco, y ése es el número que importaba.** Una
subconsulta en el `select` no es una consulta más: es una columna más. El kilobyte de
HTML es la insignia renderizándose en los presupuestos que de verdad superan el
disponible, y crece con el tamaño de la página —quince filas— y no con los datos. El
SQL se movió dentro de la banda de ruido de la §7.2, así que no se le atribuye nada;
si alguna vez molesta, la salida escrita es acotar la subconsulta a los
presupuestos, que es el único estado donde la insignia se muestra.

### 7.4. Las cinco pantallas de control

| Pantalla    | Tramo E | Tramo F   | Diferencia |
| ----------- | ------- | --------- | ---------- |
| Catálogo    | 198,7   | **198,6** | −0,1       |
| Clientes    | 59,0    | **58,9**  | −0,1       |
| Categorías  | 57,4    | **57,2**  | −0,2       |
| Marcas      | 53,3    | **53,2**  | −0,1       |
| Proveedores | 89,4    | **89,3**  | −0,1       |

Cinco pantallas que el tramo F no toca se movieron un décimo o dos de kilobyte cada
una. Es lo que permite afirmar que el medio kilobyte del formulario de venta y el
kilobyte del listado son el cambio y no la muestra — el mismo razonamiento de la
§6.2, y la razón por la que esta tabla se repite en cada corrida.

### 7.5. Qué fija el criterio del tramo F

- `tests/Feature/PesoHtmlTest.php` **no se tocó** y sigue en verde: las cuatro
  pantallas pesan lo mismo con cinco productos que con sesenta. Era el límite
  declarado del tramo y es el que dice que el medio kilobyte de más es una pantalla
  y no una tendencia.
- `tests/Feature/EscalaTest.php` **no se tocó** y sigue en verde: la subconsulta del
  listado de ventas no agregó una consulta por fila.
- Un test nuevo en `VentaModuloTest` obliga a que el conteo en SQL y el accesor en
  PHP den el mismo número. Es la condición con la que se aceptó escribir la misma
  regla dos veces, en dos lenguajes.

---

## 8. Dónde está el techo

Actualizado después del tramo F. Las tres filas que decían «ya molesta» son las que
cerró el tramo E; el tramo F no movió ninguna.

---

## 9. Qué queda para otros tramos

- **Tramo E — buscador remoto. Cerrado.** La medición está en la §6: las tres
  pantallas dejaron de mandar el catálogo, el techo duro del armador desapareció y
  el peso del HTML tiene por fin un test que lo fija. Los pendientes #32, #39 y #52
  quedaron cerrados, y de paso el #54.
- **Tramo K — impresión.** La exportación del catálogo completo con 20.000 productos
  no se midió acá: se mide en su tramo, y de esa medición sale si dompdf alcanza o si
  hace falta el escritor por filas. Los 392 MB del armador son el antecedente que
  sugiere que no va a alcanzar.
- **Tramo L — panel.** El panel general queda en 9 consultas y 59,3 ms. El recorte es
  de legibilidad, no de escala.
- **Tramo F — carga de renglones. Cerrado.** La medición está en la §7, y lo que
  dice es que el gesto nuevo cuesta medio kilobyte y no ahorra ninguno: el ahorro
  era del tramo E. Lo que el tramo F agregó a esta medición es una regla de lectura
  —por debajo de diez milisegundos no se atribuye nada— y una subconsulta en el
  listado de ventas que no cambió la cantidad de consultas. **El formulario de la
  orden de compra sigue sin medirse**, porque no está en `MedirEscala::PANTALLAS` y
  hace falta decidir contra qué borrador medir: es el pendiente #62.

  ## 8. El tramo G: una pantalla nueva y una consulta más en todas

Cuarta corrida del mismo instrumento: el mismo comando, el mismo usuario
administrador, las mismas tres repeticiones, el mismo PHP 8.2.12 y la misma MariaDB
10.4.32. **Y la última**: de acá en adelante el proyecto no vuelve a correr
`escala:medir`, por decisión de alcance del cierre de la fase. Lo que queda cuidando
la escala son los dos tests, que no cuestan una corrida: `PesoHtmlTest`, que fija que
el HTML de las cuatro pantallas de carga no crece con el catálogo, y `EscalaTest`,
que fija que la cantidad de consultas no crece con las filas. **Consecuencia práctica
para los tramos que siguen: ningún cambio que necesite una medición para justificarse
—un índice, una reescritura «por rendimiento»— se puede justificar, así que no se
hace.** Esta sección es la línea de base final.

**La diferencia de muestra.** 20.012 productos, los mismos; 2.031 órdenes de compra
contra 2.008 en la corrida del tramo F, porque probar este tramo es armar pedidos.

### 8.1. La consulta de la insignia, que se paga en toda pantalla

| Pantalla              | HTML (KB)         | Consultas   |
| --------------------- | ----------------- | ----------- |
| Formulario de venta   | 19,7 → **20,8**   | 1 → **2**   |
| Armador de pedido     | 52,9 → **54,4**   | 2 → **3**   |
| Kardex                | 47,1 → **48,3**   | 5 → **6**   |
| Kardex de un producto | 47,3 → **48,5**   | 6 → **7**   |
| Ventas                | 47,5 → **48,6**   | 5 → **6**   |
| Ventas entregadas     | 47,3 → **48,5**   | 5 → **6**   |
| Catálogo              | 198,6 → **199,6** | 12 → **13** |
| Clientes              | 58,9 → **60,0**   | — → **3**   |
| Categorías            | 57,2 → **58,4**   | — → **7**   |
| Marcas                | 53,2 → **54,4**   | — → **3**   |
| Proveedores           | 89,3 → **90,5**   | — → **3**   |
| Panel general         | 14,0 → **15,0**   | 9 → **10**  |

**La consulta de más es la misma en las doce, y es la insignia del menú de Compras**:
un `COUNT` de las órdenes en borrador, para que quien puede aprobarlas se entere sin
entrar a `/compras`. Se paga en toda pantalla y sólo para quien tiene
`compra.aprobar`; para cualquier otro no se consulta, porque un número que avisa de
algo que no se puede hacer es ruido. **No se cachea a propósito**: un conteo guardado
un minuto afirma algo que puede ya no ser cierto, y el invariante 16 dice que el
sistema no afirma hechos que no puede verificar. Es el precio del pendiente #36 y está
pago con los ojos abiertos.

**El kilo de HTML también es el menú.** Entre 1,0 y 1,2 KB en todas las pantallas: el
ítem «Armar pedido» y la insignia con su texto para lector de pantalla, rendidos por
el parcial que el layout incluye en todas. El armador suma además las pestañas, y de
ahí que sea el que más creció de los que el tramo toca.

**Compras pasó de 89,8 a 96,9 KB y eso no es el tramo.** Son 7,1 KB, y el tramo no
toca esa pantalla más que por el menú. Es muestra: el listado muestra las quince filas
más recientes, y después de probar este tramo las quince más recientes son borradores,
que rinden sus botones de aprobar, cancelar y editar; las que había antes eran órdenes
cerradas, que no rinden ninguno. Mencionarlo cuesta menos que dejar que alguien lo lea
como un efecto del tramo.

### 8.2. Las dos pantallas nuevas, que son las más caras del sistema

| Pantalla                | Consultas | SQL (ms) | Total (ms) | HTML (KB) |
| ----------------------- | --------- | -------- | ---------- | --------- |
| Sugeridos de reposición | **5**     | **77,6** | **97,4**   | **90,8**  |
| Catálogo, sin proveedor | **13**    | **83,6** | **106,8**  | **102,8** |

**Son las dos consultas más caras de las veintitrés pantallas medidas, y el motivo es
el mismo.** `paraReponer()` compone tres condiciones, y dos de ellas son subconsultas
correlacionadas sobre los 20.012 productos: «no tiene pedido en curso» es un
`NOT EXISTS` anidado dos niveles —de `orden_compra_lineas` a `ordenes_compra`— y «hay
a quién pedirle» es otro `EXISTS` sobre la tabla de vínculos. No hay índice en los
caminos de esas subconsultas, y a 80 ms está muy por encima de la banda de ruido del
§7.2, así que esto **sí** se le atribuye al tramo.

**Y aun así se deja como está, con su motivo escrito.** Un décimo de segundo en una
pantalla interna que se abre unas pocas veces por día no es un problema que haya que
pagar con un índice puesto a ojo: la regla del §5 pide medición antes y después, y
esta corrida es la última. Si alguna vez molesta, la salida escrita es un índice sobre
`orden_compra_lineas(producto_id)` —que es la columna por la que la subconsulta
correlaciona— y volver a medir. Queda acá, no como pendiente numerado, porque no es una
decisión de usabilidad sino una nota de escala.

**El peso de las dos está acotado por la paginación y no por el catálogo**, que es la
propiedad que importa: las dos paginan de a veinte, así que esos 90 y 102 KB son los
mismos con veinte mil productos que con doscientos. Sobre los ~50 KB que pesan el
layout y el menú en todas las pantallas, los sugeridos ponen unos 40: veinte filas con
su casilla, su rótulo accesible, el disponible, el mínimo, la cantidad y el costo, más
un encabezado por proveedor con su enlace.

### 8.3. Lo que la columna de milisegundos no puede decir, ampliado

**El panel general dio 20,5 ms de SQL en una corrida y 46,5 en la siguiente, con el
mismo código y cinco minutos de diferencia.** Las dos corridas son de este tramo: la
primera se hizo antes de agregar las dos pantallas al comando y la segunda después, y
entre una y otra el panel no cambió ni una línea. Así que la regla del §7.2 —por debajo
de unos diez milisegundos esta medición no atribuye nada— es **optimista para las
pantallas que agregan sobre períodos**: ahí la banda es de varias decenas de
milisegundos. Lo que sigue siendo determinista es la cantidad de consultas, el peso del
HTML y la memoria.

Eso también explica por qué el conteo unificado del panel no se le atribuye nada en
tiempo: pasó a usar la misma subconsulta que las pantallas nuevas, y su efecto queda
tapado por una varianza mayor que él. Lo que sí se puede afirmar es lo que se cuenta:
nueve consultas pasaron a diez por la insignia, y el conteo de «Para reponer» dice
ahora el mismo número que el comando de las 07:00.

### 8.4. Las pantallas de control

| Pantalla    | Tramo F | Tramo G   | Diferencia |
| ----------- | ------- | --------- | ---------- |
| Catálogo    | 198,6   | **199,6** | +1,0       |
| Clientes    | 58,9    | **60,0**  | +1,1       |
| Categorías  | 57,2    | **58,4**  | +1,2       |
| Marcas      | 53,2    | **54,4**  | +1,2       |
| Proveedores | 89,3    | **90,5**  | +1,2       |

Las cinco se movieron lo mismo, entre 1,0 y 1,2 KB, y ninguna la toca el tramo. Eso es
lo que permite afirmar que el kilobyte es el menú y no otra cosa, y es el mismo
razonamiento de las secciones 6.2 y 7.4. El catálogo creció un poco menos que las otras
cuatro porque la clase de las casillas reemplazó marcado que ya estaba.