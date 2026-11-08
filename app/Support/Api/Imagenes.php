<?php

namespace App\Support\Api;

/**
 * URL absoluta de una imagen, para la API.
 *
 * Usa `url()` y no `Storage::url()` (HL-13): `Storage::url()` arma la URL con
 * `APP_URL`, que en desarrollo es `localhost`, y un emulador o un teléfono no
 * llegan a ese host. `url()` usa el host con el que llegó el pedido, así que la
 * misma respuesta sirve a `10.0.2.2` y a la IP de la red sin configurar nada. La
 * web sigue con `Storage::url()`.
 *
 * Nunca devuelve null: un producto sin foto apunta a la imagen por omisión, y la
 * app no tiene un caso especial para «no hay imagen».
 *
 * Métodos:
 *   url()  la imagen guardada, o la de por omisión si no hay
 */
final class Imagenes
{
    public const SIN_IMAGEN = 'img/producto-sin-imagen.svg';

    public static function url(?string $ruta): string
    {
        $ruta = trim((string) $ruta, "/ \t\n\r");

        return $ruta === ''
            ? url(self::SIN_IMAGEN)
            : url('storage/'.$ruta);
    }
}
