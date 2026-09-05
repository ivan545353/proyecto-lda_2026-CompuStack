<?php

namespace app\core\models\dao;

use app\core\models\dao\base\BaseDao;
use app\core\models\dao\base\InterfaceDao;

final class SaleDao extends BaseDao implements InterfaceDao {

    private int $lastVentaId = 0;

    public function __construct(\PDO $connection) {
        parent::__construct($connection, "ventas");
    }

    public function load(int $id): array {
        $sql = "SELECT v.*, CONCAT(u.apellido, ' ', u.nombres) AS vendedor
                FROM {$this->table} v
                JOIN usuarios u ON u.id = v.usuario_id
                WHERE v.id = :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["id" => $id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$data) {
            throw new \Exception("No se encontró la venta con ID {$id}");
        }

        // Líneas
        $sqlDet = "SELECT d.producto_id AS productoId, d.cantidad,
                          d.precio_unit AS precioUnit, d.subtotal, p.nombre AS producto
                   FROM detalle_ventas d
                   JOIN productos p ON p.id = d.producto_id
                   WHERE d.venta_id = :id";
        $stmtDet = $this->connection->prepare($sqlDet);
        $stmtDet->execute(["id" => $id]);
        $data["detalles"] = $stmtDet->fetchAll(\PDO::FETCH_ASSOC);

        // Pagos + saldo
        $sqlPag = "SELECT id, metodo, monto, referencia, fecha
                   FROM pagos WHERE venta_id = :id ORDER BY fecha, id";
        $stmtPag = $this->connection->prepare($sqlPag);
        $stmtPag->execute(["id" => $id]);
        $data["pagos"] = $stmtPag->fetchAll(\PDO::FETCH_ASSOC);

        $pagado = 0.0;
        foreach ($data["pagos"] as $p) { $pagado += (float) $p["monto"]; }
        $data["pagado"] = round($pagado, 2);
        $data["saldo"]  = round((float) $data["total"] - $pagado, 2);

        return $data;
    }

