# Pruebas de la Etapa 2

Qué se probó en cada repositorio, cuáles son los tests que más importan, qué costó y qué quedó
pendiente. Es el informe que pide la consigna en la presentación («informar las pruebas realizadas,
las dificultades y el trabajo pendiente», P-10 del plan).

## 1. Cómo se prueba

| Repositorio | Herramienta | Contra qué | Tests |
| --- | --- | --- | --- |
| `compustack` (backend) | Pest, `php artisan test` | **MariaDB** de prueba (`lda_2026_testing`), no sqlite: los bloqueos, los `ENUM` y los `TIMESTAMP` se comportan como en la base real (HL-73 sólo se ve así) | **2.217**, 6.251 aserciones, en verde (corrida del cierre, 12/11/2026, unos 9 minutos) |
| `compustack-app` (Flutter) | `flutter test` | Falsos de servicio (`test/falsos/falsos.dart`) y, para el recorrido de la sesión vencida, el `Dio` real contra un servidor de mentira | **956**, en 88 archivos, en verde (corrida del cierre, 12/11/2026) |

Además de los tests:

- **El contrato contra el servidor real** (tramo I): las 24 rutas de `api/v1` pedidas con `curl` a
  `php artisan serve` y comparadas con sus ejemplos de `docs/api.md` (revisión 10, «Verificación»).
- **Mercado Pago de prueba de punta a punta** (tramo G): preferencia creada con la credencial de
  prueba del vendedor y pagada con la cuenta compradora de prueba; la preferencia volvió con la misma
  `external_reference`, `expires: true` y el vencimiento exacto del intento (HL-71). La aprobación
  (titular `APRO`) y el rechazo (`OTHE`) se recorren en el guion (`demostracion-etapa-2.md`).
- **El guion de la defensa** (`demostracion-etapa-2.md`), a ensayar dos veces en el equipo de la defensa:
  el resultado de cada ensayo se anota acá al cerrar el tramo.

Cada paso de cada tramo corrió sus tests; la suite completa, al tocar algo transversal y al cerrar el
tramo.

## 2. Los tests que más importan

Son los que fijan lo que la consigna exige verificar **en el servidor**, no sólo en la pantalla.

| Qué se protege | Backend | App |
| --- | --- | --- |
| **El precio omitido** (requisito 1): sin permiso, la clave `precios` no está, ni en `null` | `Api/PreciosTest`: «sin credencial la clave precios no está, ni en null», «con sesión de un rol sin el permiso la clave precios no está», «con el permiso viajan los precios y nada más de lo prohibido» (recorre todo el JSON buscando costo, ganancia y stock) | `data/models/producto_ficha_test.dart`: «sin la clave «precios» no hay precios; con ella, los dos» |
| **El orden por precio no filtra el precio** (1.11) | `Api/PreciosTest`: «ordenar por precio sin sesión es 401 sin_sesion, sin consultar productos» y «…sin el permiso es 403 sin_permiso, sin consultar productos» | — |
| **La frontera de gestión**: una cuenta de tienda no entra al panel, una de gestión no entra a la API | `Api/FronteraGestionTest`: «con la credencial Bearer de un cliente, ninguna ruta de gestión da 200…» (recorre todas las rutas del panel); `Api/CredencialTest`: «una cuenta de gestión no entra por la API, ni con credencial válida (D-3)» | — |
| **El pedido ajeno** (requisito 7): 404, igual que uno que no existe | `Api/PedidoConsultaTest`: «un cliente pide el pedido de otro y recibe 404, igual que un numero que no existe»; `Api/PagoTest`: «pedido ajeno, intento ajeno e intento del simulador ajeno: 404»; `Api/PedidoConfirmacionTest`: «el carrito de otra cuenta es 404…» | `data/repositories/pedido_repository_test.dart`: «el pedido ajeno es NoEncontrado, igual que uno que no existe» |
| **La idempotencia** (requisito 5): la confirmación repetida no duplica | `Api/PedidoConfirmacionTest`: «dos confirmaciones del mismo carrito dan un pedido: la segunda es 200 con el mismo numero»; `Api/PagoTest`: «dos inicios seguidos devuelven 201 y despues 200 con el mismo intento» | `data/repositories/carrito_repository_test.dart` y `ui/ficha/agregar_test.dart`: «agregar dos veces mientras corre no envía dos veces» |
| **La venta online pasa por los servicios de la Etapa 1** | `Api/PedidoConfirmacionTest`: «el pedido confirmado se cobra en el mostrador y deja el mismo asiento que una venta» (el kardex no distingue el canal) | — |
| **El intento rechazado queda fuera de `pagos`** (requisito 6) | `IntentoPagoServiceTest`: «rechazar no escribe pagos y conserva la reserva; reintentar abre otro intento»; `Api/PagoTest`: «rechazar no escribe pagos, y reintentar abre otro intento del mismo pedido» | — |
| **El regreso a la app no confirma el pago** (requisito 6) | `Api/PagoTest`: «mercado pago devuelve la url, y consultar el intento lo confirma contra el proveedor» | — |
| **La invariante de la reserva** (regla 4, D-16) | `CarritoServiceTest`: «invariante: lo reservado es la suma de los checkouts vigentes y los pedidos pendientes»; `StockReservaTest`: «liberar nunca deja lo reservado en negativo ni falla» | — |
| **La cuenta desactivada** (el riesgo de `seguridad-etapa-2.md`) | `Api/CredencialTest`: «protegida: una cuenta desactivada con la credencial todavía vigente es 401», «desactivar una cuenta corta su acceso en el pedido siguiente» | — |
| **La sesión vencida** (requisito 3) | `Api/CredencialTest`: «sesión opcional: una credencial vencida es 401 sesion_invalida» | `routing/sesion_vencida_test.dart`: «sesión vencida de punta a punta: el 401 del catálogo lo dice, lleva al login y vuelve al catálogo, ya con precios»; `core/http/auth_interceptor_test.dart`: «un 401 tardío no borra una credencial más nueva» |
| **Sin conexión** (requisito 9) | — | `core/http/error_interceptor_test.dart`: «sin red es SinConexion»; `data/repositories/carrito_repository_test.dart`: «sin red, la escritura falla y lo dice (regla 27)» |
| **La credencial no se ve** (P-5) | `Api/CredencialTest`: «cada emisión es una fila nueva, y la credencial en claro no se guarda» | `core/http/auth_interceptor_test.dart`: «no muestra la credencial ni los cuerpos» |

