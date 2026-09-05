<?php

namespace app\core\models\dao\base;

class BaseDao{

    protected $connection;
    protected $table;

    /**
     * Constructor padre de los DAO.
     * @param PDO $connection Conexión a la base de datos, ya establecida.
     * @param string $table Nombre 
     */
    public function __construct(\PDO $connection, string $table){
        $this->connection = $connection;
        $this->table = $table;
    }

    //*************** Métodos públicos *******************

    public function foundRows(): int{
        $stmt = $this->connection->query("SELECT FOUND_ROWS();");
        $data = $stmt->fetch(\PDO::FETCH_NUM);
        $stmt->closeCursor();
        return (int) $data[0];
    }

    public function getLastInsertId(): int{
        $id = $this->connection->lastInsertId();
        return is_numeric($id) ? (int) $id : 0;
    }
}