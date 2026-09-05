<?php

namespace app\core\services;

use app\core\models\dao\SaleDao;
use app\core\models\dao\ItemDao;
use app\core\models\dto\SaleDto;
use app\core\models\dto\base\InterfaceDto;
use app\core\services\base\InterfaceService;
use app\libs\database\Connection;
use app\core\exceptions\ValidationException;

final class SaleService implements InterfaceService {

    private const ESTADOS_VALIDOS = ['presupuesto', 'confirmada', 'cobrada', 'anulada'];
    private const METODOS_PAGO = ['efectivo', 'transferencia', 'mercadopago', 'qr'];

    public function load(int $id): InterfaceDto {
        $dao = new SaleDao(Connection::get());
        return new SaleDto($dao->load($id));
    }

    /**
     * Crea la venta. $confirmar=true => venta directa (nace confirmada y
     * reserva stock); false => presupuesto.
     */
    public function save(InterfaceDto $dto, bool $confirmar = false): void {
        $this->validate($dto);
        $data = $this->resolverPreciosYTotales($dto);
        unset($data["id"]);
        $data["fecha"] = date("Y-m-d H:i:s");

        $dao = new SaleDao(Connection::get());
        $dao->save($data, $confirmar);
        $dto->setId($dao->getLastInsertId());
    }

    public function update(InterfaceDto $dto): void {
        if ($dto->getId() <= 0) {
            throw new ValidationException("El id de la venta es obligatorio para actualizar.");
        }
        $this->validate($dto);
        $data = $this->resolverPreciosYTotales($dto);
        $dao = new SaleDao(Connection::get());
        $dao->update($data);
    }

    public function delete(InterfaceDto $dto): void {
        if ($dto->getId() <= 0) {
            throw new ValidationException("El id de la venta es obligatorio para eliminar.");
        }
        $dao = new SaleDao(Connection::get());
        $venta = $dao->load($dto->getId());
        if (in_array($venta["estado"], ["confirmada", "cobrada"])) {
            throw new ValidationException("No se puede eliminar una venta confirmada o cobrada. Anulala en su lugar.");
        }
        $dao->delete($dto->getId());
    }

    public function list(array $filters): array {
        $dao = new SaleDao(Connection::get());
        return $dao->list($filters);
    }

    public function updateEstado(int $id, string $nuevoEstado): void {
        $nuevoEstado = strtolower(trim($nuevoEstado));
        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS)) {
            throw new ValidationException("Estado inválido.");
        }
        $dao = new SaleDao(Connection::get());
        $dao->load($id);
        $dao->updateEstado($id, $nuevoEstado);
    }

    /**
     * Registra un pago (total o parcial) sobre una venta confirmada.
     */
    public function cobrar(int $id, string $metodo, float $monto, ?string $referencia, int $usuarioId): void {
        $metodo = strtolower(trim($metodo));
        if (!in_array($metodo, self::METODOS_PAGO)) {
            throw new ValidationException("Método de pago inválido.");
        }
        if ($monto <= 0) {
            throw new ValidationException("El monto del pago debe ser mayor a cero.");
        }

        $dao = new SaleDao(Connection::get());
        $venta = $dao->load($id);

        if ($venta["estado"] === "presupuesto") {
            throw new ValidationException("Primero confirmá la venta para poder cobrarla.");
        }
        if ($venta["estado"] === "anulada") {
            throw new ValidationException("La venta está anulada.");
        }
        if ($venta["estado"] === "cobrada") {
            throw new ValidationException("La venta ya está totalmente cobrada.");
        }
        if ($monto > (float) $venta["saldo"] + 0.001) {
            throw new ValidationException("El monto supera el saldo pendiente ($ {$venta['saldo']}).");
        }

        $dao->registrarPago($id, $metodo, $monto, $referencia, $usuarioId);
    }

    private function validate(SaleDto $dto): void {
        if ($dto->getUsuarioId() <= 0) {
            throw new ValidationException("No se pudo identificar al vendedor.");
        }
        if (count($dto->getDetalles()) === 0) {
            throw new ValidationException("La venta debe tener al menos un producto.");
        }
        foreach ($dto->getDetalles() as $linea) {
            if ($linea["productoId"] <= 0) {
                throw new ValidationException("Hay una línea sin producto válido.");
            }
            if ($linea["cantidad"] <= 0) {
                throw new ValidationException("Las cantidades deben ser mayores a cero.");
            }
        }
        if ($dto->getDescuentoPorcentaje() < 0 || $dto->getDescuentoPorcentaje() > 100) {
            throw new ValidationException("El descuento debe estar entre 0 y 100%.");
        }
    }

    private function resolverPreciosYTotales(SaleDto $dto): array {
        $itemDao = new ItemDao(Connection::get());
        $detalles = [];
        $subtotal = 0.0;

        foreach ($dto->getDetalles() as $linea) {
            $producto = $itemDao->load($linea["productoId"]);
            $precioUnit = (float) $producto["precio"];
            $cantidad   = (int) $linea["cantidad"];
            $sub        = round($precioUnit * $cantidad, 2);

            $detalles[] = [
                "productoId" => (int) $linea["productoId"],
                "cantidad"   => $cantidad,
                "precioUnit" => $precioUnit,
                "subtotal"   => $sub
            ];
            $subtotal += $sub;
        }

        $pct = $dto->getDescuentoPorcentaje();
        $descuentoMonto = round($subtotal * $pct / 100, 2);
        $total = round($subtotal - $descuentoMonto, 2);

        $data = $dto->toArray();
        $data["subtotal"]            = round($subtotal, 2);
        $data["descuento"]           = $descuentoMonto;
        $data["descuentoPorcentaje"] = $pct;
        $data["total"]               = $total;
        $data["detalles"]            = $detalles;
        return $data;
    }
}