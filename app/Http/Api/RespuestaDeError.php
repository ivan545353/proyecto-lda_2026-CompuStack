<?php

namespace App\Http\Api;

use App\Exceptions\ReglaDeNegocioException;
use App\Exceptions\StockInsuficienteException;
use App\Exceptions\TransicionInvalidaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduce cualquier excepción de `api/*` a la respuesta de error del contrato
 * (docs/api.md §6.3): `{ mensaje, codigo, errores }`.
 *
 * Métodos:
 *   desde()  excepción + pedido → JsonResponse con la forma de error
 */
final class RespuestaDeError
{
    public static function desde(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof AuthenticationException => self::sesion($request),

            $e instanceof ValidationException => self::armar(
                422, 'validacion', $e->getMessage(), $e->errors(),
            ),

            // `abort(403)` lanza una HttpException genérica, no la subclase: por eso
            // también se mira el estado.
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException,
            self::estadoHttp($e) === 403 => self::armar(
                403, 'sin_permiso', 'No tenés permiso para hacer esto.',
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException,
            self::estadoHttp($e) === 404 => self::armar(
                404, 'no_encontrado', 'No encontramos lo que buscás.',
            ),

            $e instanceof MethodNotAllowedHttpException => self::armar(
                405, 'metodo_no_permitido', 'Esa operación no está disponible en esta dirección.',
                cabeceras: $e->getHeaders(),
            ),

            $e instanceof TooManyRequestsHttpException => self::demasiadosIntentos($e),

            // Las hijas antes que la madre (HL-17).
            $e instanceof StockInsuficienteException => self::armar(409, 'stock_insuficiente', $e->getMessage()),
            $e instanceof TransicionInvalidaException => self::armar(409, 'transicion_invalida', $e->getMessage()),
            $e instanceof ReglaDeNegocioException => self::armar(409, 'regla_de_negocio', $e->getMessage()),

            // Una HttpException con otro estado (413, 503…) no está en el contrato:
            // se declara error del servidor en vez de inventar un código.
            default => self::armar(
                500, 'error_interno', 'Algo falló del lado del servidor. Probá de nuevo en un rato.',
            ),
        };
    }

    private static function estadoHttp(Throwable $e): ?int
    {
        return $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null;
    }

    /**
     * Falta la credencial, o hay una que no sirve. 
     */
    private static function sesion(Request $request): JsonResponse
    {
        $conCredencial = filled($request->header('Authorization'));

        return self::armar(
            401,
            $conCredencial ? 'sesion_invalida' : 'sin_sesion',
            $conCredencial ? 'Tu sesión venció. Iniciá sesión de nuevo.' : 'Necesitás iniciar sesión para continuar.',
            cabeceras: ['WWW-Authenticate' => 'Bearer'],
        );
    }

    private static function demasiadosIntentos(HttpExceptionInterface $e): JsonResponse
    {
        $cabeceras = $e->getHeaders();
        $espera    = max(1, (int) ($cabeceras['Retry-After'] ?? 60));

        return self::armar(
            429,
            'demasiados_intentos',
            "Demasiados intentos. Esperá {$espera} segundos y probá de nuevo.",
            extra: ['reintentar_en' => $espera],
            cabeceras: $cabeceras,
        );
    }

    /**
     * @param  array<string, mixed>  $errores    campos del 422; objeto vacío en los demás
     * @param  array<string, mixed>  $extra      claves propias de un código (`reintentar_en`, `cambios`)
     * @param  array<string, string> $cabeceras
     */
    private static function armar(
        int $estado,
        string $codigo,
        string $mensaje,
        array $errores = [],
        array $extra = [],
        array $cabeceras = [],
    ): JsonResponse {
        return response()->json([
            'mensaje' => $mensaje,
            'codigo'  => $codigo,
            'errores' => $errores === [] ? new \stdClass : $errores,
            ...$extra,
        ], $estado, $cabeceras);
    }
}
