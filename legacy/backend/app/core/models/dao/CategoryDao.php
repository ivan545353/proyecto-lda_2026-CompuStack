<?php

namespace app\core\models\dao;


use app\core\models\dao\base\BaseDao;
use app\core\models\dao\base\InterfaceDao;
use app\core\models\dto\CategoryDto;

final class CategoryDao extends BaseDao implements InterfaceDao{

    public function __construct(\PDO $connection){
        parent::__construct($connection,"categorias");
    }

    public function load(int $id): array{
        //Aca tenemos que devolver un array, y este metodo lo podemos sacar del test de la DB
        //$dto = new CategoriaDto($stmt->fetch(\PDO::FETCH_ASSOC)); de esa linea deberiamos usarla aca
        // $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["id" => $id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$data) {
            throw new \Exception("Categoría con ID {$id} no encontrada.");
        }
        return (new CategoryDto($data))->toArray();
    }

    public function save(array $data):void{
        unset($data["id"]); // asegurarse de que no se pase el id

        // Validar unicidad
        if ($this->existsByName($data["nombre"])) {
            throw new \Exception("El nombre de la categoría {$data['nombre']} ya existe.");
        }

        $sql = "INSERT INTO {$this->table} VALUES(DEFAULT, :nombre)";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($data);
        
    }

    public function update(array $data): void{
         $sql = "SELECT COUNT(*) FROM {$this->table} WHERE nombre = :nombre AND id != :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            "nombre" => $data["nombre"],
            "id"     => $data["id"]
        ]);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            throw new \Exception("<p>El nombre <strong>{$data['nombre']}</strong> ya está siendo utilizado por otra categoría.</p>");
        }

        $sql = "UPDATE {$this->table} SET nombre = :nombre WHERE id = :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            "id"     => $data["id"],
            "nombre" => $data["nombre"]
        ]);
    }

    public function delete(int $id): void{
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["id" => $id]);
    }

    public function list(array $filters): array{
        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->table} WHERE 1=1";
        $params = [];

        if (!empty($filters["nombre"])) {
            $sql .= " AND nombre LIKE :nombre";
            $params["nombre"] = "%" . $filters["nombre"] . "%";
        }

        $sql .= " ORDER BY nombre ASC";

        if (isset($filters["limit"]) && isset($filters["offset"])) {
            $sql .= " LIMIT :offset, :limit";
            $params["offset"] = (int) $filters["offset"];
            $params["limit"] = (int) $filters["limit"];
        }

        $stmt = $this->connection->prepare($sql);

        if (isset($params["offset"])) {
            $stmt->bindValue(":offset", $params["offset"], \PDO::PARAM_INT);
            $stmt->bindValue(":limit", $params["limit"], \PDO::PARAM_INT);
            unset($params["offset"], $params["limit"]);
        }

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function suggestive(array $filters): array{
        $sql = "SELECT * FROM {$this->table} WHERE nombre LIKE :keyword ORDER BY nombre ASC LIMIT 10";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            "keyword" => "%" . ($filters["keyword"] ?? "") . "%"
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Verifica si una categoría con el nombre dado ya existe en la base de datos.
     *
     * @param string $nombre El nombre de la categoría a verificar.
     * @return bool Retorna true si la categoría existe, false en caso contrario.
     */
    public function existsByName(string $nombre): bool {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE nombre = :nombre";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(["nombre" => $nombre]);
        return (bool) $stmt->fetchColumn();
    }

    
    
}
