<?php

namespace app\libs\database;

final class Connection{

    private static ?\PDO $conn = null;

    //Se instancia como privado el constructor para hacer un objeto singleton
    private function __construct(){

    }

    public static function get(): \PDO{
        if(self::$conn == null){
            
            self::$conn = new \PDO(DATABASE_DSN, 'REEMPLAZADO_POR_SEGURIDAD_VER_legacy/README.md', 'REEMPLAZADO_POR_SEGURIDAD_VER_legacy/README.md', array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ
            ));
        }

        return self::$conn;
    }

}