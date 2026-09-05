<?php

namespace app\core\models\dao\base;

/**
 * Descripción de la clase InterfaceDao
 * 
 * @author Ivan Reales
 */
interface InterfaceDao{
    /**
     * Devuelve un objeto, con los datos del correspondiente registro en base de datos.
     * @param int $id identificador del registro a cargar, desde la base de datos.
     * @return array Retorna un arreglo con los datos del registro en la base de datos.
     * @throws Exception Retorna una excepción en caso de error, o no encontrarse el registro buscado.
     */

    public function load(int $id): array;

    /**
     * Guarda los datos del objeto apsado como parámetro, como un nuevo registro en la base de datos.
     * @param array $data Arreglo con datos a guardarse como un nuevo registro en la base de datos.
     */

    public function save(array $data): void;

    /**
     * Replica los datos del objeto, en su correspondiente registro en base de datos.
     * @param array $data Arreglo con datos a replicarse en su correspondiente registro en
     * base de datos.
     */
    public function update(array $data):void;

    /**
     * Elimina de la base de datos un registro.
     * @param int $id Identificador del registro a eliminar en la base de datos.
     */
    public function delete(int $id): void;

    /**
     * Lista los registros de una tabla.
     * @param array $filters Parámetros a tener en cuenta en la sentencia SQL.
     * @return array Devuelve un arreglo con los registros encontrados.
     */
    public function list(array $filters): array;

    /**
     * Lista los registros de una tabla, en base a una búsqueda sugestiva.
     * @param array $filters Filtro, clave o dato ingresado por el usuario en el sugestivo.
     * @return array Devuelve un arreglo con los registros encontrados.
     */
    public function suggestive(array $filters): array;

    /**
     * Devuelve la cantidad total de registros afectados por una consulta SELECT
     * SQL_CALC_FOUND_ROWS.
     * Este método se debería invocar despues de llamar a un método list(), desde cualquier DAO.
     * @return int Cantidad de registros afectados, sin la cláusula "limit".
     */
    public function foundRows(): int;

    /**
     * Retorna el último id (indice primario autoincremental) generador por la conexión del dao
     * actual.
     */
    public function getLastInsertId():int;
}