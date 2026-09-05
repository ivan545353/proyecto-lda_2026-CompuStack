<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dto\UserDto;
use app\core\services\UserService;

$service = new UserService();

try {
    echo "<h3>===== TEST SAVE =====</h3>";
    $dto = new UserDto([
        "id" => 0,
        "nombres" => "Reales Ivan",
        "cuenta" => "ivan5453",
        "clave" => password_hash("5453", PASSWORD_DEFAULT),
        "estado" => 1,
        "reset" => 0
    ]);
    $service->save($dto);
    echo "<p>Usuario insertado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

    echo "<h3>===== TEST LOAD =====</h3>";
    $lastUsuario = end($usuarios);
    $cargado = $service->load($lastUsuario["id"]);
    /** @var UserDto $cargado */
    echo "<p>Usuario cargado: ID={$cargado->getId()}, Usuario={$cargado->getCuenta()}</p>";

    echo "<h3>===== TEST UPDATE =====</h3>";
    $cargado->setNombres("Titos Alan ( MODIFICACION)");
    $service->update($cargado);
    echo "<p>Usuario actualizado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

    echo "<h3>===== TEST ENABLE/DISABLE/RESET =====</h3>";
    $service->disable($cargado->getId());
    echo "<p>Usuario deshabilitado.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

    $service->enable($cargado->getId());
    echo "<p>Usuario habilitado.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

    $service->reset($cargado->getId());
    echo "<p>Usuario marcado para cambio de clave.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

    echo "<h3>===== TEST DELETE =====</h3>";
    $service->delete($cargado);
    echo "<p>Usuario eliminado correctamente.</p>";

    echo "<h3>===== TEST LIST =====</h3>";
    $usuarios = $service->list([]);
    foreach ($usuarios as $u) {
        echo "<p>ID: {$u['id']} - Nombres: {$u['nombres']} - Cuenta: {$u['cuenta']} - Estado: {$u['estado']}</p>";
    }

} catch (\PDOException $ex) {
    echo "<p>Error de base de datos => " . $ex->getMessage() . "</p>";
} catch (\Exception $ex) {
    echo "<p>Error de sistema => " . $ex->getMessage() . "</p>";
}
