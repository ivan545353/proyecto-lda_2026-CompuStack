<?php

use app\core\models\dto\CategoryDto;

require_once '../../app/core/models/dto/base/interfaceDto.php';
require_once '../../app/core/models/dto/CategoryDto.php';

$peticionCliente = '{"id":4, "nombre":"juguetes"}';
$data = json_decode($peticionCliente,true); //el true es para que lo convierta en array
//print_r($peticionCliente);

$categoria = new CategoryDto($data);
print_r($categoria->toArray());

//donde se hacen las validaciones?
//setters y dto, despues en el servicio
?>