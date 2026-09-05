<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dao\UserDao;
use app\core\models\dto\UserDto;
use app\libs\database\Connection;

function printTitle(string $title): void {
    echo "<br>================== {$title} ==================<br>";
}

try {
    $dao = new UserDao(Connection::get());

    printTitle("INSERTANDO NUEVO USUARIO");
    $data = [
        "id" => 0,
        "apellido" => "Reales",
        "nombres" => "Daniel Ivan",
        "cuenta" => "ivan5453",
        "perfil" => "ADMIN",
        "clave" => password_hash("5453", PASSWORD_DEFAULT),
        "correo" => "ivan5453@gmail.com",
        "estado" => 1,
        "fechaAlta" => date("Y-m-d"),
        "resetPass" => 0
    ];
    $dto = new UserDto($data);
    $dao->save($dto->toArray());
    $id = $dao->getLastInsertId();
    echo "Nuevo usuario insertado con ID => {$id}<br>";

    printTitle("CARGANDO USUARIO POR ID");
    $usuario = $dao->load($id);
    echo "<pre>";
    print_r($usuario);
    echo "</pre>";

    printTitle("ACTUALIZANDO USUARIO");
    $usuario["apellido"] = "Pérez";
    $usuario["correo"] = "lperez@example.com";
    $dao->update($usuario);
    echo "<pre>";
    print_r($dao->load($id));
    echo "</pre>";

    printTitle("BUSQUEDA SUGESTIVA");
    $suggestive = $dao->suggestive(["keyword" => "iv"]);
    echo "<pre>";
    print_r($suggestive);
    echo "</pre>";

    printTitle("HABILITANDO USUARIO");
    $dao->enable($id);
    echo "<pre>";
    print_r($dao->load($id));
    echo "</pre>";

    printTitle("DESHABILITANDO USUARIO");
    $dao->disable($id);
    echo "<pre>";
    print_r($dao->load($id));
    echo "</pre>";

    printTitle("MARCA PARA RESETEAR CONTRASEÑA");
    $dao->reset($id);
    echo "<pre>";
    print_r($dao->load($id));
    echo "</pre>";

    printTitle("LISTANDO TODOS LOS USUARIOS");
    $listado = $dao->list([]);
    echo "<pre>";
    print_r($listado);
    echo "</pre>";  

    printTitle("ELIMINANDO USUARIO");
    $dao->delete($id);
    echo "Usuario con ID {$id} eliminado correctamente<br>";

} catch (\Exception $ex) {
    echo "Error => " . $ex->getMessage() . "<br>";
}
