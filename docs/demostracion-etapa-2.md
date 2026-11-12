# Guion de la demostración — Etapa 2

El recorrido de la defensa, en el orden de la consigna (plan §2.3). Cada paso dice **qué se hace**, **qué
se ve** y **qué demuestra**. Se corre con los datos del seeder, sin tocar código. Nada de lo que está acá
es un secreto: el token de Mercado Pago vive en `.env`.

## 0. Antes de empezar

| Qué | Cómo |
| --- | --- |
| Base con los datos de demostración | `php artisan db:seed --class=TiendaDemoSeeder` reescribe los destacados y las fechas de alta (no hace falta `migrate:fresh`, y nunca sobre la base con datos) |
| Servidor alcanzable por el teléfono | `php artisan serve --host=0.0.0.0` (HL-12). En la app, *Perfil → Servidor*: la IP de la máquina en la red (`http://192.168.x.x:8000`), o `http://10.0.2.2:8000` en el emulador |
| **La tarea programada corriendo** | `php artisan schedule:work` en otra terminal (HL-10, HL-77): sin ella, una reserva vencida no se suelta y un pedido vencido sigue «Esperando el pago» |
| Fotos | `php artisan storage:link` |
| Mercado Pago de prueba | `MERCADOPAGO_ACCESS_TOKEN` con el token **de prueba** del vendedor de prueba; `TIENDA_SIMULADOR=false` (D-23) |
| El teléfono | Sin sesión de una cuenta **real** de Mercado Pago en Chrome (HL-74), o el emulador |
| Red | Salida a internet (Mercado Pago); el punto de acceso del teléfono como plan B |
| Catálogo limpio | Ningún producto de prueba activo (por ejemplo «asdasdasd», que sale primero por nombre): desactivarlo en *Productos* |
| Una herramienta HTTP | `curl` en una terminal, para el paso 9 |
| La app con el registro a la vista | `flutter run` en modo depuración: la consola muestra cada pedido con `Authorization` enmascarado (P-5) |

**Cuentas** (contraseña `demo1234`):

| Cuenta | Para qué |
| --- | --- |
| `cliente@sistema.local` (Camila Herrera) | El cliente que compra (pasos 3 a 6) |
| La que se registra en el paso 2 | El segundo cliente, dueño del pedido «ajeno» del paso 9 |
| `cajero@sistema.local` | Cobrar en la web el pedido de contado (paso 6): tiene `venta.cobrar` |
| `administrativo@sistema.local` | El listado de ventas por canal y el kardex (pasos 6 y 10): tiene `stock.ver` |

**Productos** del seeder de la tienda (`TiendaDemoSeeder`), con foto:

| Para qué | Producto | Código |
| --- | --- | --- |
| Novedad y destacado | Disco SSD WD Blue SN580 1TB | `SSD-WD1TB` |
| Novedad | Memoria Kingston Fury 16GB DDR4 | `RAM-KVR16` |
| Destacados | Monitor ASUS VG248QG 165Hz, Mouse Logitech G505 inalámbrico, Impresora HP Deskjet 2775 | `VG248QG`, `G505`, `HP-2775` |
| «Últimas unidades» | Auriculares HyperX Cloud Stinger | `HX-STINGER` |
| Búsqueda | «fury», «ssd», «G505» (busca por nombre **o** código) | — |

**Tarjeta de prueba** de Mercado Pago: Visa `4509 9535 6623 3704`, código `123`, vencimiento `11/30`,
DNI `12345678`. El **titular** decide el resultado: `APRO` aprueba, `OTHE` rechaza.

## 1. Visitante: catálogo, filtro, búsqueda y ficha sin precio

- Abrir la app sin sesión. **Inicio**: novedades y destacados.
- **Catálogo**: filtrar por *Almacenamiento* (trae también *Discos SSD* y *Discos HDD*, sus hijas); ordenar
  por nombre y por más recientes. Cambiar el filtro vuelve a la primera página.
- **Búsqueda**: «fury».
- **Ficha** del SSD: galería, descripción, categoría, novedad y destacado, disponibilidad. **Sin precio**:
  «Iniciá sesión para ver el precio»; sin botón de agregar.
