<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dto\CategoryDto;
use app\core\services\CategoryService;

$service = new CategoryService();

try {
    echo "<h3>===== TEST SAVE =====</h3>";
    $dto = new CategoryDto(["id" => 0, "nombre" => "procesadores"]);
    $service->save($dto);
    echo "<p>Categoría insertada correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $categorias = $service->list([]);
    foreach ($categorias as $cat) {
        echo "<p>ID: {$cat['id']} - Nombre: {$cat['nombre']}</p>";
    }

    echo "<h3>===== TEST LOAD =====</h3>";
    // Cargamos la última categoría insertada
    $lastCategoria = end($categorias);
    $cargada = $service->load($lastCategoria["id"]);
    /** @var CategoryDto $cargada */

    echo "<p>Categoría cargada: ID={$cargada->getId()}, Nombre={$cargada->getNombre()}</p>";

    echo "<h3>===== TEST UPDATE =====</h3>";
    $cargada->setNombre("procesadores_modificado");
    $service->update($cargada);
    echo "<p>Categoría actualizada correctamente.</p>";

    echo "<h3>===== TEST LIST DESPUES DEL UPDATE =====</h3>";
    $categorias = $service->list([]);
    foreach ($categorias as $cat) {
        echo "<p>ID: {$cat['id']} - Nombre: {$cat['nombre']}</p>";
    }

    echo "<h3>===== TEST DELETE =====</h3>";
    $service->delete($cargada);
    echo "<p>Categoría eliminada correctamente.</p>";
    
    echo "<h3>===== TEST LIST DESPUES DEL DELETE =====</h3>";
    $categorias = $service->list([]);
    foreach ($categorias as $cat) {
        echo "<p>ID: {$cat['id']} - Nombre: {$cat['nombre']}</p>";
    }

} catch (\PDOException $ex) {
    echo "<p>Error de base de datos => " . $ex->getMessage() . "</p>";
} catch (\Exception $ex) {
    echo "<p>Error de sistema => " . $ex->getMessage() . "</p>";
}
