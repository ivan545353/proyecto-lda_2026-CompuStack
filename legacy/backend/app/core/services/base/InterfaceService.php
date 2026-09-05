<?php

namespace app\core\services\base;

use app\core\models\dto\base\InterfaceDto;

/**
 * Descripción de la clase InterfaceService
 * 
 * @author Reales Daniel Ivan
 */

 interface InterfaceService{

    /**
     * Gestiona la lógica del negocio para obtener una entidad.
     * @param int $id identificador del registro solicitado.
     * @return InterfaceDto Retorna el objeto, si es que existe en el registro.
     */
    public function load(int $id): InterfaceDto;

    /**
     * Gestiona la logica del negocio para guardar una entidad.
     * @param InterfaceDto $dto Objeto a ser guardado como registro.
     */
    public function save(InterfaceDto $dto): void;
    
    /**
     * Gestiona la logica del negocio para actualizar los datos de una entidad.
     * @param InterfaceDto $dto objeto con los datos actualizados y que seran persistidos
     */
    public function update(InterfaceDto $dto): void;

    /**
     * Gestiona la lógica del negocio para eliminar una entidad.
     * @param InterfaceDto $dto objeto que representa el registro a eliminar.
     */
    public function delete(InterfaceDto $dto): void;

    /**
     * Gestionar la lógica del negocio para listar registros.
     * @param array $filters filtros que se tiene que tener en cuenta para armar el listado.
     * @return array Devuelve un arreglo con los registros encontrados.
     */
    public function list(array $filters): array;
 }