- En la consola: `GET /api/v1/productos/4` **sin** `Authorization`.

**Demuestra**: requisitos 1 y 2; el precio se oculta **en el servidor** (la respuesta no trae la clave
`precios`, `docs/api.md` §1), no sólo en la pantalla. El orden por precio no se ofrece.

## 2. Registro e inicio de sesión: aparecen los precios

- *Perfil → Crear cuenta*: nombre, apellido, correo nuevo, contraseña. Al terminar, la sesión queda
  abierta.
- Volver al catálogo: **aparecen los precios** (contado y Mercado Pago) y el orden por precio.
- *Perfil → Mi cuenta*: los datos básicos. Cerrar sesión y entrar con `cliente@sistema.local`.

**Demuestra**: requisito 3; la credencial se guarda en el almacenamiento seguro del teléfono y viaja
como `Bearer` (en la consola, enmascarada).

## 3. Compra con Mercado Pago de prueba (la promoción)

- Ficha del SSD → agregar 1. Ficha del mouse → agregar 1. **Carrito**: modificar una cantidad, ver
  unitarios, subtotales y total.
- **Continuar**: el servidor revalida y **reserva** (20 minutos). Confirmación: revisar productos, datos
  del cliente, entrega (retiro o envío, con su costo), el importe definitivo, medio **Mercado Pago**.
  Confirmar: «Pedido V-… registrado».
- **Pagar con Mercado Pago**: se abre el checkout de prueba. Pagar con la cuenta compradora de prueba y la
  tarjeta de prueba, titular **`APRO`**.
- Volver a la app: la pantalla de resultado **consulta al servidor**, y el servidor le pregunta a Mercado
  Pago. Recién entonces dice «Pagado».

**Demuestra**: requisitos 4, 5 y 6; el regreso a la app no confirma nada: lo confirma la consulta al
proveedor (`GET /pedidos/{id}/pagos/{intento}`). El stock salió al cobrar, no al confirmar (regla 5).

## 4. Pago rechazado, reintento y aprobación (D-23)

- Otra compra (los auriculares). Pagar con titular **`OTHE`**: «Pago rechazado: podés reintentar».
- El pedido **sigue siendo el mismo** y la mercadería sigue reservada. **Reintentar**, ahora con `APRO`:
  «Pagado».

**Demuestra**: requisito 6 (pendiente, aprobado y rechazado; reintento sin duplicar el pedido) y P-7. El
intento rechazado no dejó ningún pago (`pruebas-etapa-2.md` §2).

## 5. Consulta de los pedidos y de sus pagos

- *Perfil → Mis pedidos*: los dos pedidos, del más nuevo al más viejo, con fecha, número, importe, y los
  **dos estados por separado** (el del pedido y el del pago). Arrastrar para actualizar.
- Detalle del pedido del paso 4: los productos con el precio del pedido, el último pago, los estados.
- **Descargar comprobante**: se abre el PDF en el navegador (enlace firmado de cinco minutos; la
  credencial no viaja en la URL).

**Demuestra**: requisito 7 y P-4: de la compra a la consulta del pedido y su pago.

## 6. Compra de contado y su cobro en la web

- En la app, una compra con medio **Efectivo o transferencia** y retiro: «Pagalo en el local antes del…»
  (48 horas de reserva).
- En la web, con `administrativo@sistema.local`: *Ventas*, filtro **Canal: Tienda online**: el pedido
  aparece como una venta más, sin vendedor.
- Con `cajero@sistema.local`: abrir la venta → **Cobrar**. En la app, el detalle pasa a «Pagado» y ofrece
  el comprobante.

**Demuestra**: el pedido de la app es una venta de la Etapa 1 (requisito 5: «vinculándolo con los módulos
de ventas y pagos»), y se cobra por el mismo `PagoService`.

## 7. Error de conexión

- Detener `php artisan serve` (Ctrl+C). En la app, tirar para actualizar el catálogo o intentar agregar al
  carrito: «No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.», con la opción de reintentar. Nada se envía dos veces.
