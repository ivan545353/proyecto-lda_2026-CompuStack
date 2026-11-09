# Estado de la Etapa 2 — traspaso entre tramos

Lo reescribe `/cerrar-tramo` al final de cada tramo. Es un traspaso, no un historial: lo que
ya quedó en el plan o en los documentos, se borra de acá.

## Tramo siguiente

**C — Catálogo y detalle**, en este repositorio (backend: scopes, recursos y endpoints de
catálogo, y la casilla «destacado» en la web). **B** (cimientos de la app) sigue sin hacerse y
corre en el repositorio de la app; C no depende de B para el backend, sí para su mitad de app.

Antes de empezar (plan §14.3, fila C): cómo usan `imagenes` los productos editados a mano
(rutas, orden), y que el tope de novedades y el umbral de disponibilidad tengan sentido con el
catálogo de demostración. Esos valores son de partida: ver **Q-1** (plan §12.2).

## Lo que dejó el tramo A y condiciona a los siguientes

- **Los grupos de rutas ya existen y están vacíos** en `routes/api.php`, con prefijo `v1`:
  pública, `tienda.sesion_opcional` y `tienda.protegida`. Los grupos son *grupos de middleware
  con nombre* definidos en `bootstrap/app.php`: las rutas y los tests nombran el mismo.
  Una ruta de catálogo va en el segundo; el permiso de cada ruta lo agrega ella (`can:`).
- **Un controlador no arma errores ni envolturas a mano.** Lanza (`ReglaDeNegocioException`,
  `StockInsuficienteException`, `ValidationException`, `abort(404)`) y el renderer lo traduce.
  Los listados usan `Paginado::respuesta($paginador, Recurso::class, $criterios)`; el orden de la
  consulta desempata por `id` y lo arma quien la construye, no `Paginado`.
- **Piezas para los recursos:** `Importe::json()` (dinero), `Imagenes::url()` (siempre una URL,
  con el host del pedido), `TextosTienda::{pedido,pago,disponibilidad,pasarela}()` y sus
  constantes. `TextosTienda::PAGO_PENDIENTE_*` ya tiene dos de las tres lecturas de «pendiente».
- **`carrito_cambio` y `checkout_vencido`** están declarados en `docs/api.md` pero **no tienen
  excepción**: el renderer no los emite todavía. Cuando el tramo E cree las suyas (hijas de
  `ReglaDeNegocioException`), se agregan como brazos del `match` **antes** de
  `regla_de_negocio` en `RespuestaDeError::desde()`; `cambios` va por el parámetro `extra` de
  `armar()`.
- **`config/tienda.php` tiene todas las claves** de la etapa. `pagos.simulador` sale de
  `TIENDA_SIMULADOR` (`.env.example` ya lo trae).
- **Tests de API:** las rutas de prueba se definen dentro del test, con
  `Route::middleware('tienda.protegida')->get('api/v1/_prueba/…')`. Dentro de un mismo test el
  guard recuerda al usuario del pedido anterior: entre dos pedidos, `app('auth')->forgetGuards()`.
  `$this->get()` no manda `Accept`, que es justo lo que se quiere probar. Las ayudas globales
  llevan prefijo `tienda` (`.claude/rules/tests.md`).
- **Una cuenta con credencial pasa por `VerificarCuentaTienda` en cada pedido**; las de gestión
  dan 401 `sesion_invalida` aunque la credencial sea válida (D-3). `CredencialService::emitir()`
  escribe `expires_at` a mano (HL-25).
- **Contraseña:** cambiarla o restablecerla **no revoca** las credenciales de la cuenta. No
  estaba en el plan; es candidato para el tramo D, que arma el inicio de sesión.

## Hallazgos sin registrar (van al plan §3.3 como HL-28 en adelante)

Ninguno: los HL-17 a HL-27 quedaron registrados.

## Números

- Tests: **1.619**; la suite completa tarda unos 11 minutos (la base de pruebas tarda unos 100 s
  en migrar la primera vez).
- Pendientes: hasta el #82. Próximo libre: **#83**.
- Último HL del plan: **HL-27**. `docs/api.md`: **revisión 1**.
- Decisiones: D-12 a D-17 se siguieron por omisión.

## Preguntas abiertas

- **Q-1** (plan §12.2): valores de partida de `config/tienda.php`. `envio.costo` (hoy `0.00`) se
  decide antes del tramo F.
- El repositorio de la app todavía no está creado ni anotado en el README (plan §14.1, última
  casilla), y el logo de CompuStack (D-18) tampoco: los necesita el tramo B.
