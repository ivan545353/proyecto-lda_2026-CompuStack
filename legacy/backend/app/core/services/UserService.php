<?php

namespace app\core\services;

use app\core\models\dao\UserDao;
use app\core\models\dto\UserDto;
use app\core\models\dto\base\InterfaceDto;
use app\core\services\base\InterfaceService;
use app\libs\database\Connection;
use app\core\exceptions\ValidationException;

final class UserService implements InterfaceService {

    public function load(int $id): InterfaceDto {
        $dao = new UserDao(Connection::get());
        return new UserDto($dao->load($id));
    }

    public function save(InterfaceDto $dto): void {
        $this->validate($dto);
        $data = $dto->toArray();
        unset($data["id"]);

        if (!empty($data["clave"])) {
            $data["clave"] = password_hash($data["clave"], PASSWORD_DEFAULT);
        }

        $dao = new UserDao(Connection::get());
        $dao->save($data);
    }

    public function update(InterfaceDto $dto): void {
        $this->validateUpdate($dto);

        $dao = new UserDao(Connection::get());
        $usuarioExistente = $dao->load($dto->getId());

        $data = $dto->toArray();

        if (!empty($data["clave"])) {
            $data["clave"] = password_hash($data["clave"], PASSWORD_DEFAULT);
        } else {
            $data["clave"] = $usuarioExistente["clave"];
        }

        $dao->update($data);
    }

    public function delete(InterfaceDto $dto): void {
        $dao = new UserDao(Connection::get());
        $dao->load($dto->getId()); // valida existencia
        $dao->delete($dto->getId());
    }

    public function list(array $filters): array {
        $dao = new UserDao(Connection::get());
        return $dao->list($filters);
    }

    private function validate(UserDto $dto): void {
        if ($dto->getNombres() === "") {
            throw new ValidationException("El nombre es obligatorio.");
        }
        if ($dto->getCuenta() === "") {
            throw new ValidationException("El usuario es obligatorio.");
        }
        if ($dto->getClave() === "") {
            throw new ValidationException("La clave es obligatoria.");
        }
        if ($dto->getPerfilId() === 0) {
            throw new ValidationException("Debe seleccionar un perfil.");
        }
    }

    private function validateUpdate(UserDto $dto): void {
        if ($dto->getNombres() === "") {
            throw new ValidationException("El nombre es obligatorio.");
        }
        if ($dto->getCuenta() === "") {
            throw new ValidationException("El usuario es obligatorio.");
        }
        if ($dto->getPerfilId() === 0) {
            throw new ValidationException("Debe seleccionar un perfil.");
        }
        if ($dto->getCorreo() === "") {
            throw new ValidationException("El correo es obligatorio y debe tener un formato válido.");
        }
        // La clave no se valida en update: puede no cambiar.
    }

    // ================== Métodos especiales ===================

    public function enable(int $id): void {
        $dao = new UserDao(Connection::get());
        $dao->load($id);
        $dao->enable($id);
    }

    public function disable(int $id): void {
        $dao = new UserDao(Connection::get());
        $dao->load($id);
        $dao->disable($id);
    }

    public function reset(int $id): void {
        $dao = new UserDao(Connection::get());
        $dao->load($id);
        $dao->reset($id);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool {
        $dao = new UserDao(Connection::get());
        $usuario = $dao->load($userId);

        if (!password_verify($currentPassword, $usuario["clave"])) {
            return false;
        }

        $nuevaClaveHash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $dao->updatePassword($userId, $nuevaClaveHash);
    }

    public function listProfiles(): array {
        $dao = new UserDao(Connection::get());
        return $dao->listProfiles();
    }
}