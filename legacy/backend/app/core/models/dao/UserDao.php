<?php

namespace app\core\models\dao;

use app\core\models\dao\base\BaseDao;
use app\core\models\dao\base\InterfaceDao;

final class UserDao extends BaseDao implements InterfaceDao {

    public function __construct(\PDO $connection) {
        parent::__construct($connection, "usuarios");
    }

    public function login($cuenta): array {
        $sql  = "SELECT u.id, u.apellido, u.nombres, u.cuenta, u.correo, u.clave,";
        $sql .= " p.nombre AS perfil, u.perfil_id, u.estado, u.resetPass";
        $sql .= " FROM usuarios u";
        $sql .= " JOIN perfiles p ON p.id = u.perfil_id";
        $sql .= " WHERE (u.cuenta = :cuenta OR u.correo = :cuenta)";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["cuenta" => $cuenta]);

        if ($stmt->rowCount() != 1) {
            throw new \Exception("El nombre de usuario o la contraseña no coinciden");
        }
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function load(int $id): array {
        $sql  = "SELECT u.*, p.nombre AS perfil";
        $sql .= " FROM {$this->table} u";
        $sql .= " JOIN perfiles p ON p.id = u.perfil_id";
        $sql .= " WHERE u.id = :id LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["id" => $id]);

        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$data) {
            throw new \Exception("No se encontró el usuario con ID {$id}");
        }
        return $data;
    }

    public function save(array $data): void {
        if ($this->existsByCuenta($data["cuenta"])) {
            throw new \Exception("La cuenta '{$data["cuenta"]}' ya está en uso.");
        }
        if ($this->existsByCorreo($data["correo"])) {
            throw new \Exception("El correo '{$data["correo"]}' ya está en uso.");
        }

        $sql = "INSERT INTO {$this->table} 
            (apellido, nombres, cuenta, perfil_id, clave, correo, estado, fechaAlta, resetPass) 
            VALUES (:apellido, :nombres, :cuenta, :perfil_id, :clave, :correo, :estado, :fechaAlta, :resetPass)";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            "apellido"  => $data["apellido"],
            "nombres"   => $data["nombres"],
            "cuenta"    => $data["cuenta"],
            "perfil_id" => $data["perfil_id"],
            "clave"     => $data["clave"],
            "correo"    => $data["correo"],
            "estado"    => $data["estado"],
            "fechaAlta" => $data["fechaAlta"],
            "resetPass" => $data["resetPass"]
        ]);
    }

    public function update(array $data): void {
        if ($this->existsByCuenta($data["cuenta"], $data["id"])) {
            throw new \Exception("La cuenta '{$data["cuenta"]}' ya está en uso por otro usuario.");
        }
        if ($this->existsByCorreo($data["correo"], $data["id"])) {
            throw new \Exception("El correo '{$data["correo"]}' ya está en uso por otro usuario.");
        }

        $sql = "UPDATE {$this->table} SET 
            apellido  = :apellido,
            nombres   = :nombres,
            cuenta    = :cuenta,
            perfil_id = :perfil_id,
            clave     = :clave,
            correo    = :correo,
            estado    = :estado,
            fechaAlta = :fechaAlta,
            resetPass = :resetPass
            WHERE id = :id";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            "apellido"  => $data["apellido"],
            "nombres"   => $data["nombres"],
            "cuenta"    => $data["cuenta"],
            "perfil_id" => $data["perfil_id"],
            "clave"     => $data["clave"],
            "correo"    => $data["correo"],
            "estado"    => $data["estado"],
            "fechaAlta" => $data["fechaAlta"],
            "resetPass" => $data["resetPass"],
            "id"        => $data["id"]
        ]);
    }

    public function delete(int $id): void {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["id" => $id]);
    }

    public function list(array $filters): array {
        $sql  = "SELECT SQL_CALC_FOUND_ROWS u.*, p.nombre AS perfil";
        $sql .= " FROM {$this->table} u";
        $sql .= " JOIN perfiles p ON p.id = u.perfil_id";
        $sql .= " WHERE 1";

        if (!empty($filters["perfil_id"])) {
            $sql .= " AND u.perfil_id = :perfil_id";
        }
        if (!empty($filters["estado"])) {
            $sql .= " AND u.estado = :estado";
        }

        $sql .= " ORDER BY u.apellido, u.nombres";

        if (!empty($filters["limit"])) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $this->connection->prepare($sql);

        if (!empty($filters["perfil_id"])) {
            $stmt->bindValue(":perfil_id", (int)$filters["perfil_id"], \PDO::PARAM_INT);
        }
        if (!empty($filters["estado"])) {
            $stmt->bindValue(":estado", $filters["estado"], \PDO::PARAM_INT);
        }
        if (!empty($filters["limit"])) {
            $stmt->bindValue(":limit", (int)$filters["limit"], \PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function suggestive(array $filters): array {
        $sql = "SELECT id, cuenta, apellido, nombres FROM {$this->table} 
                WHERE cuenta LIKE :keyword OR apellido LIKE :keyword OR nombres LIKE :keyword 
                ORDER BY apellido, nombres LIMIT 10";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["keyword" => "%" . ($filters["keyword"] ?? "") . "%"]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function foundRows(): int {
        return parent::foundRows();
    }

    public function getLastInsertId(): int {
        return parent::getLastInsertId();
    }

    // ================== Métodos especiales ===================

    public function enable(int $id): void {
        $stmt = $this->connection->prepare("UPDATE {$this->table} SET estado = 1 WHERE id = :id");
        $stmt->execute(["id" => $id]);
    }

    public function disable(int $id): void {
        $stmt = $this->connection->prepare("UPDATE {$this->table} SET estado = 0 WHERE id = :id");
        $stmt->execute(["id" => $id]);
    }

    public function reset(int $id): void {
        $stmt = $this->connection->prepare("UPDATE {$this->table} SET resetPass = 1 WHERE id = :id");
        $stmt->execute(["id" => $id]);
    }

    public function existsByCuenta(string $cuenta, int $excludeId = 0): bool {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE cuenta = :cuenta";
        if ($excludeId > 0) { $sql .= " AND id != :id"; }

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(":cuenta", $cuenta);
        if ($excludeId > 0) { $stmt->bindValue(":id", $excludeId, \PDO::PARAM_INT); }
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function existsByCorreo(string $correo, int $excludeId = 0): bool {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE correo = :correo";
        if ($excludeId > 0) { $sql .= " AND id != :id"; }

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(":correo", $correo);
        if ($excludeId > 0) { $stmt->bindValue(":id", $excludeId, \PDO::PARAM_INT); }
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function findByCuenta(string $cuenta): ?array {
        $stmt = $this->connection->prepare("SELECT * FROM {$this->table} WHERE cuenta = :cuenta LIMIT 1");
        $stmt->bindValue(":cuenta", $cuenta);
        $stmt->execute();
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public function updatePassword(int $id, string $hashedPassword): bool {
        $stmt = $this->connection->prepare("UPDATE {$this->table} SET clave = :clave, resetPass = 0 WHERE id = :id");
        return $stmt->execute(["clave" => $hashedPassword, "id" => $id]);
    }

    public function listProfiles(): array {
        $stmt = $this->connection->prepare("SELECT id, nombre FROM perfiles ORDER BY nombre");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}