- Levantar el servidor de nuevo y reintentar: vuelve.

**Demuestra**: requisitos 8 y 9 (falta de conexión o servidor no disponible) y P-7. Lo arma la app: sin
servidor no hay respuesta que leer.

## 8. Sesión vencida

- Detener el servidor, poner `SANCTUM_EXPIRATION=1` en `.env` (un minuto), `php artisan config:clear` y
  levantarlo de nuevo. Cerrar sesión e iniciar otra vez (la credencial nueva vence en un minuto).
- Esperar el minuto y tocar cualquier cosa con sesión (el carrito, o el catálogo con precios): «Tu sesión
  venció. Volvé a iniciar sesión.», al inicio de sesión. Al entrar, vuelve a donde estaba.
- **Volver a dejar `SANCTUM_EXPIRATION=` vacío**, `config:clear` y reiniciar el servidor.

**Demuestra**: requisito 3 (sesiones vencidas o inválidas) y el estado «sesión vencida» del requisito 9.

## 9. Pedido ajeno: 404 desde una herramienta HTTP

En la terminal, con la credencial de `cliente@sistema.local`, pedir el pedido **del cliente del paso 2**
(su número se ve en la web, en *Ventas*), y después un número que no existe:

```bash
TOKEN=$(curl -s http://localhost:8000/api/v1/sesion -H "Content-Type: application/json" \
  -d '{"email":"cliente@sistema.local","password":"demo1234"}' | sed 's/.*"credencial":"\([^"]*\)".*/\1/')

curl -i http://localhost:8000/api/v1/pedidos/<id del pedido ajeno> -H "Authorization: Bearer $TOKEN"
curl -i http://localhost:8000/api/v1/pedidos/99999999            -H "Authorization: Bearer $TOKEN"
```

Las dos respuestas son **iguales**: `404 {"mensaje":"No encontramos lo que buscás.","codigo":"no_encontrado","errores":{}}`.
(El cliente del paso 2 necesita un pedido: hacer con él una compra de contado al registrarlo.)

**Demuestra**: requisito 7 («incluso si se intenta consultar directamente el identificador de un pedido
ajeno») y P-6: la restricción está en Laravel, no en la app. El test que lo fija:
`Api/PedidoConsultaTest`, «un cliente pide el pedido de otro y recibe 404, igual que un numero que no existe».

## 10. El kardex: la venta online deja el mismo asiento que una de mostrador

- En la web, con `administrativo@sistema.local`: *Stock* (el kardex), filtrado por el SSD.
- El movimiento de la venta online del paso 3 es una **salida por venta**, con su número, igual que el de
  una venta de mostrador. No hay un camino aparte para la tienda.

**Demuestra**: la tienda reutiliza los servicios de la Etapa 1 (`StockService` es la única puerta al stock,
regla 4). El test: `Api/PedidoConfirmacionTest`, «el pedido confirmado se cobra en el mostrador y deja el
mismo asiento que una venta».

## Para las preguntas

| Si preguntan por… | Dónde está |
| --- | --- |
| La evolución desde la Etapa 1 | `trazabilidad.md` §15 |
| La arquitectura Flutter/Laravel | `ARQUITECTURA.md` de la app y `plan-etapa-2.md` |
| Estado, carrito y stock | D-1, D-5, D-8, D-9, D-11 y D-16 del plan |
| El riesgo de seguridad | `seguridad-etapa-2.md` |
| Las pruebas, dificultades y pendientes | `pruebas-etapa-2.md` y `pendientes-usabilidad.md` |
| El contrato | `api.md` |

## Si algo falla en vivo

- **Mercado Pago no responde o no hay red**: el intento queda «Esperando la confirmación» y la app lo dice;
  el sistema no afirma lo que no puede verificar. Se muestra el pedido ya pagado en el ensayo previo (paso 5)
  y se sigue con el paso 6.
- **El teléfono no alcanza el servidor**: revisar la IP en *Perfil → Servidor* y que `serve` esté con
  `--host=0.0.0.0`; si no, el emulador.
