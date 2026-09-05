<?php

namespace app\core\controllers;

use app\libs\http\Request;
use app\libs\http\Response;
use app\core\controllers\base\BaseController;
use app\core\controllers\base\InterfaceController;
use app\core\services\SaleService;
use app\core\models\dto\SaleDto;
use app\core\exceptions\ValidationException;

final class SaleController extends BaseController implements InterfaceController {

    public function load(Request $request, Response $response): void {
        $service = new SaleService();
        $dto = $service->load((int) $request->getId());
        $response->setResult($dto->toArray());
        $response->send();
    }

    public function save(Request $request, Response $response): void {
        $data = $request->getDataFromInput() ?? [];
        $authUser = $request->getAuthUser();
        $data["usuario_id"] = $authUser->usuarioID ?? 0;

        // confirmar=true => venta directa (nace confirmada, reserva stock)
        $confirmar = (bool) ($data["confirmar"] ?? false);

        $service = new SaleService();
        $dto = new SaleDto($data);
        $service->save($dto, $confirmar);

        $response->setMessage($confirmar ? "Venta confirmada." : "Presupuesto registrado.");
        $response->setResult(["id" => $dto->getId()]);
        $response->send();
    }

    public function update(Request $request, Response $response): void {
        $data = $request->getDataFromInput() ?? [];
        $authUser = $request->getAuthUser();
        $data["usuario_id"] = $authUser->usuarioID ?? 0;

        $service = new SaleService();
        $dto = new SaleDto($data);
        $service->update($dto);

        $response->setMessage("Se modificó la venta correctamente.");
        $response->send();
    }

    public function delete(Request $request, Response $response): void {
        $service = new SaleService();
        $dto = new SaleDto(["id" => (int) $request->getId()]);
        $service->delete($dto);
        $response->setMessage("Se eliminó la venta correctamente.");
        $response->send();
    }

    public function list(Request $request, Response $response): void {
        $filters = [
            "estado"     => $request->getParameterValue("estado", null),
            "usuario_id" => $request->getParameterValue("usuario_id", null),
            "limit"      => $request->getParameterValue("limit", null)
        ];
        $service = new SaleService();
        $response->setResult($service->list($filters));
        $response->send();
    }

    public function updateEstado(Request $request, Response $response): void {
        $data = $request->getDataFromInput() ?? [];
        $estado = $data["estado"] ?? $request->getParameterValue("estado", null);
        if (!$estado) {
            throw new ValidationException("Falta indicar el nuevo estado.");
        }
        $service = new SaleService();
        $service->updateEstado((int) $request->getId(), $estado);
        $response->setMessage("Se actualizó el estado de la venta.");
        $response->send();
    }

    /**
     * Registra un pago (total o parcial).
     * PUT /sale/cobrar/{id}  body: { metodo, monto, referencia? }
     */
    public function cobrar(Request $request, Response $response): void {
        $data = $request->getDataFromInput() ?? [];
        $authUser = $request->getAuthUser();

        $metodo     = $data["metodo"] ?? "";
        $monto      = (float) ($data["monto"] ?? 0);
        $referencia = $data["referencia"] ?? "";
        $usuarioId  = $authUser->usuarioID ?? 0;

        $service = new SaleService();
        $service->cobrar((int) $request->getId(), $metodo, $monto, $referencia, $usuarioId);

        $response->setMessage("Pago registrado.");
        $response->send();
    }
}