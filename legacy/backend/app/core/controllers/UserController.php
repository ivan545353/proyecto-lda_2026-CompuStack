<?php

namespace app\core\controllers;

use app\libs\http\Request;
use app\libs\http\Response;
use app\core\controllers\base\BaseController;
use app\core\controllers\base\InterfaceController;
use app\core\services\UserService;
use app\core\models\dto\UserDto;

final class UserController extends BaseController implements InterfaceController {

  

    public function load(Request $request, Response $response): void {
        $service = new UserService();
        $dto = $service->load((int) $request->getId());
        $response->setResult($dto->toArray());
        $response->send();
    }

   

    public function save(Request $request, Response $response): void {
        $dto = new UserDto($request->getDataFromInput());
        $service = new UserService();
        $service->save($dto);

        $response->setMessage("Se agregó un nuevo usuario al sistema");
        $response->send();
    }

   


    public function update(Request $request, Response $response): void {
        $dto = new UserDto($request->getDataFromInput());
        $service = new UserService();
        $service->update($dto);

        $response->setMessage("Se modificó el usuario correctamente");
        $response->send();
    }

    public function delete(Request $request, Response $response): void {
        $service = new UserService();
        $dto = $service->load($request->getId());
        $service->delete($dto);

        $response->setMessage("Se eliminó el usuario correctamente");
        $response->send();
    }


    public function list(Request $request, Response $response): void {
        $filters = [
            "nombres" => $request->getParameterValue("nombres", null),
            "limit"   => $request->getParameterValue("limit", null)
        ];

        $service = new UserService();
        $usuarios = $service->list($filters);

        $response->setResult($usuarios);
        $response->send();
    }

    public function enable(Request $request, Response $response): void {
        $id = (int) $request->getId();
        $service = new UserService();
        $service->enable($id);
        $response->setMessage("El usuario fue habilitado.");
        $response->send();
    }

    public function disable(Request $request, Response $response): void {
        $id = (int) $request->getId();
        $service = new UserService();
        $service->disable($id);
        $response->setMessage("El usuario fue deshabilitado.");
        $response->send();
    }

    public function reset(Request $request, Response $response): void {
        $id = (int) $request->getId();
        $service = new UserService();
        $service->reset($id);
        $response->setMessage("La contraseña fue restablecida.");
        $response->send();
    }

    public function changePassword(Request $request, Response $response): void {
    $authUser = $request->getAuthUser();
    $userId = $authUser->usuarioID ?? null;
    if (!$userId) {
        throw new \app\core\exceptions\AuthenticationException("No autenticado.");
    }

    $data = $request->getDataFromInput() ?? [];
    $current = $data["currentPassword"] ?? "";
    $nueva   = $data["newPassword"] ?? "";
    $confirm = $data["confirmPassword"] ?? "";

    if ($current === "" || $nueva === "" || $confirm === "") {
        throw new \app\core\exceptions\ValidationException("Completá todos los campos.");
    }
    if (strlen($nueva) < 8) {
        throw new \app\core\exceptions\ValidationException("La nueva clave debe tener al menos 8 caracteres.");
    }
    if ($nueva !== $confirm) {
        throw new \app\core\exceptions\ValidationException("Las contraseñas nuevas no coinciden.");
    }

    $service = new UserService();
    $ok = $service->changePassword((int) $userId, $current, $nueva);
    if (!$ok) {
        throw new \app\core\exceptions\ValidationException("La contraseña actual es incorrecta.");
    }

    $response->setMessage("Contraseña actualizada correctamente.");
    $response->send();
}

    public function getCurrent(Request $request, Response $response): void {
    $authUser = $request->getAuthUser();
    $userId = $authUser->usuarioID ?? null;
    if (!$userId) {
        throw new \app\core\exceptions\AuthenticationException("No autenticado.");
    }

    $service = new UserService();
    $usuario = $service->load((int) $userId);

    $data = $usuario->toArray();
    unset($data["clave"]);   // nunca devolver el hash de la clave
    $response->setResult($data);
    $response->send();
}


    public function perfiles(Request $request, Response $response): void {
        $service = new UserService();
        $response->setResult($service->listProfiles());
        $response->send();
    }
}
