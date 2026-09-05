<?php

namespace app\libs\pipeline\middlewares;

use app\libs\pipeline\middlewares\base\BaseMiddleware;
use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;
use app\core\exceptions\AuthenticationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class AuthenticationHandlerMiddleware extends BaseMiddleware implements InterfaceMiddleware {

    public function __construct() {
        parent::__construct();
    }

    public function handler(Request $request, Response $response): void {
        $controller = $request->getController();
        $action     = $request->getAction();

        // La ruta de login es pública: no exige token
        if ($controller === APP_AUTHENTICATION_CONTROLLER && $action === APP_LOGIN_ACTION) {
            $this->handlerNext($request, $response);
            return;
        }

        // Extraer el token del header Authorization: Bearer <token>
        $token = $this->getBearerToken();
        if ($token === null) {
            throw new AuthenticationException("No autenticado. Falta el token.");
        }

        try {
            $payload = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        } catch (\Exception $ex) {
            throw new AuthenticationException("Token inválido o expirado.");
        }

        // Inyectar el usuario en el Request para el resto de la cadena
        $request->setAuthUser($payload);

        $this->handlerNext($request, $response);
    }

    private function getBearerToken(): ?string {
        $headers = null;

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
            if (isset($requestHeaders['authorization'])) {
                $headers = $requestHeaders['authorization'];
            }
        }

        if ($headers !== null && preg_match('/Bearer\s+(.+)/i', $headers, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}