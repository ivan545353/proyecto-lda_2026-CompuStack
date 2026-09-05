<?php
namespace app\libs\pipeline\middlewares;

use app\libs\database\Connection;
use app\libs\pipeline\middlewares\base\BaseMiddleware;
use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;
use app\core\exceptions\HttpException;

/**
 * Descripción de ExceptionHandlerMiddleware
 * Captura las excepcion que puedan ocurrir en el resto de la cadena de resposnabilidades.
 * 
 * @author Daniel Ivan Reales
 */

final class ExceptionHandlerMiddleware extends BaseMiddleware implements InterfaceMiddleware {

    public function __construct() {
        parent::__construct();
    }

    public function handler(Request $request, Response $response): void {
        try {
            $this->handlerNext($request, $response);
        }
        // Excepciones "de negocio" con status propio (400/401/403/404)
        catch (HttpException $ex) {
            $this->rollbackIfNeeded();
            $response->setStatus($ex->getStatus());
            $response->setMessage("");
            $response->setError($ex->getMessage());
            $response->send();
        }
        // Error de base de datos: NO se filtra el detalle al cliente
        catch (\PDOException $ex) {
            $this->rollbackIfNeeded();
            // error_log($ex->getMessage()); // opcional: loguear para vos
            $response->setStatus(500);
            $response->setMessage("");
            $response->setError("Error interno. Consulte con el administrador del sistema.");
            $response->send();
        }
        // Cualquier otra \Exception plana = error de validación/negocio (tu código actual)
        catch (\Exception $ex) {
            $this->rollbackIfNeeded();
            $response->setStatus(400);
            $response->setMessage("");
            $response->setError($ex->getMessage());
            $response->send();
        }
    }

    private function rollbackIfNeeded(): void {
        try {
            $conn = Connection::get();
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        } catch (\Throwable $ignored) {
            // Si la conexión nunca se estableció, no hay transacción que revertir
        }
    }
}