<?php

namespace app\core\controllers;

use app\libs\http\Request;
use app\libs\http\Response;
use app\core\controllers\base\BaseController;
use app\core\controllers\base\InterfaceController;
use app\core\services\CategoryService;
use app\core\models\dto\CategoryDto;

final class CategoryController extends BaseController implements InterfaceController {

    public function load(Request $request, Response $response): void {
        $service = new CategoryService();
        $dto = $service->load((int) $request->getId());
        $response->setResult($dto->toArray());
        $response->send();
    }

    public function save(Request $request, Response $response): void {
        $dto = new CategoryDto($request->getDataFromInput());
        $service = new CategoryService();
        $service->save($dto);

        $response->setMessage("Se agregó una nueva categoría al sistema");
        $response->send();
    }

    public function update(Request $request, Response $response): void {
        $dto = new CategoryDto($request->getDataFromInput());
        $service = new CategoryService();
        $service->update($dto);

        $response->setMessage("Se modificó la categoría correctamente");
        $response->send();
    }

    public function delete(Request $request, Response $response): void {
        $service = new CategoryService();
        $dto = $service->load($request->getId());
        $service->delete($dto);

        $response->setMessage("Se eliminó la categoría correctamente");
        $response->send();
    }

    public function list(Request $request, Response $response): void {
        $filters = [
            "estado" => $request->getParameterValue("estado", null),
            "limit"  => $request->getParameterValue("limit", null)
        ];

        $service = new CategoryService();
        $categorias = $service->list($filters);

        $response->setResult($categorias);
        $response->send();
    }
}