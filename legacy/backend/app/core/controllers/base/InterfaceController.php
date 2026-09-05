<?php

namespace app\core\controllers\base;

use app\libs\http\Request;
use app\libs\http\Response;

interface InterfaceController{


    /**
     * Gestiona los servicios correspondientes, para la búsqueda de una entidad existente en el sistema. Se debe enviar el ID del cliente en la petición.
     * @param Request $request Parámetros y datos de entrada. Tomados desde la petición del cliente.
     * @param Response $response Respuesta del servidor hacia el cliente.
     * @return void
     */
    public function load(Request $request, Response $response): void;
   

    /**
     * Gestiona los servicios correspondientes, para el alta de una nueva entidad en el sistema.
     * @param Request $request Parámetros y datos de entrada. Tomados desde la petición del cliente.
     * @param Response $response Respuesta del servidor hacia el cliente.
     * @return void
     */
    public function save(Request $request, Response $response): void;


    /**
     * Gestiona los servicios correspondientes, para la actualización de datos de una entidad existente en el sistema.
     * @param Request $request Parámetros y datos de entrada. Tomados desde la petición del cliente.
     * @param Response $response Respuesta del servidor hacia el cliente.
     * @return void
     */
    public function update(Request $request, Response $response): void;

    /**
     * Gestiona los servicios correspondientes, para la eliminación (física) de la entidad.
     * @param Request $request Parámetros y datos de entrada. Tomados desde la petición del cliente.
     * @param Response $response Respuesta del servidor hacia el cliente.
     * @return void
     */
    public function delete(Request $request, Response $response): void;

    /**
     * Gestiona los servicios correspondientes, para listar registros desde la base de datos.
     * @param Request $request Parámetros y datos de entrada. Tomados desde la petición del cliente.
     * @param Response $response Respuesta del servidor hacia el cliente.
     * @return void
     */
    public function list(Request $request, Response $response): void;
}