<?php

namespace App\Exceptions;

use Exception;

/**
 * Una regla de negocio que el usuario violó y puede corregir.
 *
 * Distingue tres cosas que suelen confundirse:
 *   - error de validación: el dato está mal escrito → Form Request
 *   - regla de negocio: el dato está bien pero la operación no corresponde → esto
 *   - error del sistema: algo se rompió → pantalla de error y log
 *
 * Se traduce a un mensaje en bootstrap/app.php, una sola vez para toda la
 * aplicación, así ningún controlador necesita try/catch.
 *
 * Reemplaza en parte al ExceptionHandlerMiddleware original, que mapeaba toda
 * excepción a HTTP 400: un "producto no encontrado" respondía 400 en vez de
 * 404, y el cliente no podía distinguir un error de validación de un recurso
 * inexistente (hallazgo M-30).
 */
class ReglaDeNegocioException extends Exception
{
}