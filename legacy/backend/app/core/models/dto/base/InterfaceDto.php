<?php

namespace app\core\models\dto\base;

interface InterfaceDto{
    /**
     *  Devuelve un arreglo con todos los campos de la tabla
     * @return array arreglo con los campos de la tabla
     */
    public function toArray():array;

    public function getId(): int;

}