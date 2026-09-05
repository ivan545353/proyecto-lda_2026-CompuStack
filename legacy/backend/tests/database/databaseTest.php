<?php

require_once "../../app/config/DBConfig.php";
require_once "../../app/libs/database/Connection.php";

require_once '../../app/core/models/dto/base/interfaceDto.php';
require_once '../../app/core/models/dto/CategoryDto.php';

use app\libs\database\Connection;
use app\core\models\dto\CategoryDto;

try{
    $conn = Connection::get();
    // Esto es peligroso por inyecciones SQL - USAR CONSULTAS PREPARADAS 
    // $nombre = "lala";
    // $sql = "INSERT INTO categorias VALUES(DEFAULT, '{$nombre}')";

    // $sql = "INSERT INTO categorias VALUES(DEFAULT, 'Bolsos')";
    //TEST EXEC() - Evitarlo y usar mejor query()
    // $filasAfectadas = $conn->exec($sql);

    //TEST QUERY() - Devuelve un objeto que es un pdo statement que guardamos en $stmt = statement
    // $sql = "SELECT id, nombre FROM categorias";
    // $stmt= $conn->query($sql);
    // echo "<p>Registros encontrados => {$stmt->rowCount()}</p>";

    //Mostrar el primer registro
    //Fetch() lo que hace es que devuelve el siguiente, retorna un objeto por que lo modificamos
    //Por defecto retorna un array - si queremos que cambiar lo que retorna, ingresamos por parametro
    //lo que retorna -> $registro = $stmt->fetch(\PDO::FETCH_NUM);
    // $registro = $stmt->fetch();
    // echo "<p>Primera categoria => {$registro->nombre}</p>";

    //PROBAMOS EL USO DEL DTO CON LAS CONSULTAS SQL
    $id = 55;
    $sql = "SELECT id, nombre FROM categorias WHERE id = " . $id;
    $stmt = $conn->query($sql);

    $dto = new CategoryDto($stmt->fetch(\PDO::FETCH_ASSOC));
    print_r($dto->toArray());

}
catch(\PDOException $ex){
    echo "Error Database => " . $ex->getMessage();
}