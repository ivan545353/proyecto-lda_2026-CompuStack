<?php

namespace app\core\services;

use app\core\models\dao\UserDao;
use app\libs\database\Connection;
use app\core\models\dto\LoginDto;
use app\core\exceptions\AuthenticationException;
use Firebase\JWT\JWT;

final class AuthenticationService {

    private UserDao $dao;

    public function __construct() {
        $this->dao = new UserDao(Connection::get());
    }

    public function login(LoginDto $login): string {
        $usuario = $this->dao->login($login->getUserName());

        // Usuario inexistente: $usuario viene null/false
        if (!$usuario || !password_verify($login->getPassword(), $usuario["clave"])) {
            throw new AuthenticationException("El usuario o la clave es incorrecta.");
        }

        if ($usuario["estado"] != 1) {
            throw new AuthenticationException("Su cuenta está inactiva.");
        }

        if ($usuario["resetPass"] != 0) {
            throw new AuthenticationException("Su clave ha caducado.");
        }

        // Generar el JWT con los datos del usuario
        $payload = [
            "usuarioID" => (int) $usuario["id"],
            "cuenta"    => $usuario["cuenta"],
            "perfil"    => $usuario["perfil"],
            "correo"    => $usuario["correo"],
            "iat"       => time(),
            "exp"       => time() + JWT_EXPIRATION
        ];

        return JWT::encode($payload, JWT_SECRET, 'HS256');
    }

    public function logout(): void {
        // Con JWT el logout es del lado del cliente (descartar el token).
        // No hay estado en el servidor que limpiar.
    }
}