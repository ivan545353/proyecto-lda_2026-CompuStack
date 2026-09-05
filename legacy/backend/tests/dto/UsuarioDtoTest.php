<?php

use app\core\models\dto\UserDto;

require_once '../../app/core/models/dto/base/InterfaceDto.php';
require_once '../../app/core/models/dto/UserDto.php';

$peticionCliente = '{
    "id":1,
    "apellido":"Reales",
    "nombres":"Daniel Ivan",
    "cuenta":"ivan5453",
    "perfil":"Administrador",
    "clave":"5453",
    "correo":"ivan545353@gmail.com",
    "estado":1,
    "fechaAlta":"2025-06-17",
    "resetPass":0
}';

$data = json_decode($peticionCliente, true);

$usuario = new UserDto($data);

echo "<pre>";
print_r($usuario->toArray());
echo "</pre>";