## 3. Por tramo

| Tramo | Backend (`tests/Feature/Api`, salvo indicación) | App (`test/`) |
| --- | --- | --- |
| A — Contrato | `ContratoTest`, `CredencialTest`, `PiezasTest` | — |
| B — Cimientos | — | `core/http`, `routing`, `ui/comun`, `ui/ajustes` |
| C — Catálogo | `CatalogoFiltrosTest`, `CatalogoModuloTest`, `CatalogoEscalaTest`, `TiendaDemoSeederTest` | `ui/catalogo`, `ui/busqueda`, `ui/categorias`, `ui/inicio`, `ui/ficha` |
| D — Cuenta y precios | `CuentaTest`, `PreciosTest`, `FronteraGestionTest` | `ui/cuenta`, `routing/sesion_vencida_test.dart` |
| E — Carrito | `CarritoTest`, `CheckoutTest`; `CarritoServiceTest`, `StockReservaTest` | `ui/carrito`, `ui/agregado`, `data/repositories/carrito_repository_test.dart` |
| F — Confirmación | `PedidoConfirmacionTest` | `ui/checkout` |
| K — Cuenta editable | `PerfilEdicionTest`, `CuentaTest` | `ui/cuenta` |
| G — Pagos | `PagoTest`; `IntentoPagoServiceTest` | `ui/pago` |
| H — Consulta | `PedidoConsultaTest`, `PedidoEscalaTest` | `ui/pedidos`, `data/repositories/pedido_repository_test.dart` |

La tabla nombra cada archivo por el tramo que lo creó; un tramo posterior puede sumarle casos (el K a `CuentaTest`).

## 4. Dificultades

- **El `TIMESTAMP` de MariaDB** (HL-73): el primer `TIMESTAMP NOT NULL` sin `DEFAULT` recibe
  `ON UPDATE CURRENT_TIMESTAMP`; guardar la `url_pago` le pisaba el vencimiento al intento, que nacía
  vencido. Los tests del simulador no lo veían porque no hacen `UPDATE`: apareció con Mercado Pago.
  Por eso los tests corren contra MariaDB.
- **Mercado Pago y las cookies del teléfono** (HL-74): la pestaña del navegador usa la sesión del Chrome
  del teléfono; con una cuenta real abierta, Mercado Pago rechaza mezclar una parte de prueba con una real.
  Se resuelve cerrando la cuenta real o usando el emulador.
- **Los campos de la preferencia** (HL-71): el vencimiento pedía además `expires: true` y la fecha con zona;
  se verificó contra el sandbox, no sólo contra la documentación.
- **El 401 tardío** en la app: un 401 de un pedido viejo borraba la credencial nueva. Un 401 sólo borra la
  credencial que viajó en ese pedido.
- **El tiempo de la suite**: más de 2.200 tests contra MariaDB tardan unos diez minutos; cada paso corre
  sólo los archivos que toca.

## 5. Pendientes

- **Quitar el simulador del backend** (D-23): la consigna acepta la pasarela de prueba, que anda; el
  simulador queda apagado (`TIENDA_SIMULADOR=false`) y su retiro, numerado en `pendientes-usabilidad.md`.
- **Un pedido vencido sin la tarea programada** (HL-77) sigue «Esperando el pago» hasta que corre
  `tienda:vencer-reservas`: en la defensa, `php artisan schedule:work` corriendo.
- **El comprobante desde el teléfono**: falta probar que el teléfono alcance la URL firmada y que Chrome
  abra el PDF (se hace en el ensayo).
- `Api/PedidoConsultaTest`, «…baja el pdf sin vendedor», no comprueba la ausencia del vendedor: el PDF es
  binario.
- El choque de documento al guardar el perfil no tiene test de concurrencia.
- Cambiar la contraseña desde la app quedó fuera del K.
