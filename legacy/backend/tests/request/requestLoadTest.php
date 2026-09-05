<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dto\CategoryDto;
use app\core\services\CategoryService;
use app\libs\http\Request;
use app\libs\http\Response;

$response = new Response();
try {
    $request = new Request();
 
    $response->setController($request->getController());
    $response->setAction($request->getAction());

    $data = $request->getDataFromInput();
    $service = new CategoryService();
    $dto = $service->load($data["id"]);

    $response->setResult($dto->toArray());
    $response->send();

} catch (\PDOException $ex) {
    $response->setError("Error database => ". $ex->getMessage());
    $response->send();
} catch (\Exception $ex) {
    $response->setError("Error de sistema => " . $ex->getMessage());
    $response->send();
}
