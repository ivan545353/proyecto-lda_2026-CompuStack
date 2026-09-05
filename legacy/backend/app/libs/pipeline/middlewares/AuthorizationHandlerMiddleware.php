<?php

namespace app\libs\pipeline\middlewares;

use app\libs\pipeline\middlewares\base\BaseMiddleware;
use app\libs\pipeline\middlewares\base\InterfaceMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;
use app\libs\database\Connection;
use app\core\exceptions\AuthorizationException;

final class AuthorizationHandlerMiddleware extends BaseMiddleware implements InterfaceMiddleware {

    // Acciones propias del usuario que cualquiera autenticado puede usar
    // sobre sí mismo, sin chequear permisos de módulo.
    private const ACCIONES_PROPIAS = ["myAccount", "getCurrent", "changePassword"];

    // Mapeo de acción -> columna de permiso requerida
    private const MAPA_PERMISOS = [
        "list"   => "can_read",
        "load"   => "can_read",
        "save"   => "can_create",
        "update" => "can_update",
        "delete" => "can_delete",
    ];

    public function __construct() {
        parent::__construct();
    }

    public function handler(Request $request, Response $response): void {
        $controller = $request->getController();
    $action     = $request->getAction();

    // Rutas públicas: no requieren autorización (no hay token todavía)
    if ($controller === APP_AUTHENTICATION_CONTROLLER && $action === APP_LOGIN_ACTION) {
        $this->handlerNext($request, $response);
        return;
    }

    // El perfil viene del token (inyectado por AuthenticationHandlerMiddleware)
    $authUser = $request->getAuthUser();
    $perfil   = $authUser->perfil ?? null;

    if ($perfil === null) {
        throw new AuthorizationException("No se pudo determinar el perfil del usuario.");
    }

        // Acciones del propio usuario sobre su cuenta: permitidas sin chequeo de módulo
        if ($controller === "user" && in_array($action, self::ACCIONES_PROPIAS)) {
            $this->handlerNext($request, $response);
            return;
        }

        // Acciones especiales de user (enable/disable/reset) se tratan como update
        $columna = self::MAPA_PERMISOS[$action] ?? "can_update";

        if (!$this->tienePermiso($perfil, $controller, $columna)) {
            throw new AuthorizationException("No tenés permiso para realizar esta acción.");
        }

        $this->handlerNext($request, $response);
    }

    private function tienePermiso(string $perfil, string $modulo, string $columna): bool {
        $sql = "SELECT pm.{$columna} AS permitido
                FROM permisos pm
                JOIN perfiles pf ON pf.id = pm.perfil_id
                JOIN modulos  mo ON mo.id = pm.modulo_id
                WHERE pf.nombre = :perfil AND mo.nombre = :modulo
                LIMIT 1";

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute(["perfil" => $perfil, "modulo" => $modulo]);
        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Sin fila = sin acceso (deny-by-default). Con fila, vale el flag.
        return $fila !== false && (int) $fila["permitido"] === 1;
    }
}