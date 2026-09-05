<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dto\ItemDto;
use app\core\services\ItemService;

$service = new ItemService();

try {
    echo "<h3>===== TEST SAVE =====</h3>";
    $dto = new ItemDto([
        "id" => 0,
        "nombre" => "Teclado Gamer",
        "descripcion" => "Teclado retroiluminado RGB",
        "precio" => 75000,
        "stock" => 50,
        "categoriaId" => 65 
    ]);
    $service->save($dto);
    echo "<p>Producto insertado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $productos = $service->list([]);
    foreach ($productos as $prod) {
        echo "<p>ID: {$prod['id']} - Nombre: {$prod['nombre']} - Precio: {$prod['precio']}</p>";
    }

    echo "<h3>===== TEST LOAD =====</h3>";
    $lastProducto = end($productos);
    $cargado = $service->load($lastProducto["id"]);
    /** @var ItemDto $cargado */
    echo "<p>Producto cargado: ID={$cargado->getId()}, Nombre={$cargado->getNombre()}</p>";
    
    echo "<h3>===== TEST UPDATE =====</h3>";
    $cargado->setPrecio(9999);
    $service->update($cargado);
    echo "<p>Producto actualizado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $productos = $service->list([]);
    foreach ($productos as $prod) {
        echo "<p>ID: {$prod['id']} - Nombre: {$prod['nombre']} - Precio: {$prod['precio']}</p>";
    }

    echo "<h3>===== TEST DELETE =====</h3>";
    $service->delete($cargado);
    echo "<p>Producto eliminado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $productos = $service->list([]);
    foreach ($productos as $prod) {
        echo "<p>ID: {$prod['id']} - Nombre: {$prod['nombre']} - Precio: {$prod['precio']}</p>";
    }

} catch (\PDOException $ex) {
    echo "<p>Error de base de datos => " . $ex->getMessage() . "</p>";
} catch (\Exception $ex) {
    echo "<p>Error de sistema => " . $ex->getMessage() . "</p>";
}
