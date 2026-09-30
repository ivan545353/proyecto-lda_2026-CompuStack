# Pendientes de usabilidad y consistencia

Cosas detectadas mientras se construían las fases, que **no** son bugs y por eso
no frenan un paso, pero que hay que resolver o descartar con intención antes de
la entrega. Se revisan todas juntas en la auditoría de usabilidad del cierre.

Cada punto dice dónde vive y de qué depende. Un punto que dependa de una fase
que todavía no llegó no se puede resolver antes, y eso está anotado a propósito:
la lista no es una lista de deudas, es una lista de decisiones pendientes.

**Estados:** Abierto · Resuelto · Descartado (con motivo)

---

## 1. Se ofrece lo que después se rechaza

Heurística de Nielsen: prevención de errores. Una acción que el sistema va a
negar no debería estar disponible.

| #   | Punto                                                                                                                          | Dónde                                                  | Depende de             | Estado  |
| --- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------ | ---------------------- | ------- |
| 1   | El botón de eliminar un cliente aparece aunque tenga ventas y la baja vaya a fallar. Falta `withCount('ventas')` en el listado | `ClienteController::index`, `clientes/index.blade.php` | modelo `Venta`, Fase 6 | Abierto |
| 2   | Lo mismo en el listado de personal: el botón aparece aunque la persona tenga ventas, pagos o movimientos de stock              | `PersonalController::index`                            | Fases 5 y 6            | Abierto |
| 3   | El botón de generar enlace de contraseña aparece para una cuenta desactivada, y el servicio lo rechaza                         | `personal/form.blade.php`                              | nada                   | Abierto |

En los tres casos el mensaje de error explica bien qué pasó, así que el sistema
no miente; lo que falta es no ofrecer el camino.

## 2. Huecos funcionales conocidos

| #   | Punto                                                                                                                                                                                                                                                              | Dónde                                          | Depende de                    | Estado  |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------- | ----------------------------- | ------- |
| 4   | Una cuenta de tienda no se administra en ninguna pantalla: no se le puede quitar el acceso ni generarle un enlace de contraseña. La de `cliente@sistema.local` existe sólo como dato de demostración                                                               | `PersonalController` lista sólo ámbito gestión | tienda, Etapa 2               | Abierto |
| 5   | Falta el enlace «olvidé mi contraseña» en el login. Decidido así: sin SMTP configurado, un «te enviamos un correo» que nunca llega es peor que no tener el botón. Con `MAIL_MAILER=log` se puede probar leyendo `storage/logs`                                     | `auth/login.blade.php`                         | SMTP, Etapa 2                 | Abierto |
| 6   | Cambiar la contraseña no cierra las otras sesiones. Quien la cambia porque sospecha un robo espera echar al intruso, y hoy el intruso sigue adentro. Requiere el middleware `AuthenticateSession`, que cambia el comportamiento de la sesión en toda la aplicación | `CuentaController`                             | revisión de seguridad, Fase 8 | Abierto |
| 7   | El panel es una pantalla vacía provisional                                                                                                                                                                                                                         | `panel/index.blade.php`                        | Fase 7                        | Abierto |
| 8   | No hay forma de ver el historial de una persona ni de un cliente desde su ficha                                                                                                                                                                                    | —                                              | Fases 5 y 6                   | Abierto |
| 9   | Las direcciones de un cliente no tienen tope. Nada impide cargar cincuenta                                                                                                                                                                                         | `DireccionService`                             | nada                          | Abierto |

## 3. Consistencia entre módulos

Cada módulo se construyó en su propio paso, y eso deja diferencias que sólo se
ven mirando el sistema entero.

| #   | Punto                                                                                                                                                                                                                                                 | Estado  |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| 10  | **Tres políticas de baja distintas**, cada una justificada: el catálogo desactiva, el personal desactiva si tiene historial, el cliente rechaza la baja. El usuario ve tres comportamientos; revisar que los tres mensajes lo expliquen igual de bien | Abierto |
| 11  | **Rótulos de alta**: «Nueva marca», «Nuevo cliente», «Nuevo integrante», «Nueva dirección». Revisar que la fórmula sea la misma en todos                                                                                                              | Abierto |
| 12  | **Badges de estado**: «Activa/Inactiva» en marcas, «Con acceso/Sin acceso» en personal, «De mostrador/Con cuenta» en clientes. Ninguno comunica sólo con color, pero la convención de qué va oscuro y qué va gris conviene unificarla                 | Abierto |
| 13  | **Voz de los mensajes de éxito**: algunos empiezan con «Se guardó…», otros con el nombre del registro. Unificar                                                                                                                                       | Abierto |
| 14  | Los formularios tienen «Cancelar» abajo pero no un «volver al listado» arriba. En un formulario largo hay que bajar hasta el final para salir                                                                                                         | Abierto |
| 15  | El listado de personal permite buscar por legajo pero no lo muestra como columna: se busca por un dato que no se ve                                                                                                                                   | Abierto |

## 4. Accesibilidad y diálogos

| #   | Punto                                                                                                                                                                                             | Estado  |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| 16  | Las confirmaciones de baja usan `confirm()` del navegador: no se puede estilar, no acompaña el idioma de la aplicación y en móvil queda incómodo. Evaluar un modal de Bootstrap con foco atrapado | Abierto |
| 17  | Verificar con lector de pantalla los `aria-describedby` que enlazan dos ids (ayuda + error), y el foco visible en el desplegable del navbar                                                       | Abierto |
| 18  | Ninguna baja física ofrece deshacer. El `confirm()` es la única red                                                                                                                               | Abierto |

## 5. Datos

| #   | Punto                                                                                                                                                                                                                        | Estado                     |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| 19  | `clientes` no tiene columna `activo`, y está decidido así (un cliente no se elige de un desplegable, se busca). Si el negocio pide ocultar a quien no compra más, ahí se justifica agregarla                                 | Abierto                    |
| 20  | El código postal admite los dos formatos del país (`9011` y `Z9011XAA`) sin normalizar a uno. Dos clientes de la misma cuadra pueden quedar con formatos distintos, y la cotización de envío de la Etapa 2 va a preferir uno | Abierto                    |
| 21  | El CUIT se valida por largo, no por dígito verificador: un CUIT de once dígitos inventado pasa                                                                                                                               | Abierto                    |
| 22  | El CUIL de los empleados no se guarda, y está decidido así (sólo hace falta para liquidar sueldos, fuera de alcance). Ver `modelo-datos.md`, sección `empleados`                                                             | Descartado para la Etapa 1 |

## 6. Textos

| #   | Punto                                                                                                                                                                    | Estado  |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- |
| 23  | `lang/es` cubre los mensajes de validación, pero los textos de la interfaz están escritos a mano en cada vista. Si alguna vez hace falta otro idioma, hay que extraerlos | Abierto |