<?php
namespace app;

use app\libs\pipeline\Pipeline;
use app\libs\pipeline\middlewares\ExceptionHandlerMiddleware;
use app\libs\pipeline\middlewares\RouterHandlerMiddleware;
use app\libs\pipeline\middlewares\AuthenticationHandlerMiddleware;
use app\libs\pipeline\middlewares\AuthorizationHandlerMiddleware;
use app\libs\http\Request;
use app\libs\http\Response;
use app\libs\pipeline\middlewares\CorsHandlerMiddleware;

final class App{

    private function __construct(){}

    public static function run(){
        $pipeline = new Pipeline();
        // Esto es encadenamiento de metodos, gracias al return $this del metodo pipe
        $pipeline->pipe(new CorsHandlerMiddleware())
                ->pipe(new ExceptionHandlerMiddleware())
                ->pipe(new AuthenticationHandlerMiddleware())
                ->pipe(new AuthorizationHandlerMiddleware())
                ->pipe(new RouterHandlerMiddleware());

        $pipeline->process(new Request(), new Response());
    }
}