    /**
     * Crea la venta. Si $confirmar es true, nace 'confirmada' y descuenta
     * stock en la misma transacción; si no, queda 'presupuesto'.
     */
    public function save(array $data, bool $confirmar = false): void {
        $conn = $this->connection;
        try {
            $conn->beginTransaction();

            $stmt = $conn->query("SELECT numero FROM venta_numeracion FOR UPDATE");
            $numero = (int) $stmt->fetchColumn() + 1;
            $conn->prepare("UPDATE venta_numeracion SET numero = :n")->execute(["n" => $numero]);

            $estado = $confirmar ? 'confirmada' : 'presupuesto';

            $sql = "INSERT INTO {$this->table}
                    (numero, usuario_id, cliente, fecha, estado, subtotal, descuento, descuento_porcentaje, total, observaciones)
                    VALUES (:numero, :usuario_id, :cliente, :fecha, :estado, :subtotal, :descuento, :descuento_porcentaje, :total, :observaciones)";
            $conn->prepare($sql)->execute([
                "numero"               => $numero,
                "usuario_id"           => $data["usuario_id"],
                "cliente"              => $data["cliente"] !== "" ? $data["cliente"] : null,
                "fecha"                => $data["fecha"],
                "estado"               => $estado,
                "subtotal"             => $data["subtotal"],
                "descuento"            => $data["descuento"],
                "descuento_porcentaje" => $data["descuentoPorcentaje"],
                "total"                => $data["total"],
                "observaciones"        => $data["observaciones"] !== "" ? $data["observaciones"] : null
            ]);

            $ventaId = (int) $conn->lastInsertId();
            $this->lastVentaId = $ventaId;

            $this->insertDetalles($ventaId, $data["detalles"]);

            if ($confirmar) {
                $this->moverStock($data["detalles"], "descontar");
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public function update(array $data): void {
        $conn = $this->connection;
        try {
            $conn->beginTransaction();

            $actual = $this->load((int) $data["id"]);
            if ($actual["estado"] !== "presupuesto") {
                throw new \Exception("Solo se puede modificar una venta en estado presupuesto.");
            }

            $sql = "UPDATE {$this->table} SET
                        cliente = :cliente, subtotal = :subtotal, descuento = :descuento,
                        descuento_porcentaje = :descuento_porcentaje, total = :total,
                        observaciones = :observaciones
                    WHERE id = :id";
            $conn->prepare($sql)->execute([
                "cliente"              => $data["cliente"] !== "" ? $data["cliente"] : null,
                "subtotal"             => $data["subtotal"],
                "descuento"            => $data["descuento"],
                "descuento_porcentaje" => $data["descuentoPorcentaje"],
                "total"                => $data["total"],
                "observaciones"        => $data["observaciones"] !== "" ? $data["observaciones"] : null,
                "id"                   => $data["id"]
            ]);

            $conn->prepare("DELETE FROM detalle_ventas WHERE venta_id = :id")->execute(["id" => $data["id"]]);
            $this->insertDetalles((int) $data["id"], $data["detalles"]);

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void {
        $stmt = $this->connection->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->execute(["id" => $id]);
    }

    public function list(array $filters): array {
        $sql = "SELECT SQL_CALC_FOUND_ROWS v.*, CONCAT(u.apellido, ' ', u.nombres) AS vendedor
                FROM {$this->table} v
                JOIN usuarios u ON u.id = v.usuario_id
                WHERE 1=1";
        if (!empty($filters["estado"]))     { $sql .= " AND v.estado = :estado"; }
        if (!empty($filters["usuario_id"])) { $sql .= " AND v.usuario_id = :usuario_id"; }
        $sql .= " ORDER BY v.fecha DESC, v.id DESC";
        if (!empty($filters["limit"]))      { $sql .= " LIMIT :limit"; }

        $stmt = $this->connection->prepare($sql);
        if (!empty($filters["estado"]))     { $stmt->bindValue(":estado", $filters["estado"]); }
        if (!empty($filters["usuario_id"])) { $stmt->bindValue(":usuario_id", (int) $filters["usuario_id"], \PDO::PARAM_INT); }
        if (!empty($filters["limit"]))      { $stmt->bindValue(":limit", (int) $filters["limit"], \PDO::PARAM_INT); }
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function suggestive(array $filters): array {
        $keyword = "%" . ($filters["keyword"] ?? "") . "%";
        $sql = "SELECT id, numero, cliente, estado FROM {$this->table}
                WHERE cliente LIKE :keyword OR numero LIKE :keyword
                ORDER BY id DESC LIMIT 10";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["keyword" => $keyword]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateEstado(int $id, string $nuevoEstado): void {
        $conn = $this->connection;
        try {
            $conn->beginTransaction();
            $venta = $this->load($id);
            $actual = $venta["estado"];

            if ($actual === "presupuesto" && $nuevoEstado === "confirmada") {
                $this->moverStock($venta["detalles"], "descontar");
            } elseif (in_array($actual, ["confirmada", "cobrada"]) && $nuevoEstado === "anulada") {
                $this->moverStock($venta["detalles"], "reponer");
            }

            $conn->prepare("UPDATE {$this->table} SET estado = :estado WHERE id = :id")
                 ->execute(["estado" => $nuevoEstado, "id" => $id]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Registra un pago. Si la suma de pagos cubre el total, la venta
     * pasa a 'cobrada'. Todo en una transacción.
     */
    public function registrarPago(int $ventaId, string $metodo, float $monto, ?string $referencia, int $usuarioId): void {
        $conn = $this->connection;
        try {
            $conn->beginTransaction();

            // Bloquea la venta para leer total y evitar carreras de pagos
            $stmt = $conn->prepare("SELECT total, estado FROM {$this->table} WHERE id = :id FOR UPDATE");
            $stmt->execute(["id" => $ventaId]);
            $venta = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$venta) throw new \Exception("No se encontró la venta.");

            $conn->prepare("INSERT INTO pagos (venta_id, metodo, monto, referencia, fecha, usuario_id)
                            VALUES (:v, :m, :mo, :r, NOW(), :u)")
                 ->execute([
                    "v"  => $ventaId, "m" => $metodo, "mo" => $monto,
                    "r"  => $referencia !== "" ? $referencia : null, "u" => $usuarioId
                 ]);

            $stmtSum = $conn->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE venta_id = :id");
            $stmtSum->execute(["id" => $ventaId]);
            $pagado = (float) $stmtSum->fetchColumn();

            if ($pagado + 0.001 >= (float) $venta["total"]) {
                $conn->prepare("UPDATE {$this->table} SET estado = 'cobrada' WHERE id = :id")
                     ->execute(["id" => $ventaId]);
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public function getLastInsertId(): int {
        return $this->lastVentaId;
    }

    private function insertDetalles(int $ventaId, array $detalles): void {
        $sql = "INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_unit, subtotal)
                VALUES (:venta_id, :producto_id, :cantidad, :precio_unit, :subtotal)";
        $stmt = $this->connection->prepare($sql);
        foreach ($detalles as $d) {
            $stmt->execute([
                "venta_id"    => $ventaId,
                "producto_id" => $d["productoId"],
                "cantidad"    => $d["cantidad"],
                "precio_unit" => $d["precioUnit"],
                "subtotal"    => $d["subtotal"]
            ]);
        }
    }

    private function moverStock(array $detalles, string $modo): void {
        foreach ($detalles as $d) {
            $productoId = (int) $d["productoId"];
            $cantidad   = (int) $d["cantidad"];

            if ($modo === "descontar") {
                $stmt = $this->connection->prepare("SELECT stock FROM productos WHERE id = :id FOR UPDATE");
                $stmt->execute(["id" => $productoId]);
                $stock = (int) $stmt->fetchColumn();
                if ($stock < $cantidad) {
                    throw new \Exception("Stock insuficiente para el producto ID {$productoId}.");
                }
                $op = "stock - :cant";
            } else {
                $op = "stock + :cant";
            }

            $this->connection->prepare("UPDATE productos SET stock = {$op} WHERE id = :id")
                 ->execute(["cant" => $cantidad, "id" => $productoId]);
        }
    }
}