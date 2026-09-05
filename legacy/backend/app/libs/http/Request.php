<?php

namespace app\libs\http;

final class Request{
    
    private $controller, $action;
    private $authUser = null;

    public function __construct(){
        $this->setController($_GET["controller"] ?? APP_DEFAULT_CONTROLLER);
        $this->setAction($_GET["action"] ?? APP_DEFAULT_ACTION);
    }

    /*==============GETTERS Y SETTERS=================*/
    public function getMethod(): string{
        return $_SERVER["REQUEST_METHOD"];
    }

    public function getController() : ?string{
        return $this->controller;
    }

    public function setController(?string $controller): void{
        $this->controller = $controller;
    }

    public function getAction() : ?string{
        return $this->action;
    }

    public function setAction(?string $action) : void{
        $this->action = $action;
    }

    public function getId() : ?string{
        return $this->getParameterValue("id",null);
    }

    public function getParameterValue(string $paramName, ?string $defaultValue) : ?string{
    // Para POST con formularios clásicos, $_POST; para el resto (GET/PUT/DELETE)
    // los parámetros de ruta llegan por la URL, es decir $_GET.
    if ($this->getMethod() === "POST" && isset($_POST[$paramName])) {
        return $_POST[$paramName];
    }
    return $_GET[$paramName] ?? $defaultValue;
}

    public function getDataFromInput(): ?array{
        return json_decode(file_get_contents("php://input"), true);
    }

    public function setAuthUser($authUser): void { $this->authUser = $authUser; }

    public function getAuthUser() { return $this->authUser; }
}