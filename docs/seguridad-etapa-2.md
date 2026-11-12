# Un riesgo de seguridad detectado y su solución

La consigna pide presentar «un riesgo de seguridad detectado y su solución» (P-9 del plan). El elegido
es **la cuenta desactivada que seguía operando por la API** (plan §1.3 A): se encontró antes de escribir
la API, leyendo el backend de la Etapa 1, y es el que mejor muestra por qué abrir una puerta nueva obliga
a revisar las protecciones de la vieja.

## 1. Qué se encontró

En la Etapa 1, desactivar una cuenta la desconecta en el pedido siguiente: `VerificarUsuarioActivo`
revisa `users.activo` en **cada** pedido con sesión, no sólo en el login. Fue el cierre del hallazgo
**A-5** de la auditoría («deshabilitar un usuario no lo desloguea»; `auditoria.md`).

Pero ese middleware se agrega **sólo al grupo `web`**:

```php
// bootstrap/app.php
$middleware->web(append: [
    \App\Http\Middleware\VerificarUsuarioActivo::class,
]);
```

Y trabaja con la sesión de navegador. Las rutas `api/*` no pasan por el grupo `web`, y la app no tiene
sesión de navegador: tiene una **credencial** (un token de Sanctum) que vale siete días.

## 2. Cómo se encontró

En la revisión del backend del hito 1, antes del tramo A: el plan recorre cada control de la Etapa 1 y
pregunta si alcanza a la puerta nueva. Este no alcanzaba. Ningún test lo mostraba porque la API no existía
todavía; es exactamente el tipo de falla que aparece después, en producción, cuando nadie la busca.

## 3. Qué podía pasar

Sin arreglo, una cuenta de cliente desactivada por el local —por fraude, por un contracargo, a pedido
del propio cliente— **seguía comprando y consultando por la app hasta siete días**, lo que dura la
credencial. Es A-5 reabierto por la puerta nueva: lo que la Etapa 1 había cerrado para la web volvía a
estar abierto para el teléfono. Lo mismo con una contraseña cambiada o restablecida: el teléfono perdido
seguía con acceso.

Y un caso vecino: una cuenta **de gestión** (un vendedor, un administrativo) con una credencial válida
podría haber entrado a rutas de la tienda, que suponen una ficha de cliente que esa cuenta no tiene (D-3).

## 4. Qué se hizo

Dos cosas, porque una sola no alcanza:

1. **Se revocan las credenciales** en la misma transacción que desactiva la cuenta. `UsuarioService`
   llama a `CredencialService::revocarTodas()` al desactivar (`actualizar()`), al dar de baja
   (`eliminar()`, en sus dos ramas: la que borra y la que desactiva una cuenta con historial) y al cambiar
   o restablecer la contraseña.
2. **Se revalida la cuenta en cada pedido** (el «cinturón»): `App\Http\Middleware\Api\VerificarCuentaTienda`
   corre en los dos grupos con credencial, `tienda.protegida` y `tienda.sesion_opcional`. Si la cuenta
   está inactiva o es de gestión, responde el mismo **401 `sesion_invalida`** que una credencial vencida:

```php
if ($usuario !== null && (! $usuario->activo || $usuario->esDeGestion())) {
    throw new AuthenticationException;
}
```

La primera cubre el camino normal; la segunda, cualquier camino que desactive una cuenta **sin pasar por
el servicio** (una corrección a mano en la base, un comando futuro). La app no distingue los casos: borra
la credencial, dice «Tu sesión venció» y lleva al inicio de sesión (`docs/api.md` §3 y §4).

Además, el inicio de sesión responde **el mismo 422** a una contraseña incorrecta, un correo inexistente,
una cuenta inactiva y una de gestión: decir cuál falló permitiría saber qué correos tienen cuenta.

## 5. Qué test lo fija

En `tests/Feature/Api/CredencialTest.php`:

| Test | Qué fija |
| --- | --- |
| «desactivar una cuenta corta su acceso en el pedido siguiente» | El camino normal: la credencial servía, se desactiva por `UsuarioService` y el pedido siguiente es 401 |
| «protegida: una cuenta desactivada con la credencial todavía vigente es 401» | El cinturón: la cuenta se desactiva **por fuera** del servicio, la credencial sigue en la tabla, y aun así es 401 |
| «sesión opcional también revalida la cuenta» | Lo mismo en el catálogo: una cuenta desactivada no ve precios como si siguiera activa |
| «una cuenta de gestión no entra por la API, ni con credencial válida (D-3)» | El caso vecino |
| «desactivar una cuenta revoca sus credenciales», «cambiar la contraseña desde la cuenta revoca…», «restablecer la contraseña con el enlace revoca…», «borrar una cuenta sin historial revoca…», «una cuenta con historial se desactiva por eliminar() y también pierde sus credenciales» | La revocación en cada camino de `UsuarioService` |
| «una sesión web abierta no autentica contra la API (guard de Sanctum vacío)» | La puerta inversa: una sesión del panel no sirve contra `api/*` |

## 6. Lo que deja

**Una puerta nueva hereda las reglas, no las protecciones.** La regla («una cuenta desactivada no
opera») estaba escrita y probada; la protección estaba atada a un grupo de rutas. La pregunta que lo encontró —¿este control de la
Etapa 1 alcanza a la puerta nueva, o se apoya en la sesión del navegador?— sirve para cada control. El otro
candidato del plan, el orden por precio que dejaba deducir los precios ocultos (1.11), se resolvió con la
misma idea: el 401 o 403 sale **antes de consultar** (`Api/PreciosTest`).
