<?php

use app\core\models\dto\ItemDto;

require_once '../../app/core/models/dto/base/InterfaceDto.php';
require_once '../../app/core/models/dto/ItemDto.php';

$peticionCliente = '{
    "id":2,
    "nombre":"Placa de Video NVIDIA RTX 3080",
    "codigo":"NVIDIA-RTX3080",
    "descripcion":"Placa de video de alto rendimiento para juegos",
    "categoriaId":4,
    "precio":1999.99,
    "stock":15
}';

$data = json_decode($peticionCliente, true);

$producto = new ItemDto($data);

echo "<pre>";
print_r($producto->toArray());
echo "</pre>";
