<?php

require_once "../../app/core/models/enums/PerfilUsuarioEnum.php";

use app\core\models\enums\PerfilUsuarioEnum;

?>

<select>
    <option value="">Seleccione...</option>
    <?php
        foreach(PerfilUsuarioEnum::cases() as $perfil){
            echo "<option value =\"{$perfil->value}\">{$perfil->value}</option>";
        }
    ?>
</select>

<!-- REPOSITORIO LAB PROGRAMACION github.com/joserasjido/LP-UACO-2025-->