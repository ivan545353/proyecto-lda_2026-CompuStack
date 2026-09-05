<?php
namespace app\libs\pipeline\middlewares;

use app\libs\pipeline\middlewares\base\BaseMiddleware;
use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;

final class CorsHandlerMiddleware extends BaseMiddleware implements InterfaceMiddleware {
    public function handler(Request $request, Response $response): void {
        header("Access-Control-Allow-Origin: http://localhost:4200");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

        // Preflight: el navegador pregunta antes del request real
        if ($request->getMethod() === "OPTIONS") {
            http_response_code(204);
            exit;
        }
        $this->handlerNext($request, $response);
    }
}