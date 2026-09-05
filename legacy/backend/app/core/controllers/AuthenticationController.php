<?php
namespace app\core\controllers;

use app\core\controllers\base\BaseController;
use app\core\services\AuthenticationService;
use app\libs\http\Request;
use app\libs\http\Response;
use app\core\models\dto\LoginDto;

final class AuthenticationController extends BaseController {

    public function login(Request $request, Response $response): void {
    $dto = new LoginDto($request->getDataFromInput());
    $service = new AuthenticationService();
        $token = $service->login($dto);

        $response->setResult(["token" => $token]);
        $response->setMessage("OK");
        $response->send();
    }

    public function logout(Request $request, Response $response): void {
        $service = new AuthenticationService();
        $service->logout();
        $response->setMessage("OK");
        $response->send();
    }
}