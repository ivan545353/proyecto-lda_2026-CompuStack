<?php

require_once '../../app/config/AppConfig.php';
require_once '../../app/config/DBConfig.php';
require_once '../../app/vendor/autoload.php';

use app\core\models\dto\CategoryDto;
use app\core\services\CategoryService;
use app\libs\http\Request;
use app\libs\http\Response;

//Response afuera del try para que quede como variable global y pueda agarrarlo en el catch
$response = new Response();
try {
    $request = new Request();
    //Seteamos el controller y el action para que no quede vacio en el header de la peticion.
    //Lo colocamos primero siempre.
    $response->setController($request->getController());
    $response->setAction($request->getAction());
    //devuelve null porque no encontro nada en el buffer, entonces es correcto
    // var_dump($request->getDataFromInput());
    //ESTE SI devuelve por que es con POST, pero no nos interesa porque vamos a trabajar con peticiones asincronas
    // print_r($_POST);
    $dto = new CategoryDto($request->getDataFromInput());
    $service = new CategoryService();
    $service->save($dto);

    $response->setMessage("<p>Se agregó una nueva categoría al sistema</p>");
    $response->send();

    //SE tiene que cargar en la base de datos.

} catch (\PDOException $ex) {
    //BUSCO QUE EL ERROR SE META AL ATRIBUTO ERROR DEL JSON Y NO QUE SALTE EN CONSOLA IMPORTANTE
    //LA IDEA ES DESPUES USARLO PARA VALIDAR, SI EL CAMPO ERROR ESTA VACIO SE SIGUE.
    $response->setError("Error database => ". $ex->getMessage());
    $response->send();
} catch (\Exception $ex) {
    $response->setError("Error de sistema => " . $ex->getMessage());
    $response->send();
}

//SI EL ERROR ES DISTINTO DE VACIO MUESTRA UN MENSAJE DE ERROR, SINO MUESTRA MESSAGE EN EL FRONT 
//EN EL JS DEL FEEDBACK QUE IMPLEMENTE CON ANTERIORIDAD
//TENGO QUE USAR EL FETCH EN TODOS LOS METODOS ES DECIR TODOS LOS BOTONES QUE IMPLIQUEN EDITAR, GRABAR ETC
// AHORA LO VAMOS A PROBAR.
