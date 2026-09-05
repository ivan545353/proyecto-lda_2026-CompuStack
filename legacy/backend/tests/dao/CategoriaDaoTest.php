<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\libs\database\Connection;
use app\core\models\dto\CategoryDto;
use app\core\models\dao\CategoryDao;

function printTitle(string $title): void {
    echo "<br>================== {$title} ==================<br>";
}

try {
    $connection = Connection::get();
    $dao = new CategoryDao($connection);

    // Prueba: INSERT
    printTitle("INSERTANDO NUEVA CATEGORIA");
    $nuevaCategoria = new CategoryDto(["id" => 0, "nombre" => "Zapatillas"]);
    $dao->save($nuevaCategoria->toArray());
    $lastId = $dao->getLastInsertId();
    echo "Nueva categoría insertada con ID => {$lastId}\n";

    // Prueba: LOAD
    printTitle("CARGANDO CATEGORIA POR ID");
    $categoriaCargada = $dao->load($lastId);
    echo "<pre>";
    print_r($categoriaCargada);
    echo "</pre>";

    // Prueba: UPDATE
    printTitle("ACTUALIZANDO CATEGORIA");
    $categoriaActualizada = new CategoryDto([
        "id" => $lastId,
        "nombre" => "Zapatillas Deportivas"
    ]);
    $dao->update($categoriaActualizada->toArray());
    echo "Categoría actualizada correctamente.\n";

    // Prueba: LIST (sin filtros)
    printTitle("LISTANDO TODAS LAS CATEGORIAS");
    $todasLasCategorias = $dao->list([]);
    echo "<pre>";
    print_r($todasLasCategorias);
    echo "</pre>";
    echo "Total registros encontrados (con foundRows()): " . $dao->foundRows() . "\n";

    // Prueba: LIST (con filtro por nombre)
    printTitle("LISTANDO CON FILTRO POR NOMBRE");
    $categoriasFiltradas = $dao->list(["nombre" => "Zapa"]);
    echo "<pre>";
    print_r($categoriasFiltradas);
    echo "</pre>";
    
    // Prueba: SUGGESTIVE
    printTitle("BUSQUEDA SUGESTIVA POR PALABRA CLAVE");
    $sugerencias = $dao->suggestive(["keyword" => "Za"]);
    echo "<pre>";
    print_r($sugerencias);
    echo "</pre>";
    
    // Prueba: DELETE
    printTitle("ELIMINANDO CATEGORIA");
    $dao->delete($lastId);
    echo "Categoría con ID {$lastId} eliminada correctamente.\n";

} catch (\PDOException $ex) {
    echo "Error de base de datos => " . $ex->getMessage() . "\n";
} catch (\Exception $ex) {
    echo "Error => " . $ex->getMessage() . "\n";
}