<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dao\ItemDao;
use app\libs\database\Connection;
use app\core\models\dto\ItemDto;

function printTitle(string $title): void {
    echo "<br>================== {$title} ==================<br>";
}

try {
    $connection = Connection::get();
    $dao = new ItemDao($connection);

    printTitle("INSERTANDO NUEVO PRODUCTO");

    $data = [
        "id"          => 0,
        "nombre"      => "Placa de Video NVIDIA RTX 3080",
        "codigo"      => "RTX3080-12GB",
        "descripcion" => "Placa de video de alto rendimiento",
        "categoriaId" => 55,
        "precio"      => 2999.99,
        "stock"       => 50
    ];

    $dto = new ItemDto($data);
    $dao->save($dto->toArray());

    $newId = $dao->getLastInsertId();
    echo "Nuevo producto insertado con ID => {$newId}<br>";
    // Verificamos cambios
    $producto = $dao->load($newId);
    echo "<pre>";
    print_r($producto);
    echo "</pre>";

    printTitle("PROBANDO UPDATE");

    // Modificamos el producto
    $producto = $dao->load($newId);
    $producto["precio"] = 3599.99;
    $producto["stock"] = 30;
    $producto["descripcion"] = "Placa de video de alto rendimiento con memoria de 12GB";
    $dao->update($producto);

    echo "Producto actualizado correctamente.<br>";

    // Verificamos cambios
    $productoActualizado = $dao->load($newId);
    echo "<pre>";
    print_r($productoActualizado);
    echo "</pre>";

    printTitle("PROBANDO SUGGESTIVE BUSCANDO \"Placa\"");

    $result = $dao->suggestive(["keyword" => "Placa"]);
    echo "<pre>";
    print_r($result);
    echo "</pre>";

    printTitle("CARGANDO PRODUCTO POR ID");
    $producto = $dao->load($newId);
    echo "<pre>";
    print_r($producto);
    echo "</pre>";

    printTitle("LISTANDO PRODUCTOS");
    $productos = $dao->list(["limit" => 10, "offset" => 0]);
    echo "<pre>";
    print_r($productos);
    echo "</pre>";

    printTitle("ELIMINANDO PRODUCTO");
    $dao->delete($newId);
    echo "Producto con ID {$newId} eliminado.";

} catch (\PDOException $ex) {
    echo "Error Database => " . $ex->getMessage();
} catch (\Exception $ex) {
    echo "Error => " . $ex->getMessage();
}
